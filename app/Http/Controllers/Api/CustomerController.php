<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ContactResource;
use App\Models\Hospital;
use App\Models\Invoice;
use App\Models\Quotation;
use App\Services\EffectivePermissionResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// The Customers page: a customer is a client record (hospitals row) —
// the facility we sell to or service — and the people we talk to there
// are its contacts. The hospitals table also holds the ~13,600-row
// national facility registry (prospects), so "customer" here means a
// facility we actually do business with: it has our machines, invoices,
// sales orders or contact people, or came over from Clickhuduma.
class CustomerController extends Controller
{
    private const OPEN = ['pending', 'sent', 'partial', 'overdue'];

    private function assertAccess(Request $request): void
    {
        $resolver = app(EffectivePermissionResolver::class);
        $user = $request->user();
        abort_unless(
            $resolver->can($user, 'authority.admin_tier') || $resolver->can($user, 'screens.customers'),
            403,
            'You are not authorised to view customers.'
        );
    }

    private function customers(): Builder
    {
        return Hospital::query()->where(fn ($w) => $w
            ->where('machine_count', '>', 0)
            ->orWhereHas('invoices')
            ->orWhereHas('salesOrders')
            ->orWhereHas('contacts')
            ->orWhere('notes', 'ilike', 'Client imported from Clickhuduma%'));
    }

    /** Open balance of a customer's invoices: total − paid − applied credit notes. */
    private const BALANCE_SQL = <<<'SQL'
        coalesce((select sum(greatest(0, i.total - i.amount_paid - coalesce((
            select sum(cn.amount) from credit_notes cn where cn.invoice_id = i.id and cn.status = 'applied'
        ), 0))) from invoices i where i.hospital_id = hospitals.id and i.status in ('pending','sent','partial','overdue')), 0)
        SQL;

    // GET /customers — every customer with contact details and sales totals.
    public function index(Request $request)
    {
        $this->assertAccess($request);

        $rows = $this->customers()
            ->when(trim((string) $request->input('search')), function ($q, $s) {
                $q->where(fn ($w) => $w->where('name', 'ilike', "%{$s}%")
                    ->orWhere('tin', 'ilike', "%{$s}%")
                    ->orWhere('contact_phone', 'ilike', "%{$s}%")
                    ->orWhereHas('contacts', fn ($c) => $c->where(DB::raw("first_name || ' ' || last_name"), 'ilike', "%{$s}%")));
            })
            ->select('hospitals.*')
            ->withCount(['contacts', 'invoices'])
            ->withSum('invoices as total_billed', 'total')
            ->withMax('invoices as last_invoice_date', 'issue_date')
            ->selectRaw(self::BALANCE_SQL . ' as balance')
            ->orderByRaw('last_invoice_date desc nulls last')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $rows->map(fn (Hospital $h) => $this->row($h))->values()]);
    }

    // GET /customers/{hospital} — one customer: details, totals, contact
    // people, recent invoices and quotations.
    public function show(Request $request, Hospital $hospital)
    {
        $this->assertAccess($request);

        $invoices = Invoice::where('hospital_id', $hospital->id)
            ->withSum(['creditNotes as credited' => fn ($c) => $c->where('status', 'applied')], 'amount')
            ->orderByDesc('issue_date')->orderByDesc('id')
            ->get();
        $open = $invoices->whereIn('status', self::OPEN);
        $balanceOf = fn (Invoice $i) => max(0, (int) $i->total - (int) $i->amount_paid - (int) ($i->credited ?? 0));

        $hospital->contacts_count = $hospital->contacts()->count();
        $hospital->invoices_count = $invoices->count();
        $hospital->total_billed = (int) $invoices->sum('total');
        $hospital->balance = (int) $open->sum($balanceOf);
        $hospital->last_invoice_date = $invoices->first()?->issue_date;

        // Quotations carry only the typed client name, not a hospital id.
        $quotations = Quotation::whereRaw('lower(trim(client_name)) = ?', [mb_strtolower(trim($hospital->name))])
            ->orderByDesc('created_at')->limit(10)
            ->get(['id', 'quotation_number', 'status', 'total_amount', 'created_at']);

        return response()->json(['data' => $this->row($hospital) + [
            'total_paid'         => (int) $invoices->sum('amount_paid'),
            'open_invoices'      => $open->filter(fn ($i) => $balanceOf($i) > 0)->count(),
            'first_invoice_date' => $invoices->last()?->issue_date?->toDateString(),
            'notes'              => $hospital->notes,
            'contacts'           => ContactResource::collection($hospital->contacts()->with('tags')->orderBy('first_name')->get()),
            'recent_invoices'    => $invoices->take(15)->map(fn (Invoice $i) => [
                'id'             => $i->id,
                'invoice_number' => $i->invoice_number,
                'issue_date'     => $i->issue_date?->toDateString(),
                'due_date'       => $i->due_date?->toDateString(),
                'status'         => $i->status,
                'total'          => (int) $i->total,
                'balance'        => in_array($i->status, self::OPEN, true) ? $balanceOf($i) : 0,
            ])->values(),
            'recent_quotations'  => $quotations->map(fn (Quotation $q) => [
                'id'               => $q->id,
                'quotation_number' => $q->quotation_number,
                'status'           => $q->status,
                'total'            => (int) $q->total_amount,
                'date'             => $q->created_at?->toDateString(),
            ])->values(),
        ]]);
    }

    private function row(Hospital $h): array
    {
        $last = $h->last_invoice_date;

        return [
            'id'                => $h->id,
            'name'              => $h->name,
            'type'              => $h->type,
            'region'            => $h->region,
            'district'          => $h->district,
            'tin'               => $h->tin,
            'phone'             => $h->contact_phone,
            'email'             => $h->contact_email,
            'address'           => $h->address,
            'contact_name'      => $h->contact_name,
            'machine_count'     => (int) $h->machine_count,
            'contacts_count'    => (int) ($h->contacts_count ?? 0),
            'invoices_count'    => (int) ($h->invoices_count ?? 0),
            'total_billed'      => (int) ($h->total_billed ?? 0),
            'balance'           => (int) ($h->balance ?? 0),
            'last_invoice_date' => $last ? substr((string) $last, 0, 10) : null,
        ];
    }
}
