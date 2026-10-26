<?php

namespace App\Services;

use App\Models\ApprovalLog;
use App\Models\CreditorAllocation;
use App\Models\CreditorOpeningBalance;
use App\Models\DeliveryJob;
use App\Models\Expense;
use App\Models\InventoryItem;
use App\Models\Invoice;
use App\Models\Location;
use App\Models\Machine;
use App\Models\Payment;
use App\Models\PerDiemRequest;
use App\Models\PurchaseOrder;
use App\Models\PurchaseReturn;
use App\Models\PurchaseRequisition;
use App\Models\SalesOrder;
use App\Models\SerialNumber;
use App\Models\Shipment;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\Tender;
use App\Models\Transaction;
use App\Models\Vendor;
use App\Models\VendorBill;
use App\Models\VendorFee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The web of records behind any record: requisition → LPO → approvals →
 * payments (cash, which bank, or on credit) → supplier invoice → shipment
 * (tender, TMDA, clearing fee) → received into a store → sold (sales order,
 * machine at a hospital, customer invoice, payments) — and the ledger
 * entries each one posted.
 *
 * Walks outward from the subject. Hubs (a supplier, a stock item, a store,
 * a hospital, a tender) are shown but only expanded when they're the
 * subject, so one LPO doesn't pull in every other order of its supplier.
 */
class ConnectionsService
{
    private const MAX_NODES = 120;
    private const HUBS = ['supplier', 'vendor', 'inventory_item', 'location', 'hospital', 'tender'];

    public const TYPES = [
        'purchase_requisition' => PurchaseRequisition::class,
        'purchase_order' => PurchaseOrder::class,
        'purchase_return' => PurchaseReturn::class,
        'supplier_payment' => SupplierPayment::class,
        'vendor_bill' => VendorBill::class,
        'supplier' => Supplier::class,
        'vendor' => Vendor::class,
        'vendor_fee' => VendorFee::class,
        'shipment' => Shipment::class,
        'tender' => Tender::class,
        'inventory_item' => InventoryItem::class,
        'serial_number' => SerialNumber::class,
        'stock_movement' => StockMovement::class,
        'location' => Location::class,
        'machine' => Machine::class,
        'sales_order' => SalesOrder::class,
        'invoice' => Invoice::class,
        'payment' => Payment::class,
        'hospital' => \App\Models\Hospital::class,
        'delivery_job' => DeliveryJob::class,
        'expense' => Expense::class,
        'per_diem_request' => PerDiemRequest::class,
        'creditor_opening' => CreditorOpeningBalance::class,
    ];

    /** Plain-language labels for approval-log actions. */
    private const ACTIONS = [
        'submitted' => 'Submitted for approval', 'sales_approved' => 'Sales Manager approved',
        'director_reviewed' => 'Director reviewed', 'payment_initiated' => 'Payment initiated',
        'credit_requested' => 'Asked to approve on credit', 'approved' => 'Approved', 'paid' => 'Paid',
        'rejected' => 'Rejected', 'cancelled' => 'Cancelled', 'created' => 'Recorded', 'initiated' => 'Recorded',
        'updated' => 'Updated', 'submitted_for_payment' => 'Submitted for payment', 'received' => 'Received',
    ];

    private array $nodes = [];
    private array $edges = [];

    public static function typeOf(Model $m): ?string
    {
        return array_search($m::class, self::TYPES, true) ?: null;
    }

    public function build(string $type, int $id): array
    {
        abort_unless(isset(self::TYPES[$type]), 404, 'Unknown record type.');
        $subject = (self::TYPES[$type])::findOrFail($id);
        $this->nodes = [];
        $this->edges = [];

        $queue = [[$subject, 0]];
        $this->addNode($subject, 0);
        while ($queue && count($this->nodes) < self::MAX_NODES) {
            [$model, $depth] = array_shift($queue);
            $t = self::typeOf($model);
            if ($depth > 0 && in_array($t, self::HUBS, true)) {
                continue;
            }
            if ($depth >= 4) {
                continue;
            }
            foreach ($this->neighbours($t, $model) as [$other, $relation]) {
                if (! $other) {
                    continue;
                }
                $key = $this->key($other);
                $this->edges[$this->key($model) . '>' . $key] = ['from' => $this->key($model), 'to' => $key, 'relation' => $relation];
                if (! isset($this->nodes[$key])) {
                    $this->addNode($other, $depth + 1);
                    $queue[] = [$other, $depth + 1];
                }
            }
        }

        return [
            'subject' => $this->nodes[$this->key($subject)],
            'nodes' => array_values($this->nodes),
            'edges' => array_values($this->edges),
            'timeline' => $this->timeline(),
            'ledger' => $this->ledger(),
            'truncated' => count($this->nodes) >= self::MAX_NODES,
        ];
    }

    private function key(Model $m): string
    {
        return self::typeOf($m) . ':' . $m->getKey();
    }

    private function addNode(Model $m, int $depth): void
    {
        $this->nodes[$this->key($m)] = ['key' => $this->key($m), 'type' => self::typeOf($m), 'id' => $m->getKey(), 'depth' => $depth] + $this->describe($m);
    }

    private static function d($v): ?string
    {
        return $v ? Carbon::parse($v)->toDateString() : null;
    }

    /** title, subtitle, status, amount, currency, date */
    private function describe(Model $m): array
    {
        $money = fn ($amount, $cur) => ['amount' => $amount === null ? null : (float) $amount, 'currency' => $cur];

        return match (true) {
            $m instanceof PurchaseRequisition => ['title' => $m->pr_number, 'subtitle' => 'Requisition' . ($m->department ? ' · ' . $m->department : ''), 'status' => $m->status, 'date' => self::d($m->submitted_at ?? $m->created_at)] + $money(null, null),
            $m instanceof PurchaseOrder => ['title' => $m->po_number, 'subtitle' => 'LPO · ' . ($m->supplier?->name ?? '') . ($m->on_credit ? ' · on credit' : ''), 'status' => $m->status . ' · ' . $m->payment_status, 'date' => self::d($m->created_at)] + $money($m->total_amount, $m->currency),
            $m instanceof PurchaseReturn => ['title' => $m->return_number, 'subtitle' => 'Purchase return · ' . ($m->supplier?->name ?? ''), 'status' => $m->posted ? 'on supplier account' : 'stock only', 'date' => self::d($m->return_date)] + $money($m->total_amount, $m->currency),
            $m instanceof SupplierPayment => ['title' => $m->payment_number, 'subtitle' => 'Payment from ' . ($m->paidFrom?->name ?? 'bank') . ($m->reference ? " · {$m->reference}" : ''), 'status' => $m->status, 'date' => self::d($m->payment_date)] + $money($m->amount, $m->currency),
            $m instanceof VendorBill => ['title' => $m->supplier_reference ?: $m->bill_number, 'subtitle' => 'Supplier invoice · ' . ($m->supplier?->name ?? ''), 'status' => $m->status, 'date' => self::d($m->issue_date)] + $money($m->total, $m->currency),
            $m instanceof Supplier => ['title' => $m->name, 'subtitle' => 'Supplier · ' . ($m->type ?? '') . ' · ' . ($m->currency ?? ''), 'status' => $m->is_active ? 'active' : 'inactive', 'date' => null] + $money(null, $m->currency),
            $m instanceof Vendor => ['title' => $m->name, 'subtitle' => 'Vendor · ' . $m->type, 'status' => $m->is_active ? 'active' : 'inactive', 'date' => null] + $money(null, 'TZS'),
            $m instanceof VendorFee => ['title' => 'Fee ' . $m->id, 'subtitle' => ($m->vendor?->name ?? 'Vendor') . ' · ' . $m->description, 'status' => $m->status, 'date' => self::d($m->created_at)] + $money($m->billed_amount, $m->currency ?: 'TZS'),
            $m instanceof Shipment => ['title' => $m->reference, 'subtitle' => 'Shipment · ' . $m->description, 'status' => 'step ' . $m->step, 'date' => self::d($m->created_at)] + $money(null, null),
            $m instanceof Tender => ['title' => $m->tender_number, 'subtitle' => 'Tender · ' . $m->title, 'status' => $m->status, 'date' => self::d($m->created_at)] + $money($m->contract_value ?? $m->estimated_value, $m->currency),
            $m instanceof InventoryItem => ['title' => $m->name, 'subtitle' => 'Stock item' . ($m->sku ? ' · ' . $m->sku : ''), 'status' => null, 'date' => null] + $money(null, null),
            $m instanceof SerialNumber => ['title' => $m->serial_number, 'subtitle' => 'Serial · ' . ($m->inventoryItem?->name ?? ''), 'status' => $m->status, 'date' => self::d($m->purchase_date)] + $money(null, null),
            $m instanceof StockMovement => ['title' => ucfirst($m->type) . ' ' . abs($m->quantity) . ' × ' . ($m->inventoryItem?->name ?? 'item'), 'subtitle' => 'Stock · ' . ($m->location?->name ?? '') . ($m->batch_number ? ' · batch ' . $m->batch_number : ''), 'status' => $m->type, 'date' => self::d($m->created_at)] + $money($m->unit_cost, 'TZS'),
            $m instanceof Location => ['title' => $m->name, 'subtitle' => 'Store / location', 'status' => null, 'date' => null] + $money(null, null),
            $m instanceof Machine => ['title' => $m->serial_no, 'subtitle' => 'Machine · ' . $m->model . ($m->hospital?->name ? ' · ' . $m->hospital->name : ''), 'status' => $m->status, 'date' => self::d($m->arrival_date ?? $m->created_at)] + $money(null, null),
            $m instanceof SalesOrder => ['title' => $m->order_number, 'subtitle' => 'Sales order · ' . $m->client_name, 'status' => $m->status, 'date' => self::d($m->created_at)] + $money($m->total_amount, $m->currency),
            $m instanceof Invoice => ['title' => $m->invoice_number, 'subtitle' => 'Customer invoice · ' . $m->client_name, 'status' => $m->status, 'date' => self::d($m->issue_date)] + $money($m->total, $m->currency ?: 'TZS'),
            $m instanceof Payment => ['title' => $m->payment_number, 'subtitle' => 'Customer payment · ' . str_replace('_', ' ', (string) $m->payment_method), 'status' => 'received', 'date' => self::d($m->paid_at)] + $money($m->amount, 'TZS'),
            $m instanceof DeliveryJob => ['title' => $m->job_number, 'subtitle' => 'Delivery · ' . $m->destination_name, 'status' => $m->status, 'date' => self::d($m->created_at)] + $money(null, null),
            $m instanceof Expense => ['title' => $m->name, 'subtitle' => 'Expense', 'status' => $m->status, 'date' => self::d($m->expense_date)] + $money($m->amount, 'TZS'),
            $m instanceof PerDiemRequest => ['title' => 'Per diem ' . $m->id, 'subtitle' => $m->staff_name_snapshot . ' · ' . $m->destination, 'status' => $m->status, 'date' => self::d($m->start_date)] + $money($m->amount, 'TZS'),
            $m instanceof CreditorOpeningBalance => ['title' => 'Opening balance', 'subtitle' => 'As at ' . self::d($m->as_at), 'status' => null, 'date' => self::d($m->as_at)] + $money($m->amount, $m->currency),
            $m instanceof \App\Models\Hospital => ['title' => $m->name, 'subtitle' => 'Hospital / customer', 'status' => null, 'date' => null] + $money(null, null),
            default => ['title' => class_basename($m) . ' ' . $m->getKey(), 'subtitle' => null, 'status' => null, 'date' => null] + $money(null, null),
        };
    }

    /** @return array<int, array{0: ?Model, 1: string}> */
    private function neighbours(string $type, Model $m): array
    {
        $out = [];
        $add = function ($models, string $relation) use (&$out) {
            foreach ($models instanceof Collection ? $models : collect([$models]) as $x) {
                if ($x instanceof Model && isset(array_flip(self::TYPES)[$x::class])) {
                    $out[] = [$x, $relation];
                }
            }
        };
        $movementsFor = fn (Model $ref) => StockMovement::where('reference_type', $ref->getMorphClass())->where('reference_id', $ref->getKey())->get();

        switch ($type) {
            case 'purchase_requisition':
                $add(PurchaseOrder::where('purchase_requisition_id', $m->id)->get(), 'ordered on');
                break;
            case 'purchase_order':
                $add($m->purchaseRequisition ?? null, 'requested in');
                $add($m->supplier, 'supplier');
                $add($m->location, 'deliver to');
                $add($m->items()->with('inventoryItem')->get()->pluck('inventoryItem')->filter(), 'item');
                $add(SupplierPayment::where('purchase_order_id', $m->id)->get(), 'payment');
                $add(VendorBill::where('purchase_order_id', $m->id)->get(), 'supplier invoice');
                $add(Shipment::where('purchase_order_id', $m->id)->get(), 'shipment');
                $add($movementsFor($m), 'received into store');
                $add(PurchaseReturn::where('purchase_order_id', $m->id)->get(), 'returned');
                break;
            case 'purchase_return':
                $add($m->purchaseOrder, 'returned from');
                $add($m->supplier, 'returned to');
                $add($movementsFor($m), 'taken out of store');
                $add(VendorBill::whereIn('id', CreditorAllocation::where('purchase_return_id', $m->id)->pluck('vendor_bill_id')->filter())->get(), 'credits');
                break;
            case 'supplier_payment':
                $add($m->supplier ?? $m->vendor, 'paid to');
                $add($m->purchaseOrder, 'for LPO');
                $add(VendorBill::whereIn('id', CreditorAllocation::where('supplier_payment_id', $m->id)->pluck('vendor_bill_id')->filter())->get(), 'settles');
                break;
            case 'vendor_bill':
                $add($m->supplier, 'from');
                $add($m->purchaseOrder, 'for LPO');
                $add(SupplierPayment::whereIn('id', CreditorAllocation::where('vendor_bill_id', $m->id)->pluck('supplier_payment_id')->filter())->get(), 'paid by');
                break;
            case 'shipment':
                $add($m->purchaseOrder, 'for LPO');
                $add($m->tender, 'tender');
                $add($m->vendorFee, 'clearing fee');
                $add($m->carrier ?? null, 'carrier');
                $add($m->supplier, 'supplier');
                $add($m->machines, 'machine');
                break;
            case 'vendor_fee':
                $add($m->vendor, 'vendor');
                $add(Shipment::where('vendor_fee_id', $m->id)->get(), 'shipment');
                $add($m->deliveryJob ?? null, 'delivery job');
                break;
            case 'delivery_job':
                $add($m->fee ?? null, 'fee');
                $add($m->invoice ?? null, 'invoice');
                break;
            case 'stock_movement':
                $ref = $m->reference_type && $m->reference_id && class_exists($m->reference_type) ? $m->reference_type::find($m->reference_id) : null;
                $add($ref, 'from');
                $add($m->inventoryItem, 'item');
                $add($m->location, 'store');
                break;
            case 'inventory_item':
                $poIds = \App\Models\PurchaseOrderItem::where('inventory_item_id', $m->id)->pluck('purchase_order_id');
                $add(PurchaseOrder::whereIn('id', $poIds)->latest('id')->limit(15)->get(), 'ordered on');
                $add(StockMovement::where('inventory_item_id', $m->id)->latest('id')->limit(25)->get(), 'stock movement');
                $add(SerialNumber::where('inventory_item_id', $m->id)->latest('id')->limit(15)->get(), 'serial');
                break;
            case 'serial_number':
                $add($m->inventoryItem, 'item');
                $add($m->assigned_to_machine_id ? Machine::find($m->assigned_to_machine_id) : null, 'in machine');
                $add($m->location ?? null, 'store');
                break;
            case 'machine':
                $add($m->hospital, 'at');
                $add($m->salesOrder, 'sold on');
                $add($m->invoices ?? collect(), 'invoiced on');
                $add(Shipment::whereHas('machines', fn ($q) => $q->where('machines.id', $m->id))->get(), 'shipped on');
                break;
            case 'sales_order':
                $add($m->hospital, 'customer');
                $add(Invoice::where('sales_order_id', $m->id)->get(), 'invoice');
                $add(Machine::where('sales_order_id', $m->id)->get(), 'machine');
                $add($movementsFor($m), 'issued from store');
                break;
            case 'invoice':
                $add($m->salesOrder, 'from order');
                $add($m->hospital, 'customer');
                $add(Payment::where('invoice_id', $m->id)->get(), 'payment');
                break;
            case 'payment':
                $add($m->invoice ?? null, 'for invoice');
                break;
            case 'supplier':
                $add(PurchaseOrder::where('supplier_id', $m->id)->latest('id')->limit(15)->get(), 'LPO');
                $add(VendorBill::where('supplier_id', $m->id)->latest('id')->limit(15)->get(), 'invoice');
                $add(SupplierPayment::where('supplier_id', $m->id)->latest('id')->limit(15)->get(), 'payment');
                $add(Shipment::where('supplier_id', $m->id)->latest('id')->limit(10)->get(), 'shipment');
                $add(CreditorOpeningBalance::where('creditor_type', 'supplier')->where('creditor_id', $m->id)->get(), 'opening balance');
                break;
            case 'vendor':
                $add(VendorFee::where('vendor_id', $m->id)->latest('id')->limit(20)->get(), 'fee');
                $add(SupplierPayment::where('vendor_id', $m->id)->latest('id')->limit(10)->get(), 'payment');
                $add(Shipment::where('carrier_vendor_id', $m->id)->latest('id')->limit(10)->get(), 'carried');
                break;
            case 'tender':
                $add(Shipment::where('tender_id', $m->id)->get(), 'shipment');
                break;
            case 'location':
                $add(StockMovement::where('location_id', $m->id)->latest('id')->limit(20)->get(), 'movement');
                break;
            case 'hospital':
                $add(Machine::where('hospital_id', $m->id)->latest('id')->limit(15)->get(), 'machine');
                $add(Invoice::where('hospital_id', $m->id)->latest('id')->limit(15)->get(), 'invoice');
                break;
        }

        return $out;
    }

    /** Everything that happened to the records in the web, oldest first. */
    private function timeline(): array
    {
        $events = collect();
        $byClass = collect($this->nodes)->groupBy(fn ($n) => class_basename(self::TYPES[$n['type']]));
        foreach ($byClass as $class => $nodes) {
            $ids = $nodes->pluck('id');
            foreach (ApprovalLog::with('actor:id,name,role')->where('subject_type', $class)->whereIn('subject_id', $ids)->get() as $log) {
                $node = $nodes->firstWhere('id', $log->subject_id);
                $events->push([
                    'at' => $log->created_at?->toIso8601String(),
                    'node' => $node['key'],
                    'title' => $node['title'],
                    'action' => self::ACTIONS[$log->action] ?? ucfirst(str_replace('_', ' ', $log->action)),
                    'actor' => $log->actor?->name,
                    'role' => $log->actor?->role,
                    'detail' => $log->notes,
                ]);
            }
        }
        foreach ($this->nodes as $n) {
            if ($n['type'] === 'stock_movement') {
                $mv = StockMovement::with(['performedBy:id,name', 'location:id,name'])->find($n['id']);
                $events->push(['at' => $mv?->created_at?->toIso8601String(), 'node' => $n['key'], 'title' => $n['title'],
                    'action' => $mv && $mv->quantity >= 0 ? 'Received into ' . ($mv->location?->name ?? 'store') : 'Issued from ' . ($mv?->location?->name ?? 'store'),
                    'actor' => $mv?->performedBy?->name ?? null, 'role' => null, 'detail' => $mv?->notes]);
            }
            if ($n['type'] === 'shipment') {
                foreach (\App\Models\ShipmentEvent::with('user:id,name')->where('shipment_id', $n['id'])->get() as $e) {
                    $s = Shipment::find($n['id']);
                    $events->push(['at' => $e->created_at?->toIso8601String(), 'node' => $n['key'], 'title' => $n['title'],
                        'action' => 'Shipment: ' . app(\App\Services\Shipment\ShipmentFlow::class)->label($s, $e->to_step),
                        'actor' => $e->user?->name, 'role' => null, 'detail' => $e->reason ?: $e->note]);
                }
            }
            if ($n['type'] === 'payment') {
                $p = Payment::find($n['id']);
                $events->push(['at' => $p?->paid_at ? Carbon::parse($p->paid_at)->toIso8601String() : null, 'node' => $n['key'], 'title' => $n['title'],
                    'action' => 'Customer paid', 'actor' => null, 'role' => null, 'detail' => $p?->reference]);
            }
        }

        return $events->filter(fn ($e) => $e['at'])->sortBy('at')->values()->all();
    }

    /** The general-ledger legs posted for the records in the web. */
    private function ledger(): array
    {
        $refs = [];
        foreach ($this->nodes as $n) {
            $id = $n['id'];
            match ($n['type']) {
                'vendor_bill' => $refs[] = "BILL-{$id}",
                'supplier_payment' => $refs[] = "SPAY-{$id}",
                'purchase_return' => $refs[] = "PRET-{$id}",
                'vendor_fee' => array_push($refs, "VFEE-{$id}", "VFEE-{$id}-PAY"),
                'per_diem_request' => $refs[] = "PDM-{$id}",
                'creditor_opening' => $refs[] = "CROB-{$id}",
                'invoice' => $refs[] = "INV-{$id}",
                'expense' => $refs[] = "EXP-{$id}",
                'payment' => ($p = Payment::find($id)) ? $refs[] = "INV-{$p->invoice_id}-PAY-{$id}" : null,
                default => null,
            };
        }
        if (! $refs) {
            return [];
        }

        return Transaction::with('account:id,code,name')->whereIn('reference', $refs)->orderBy('created_at')->orderBy('id')->get()
            ->map(fn ($t) => [
                'reference' => $t->reference, 'date' => $t->created_at?->toDateString(),
                'account' => ($t->account?->code ?? '') . ' ' . ($t->account?->name ?? ''),
                'type' => $t->type, 'amount' => (int) $t->amount, 'description' => $t->description,
            ])->values()->all();
    }
}
