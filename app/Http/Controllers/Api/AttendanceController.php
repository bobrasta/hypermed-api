<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AttendanceImport;
use App\Models\AttendanceRecord;
use App\Models\User;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

class AttendanceController extends Controller
{
    private function authorize(Request $request): void
    {
        abort_if(! $request->user()->hasStaffManageAuthority(), 403, 'You are not authorised to manage attendance.');
    }

    public function index(Request $request)
    {
        $this->authorize($request);

        $data = $request->validate([
            'start'   => ['required', 'date'],
            'end'     => ['required', 'date'],
            'user_id' => ['nullable', 'exists:users,id'],
        ]);

        $records = AttendanceRecord::with('user')
            ->whereBetween('date', [$data['start'], $data['end']])
            ->when($data['user_id'] ?? null, fn ($q, $uid) => $q->where('user_id', $uid))
            ->orderBy('date')
            ->get();

        return response()->json(['data' => $records->map(fn ($r) => $this->recordJson($r))]);
    }

    // Manual marking — the primary path (see class doc): HR marks a single
    // staff member's day directly, no device/import dependency at all.
    public function mark(Request $request)
    {
        $this->authorize($request);

        $data = $request->validate([
            'user_id'        => ['required', 'exists:users,id'],
            'date'           => ['required', 'date'],
            'status'         => ['required', 'in:present,absent,late,half_day,leave'],
            'clock_in'       => ['nullable', 'date_format:H:i'],
            'clock_out'      => ['nullable', 'date_format:H:i'],
            'overtime_hours' => ['nullable', 'numeric', 'min:0'],
        ]);

        $record = AttendanceRecord::updateOrCreate(
            ['user_id' => $data['user_id'], 'date' => $data['date']],
            [
                'status'         => $data['status'],
                'clock_in'       => $data['clock_in'] ?? null,
                'clock_out'      => $data['clock_out'] ?? null,
                'overtime_hours' => $data['overtime_hours'] ?? null,
                'source'         => 'manual',
                'marked_by'      => $request->user()->id,
            ],
        );

        return response()->json(['data' => $this->recordJson($record->load('user'))]);
    }

    // Marks the same status for several staff on one date in one call —
    // e.g. "mark today present" for a whole team, without one request per
    // person.
    public function bulkMark(Request $request)
    {
        $this->authorize($request);

        $data = $request->validate([
            'user_ids' => ['required', 'array', 'min:1'],
            'user_ids.*' => ['exists:users,id'],
            'date'     => ['required', 'date'],
            'status'   => ['required', 'in:present,absent,late,half_day,leave'],
        ]);

        foreach ($data['user_ids'] as $userId) {
            AttendanceRecord::updateOrCreate(
                ['user_id' => $userId, 'date' => $data['date']],
                ['status' => $data['status'], 'source' => 'manual', 'marked_by' => $request->user()->id],
            );
        }

        return response()->json(['message' => count($data['user_ids']) . ' record(s) marked.']);
    }

    // Best-effort bulk import from a biometric-terminal Excel export. No
    // real HIK sample file was available to lock an exact column layout
    // against, so this matches columns by flexible header-name variants
    // (Name/Employee/Staff → name, ID/Badge/Biometric → biometric_id,
    // Date, Time In/Check In → clock_in, Time Out/Check Out → clock_out)
    // rather than fixed positions. Rows it can't confidently match to a
    // staff member + date are collected and returned, not silently
    // dropped — manual marking above always still works as a fallback for
    // exactly those rows.
    public function import(Request $request)
    {
        $this->authorize($request);
        $request->validate(['file' => ['required', 'file', 'max:20480']]);

        $file = $request->file('file');
        $spreadsheet = IOFactory::load($file->getRealPath());
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, false);

        if (count($rows) < 2) {
            return response()->json(['message' => 'The file has no data rows.'], 422);
        }

        $header = array_map(fn ($h) => strtolower(trim((string) $h)), $rows[0]);
        $col = fn (array $candidates) => $this->findColumn($header, $candidates);

        $nameCol   = $col(['name', 'employee name', 'staff name', 'full name']);
        $idCol     = $col(['id', 'employee id', 'badge no', 'badge id', 'person id', 'biometric id', 'card no']);
        $dateCol   = $col(['date', 'attendance date']);
        $inCol     = $col(['time in', 'check in', 'clock in', 'in time', 'first in']);
        $outCol    = $col(['time out', 'check out', 'clock out', 'out time', 'last out']);
        $statusCol = $col(['status', 'attendance status']);

        if ($dateCol === null || ($nameCol === null && $idCol === null)) {
            return response()->json([
                'message' => 'Could not find a Date column and a Name/ID column in the file\'s header row. '
                    . 'Found headers: ' . implode(', ', array_filter($header)),
            ], 422);
        }

        $usersByBiometric = User::whereNotNull('biometric_id')->get()->keyBy(fn ($u) => strtolower(trim($u->biometric_id)));
        $usersByName = User::all()->keyBy(fn ($u) => strtolower(trim($u->name)));

        $matched = 0;
        $unmatched = [];
        $dataRows = array_slice($rows, 1);

        foreach ($dataRows as $i => $row) {
            $rawName = $nameCol !== null ? trim((string) ($row[$nameCol] ?? '')) : null;
            $rawId   = $idCol !== null ? trim((string) ($row[$idCol] ?? '')) : null;
            $rawDate = $dateCol !== null ? $row[$dateCol] ?? null : null;

            if (($rawName === null || $rawName === '') && ($rawId === null || $rawId === '')) {
                continue; // blank row
            }

            $user = null;
            if ($rawId) {
                $user = $usersByBiometric->get(strtolower($rawId));
            }
            if (! $user && $rawName) {
                $user = $usersByName->get(strtolower($rawName));
            }

            $date = $this->parseExcelDate($rawDate);

            if (! $user || ! $date) {
                $unmatched[] = [
                    'row'    => $i + 2, // +1 header, +1 to 1-index
                    'name'   => $rawName,
                    'id'     => $rawId,
                    'date'   => is_string($rawDate) ? $rawDate : null,
                    'reason' => ! $user ? 'No matching staff member' : 'Unreadable date',
                ];
                continue;
            }

            $clockIn  = $inCol !== null ? $this->parseExcelTime($row[$inCol] ?? null) : null;
            $clockOut = $outCol !== null ? $this->parseExcelTime($row[$outCol] ?? null) : null;
            $statusRaw = $statusCol !== null ? strtolower(trim((string) ($row[$statusCol] ?? ''))) : '';
            $status = match (true) {
                str_contains($statusRaw, 'absent') => 'absent',
                str_contains($statusRaw, 'late')   => 'late',
                str_contains($statusRaw, 'half')   => 'half_day',
                str_contains($statusRaw, 'leave')  => 'leave',
                default => 'present',
            };

            AttendanceRecord::updateOrCreate(
                ['user_id' => $user->id, 'date' => $date],
                ['clock_in' => $clockIn, 'clock_out' => $clockOut, 'status' => $status, 'source' => 'biometric_import'],
            );
            $matched++;
        }

        $import = AttendanceImport::create([
            'filename'       => $file->getClientOriginalName(),
            'uploaded_by'    => $request->user()->id,
            'row_count'      => count($dataRows),
            'matched_count'  => $matched,
            'unmatched_rows' => $unmatched,
        ]);

        // Backfill which rows a record came from, for traceability.
        AttendanceRecord::where('source', 'biometric_import')
            ->whereNull('attendance_import_id')
            ->update(['attendance_import_id' => $import->id]);

        return response()->json(['data' => [
            'id'             => $import->id,
            'filename'       => $import->filename,
            'row_count'      => $import->row_count,
            'matched_count'  => $import->matched_count,
            'unmatched_rows' => $import->unmatched_rows,
        ]]);
    }

    public function imports(Request $request)
    {
        $this->authorize($request);

        $imports = AttendanceImport::with('uploadedBy')->latest()->limit(20)->get();

        $data = $imports->map(fn ($i) => [
            'id'                => $i->id,
            'filename'          => $i->filename,
            'uploaded_by_name'  => $i->uploadedBy?->name,
            'row_count'         => $i->row_count,
            'matched_count'     => $i->matched_count,
            'unmatched_rows'    => $i->unmatched_rows,
            'created_at'        => $i->created_at?->toIso8601String(),
        ]);

        return response()->json(['data' => $data]);
    }

    private function findColumn(array $header, array $candidates): ?int
    {
        foreach ($candidates as $candidate) {
            $idx = array_search($candidate, $header, true);
            if ($idx !== false) return $idx;
        }
        // fall back to partial contains-match
        foreach ($header as $idx => $h) {
            foreach ($candidates as $candidate) {
                if ($h !== '' && str_contains($h, $candidate)) return $idx;
            }
        }
        return null;
    }

    private function parseExcelDate($raw): ?string
    {
        if ($raw === null || $raw === '') return null;
        if (is_numeric($raw)) {
            try { return ExcelDate::excelToDateTimeObject($raw)->format('Y-m-d'); } catch (\Throwable) { return null; }
        }
        $ts = strtotime((string) $raw);
        return $ts ? date('Y-m-d', $ts) : null;
    }

    private function parseExcelTime($raw): ?string
    {
        if ($raw === null || $raw === '') return null;
        if (is_numeric($raw)) {
            try { return ExcelDate::excelToDateTimeObject($raw)->format('H:i'); } catch (\Throwable) { return null; }
        }
        $ts = strtotime((string) $raw);
        return $ts ? date('H:i', $ts) : null;
    }

    private function recordJson(AttendanceRecord $r): array
    {
        return [
            'id'             => $r->id,
            'user_id'        => $r->user_id,
            'user_name'      => $r->user?->name,
            'date'           => $r->date?->toDateString(),
            'clock_in'       => $r->clock_in,
            'clock_out'      => $r->clock_out,
            'status'         => $r->status,
            'overtime_hours' => $r->overtime_hours,
            'source'         => $r->source,
        ];
    }
}
