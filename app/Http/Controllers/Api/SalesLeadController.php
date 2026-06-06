<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SalesLeadResource;
use App\Models\SalesLead;
use Illuminate\Http\Request;

class SalesLeadController extends Controller
{
    public function index()
    {
        $leads = SalesLead::with(['hospital', 'assignee'])->get();

        $grouped = $leads->groupBy('stage')->map(fn ($group) =>
            SalesLeadResource::collection($group)
        );

        return response()->json(['data' => $grouped]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'hospital_id'       => ['nullable', 'exists:hospitals,id'],
            'hospital_name_raw' => ['nullable', 'string'],
            'contact_id'        => ['nullable', 'exists:contacts,id'],
            'contact_name_raw'  => ['nullable', 'string'],
            'machine_type'      => ['nullable', 'string'],
            'deal_value'        => ['nullable', 'integer', 'min:0'],
            'stage'             => ['required', 'in:lead,qualified,demo_scheduled,proposal_sent,negotiation,won,lost'],
            'demo_date'         => ['nullable', 'date'],
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
        $data = $request->validate([
            'hospital_id'       => ['nullable', 'exists:hospitals,id'],
            'hospital_name_raw' => ['nullable', 'string'],
            'contact_name_raw'  => ['nullable', 'string'],
            'machine_type'      => ['nullable', 'string'],
            'deal_value'        => ['nullable', 'integer', 'min:0'],
            'stage'             => ['sometimes', 'in:lead,qualified,demo_scheduled,proposal_sent,negotiation,won,lost'],
            'demo_date'         => ['nullable', 'date'],
            'assigned_to'       => ['nullable', 'exists:users,id'],
        ]);

        $lead->update($data);

        return response()->json(['data' => new SalesLeadResource($lead->load(['hospital', 'assignee']))]);
    }

    public function destroy(SalesLead $lead)
    {
        $lead->delete();

        return response()->json(null, 204);
    }

    public function updateStage(Request $request, SalesLead $lead)
    {
        $request->validate([
            'stage' => ['required', 'in:lead,qualified,demo_scheduled,proposal_sent,negotiation,won,lost'],
        ]);

        $lead->update(['stage' => $request->stage]);

        return response()->json(['data' => new SalesLeadResource($lead->load(['hospital', 'assignee']))]);
    }
}
