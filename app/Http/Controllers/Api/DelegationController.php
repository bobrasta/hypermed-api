<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Delegation;
use Illuminate\Http\Request;

class DelegationController extends Controller
{
    // Only a Director (admin-tier) can delegate — and only their own
    // authority, not someone else's, so delegator_id is always the caller.
    public function index(Request $request)
    {
        abort_if(! $request->user()->hasDirectorAuthority(), 403, 'Only the Director can view delegations.');

        $delegations = Delegation::with(['delegator', 'delegate', 'revokedBy'])
            ->orderByDesc('starts_at')->get();

        return response()->json(['data' => $delegations->map(fn (Delegation $d) => $this->payload($d))]);
    }

    public function store(Request $request)
    {
        abort_if(! $request->user()->isAdminTier(), 403, 'Only the Director can delegate approval authority.');

        $data = $request->validate([
            'delegate_id' => ['required', 'exists:users,id', 'different:delegator_id'],
            'reason'      => ['nullable', 'string'],
            'starts_at'   => ['required', 'date'],
            'ends_at'     => ['required', 'date', 'after:starts_at'],
        ]);

        abort_if((int) $data['delegate_id'] === $request->user()->id, 422, 'You cannot delegate to yourself.');

        $delegation = Delegation::create([
            'delegator_id' => $request->user()->id,
            'delegate_id'  => $data['delegate_id'],
            'reason'       => $data['reason'] ?? null,
            'starts_at'    => $data['starts_at'],
            'ends_at'      => $data['ends_at'],
        ]);

        return response()->json(['data' => $this->payload($delegation->load(['delegator', 'delegate']))], 201);
    }

    public function revoke(Request $request, Delegation $delegation)
    {
        abort_if(! $request->user()->isAdminTier(), 403, 'Only the Director can revoke a delegation.');
        abort_if($delegation->revoked_at !== null, 422, 'This delegation is already revoked.');

        $delegation->update(['revoked_at' => now(), 'revoked_by' => $request->user()->id]);

        return response()->json(['data' => $this->payload($delegation->load(['delegator', 'delegate', 'revokedBy']))]);
    }

    private function payload(Delegation $d): array
    {
        return [
            'id'             => $d->id,
            'delegator_name' => $d->delegator?->name,
            'delegate_id'    => $d->delegate_id,
            'delegate_name'  => $d->delegate?->name,
            'reason'         => $d->reason,
            'starts_at'      => $d->starts_at?->toIso8601String(),
            'ends_at'        => $d->ends_at?->toIso8601String(),
            'revoked_at'     => $d->revoked_at?->toIso8601String(),
            'revoked_by_name' => $d->revokedBy?->name,
            'is_active'      => $d->revoked_at === null && $d->starts_at <= now() && $d->ends_at >= now(),
        ];
    }
}
