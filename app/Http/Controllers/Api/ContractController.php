<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AllowanceResource;
use App\Http\Resources\ContractResource;
use App\Models\Allowance;
use App\Models\Contract;
use App\Models\HrSetting;
use App\Models\User;
use Illuminate\Http\Request;

class ContractController extends Controller
{
    public function index(User $user)
    {
        return ContractResource::collection(
            $user->contracts()->with(['createdBy', 'allowances'])->latest('start_date')->get()
        );
    }

    public function store(Request $request, User $user)
    {
        abort_if(! $request->user()->hasStaffManageAuthority(), 403, 'You are not authorised to manage contracts.');

        $data = $request->validate([
            'contract_type'          => ['required', 'in:permanent,fixed_term'],
            'start_date'             => ['required', 'date'],
            'end_date'               => ['nullable', 'date', 'after:start_date'],
            'probation_period_days'  => ['nullable', 'integer', 'min:0'],
            'base_salary'            => ['nullable', 'integer', 'min:0'],
        ]);

        abort_if($data['contract_type'] === 'fixed_term' && empty($data['end_date']), 422,
            'Fixed-term contracts require an end date.');

        $probationDays = $data['probation_period_days'] ?? (int) HrSetting::get('default_probation_days', '90');
        $startDate = \Carbon\Carbon::parse($data['start_date']);

        $contract = Contract::create([
            'user_id'               => $user->id,
            'contract_type'         => $data['contract_type'],
            'start_date'            => $data['start_date'],
            'end_date'              => $data['end_date'] ?? null,
            'probation_period_days' => $probationDays,
            'probation_end_date'    => $startDate->copy()->addDays($probationDays)->toDateString(),
            'base_salary'           => $data['base_salary'] ?? null,
            'status'                => 'active',
            'created_by'            => $request->user()->id,
        ]);

        return (new ContractResource($contract->load(['createdBy', 'allowances'])))->response()->setStatusCode(201);
    }

    // Writes a NEW row referencing the old one — the old contract's term is
    // over, never mutated in place, matching the append-only convention
    // used for salary adjustments/position changes elsewhere in this plan.
    public function renew(Request $request, Contract $contract)
    {
        abort_if(! $request->user()->hasStaffManageAuthority(), 403, 'You are not authorised to manage contracts.');
        abort_if($contract->status !== 'active', 422, 'Only an active contract can be renewed.');

        $data = $request->validate([
            'contract_type'         => ['required', 'in:permanent,fixed_term'],
            'start_date'            => ['required', 'date'],
            'end_date'              => ['nullable', 'date', 'after:start_date'],
            'base_salary'           => ['nullable', 'integer', 'min:0'],
        ]);

        abort_if($data['contract_type'] === 'fixed_term' && empty($data['end_date']), 422,
            'Fixed-term contracts require an end date.');

        $renewed = Contract::create([
            'user_id'                  => $contract->user_id,
            'contract_type'            => $data['contract_type'],
            'start_date'               => $data['start_date'],
            'end_date'                 => $data['end_date'] ?? null,
            'probation_period_days'    => 0,
            'base_salary'              => $data['base_salary'] ?? $contract->base_salary,
            'status'                   => 'active',
            'renewed_from_contract_id' => $contract->id,
            'created_by'               => $request->user()->id,
        ]);

        $contract->update(['status' => 'ended']);

        return (new ContractResource($renewed->load(['createdBy', 'allowances'])))->response()->setStatusCode(201);
    }

    public function end(Request $request, Contract $contract)
    {
        abort_if(! $request->user()->hasStaffManageAuthority(), 403, 'You are not authorised to manage contracts.');
        abort_if($contract->status !== 'active', 422, 'Only an active contract can be ended.');

        $data = $request->validate(['end_date' => ['nullable', 'date']]);

        $contract->update([
            'status'   => 'ended',
            'end_date' => $data['end_date'] ?? now()->toDateString(),
        ]);

        return new ContractResource($contract->load(['createdBy', 'allowances']));
    }

    public function resign(Request $request, Contract $contract)
    {
        abort_if(! $request->user()->hasStaffManageAuthority(), 403, 'You are not authorised to manage contracts.');
        abort_if($contract->status !== 'active', 422, 'Only an active contract can be marked resigned.');

        $data = $request->validate([
            'resignation_date'   => ['required', 'date'],
            'resignation_reason' => ['nullable', 'string'],
        ]);

        $contract->update([
            'status'              => 'resigned',
            'resignation_date'    => $data['resignation_date'],
            'resignation_reason'  => $data['resignation_reason'] ?? null,
        ]);

        return new ContractResource($contract->load(['createdBy', 'allowances']));
    }

    // Overwrites any previous document — a contract has one current signed
    // copy on file, not a version history (unlike applicant CVs, which are
    // deliberately versioned across reapplications).
    public function uploadDocument(Request $request, Contract $contract)
    {
        abort_if(! $request->user()->hasStaffManageAuthority(), 403, 'You are not authorised to manage contracts.');
        $request->validate(['file' => ['required', 'file', 'max:10240']]);

        $file = $request->file('file');
        $path = $file->store('contract-documents/' . $contract->id, 'public');

        $contract->update([
            'document_path'        => $path,
            'document_name'        => $file->getClientOriginalName(),
            'document_uploaded_at' => now(),
        ]);

        return new ContractResource($contract->load(['createdBy', 'allowances']));
    }

    public function addAllowance(Request $request, Contract $contract)
    {
        abort_if(! $request->user()->hasStaffManageAuthority(), 403, 'You are not authorised to manage contracts.');

        $data = $request->validate([
            'type'           => ['required', 'string', 'max:255'],
            'amount'         => ['required', 'integer', 'min:0'],
            'recurring'      => ['nullable', 'boolean'],
            'effective_date' => ['required', 'date'],
        ]);
        $data['recurring'] = $data['recurring'] ?? true;

        $allowance = $contract->allowances()->create($data);

        return (new AllowanceResource($allowance))->response()->setStatusCode(201);
    }

    public function removeAllowance(Request $request, Contract $contract, Allowance $allowance)
    {
        abort_if(! $request->user()->hasStaffManageAuthority(), 403, 'You are not authorised to manage contracts.');
        abort_if($allowance->contract_id !== $contract->id, 404);

        $allowance->delete();

        return response()->noContent();
    }
}
