<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SalesActivity;
use App\Models\SalesLead;
use Illuminate\Http\Request;

// "Log activity" on the sales dashboard. A rep logs and sees their own; a
// sales.view_full_numbers holder sees the whole team's (same scoping rule as
// SalesLeadController::index).
class SalesActivityController extends Controller
{
    public const TYPES = ['call', 'visit', 'meeting', 'demo', 'email', 'whatsapp'];

    public function index(Request $request)
    {
        $user = $request->user();
        $activities = SalesActivity::with(['createdBy', 'hospital', 'lead.hospital'])
            ->when(! $user->hasSalesViewFullNumbers(), fn ($q) => $q->where('created_by', $user->id))
            ->when($request->query('lead_id'), fn ($q, $id) => $q->where('sales_lead_id', $id))
            ->latest('occurs_at')->limit(100)->get();

        return response()->json(['data' => $activities->map(fn ($a) => self::present($a))->values()]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        abort_if(! $user->hasSalesCreateAuthority(), 403, 'You are not authorised to log sales activity.');

        $data = $request->validate([
            'type'              => ['required', 'in:' . implode(',', self::TYPES)],
            'subject'           => ['required', 'string', 'max:255'],
            'note'              => ['nullable', 'string'],
            'occurs_at'         => ['required', 'date'],
            'sales_lead_id'     => ['nullable', 'exists:sales_leads,id'],
            'hospital_id'       => ['nullable', 'exists:hospitals,id'],
            'hospital_name_raw' => ['nullable', 'string', 'max:255'],
        ]);

        // Eloquent stores a datetime's wall-clock digits without converting
        // its offset — "09:10+03:00" would land as 09:10 UTC. Normalise first.
        $data['occurs_at'] = \Illuminate\Support\Carbon::parse($data['occurs_at'])->setTimezone(config('app.timezone'));

        if (! empty($data['sales_lead_id'])) {
            $lead = SalesLead::find($data['sales_lead_id']);
            abort_if(! $user->hasSalesViewFullNumbers() && $lead->assigned_to !== $user->id, 403,
                'This lead is not assigned to you.');
            $data['hospital_id'] ??= $lead->hospital_id;
            $data['hospital_name_raw'] ??= $lead->hospital_name_raw;
        }

        $activity = SalesActivity::create($data + ['created_by' => $user->id]);

        return response()->json(['data' => self::present($activity->load(['createdBy', 'hospital', 'lead.hospital']))], 201);
    }

    public function destroy(Request $request, SalesActivity $activity)
    {
        abort_if($activity->created_by !== $request->user()->id, 403, 'You can only remove activity you logged.');
        $activity->delete();

        return response()->json(null, 204);
    }

    public static function present(SalesActivity $a): array
    {
        return [
            'id'            => $a->id,
            'type'          => $a->type,
            'subject'       => $a->subject,
            'note'          => $a->note,
            'occurs_at'     => $a->occurs_at?->toIso8601String(),
            'sales_lead_id' => $a->sales_lead_id,
            'client'        => $a->client_name,
            'by'            => $a->createdBy?->name,
            'by_id'         => $a->created_by,
        ];
    }
}
