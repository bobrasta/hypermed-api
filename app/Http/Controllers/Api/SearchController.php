<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Expense;
use App\Models\Hospital;
use App\Models\InventoryItem;
use App\Models\Invoice;
use App\Models\Location;
use App\Models\Machine;
use App\Models\Quotation;
use App\Models\SalesLead;
use App\Models\SalesOrder;
use App\Models\ServiceTicket;
use App\Models\Supplier;
use App\Models\User;
use App\Models\VendorBill;
use App\Services\EffectivePermissionResolver;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    // Results per entity type — enough to be useful in a dropdown without
    // turning this into a full paginated search page.
    private const LIMIT = 6;

    public function index(Request $request, EffectivePermissionResolver $resolver)
    {
        $q = trim((string) $request->query('q', ''));

        if (mb_strlen($q) < 2) {
            return response()->json(['data' => []]);
        }

        $user = $request->user();
        $canAll = $resolver->can($user, 'authority.admin_tier');
        $can = fn (string $screenKey) => $canAll || $resolver->can($user, "screens.{$screenKey}");

        $results = [];

        if ($can('machines')) {
            $results = array_merge($results, Machine::query()
                ->where(fn ($w) => $w->where('model', 'ilike', "%{$q}%")
                    ->orWhere('serial_no', 'ilike', "%{$q}%")
                    ->orWhere('type', 'ilike', "%{$q}%"))
                ->with('hospital')
                ->limit(self::LIMIT)->get()
                ->map(fn (Machine $m) => [
                    'type' => 'machine', 'id' => $m->id,
                    'title' => $m->model, 'subtitle' => trim("{$m->serial_no} · " . ($m->hospital?->name ?? '—')),
                ])->all());
        }

        if ($can('hospitals')) {
            $results = array_merge($results, Hospital::query()
                ->where(fn ($w) => $w->where('name', 'ilike', "%{$q}%")
                    ->orWhere('short_code', 'ilike', "%{$q}%")
                    ->orWhere('region', 'ilike', "%{$q}%"))
                ->limit(self::LIMIT)->get()
                ->map(fn (Hospital $h) => [
                    'type' => 'hospital', 'id' => $h->id,
                    'title' => $h->name, 'subtitle' => $h->region ?? $h->type,
                ])->all());
        }

        if ($can('service')) {
            $results = array_merge($results, ServiceTicket::query()
                ->where(fn ($w) => $w->where('ticket_number', 'ilike', "%{$q}%")
                    ->orWhere('description', 'ilike', "%{$q}%"))
                ->with('machine')
                ->limit(self::LIMIT)->get()
                ->map(fn (ServiceTicket $t) => [
                    'type' => 'service_ticket', 'id' => $t->id,
                    'title' => $t->ticket_number, 'subtitle' => $t->machine?->model ?? $t->description,
                ])->all());
        }

        if ($can('inventory')) {
            $results = array_merge($results, InventoryItem::query()
                ->where(fn ($w) => $w->where('name', 'ilike', "%{$q}%")
                    ->orWhere('sku', 'ilike', "%{$q}%"))
                ->limit(self::LIMIT)->get()
                ->map(fn (InventoryItem $i) => [
                    'type' => 'inventory_item', 'id' => $i->id,
                    'title' => $i->name, 'subtitle' => $i->sku,
                ])->all());

            $results = array_merge($results, Supplier::query()
                ->where('name', 'ilike', "%{$q}%")
                ->limit(self::LIMIT)->get()
                ->map(fn (Supplier $s) => [
                    'type' => 'supplier', 'id' => $s->id,
                    'title' => $s->name, 'subtitle' => 'Supplier',
                ])->all());

            $results = array_merge($results, Location::query()
                ->where(fn ($w) => $w->where('name', 'ilike', "%{$q}%")
                    ->orWhere('code', 'ilike', "%{$q}%"))
                ->limit(self::LIMIT)->get()
                ->map(fn (Location $l) => [
                    'type' => 'location', 'id' => $l->id,
                    'title' => $l->name, 'subtitle' => 'Location',
                ])->all());
        }

        if ($can('staff')) {
            $results = array_merge($results, User::query()
                ->where(fn ($w) => $w->where('name', 'ilike', "%{$q}%")
                    ->orWhere('email', 'ilike', "%{$q}%"))
                ->limit(self::LIMIT)->get()
                ->map(fn (User $u) => [
                    'type' => 'staff', 'id' => $u->id,
                    'title' => $u->name, 'subtitle' => $u->role,
                ])->all());
        }

        if ($can('sales')) {
            $results = array_merge($results, SalesLead::query()
                ->where(fn ($w) => $w->where('hospital_name_raw', 'ilike', "%{$q}%")
                    ->orWhere('contact_name_raw', 'ilike', "%{$q}%")
                    ->orWhere('machine_type', 'ilike', "%{$q}%"))
                ->limit(self::LIMIT)->get()
                ->map(fn (SalesLead $l) => [
                    'type' => 'sales_lead', 'id' => $l->id,
                    'title' => $l->contact_name_raw ?? $l->hospital_name_raw, 'subtitle' => $l->machine_type,
                ])->all());

            $results = array_merge($results, Quotation::query()
                ->where(fn ($w) => $w->where('quotation_number', 'ilike', "%{$q}%")
                    ->orWhere('client_name', 'ilike', "%{$q}%"))
                ->limit(self::LIMIT)->get()
                ->map(fn (Quotation $qt) => [
                    'type' => 'quotation', 'id' => $qt->id,
                    'title' => $qt->quotation_number, 'subtitle' => $qt->client_name,
                ])->all());

            $results = array_merge($results, SalesOrder::query()
                ->where(fn ($w) => $w->where('order_number', 'ilike', "%{$q}%")
                    ->orWhere('client_name', 'ilike', "%{$q}%"))
                ->limit(self::LIMIT)->get()
                ->map(fn (SalesOrder $so) => [
                    'type' => 'sales_order', 'id' => $so->id,
                    'title' => $so->order_number, 'subtitle' => $so->client_name,
                ])->all());

            $results = array_merge($results, Invoice::query()
                ->where(fn ($w) => $w->where('invoice_number', 'ilike', "%{$q}%")
                    ->orWhere('client_name', 'ilike', "%{$q}%"))
                ->limit(self::LIMIT)->get()
                ->map(fn (Invoice $inv) => [
                    'type' => 'invoice', 'id' => $inv->id,
                    'title' => $inv->invoice_number, 'subtitle' => $inv->client_name,
                ])->all());
        }

        if ($can('customers')) {
            $results = array_merge($results, Contact::query()
                ->where(fn ($w) => $w->where('first_name', 'ilike', "%{$q}%")
                    ->orWhere('last_name', 'ilike', "%{$q}%")
                    ->orWhere('email', 'ilike', "%{$q}%"))
                ->limit(self::LIMIT)->get()
                ->map(fn (Contact $c) => [
                    'type' => 'contact', 'id' => $c->id,
                    'title' => trim("{$c->first_name} {$c->last_name}"), 'subtitle' => $c->job_title ?? $c->email,
                ])->all());
        }

        if ($can('finance')) {
            $results = array_merge($results, VendorBill::query()
                ->where(fn ($w) => $w->where('bill_number', 'ilike', "%{$q}%")
                    ->orWhereHas('supplier', fn ($s) => $s->where('name', 'ilike', "%{$q}%")))
                ->with('supplier')
                ->limit(self::LIMIT)->get()
                ->map(fn (VendorBill $b) => [
                    'type' => 'vendor_bill', 'id' => $b->id,
                    'title' => $b->bill_number, 'subtitle' => $b->supplier?->name,
                ])->all());

            $results = array_merge($results, Expense::query()
                ->where('name', 'ilike', "%{$q}%")
                ->limit(self::LIMIT)->get()
                ->map(fn (Expense $e) => [
                    'type' => 'expense', 'id' => $e->id,
                    'title' => $e->name, 'subtitle' => 'Expense',
                ])->all());
        }

        return response()->json(['data' => $results]);
    }
}
