<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Allowance;
use App\Models\ApprovalLog;
use App\Models\Contract;
use App\Models\SalaryAdjustment;
use App\Models\StaffPaymentProfile;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Import staff pay (2026-10-24): "I have their data and their salary — why
 * would I add them manually? What if there are 1000 of them?" One
 * spreadsheet row per person: matched by email, staff/biometric ID or name,
 * then salary, allowances, company-paid health insurance, NSSF/NIDA/TIN and
 * bank details.
 *
 * POST with the file previews (nothing saved); the same file with commit=1
 * saves exactly what the preview showed. A new salary creates the person's
 * contract; a salary that differs from an existing contract goes to the
 * Director as a pending Salary Adjustment — the import never changes
 * existing pay by itself.
 */
class StaffPayImportController extends Controller
{
    private const COLUMNS = [
        'name'        => ['name', 'full name', 'employee name', 'staff name', 'employee'],
        'email'       => ['email', 'e-mail', 'email address', 'work email'],
        'staff_id'    => ['staff id', 'employee id', 'emp no', 'employee no', 'staff no', 'biometric id', 'badge no', 'id'],
        'salary'      => ['basic salary', 'basic pay', 'base salary', 'salary', 'basic', 'gross salary'],
        'allowances'  => ['allowances', 'allowance', 'total allowances', 'monthly allowance'],
        'health'      => ['health insurance', 'medical insurance', 'insurance premium', 'health', 'insurance'],
        'nssf'        => ['nssf number', 'nssf no', 'nssf'],
        'nida'        => ['nida number', 'nida no', 'nida', 'national id'],
        'tin'         => ['tin number', 'tin no', 'tin'],
        'bank'        => ['bank', 'bank name', 'payment provider', 'provider'],
        'account'     => ['account number', 'account no', 'bank account', 'acc no'],
        'account_name' => ['account name', 'name on account'],
        'start_date'  => ['start date', 'hire date', 'date joined', 'employment date'],
    ];

    public function __invoke(Request $request)
    {
        abort_if(! $request->user()->hasAccountantAuthority(), 403, 'You are not authorised to manage payroll.');
        $request->validate(['file' => ['required', 'file', 'max:20480', 'mimes:xlsx,xls,csv,txt'], 'commit' => ['sometimes', 'boolean']]);

        $rows = IOFactory::load($request->file('file')->getRealPath())->getActiveSheet()->toArray(null, true, true, false);
        // The header is the first row that names a person and a salary (sheets often start with a title).
        $headerAt = null;
        foreach (array_slice($rows, 0, 10) as $i => $r) {
            $h = array_map(fn ($c) => strtolower(trim((string) $c)), $r);
            if ($this->find($h, 'salary') !== null && ($this->find($h, 'name') !== null || $this->find($h, 'email') !== null)) {
                $headerAt = $i;
                break;
            }
        }
        abort_if($headerAt === null, 422, 'Could not find a header row with a name (or email) column and a salary column. '
            . 'First row found: ' . implode(', ', array_filter(array_map('strval', $rows[0] ?? []))));
        $header = array_map(fn ($c) => strtolower(trim((string) $c)), $rows[$headerAt]);
        $cols = collect(self::COLUMNS)->map(fn ($_, $k) => $this->find($header, $k))->all();

        $users = User::where('is_active', true)->with(['activeContract.allowances', 'paymentProfile'])->get();
        $byEmail = $users->keyBy(fn ($u) => strtolower(trim((string) $u->email)));
        $byStaffId = $users->filter(fn ($u) => $u->biometric_id)->keyBy(fn ($u) => strtolower(trim($u->biometric_id)));
        $byName = $users->keyBy(fn ($u) => $this->normName($u->name));

        $preview = [];
        $seen = [];
        foreach (array_slice($rows, $headerAt + 1) as $i => $r) {
            $v = fn (string $k) => $cols[$k] === null ? null : trim((string) ($r[$cols[$k]] ?? ''));
            if (collect($cols)->filter(fn ($c) => $c !== null)->every(fn ($c) => trim((string) ($r[$c] ?? '')) === '')) {
                continue; // blank line
            }
            $line = $headerAt + $i + 2;
            $name = $v('name');
            $user = ($v('email') ? $byEmail->get(strtolower($v('email'))) : null)
                ?? ($v('staff_id') ? $byStaffId->get(strtolower($v('staff_id'))) : null)
                ?? ($name ? ($byName->get($this->normName($name)) ?? $this->looseMatch($users, $name)) : null);

            $row = ['line' => $line, 'name_in_file' => $name ?: $v('email'), 'user' => $user ? ['id' => $user->id, 'name' => $user->name] : null,
                'salary' => $this->money($v('salary')), 'allowances' => $this->money($v('allowances')),
                'health_insurance' => $this->money($v('health')), 'actions' => [], 'problems' => []];
            if (! $user) {
                $row['problems'][] = 'No active staff member matches this row — check the name or add an email column.';
            } elseif (isset($seen[$user->id])) {
                $row['problems'][] = "Same person as line {$seen[$user->id]} — skipped.";
                $user = null;
            }
            if ($user) {
                $seen[$user->id] = $line;
                $row['actions'] = $this->apply($user, $row, $v, $request, false);
            }
            $preview[] = $row;
        }

        $ready = collect($preview)->filter(fn ($r) => $r['user'] && $r['actions']);
        if ($request->boolean('commit')) {
            DB::transaction(function () use ($ready, $users, $cols, $rows, $headerAt, $request) {
                foreach ($ready as $row) {
                    $r = $rows[$row['line'] - 1];
                    $v = fn (string $k) => $cols[$k] === null ? null : trim((string) ($r[$cols[$k]] ?? ''));
                    $this->apply($users->firstWhere('id', $row['user']['id']), $row, $v, $request, true);
                }
            });
            activity()->causedBy($request->user())
                ->withProperties(['file' => $request->file('file')->getClientOriginalName(), 'people' => $ready->count()])
                ->log('staff pay imported');
        }

        return response()->json(['data' => [
            'committed' => $request->boolean('commit'),
            'columns_found' => collect($cols)->filter(fn ($c) => $c !== null)->map(fn ($c) => $header[$c])->all(),
            'rows' => $preview,
            'ready' => $ready->count(),
            'unmatched' => collect($preview)->filter(fn ($r) => ! $r['user'])->count(),
        ]]);
    }

    /** What the row changes for this person; saves it when $save. */
    private function apply(User $user, array $row, \Closure $v, Request $request, bool $save): array
    {
        $actions = [];
        $contract = $user->activeContract;
        $salary = $row['salary'];
        $fmt = fn ($n) => 'TSh ' . number_format((int) $n);

        if ($salary !== null) {
            if (! $contract || $contract->base_salary === null || $contract->salary_placeholder) {
                $actions[] = "Salary set: {$fmt($salary)}" . ($contract ? ($contract->salary_placeholder ? ' (replaces the placeholder)' : '') : ' (new contract)');
                if ($save) {
                    if ($contract) {
                        $contract->update(['base_salary' => $salary, 'salary_placeholder' => false]);
                    } else {
                        $start = $this->date($v('start_date')) ?? $user->hire_date ?? now()->toDateString();
                        $contract = Contract::create(['user_id' => $user->id, 'contract_type' => 'permanent', 'start_date' => $start,
                            'base_salary' => $salary, 'status' => 'active', 'created_by' => $request->user()->id]);
                        $user->setRelation('activeContract', $contract->load('allowances'));
                    }
                }
            } elseif ((int) $contract->base_salary !== $salary) {
                $actions[] = "Salary change {$fmt($contract->base_salary)} → {$fmt($salary)} sent to the Director for approval";
                if ($save) {
                    $adj = SalaryAdjustment::create(['user_id' => $user->id, 'contract_id' => $contract->id,
                        'previous_salary' => $contract->base_salary, 'new_salary' => $salary, 'reason' => 'Staff pay import',
                        'effective_date' => now()->startOfMonth()->toDateString(), 'status' => 'pending', 'created_by' => $request->user()->id]);
                    ApprovalLog::record($adj, 'initiated', $request->user());
                }
            }
        }
        $contract = $user->activeContract;

        if ($row['allowances'] !== null && ($contract || $salary !== null)) {
            $current = $contract ? (int) $contract->allowances->where('recurring', true)->sum('amount') : 0;
            if ($current !== $row['allowances']) {
                $actions[] = "Monthly allowances: {$fmt($row['allowances'])}";
                if ($save && $contract) {
                    // One "Imported" recurring allowance carries the file's total.
                    $contract->allowances()->where('type', 'Imported')->delete();
                    $others = (int) $contract->allowances()->where('recurring', true)->sum('amount');
                    if ($row['allowances'] - $others > 0) {
                        Allowance::create(['contract_id' => $contract->id, 'type' => 'Imported', 'amount' => $row['allowances'] - $others,
                            'recurring' => true, 'effective_date' => now()->toDateString()]);
                    }
                }
            }
        }

        if ($row['health_insurance'] !== null && ($contract || $salary !== null)
            && (int) ($contract?->health_insurance_amount ?? -1) !== $row['health_insurance']) {
            $actions[] = "Health insurance (company-paid): {$fmt($row['health_insurance'])}/month";
            if ($save && $contract) {
                $contract->update(['health_insurance_amount' => $row['health_insurance']]);
            }
        }

        $ids = array_filter(['nssf_number' => $v('nssf'), 'nida_number' => $v('nida'), 'tin_number' => $v('tin')]);
        $changed = array_filter($ids, fn ($val, $k) => (string) $user->{$k} !== $val, ARRAY_FILTER_USE_BOTH);
        if ($changed) {
            $actions[] = 'IDs: ' . implode(', ', array_map(fn ($k) => strtoupper(explode('_', $k)[0]), array_keys($changed)));
            if ($save) {
                $user->update($changed);
            }
        }

        if ($v('account')) {
            $pp = $user->paymentProfile;
            $bank = array_filter(['provider' => $v('bank'), 'account_number' => $v('account'), 'account_name' => $v('account_name') ?: $user->name]);
            if (! $pp || $pp->account_number !== $bank['account_number'] || ($bank['provider'] ?? $pp->provider) !== $pp->provider) {
                $actions[] = 'Bank: ' . trim(($bank['provider'] ?? '') . ' ' . $bank['account_number']);
                if ($save) {
                    StaffPaymentProfile::updateOrCreate(['user_id' => $user->id], $bank);
                }
            }
        }

        return $actions;
    }

    private function find(array $header, string $key): ?int
    {
        foreach (self::COLUMNS[$key] as $c) {
            $i = array_search($c, $header, true);
            if ($i !== false) {
                return $i;
            }
        }
        foreach ($header as $i => $h) {
            foreach (self::COLUMNS[$key] as $c) {
                if ($h !== '' && strlen($c) > 3 && str_contains($h, $c)) {
                    return $i;
                }
            }
        }

        return null;
    }

    private function normName(?string $n): string
    {
        return preg_replace('/\s+/', ' ', strtolower(trim((string) $n)));
    }

    /** "Yusuph Tito" ↔ "Yusuph Kulwa Tito": every word in the file's name appears in exactly one staff name. */
    private function looseMatch($users, string $name): ?User
    {
        $words = array_filter(explode(' ', $this->normName($name)), fn ($w) => strlen($w) > 1);
        if (count($words) < 2) {
            return null;
        }
        $hits = $users->filter(fn ($u) => collect($words)->every(fn ($w) => in_array($w, explode(' ', $this->normName($u->name)), true)));

        return $hits->count() === 1 ? $hits->first() : null;
    }

    private function money(?string $s): ?int
    {
        if ($s === null || $s === '') {
            return null;
        }
        $n = preg_replace('/[^0-9.]/', '', $s);

        return $n === '' ? null : (int) round((float) $n);
    }

    private function date(?string $s): ?string
    {
        if (! $s) {
            return null;
        }
        try {
            return \Illuminate\Support\Carbon::parse($s)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
