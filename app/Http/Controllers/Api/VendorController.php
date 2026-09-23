<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Vendor;
use Illuminate\Http\Request;

class VendorController extends Controller
{
    // Broad "who'd plausibly touch a transit/delivery vendor" gate — same
    // set of roles that already sit on the procurement/logistics/finance
    // chain this section plugs into. No separate read-only tier: this
    // registry is small and internal, unlike e.g. Hospitals.
    private function assertOpsAccess(Request $request): void
    {
        $user = $request->user();
        abort_if(
            ! $user->hasProcurementCreateAuthority()
                && ! $user->hasProcurementApprovalAuthority()
                && ! $user->hasLogisticsDeliverAuthority()
                && ! $user->hasLogisticsReceiveAuthority()
                && ! $user->hasAccountantAuthority()
                && ! $user->hasFinanceApprovalAuthority()
                && ! $user->hasDirectorAuthority(),
            403,
            'You are not authorised to view vendor records.'
        );
    }

    private function assertManageAccess(Request $request): void
    {
        abort_if(
            ! $request->user()->hasFinanceApprovalAuthority() && ! $request->user()->hasDirectorAuthority(),
            403,
            'You are not authorised to manage vendor records.'
        );
    }

    private function fmt(Vendor $v): array
    {
        return [
            'id' => $v->id, 'name' => $v->name, 'type' => $v->type, 'tin' => $v->tin,
            'payment_account_type' => $v->payment_account_type,
            'payment_account_name' => $v->payment_account_name,
            'payment_account_number' => $v->payment_account_number,
            'payment_bank_name' => $v->payment_bank_name,
            'contact_name' => $v->contact_name, 'contact_phone' => $v->contact_phone,
            'contact_email' => $v->contact_email, 'is_active' => $v->is_active,
        ];
    }

    public function index(Request $request)
    {
        $this->assertOpsAccess($request);

        $query = Vendor::query();
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }
        if (! $request->boolean('include_inactive')) {
            $query->where('is_active', true);
        }
        if ($request->filled('q')) {
            $query->where('name', 'ilike', "%{$request->q}%");
        }

        return response()->json(['data' => $query->orderBy('name')->get()->map(fn ($v) => $this->fmt($v))]);
    }

    public function show(Request $request, Vendor $vendor)
    {
        $this->assertOpsAccess($request);

        return response()->json(['data' => $this->fmt($vendor)]);
    }

    public function store(Request $request)
    {
        $this->assertManageAccess($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'in:clearing,delivery,other'],
            'tin' => ['nullable', 'string', 'max:50'],
            'payment_account_type' => ['nullable', 'in:bank,mobile_money'],
            'payment_account_name' => ['nullable', 'string', 'max:255'],
            'payment_account_number' => ['nullable', 'string', 'max:100'],
            'payment_bank_name' => ['nullable', 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:50'],
            'contact_email' => ['nullable', 'email', 'max:255'],
        ]);

        $vendor = Vendor::create($data);

        return response()->json(['data' => $this->fmt($vendor)], 201);
    }

    public function update(Request $request, Vendor $vendor)
    {
        $this->assertManageAccess($request);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'type' => ['sometimes', 'in:clearing,delivery,other'],
            'tin' => ['nullable', 'string', 'max:50'],
            'payment_account_type' => ['nullable', 'in:bank,mobile_money'],
            'payment_account_name' => ['nullable', 'string', 'max:255'],
            'payment_account_number' => ['nullable', 'string', 'max:100'],
            'payment_bank_name' => ['nullable', 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:50'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $vendor->update($data);

        return response()->json(['data' => $this->fmt($vendor->fresh())]);
    }
}
