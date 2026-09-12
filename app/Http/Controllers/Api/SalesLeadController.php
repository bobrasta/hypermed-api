<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SalesLeadResource;
use App\Models\SalesLead;
use Illuminate\Http\Request;

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
            'assigned_to'       => ['nullable', 'exists:users,id'],
        ]);

        $lead = SalesLead::create($data);

        return response()->json(['data' => new SalesLeadResource($lead->load(['hospital', 'assignee']))], 201);
    }

    public function show(SalesLead $lead)
    {
        $lead->load(['hospital', 'assignee', 'contact']);

        return response()->json(['data' => new SalesLeadResource($lead)]);
    }

    public function update(Request $request, SalesLead $lead)
    {
        abort_if(! $request->user()->hasSalesEditAuthority(), 403, 'You are not authorised to edit sales leads.');

        $data = $request->validate([
            'hospital_id'       => ['nullable', 'exists:hospitals,id'],
            'hospital_name_raw' => ['nullable', 'string'],
            'contact_name_raw'  => ['nullable', 'string'],
            'source'            => ['nullable', 'in:referral,tender,inbound_call,walk_in,other'],
            'source_notes'      => ['nullable', 'string', 'max:255'],
            'machine_type'      => ['nullable', 'string'],
            'deal_value'        => ['nullable', 'integer', 'min:0'],
            'stage'             => ['sometimes', 'in:lead,qualified,demo_scheduled,proposal_sent,negotiation,won,lost'],
            'demo_date'         => ['nullable', 'date'],
            'follow_up_date'    => ['nullable', 'date'],
            'assigned_to'       => ['nullable', 'exists:users,id'],
        ]);

        $lead->update($data);

        return response()->json(['data' => new SalesLeadResource($lead->load(['hospital', 'assignee']))]);
    }

    public function destroy(Request $request, SalesLead $lead)
    {
        abort_if(! $request->user()->hasSalesEditAuthority(), 403, 'You are not authorised to edit sales leads.');

        $lead->delete();

        return response()->json(null, 204);
    }

    public function updateStage(Request $request, SalesLead $lead)
    {
        abort_if(! $request->user()->hasSalesEditAuthority(), 403, 'You are not authorised to edit sales leads.');

        $request->validate([
            'stage' => ['required', 'in:lead,qualified,demo_scheduled,proposal_sent,negotiation,won,lost'],
        ]);

        $lead->update(['stage' => $request->stage]);

        return response()->json(['data' => new SalesLeadResource($lead->load(['hospital', 'assignee']))]);
    }
}
