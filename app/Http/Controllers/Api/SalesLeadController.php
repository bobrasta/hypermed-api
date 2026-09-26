<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SalesLeadResource;
use App\Models\SalesLead;
use App\Models\User;
use Illuminate\Http\Request;

const STAGE_LABELS = [
    'lead' => 'Lead', 'qualified' => 'Qualified', 'demo_scheduled' => 'Demo scheduled',
    'proposal_sent' => 'Proposal sent', 'negotiation' => 'Negotiation', 'won' => 'Won', 'lost' => 'Lost',
];

class SalesLeadController extends Controller
{
    // Every caller got every lead regardless of role — a plain 'sales' rep
    // saw the whole company's pipeline (values, client names, everything)
    // with no way to see only their own book. Mirrors DashboardController's
    // sales.view_full_numbers gate: manager-tier sees everyone, a rep sees
    // only leads assigned to them.
    public function index(Request $request)
    {
        $leads = SalesLead::with(['hospital', 'assignee'])
            ->when(! $request->user()->hasSalesViewFullNumbers(), fn ($q) => $q->where('assigned_to', $request->user()->id))
            ->latest()->get();

        return SalesLeadResource::collection($leads);
    }

    public function store(Request $request)
    {
        abort_if(! $request->user()->hasSalesCreateAuthority(), 403, 'You are not authorised to create sales leads.');

        $data = $request->validate([
            'hospital_id'       => ['nullable', 'exists:hospitals,id'],
            'hospital_name_raw' => ['nullable', 'string'],
            'contact_id'        => ['nullable', 'exists:contacts,id'],
            'contact_name_raw'  => ['nullable', 'string'],
            'source'            => ['nullable', 'in:referral,tender,inbound_call,walk_in,other'],
            'source_notes'      => ['nullable', 'string', 'max:255'],
            'machine_type'      => ['nullable', 'string'],
            'deal_value'        => ['nullable', 'integer', 'min:0'],
            'stage'             => ['required', 'in:lead,qualified,demo_scheduled,proposal_sent,negotiation,won,lost'],
            'demo_date'         => ['nullable', 'date'],
            'follow_up_date'    => ['nullable', 'date'],
            'expected_close_date' => ['nullable', 'date'],
            'forecast_category' => ['nullable', 'in:commit,best_case,pipeline'],
            'assigned_to'       => ['nullable', 'exists:users,id'],
        ]);

        // A rep can only ever create their own lead — only a manager can
        // hand a brand-new lead straight to someone else.
        if (! $request->user()->hasSalesApprovalAuthority()) {
            $data['assigned_to'] = $request->user()->id;
        }

        $lead = SalesLead::create($data);

        return response()->json(['data' => new SalesLeadResource($lead->load(['hospital', 'assignee']))], 201);
    }

    public function show(Request $request, SalesLead $lead)
    {
        $this->authorizeOwnLead($request->user(), $lead);

        $lead->load(['hospital', 'assignee', 'contact', 'events.createdBy']);

        return response()->json(['data' => new SalesLeadResource($lead)]);
    }

    public function update(Request $request, SalesLead $lead)
    {
        abort_if(! $request->user()->hasSalesEditAuthority(), 403, 'You are not authorised to edit sales leads.');
        $this->authorizeOwnLead($request->user(), $lead);

        $data = $request->validate([
            'hospital_id'       => ['nullable', 'exists:hospitals,id'],
            'hospital_name_raw' => ['nullable', 'string'],
            'contact_name_raw'  => ['nullable', 'string'],
            'source'            => ['nullable', 'in:referral,tender,inbound_call,walk_in,other'],
            'source_notes'      => ['nullable', 'string', 'max:255'],
            'notes'             => ['nullable', 'string'],
            'machine_type'      => ['nullable', 'string'],
            'deal_value'        => ['nullable', 'integer', 'min:0'],
            'stage'             => ['sometimes', 'in:lead,qualified,demo_scheduled,proposal_sent,negotiation,won,lost'],
            'demo_date'         => ['nullable', 'date'],
            'follow_up_date'    => ['nullable', 'date'],
            'expected_close_date' => ['nullable', 'date'],
            'forecast_category' => ['nullable', 'in:commit,best_case,pipeline'],
            'assigned_to'       => ['nullable', 'exists:users,id'],
        ]);

        // Reassigning to someone else is manager work — a rep editing their
        // own lead can't smuggle a transfer through the general edit form.
        // Silently dropped (not rejected) since the edit dialog round-trips
        // the current, unchanged value for everyone.
        if (isset($data['assigned_to']) && $data['assigned_to'] != $lead->assigned_to
            && ! $request->user()->hasSalesApprovalAuthority()) {
            unset($data['assigned_to']);
        }

        if (isset($data['assigned_to']) && $data['assigned_to'] != $lead->assigned_to) {
            $newOwner = User::find($data['assigned_to']);
            $lead->events()->create([
                'type' => 'reassigned', 'title' => "Reassigned to {$newOwner?->name}",
                'note' => "By {$request->user()->name}", 'created_by' => $request->user()->id,
            ]);
        }

        if (isset($data['stage']) && $data['stage'] !== $lead->stage) {
            $lead->events()->create([
                'type' => 'stage_change', 'title' => 'Stage → ' . (STAGE_LABELS[$data['stage']] ?? $data['stage']),
                'note' => $request->user()->name, 'created_by' => $request->user()->id,
            ]);
        }

        $lead->update($data);

        return response()->json(['data' => new SalesLeadResource($lead->load(['hospital', 'assignee']))]);
    }

    public function destroy(Request $request, SalesLead $lead)
    {
        abort_if(! $request->user()->hasSalesEditAuthority(), 403, 'You are not authorised to edit sales leads.');
        $this->authorizeOwnLead($request->user(), $lead);

        $lead->delete();

        return response()->json(null, 204);
    }

    public function updateStage(Request $request, SalesLead $lead)
    {
        abort_if(! $request->user()->hasSalesEditAuthority(), 403, 'You are not authorised to edit sales leads.');
        $this->authorizeOwnLead($request->user(), $lead);

        $request->validate([
            'stage' => ['required', 'in:lead,qualified,demo_scheduled,proposal_sent,negotiation,won,lost'],
        ]);

        if ($request->stage !== $lead->stage) {
            $lead->events()->create([
                'type' => 'stage_change', 'title' => 'Stage → ' . (STAGE_LABELS[$request->stage] ?? $request->stage),
                'note' => $request->user()->name, 'created_by' => $request->user()->id,
            ]);
        }

        $lead->update(['stage' => $request->stage]);

        return response()->json(['data' => new SalesLeadResource($lead->load(['hospital', 'assignee']))]);
    }

    // Mirrors index()'s sales.view_full_numbers scoping on every single-lead
    // route — without this a rep could reach any lead in the company
    // directly by numeric ID even though the list view never shows it to
    // them.
    private function authorizeOwnLead(User $user, SalesLead $lead): void
    {
        abort_if(! $user->hasSalesViewFullNumbers() && $lead->assigned_to !== $user->id, 403,
            'This lead is not assigned to you.');
    }
}
