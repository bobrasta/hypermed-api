<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

// Read-only view onto spatie/laravel-activitylog's activity_log table,
// made human-readable: who did it (name + role), which record (by name, not
// "#12"), and each changed field as label + old → new with foreign keys
// resolved to names ("Position: Sales Manager → Field Technician").
// Admin-tier only (company-wide change history is sensitive).
class ActivityLogController extends Controller
{
    // field => [table, display column] for id columns shown in diffs.
    private const REFS = [
        'position_id' => ['positions', 'title'],
        'manager_id' => ['users', 'name'],
        'user_id' => ['users', 'name'],
        'assigned_to' => ['users', 'name'],
        'created_by' => ['users', 'name'],
        'approved_by' => ['users', 'name'],
        'reviewed_by' => ['users', 'name'],
        'paid_by' => ['users', 'name'],
        'ordered_by' => ['users', 'name'],
        'hospital_id' => ['hospitals', 'name'],
        'supplier_id' => ['suppliers', 'name'],
        'location_id' => ['locations', 'name'],
        'inventory_item_id' => ['inventory_items', 'name'],
        'machine_id' => ['machines', 'serial_no'],
        'vendor_id' => ['vendors', 'name'],
        'department_id' => ['departments', 'name'],
        'leave_type_id' => ['leave_types', 'name'],
        'owner_id' => ['users', 'name'],
        'procuring_entity_id' => ['procuring_entities', 'name'],
        'tender_id' => ['tenders', 'tender_number'],
        'purchase_order_id' => ['purchase_orders', 'po_number'],
        'sales_order_id' => ['sales_orders', 'order_number'],
        'quotation_id' => ['quotations', 'quotation_number'],
        'invoice_id' => ['invoices', 'invoice_number'],
        'shipment_id' => ['shipments', 'reference'],
    ];

    // Subject model => [table, display column].
    private const SUBJECTS = [
        'User' => ['users', 'name'],
        'Position' => ['positions', 'title'],
        'Hospital' => ['hospitals', 'name'],
        'Supplier' => ['suppliers', 'name'],
        'InventoryItem' => ['inventory_items', 'name'],
        'Invoice' => ['invoices', 'invoice_number'],
        'PurchaseOrder' => ['purchase_orders', 'po_number'],
        'SalesOrder' => ['sales_orders', 'order_number'],
        'Quotation' => ['quotations', 'quotation_number'],
        'Expense' => ['expenses', 'name'],
        'Machine' => ['machines', 'serial_no'],
        'Location' => ['locations', 'name'],
        'Department' => ['departments', 'name'],
        'Vendor' => ['vendors', 'name'],
        'Shipment' => ['shipments', 'reference'],
        'Tender' => ['tenders', 'tender_number'],
    ];

    private const LABELS = [
        'staff_group' => 'Group',
        'max_discount_percent' => 'Max discount %',
        'commission_percent' => 'Commission %',
        'is_active' => 'Active',
        'avail_status' => 'Availability',
    ];

    public function index(Request $request)
    {
        abort_unless($request->user()->isAdminTier(), 403,
            'Access Denied: you do not have permission to view the activity log.');

        $query = Activity::with('causer')->latest();

        if ($request->filled('subject_type')) {
            // Accept the short model name (e.g. "Expense") rather than
            // requiring the fully-qualified class string in the URL.
            $query->where('subject_type', 'App\\Models\\' . $request->input('subject_type'));
        }
        if ($request->filled('subject_id')) {
            $query->where('subject_id', $request->input('subject_id'));
        }
        if ($request->filled('causer_id')) {
            $query->where('causer_id', $request->input('causer_id'));
        }

        $activities = $query->paginate(50);
        $rows = $activities->getCollection();

        // Batch-resolve every id this page mentions (subjects + diff refs).
        $want = [];
        foreach ($rows as $a) {
            $type = class_basename((string) $a->subject_type);
            if (isset(self::SUBJECTS[$type]) && $a->subject_id) {
                $want[implode('.', self::SUBJECTS[$type])][] = $a->subject_id;
            }
            foreach (['attributes', 'old'] as $side) {
                foreach ((array) ($a->changes[$side] ?? []) as $field => $value) {
                    if (isset(self::REFS[$field]) && is_numeric($value)) {
                        $want[implode('.', self::REFS[$field])][] = (int) $value;
                    }
                }
            }
        }
        $names = [];
        foreach ($want as $key => $ids) {
            [$table, $col] = explode('.', $key);
            try {
                $names[$key] = DB::table($table)->whereIn('id', array_unique($ids))->pluck($col, 'id')->all();
            } catch (\Throwable) {
                $names[$key] = [];
            }
        }

        $activities->setCollection($rows->map(function (Activity $a) use ($names) {
            $type = class_basename((string) $a->subject_type);
            $subjectLabel = null;
            if (isset(self::SUBJECTS[$type]) && $a->subject_id) {
                $subjectLabel = $names[implode('.', self::SUBJECTS[$type])][$a->subject_id] ?? null;
            }
            $subjectText = trim(Str::lower(Str::headline($type)) . ' ' . ($subjectLabel ?? ($a->subject_id ? '#' . $a->subject_id : '')));
            $who = $a->causer?->name ?? 'System';
            $verb = in_array($a->description, ['created', 'updated', 'deleted'], true) ? $a->description : null;

            return [
                'id' => $a->id,
                'description' => $a->description,
                'summary' => $verb ? "{$who} {$verb} {$subjectText}" : "{$who}: {$a->description}" . ($subjectText ? " ({$subjectText})" : ''),
                'subject_type' => $type,
                'subject_id' => $a->subject_id,
                'subject_label' => $subjectLabel,
                'causer_name' => $a->causer?->name,
                'causer_role' => $a->causer?->role ? Str::upper(str_replace('_', ' ', $a->causer->role)) : null,
                'field_changes' => $this->fieldChanges($a, $names),
                // Raw diff kept for anything that still wants it.
                'changes' => $a->changes->isEmpty() ? null : $a->changes,
                'created_at' => $a->created_at?->toIso8601String(),
            ];
        }));

        return response()->json(['data' => $activities]);
    }

    /** @return list<array{field: string, old: ?string, new: ?string}> */
    private function fieldChanges(Activity $a, array $names): array
    {
        $new = (array) ($a->changes['attributes'] ?? []);
        $old = (array) ($a->changes['old'] ?? []);
        $out = [];
        foreach (array_unique([...array_keys($new), ...array_keys($old)]) as $field) {
            if (in_array($field, ['updated_at', 'created_at', 'password', 'remember_token'], true)) {
                continue;
            }
            $isCreate = $old === [] && $a->description === 'created';
            // On creation only list fields that were actually filled in.
            if ($isCreate && ($new[$field] ?? null) === null || $isCreate && ($new[$field] ?? '') === '') {
                continue;
            }
            $out[] = [
                'field' => self::LABELS[$field] ?? Str::ucfirst(str_replace('_', ' ', preg_replace('/_id$/', '', $field))),
                'old' => array_key_exists($field, $old) ? $this->display($field, $old[$field], $names) : null,
                'new' => array_key_exists($field, $new) ? $this->display($field, $new[$field], $names) : null,
            ];
        }

        return $out;
    }

    private function display(string $field, mixed $value, array $names): string
    {
        if ($value === null || $value === '') {
            return '—';
        }
        if (isset(self::REFS[$field]) && is_numeric($value)) {
            return $names[implode('.', self::REFS[$field])][(int) $value] ?? "#{$value}";
        }
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }
        if ($field === 'role') {
            return Str::upper(str_replace('_', ' ', (string) $value));
        }
        if (is_array($value)) {
            return json_encode($value);
        }
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/', $value)) {
            try {
                return Carbon::parse($value)->timezone('Africa/Dar_es_Salaam')->format('d M Y H:i');
            } catch (\Throwable) {
            }
        }

        return (string) $value;
    }
}
