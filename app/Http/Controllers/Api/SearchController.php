<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\DeviceRegistration;
use App\Models\Expense;
use App\Models\Hospital;
use App\Models\InventoryItem;
use App\Models\Invoice;
use App\Models\Letter;
use App\Models\Location;
use App\Models\Machine;
use App\Models\Meeting;
use App\Models\NestTender;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\Quotation;
use App\Models\SalesLead;
use App\Models\SalesOrder;
use App\Models\ServiceTicket;
use App\Models\Shipment;
use App\Models\Supplier;
use App\Models\Tender;
use App\Models\User;
use App\Models\VendorBill;
use App\Services\EffectivePermissionResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Global search (top bar). Each record type is searched only when the
 * caller can open its screen, and with the same visibility the list screen
 * uses (meetings they attend, letters they wrote or sign). Every word typed
 * must match one of the record's fields, so "kanam gloves" finds a Kanam PO
 * for gloves. Exact and starts-with matches come first.
 */
class SearchController extends Controller
{
    // Results per record type: enough for a dropdown, not a results page.
    private const LIMIT = 6;

    private array $terms = [];
    private string $q = '';

    public function index(Request $request, EffectivePermissionResolver $resolver)
    {
        $this->q = trim((string) $request->query('q', ''));
        if (mb_strlen($this->q) < 2) {
            return response()->json(['data' => []]);
        }
        $this->terms = array_values(array_filter(preg_split('/\s+/', $this->q), fn ($t) => $t !== ''));

        $user = $request->user();
        $canAll = $resolver->can($user, 'authority.admin_tier');
        $can = fn (string ...$screens) => $canAll || collect($screens)->contains(fn ($s) => $resolver->can($user, "screens.{$s}"));

        $results = [];
        $add = function (string $type, Builder $query, array $columns, callable $row, array $relations = []) use (&$results) {
            $this->match($query, $columns, $relations);
            foreach ($query->limit(self::LIMIT)->get() as $model) {
                $results[] = ['type' => $type, 'id' => $model->getKey()] + $row($model);
            }
        };

        if ($can('machines')) {
            $add('machine', Machine::query()->with('hospital:id,name'), ['model', 'serial_no', 'type'],
                fn (Machine $m) => ['title' => $m->model ?: $m->type, 'subtitle' => trim(($m->serial_no ?? '') . ' · ' . ($m->hospital?->name ?? '—'), ' ·')],
                ['hospital' => ['name']]);
        }

        if ($can('hospitals', 'customers')) {
            $add('hospital', Hospital::query(), ['name', 'short_code', 'region', 'district'],
                fn (Hospital $h) => ['title' => $h->name, 'subtitle' => collect([$h->district ?? null, $h->region])->filter()->implode(', ') ?: $h->type]);
        }

        if ($can('service')) {
            $add('service_ticket', ServiceTicket::query()->with('machine:id,model,hospital_id', 'machine.hospital:id,name'), ['ticket_number', 'description'],
                fn (ServiceTicket $t) => ['title' => $t->ticket_number, 'subtitle' => trim(($t->machine?->model ?? '') . ' · ' . ($t->machine?->hospital?->name ?? $t->description ?? ''), ' ·')],
                ['machine.hospital' => ['name']]);
        }

        if ($can('inventory', 'purchases', 'sales')) {
            $add('inventory_item', InventoryItem::query(), ['name', 'sku'],
                fn (InventoryItem $i) => ['title' => $i->name, 'subtitle' => $i->sku]);
        }

        if ($can('inventory', 'purchases', 'shipments')) {
            $add('supplier', Supplier::query(), ['name', 'country'],
                fn (Supplier $s) => ['title' => $s->name, 'subtitle' => trim('Supplier · ' . ($s->country ?? ''), ' ·')]);
        }

        if ($can('inventory', 'purchases')) {
            $add('purchase_order', PurchaseOrder::query()->with('supplier:id,name')->orderByDesc('id'), ['po_number', 'status'],
                fn (PurchaseOrder $po) => ['title' => $po->po_number, 'subtitle' => trim(($po->supplier?->name ?? '') . ' · ' . str_replace('_', ' ', $po->status), ' ·')],
                ['supplier' => ['name'], 'items.inventoryItem' => ['name', 'sku']]);
        }

        if ($can('inventory')) {
            $add('requisition', PurchaseRequisition::query()->orderByDesc('id'), ['pr_number', 'department', 'justification'],
                fn (PurchaseRequisition $r) => ['title' => $r->pr_number, 'subtitle' => trim(($r->department ?? '') . ' · ' . $r->status, ' ·')]);
            $add('location', Location::query(), ['name', 'code'],
                fn (Location $l) => ['title' => $l->name, 'subtitle' => 'Location' . ($l->code ? " · {$l->code}" : '')]);
        }

        if ($can('staff', 'hr_directory', 'hr_staff')) {
            $add('staff', User::query()->where('is_active', true)->whereNull('vendor_id')->with('position:id,title'), ['name', 'email', 'phone'],
                fn (User $u) => ['title' => $u->name, 'subtitle' => $u->position?->title ?? strtoupper(str_replace('_', ' ', (string) $u->role))]);
        }

        if ($can('sales')) {
            $add('sales_lead', SalesLead::query(), ['hospital_name_raw', 'contact_name_raw', 'machine_type'],
                fn (SalesLead $l) => ['title' => $l->contact_name_raw ?: $l->hospital_name_raw, 'subtitle' => trim(($l->hospital_name_raw ?? '') . ' · ' . ($l->machine_type ?? ''), ' ·')]);
        }

        if ($can('sales', 'quotations')) {
            $add('quotation', Quotation::query()->orderByDesc('id'), ['quotation_number', 'client_name'],
                fn (Quotation $qt) => ['title' => $qt->quotation_number, 'subtitle' => $qt->client_name]);
        }

        if ($can('sales')) {
            $add('sales_order', SalesOrder::query()->orderByDesc('id'), ['order_number', 'client_name'],
                fn (SalesOrder $so) => ['title' => $so->order_number, 'subtitle' => $so->client_name]);
            $add('invoice', Invoice::query()->orderByDesc('id'), ['invoice_number', 'client_name'],
                fn (Invoice $inv) => ['title' => $inv->invoice_number, 'subtitle' => $inv->client_name]);
        }

        if ($can('customers', 'sales')) {
            $add('contact', Contact::query(), ['first_name', 'last_name', 'email', 'phone'],
                fn (Contact $c) => ['title' => trim("{$c->first_name} {$c->last_name}"), 'subtitle' => $c->job_title ?? $c->email]);
        }

        if ($can('finance')) {
            $add('vendor_bill', VendorBill::query()->with('supplier:id,name')->orderByDesc('id'), ['bill_number'],
                fn (VendorBill $b) => ['title' => $b->bill_number, 'subtitle' => $b->supplier?->name],
                ['supplier' => ['name']]);
            $add('expense', Expense::query()->orderByDesc('id'), ['name', 'reference'],
                fn (Expense $e) => ['title' => $e->name, 'subtitle' => 'Expense · ' . str_replace('_', ' ', $e->status)]);
        }

        if ($can('shipments')) {
            $add('shipment', Shipment::query()->with('supplier:id,name')->orderByDesc('id'), ['reference', 'description', 'control_number', 'tmda_application_ref'],
                fn (Shipment $s) => ['title' => $s->reference, 'subtitle' => trim(($s->supplier?->name ?? '') . ' · ' . ($s->description ?? ''), ' ·')],
                ['supplier' => ['name']]);
        }

        if ($can('tenders')) {
            $add('tender', Tender::query()->orderByDesc('id'), ['tender_number', 'contract_number', 'title'],
                fn (Tender $t) => ['title' => $t->tender_number ?: $t->title, 'subtitle' => $t->tender_number ? $t->title : str_replace('_', ' ', (string) $t->status)]);
            $add('nest_tender', NestTender::query()->orderByDesc('closing_at'), ['reference_number', 'description', 'procuring_entity_name'],
                fn (NestTender $n) => ['title' => $n->reference_number ?: 'NeST tender', 'subtitle' => trim(($n->procuring_entity_name ?? '') . ' · ' . ($n->description ?? ''), ' ·')]);
            $add('device_registration', DeviceRegistration::query(), ['brand_name', 'common_name', 'model', 'manufacturer', 'registration_number'],
                fn (DeviceRegistration $d) => ['title' => $d->brand_name ?: $d->common_name, 'subtitle' => trim(($d->registration_number ?? '') . ' · ' . ($d->manufacturer ?? ''), ' ·')]);
        }

        // Everyone: the meetings they can see, the letters they wrote or sign.
        $add('meeting', Meeting::query()->visibleTo($user, $resolver->can($user, 'meetings.manage'))->orderByDesc('starts_at'), ['title', 'location'],
            fn (Meeting $m) => ['title' => $m->title, 'subtitle' => trim(($m->starts_at?->format('d M Y') ?? '') . ($m->location ? " · {$m->location}" : ''))]);
        $add('letter', Letter::query()
            ->when(! $user->isAdminTier(), fn ($q) => $q->where(fn ($q) => $q->where('created_by', $user->id)->orWhere('signatory_id', $user->id)))
            ->orderByDesc('id'),
            ['reference_number', 'recipient', 'subject'],
            fn (Letter $l) => ['title' => $l->subject ?: $l->reference_number, 'subtitle' => trim($l->reference_number . ' · ' . ($l->recipient ?? ''), ' ·')]);

        // Best matches first; the same score keeps the record-type order above.
        $scored = array_map(fn ($r, $i) => [$this->score((string) ($r['title'] ?? '')), $i, $r], $results, array_keys($results));
        usort($scored, fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return response()->json(['data' => array_map(fn ($s) => $s[2], $scored)]);
    }

    /** Every word must match one of the columns (or a related record's columns). */
    private function match(Builder $query, array $columns, array $relations = []): void
    {
        foreach ($this->terms as $term) {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term) . '%';
            $query->where(function (Builder $w) use ($columns, $relations, $like) {
                foreach ($columns as $column) {
                    $w->orWhere($column, 'ilike', $like);
                }
                foreach ($relations as $relation => $relColumns) {
                    $w->orWhereHas($relation, fn (Builder $r) => $r->where(function (Builder $rw) use ($relColumns, $like) {
                        foreach ($relColumns as $c) {
                            $rw->orWhere($c, 'ilike', $like);
                        }
                    }));
                }
            });
        }
    }

    private function score(string $title): int
    {
        $t = mb_strtolower($title);
        $q = mb_strtolower($this->q);

        return match (true) {
            $t === $q => 0,
            str_starts_with($t, $q) => 1,
            (bool) preg_match('/(^|[\s\-\/#(])' . preg_quote($q, '/') . '/u', $t) => 2,
            str_contains($t, $q) => 3,
            default => 4,
        };
    }
}
