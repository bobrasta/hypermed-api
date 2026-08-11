<?php

namespace App\Services;

use App\Models\AppNotification;
use App\Models\SalesLead;

/**
 * Notifies a lead's assigned rep once their follow_up_date arrives. Idempotent
 * per follow_up_date: only sends if no reminder notification for this lead has
 * been created since the current follow_up_date was set, so re-running the
 * command daily doesn't spam, but moving the date forward triggers a fresh one.
 */
class LeadFollowUpService
{
    public function sendDueReminders(): int
    {
        $due = SalesLead::query()
            ->whereNotNull('follow_up_date')
            ->whereNotNull('assigned_to')
            ->whereDate('follow_up_date', '<=', now()->toDateString())
            ->whereNotIn('stage', ['won', 'lost'])
            ->with(['assignee', 'hospital'])
            ->get();

        $sent = 0;

        foreach ($due as $lead) {
            $alreadySent = AppNotification::query()
                ->where('user_id', $lead->assigned_to)
                ->where('type', 'lead_follow_up')
                ->where('entity_type', 'sales_lead')
                ->where('entity_id', $lead->id)
                ->whereDate('created_at', '>=', $lead->follow_up_date)
                ->exists();

            if ($alreadySent) {
                continue;
            }

            $clientName = $lead->hospital?->name ?? $lead->hospital_name_raw ?? 'this lead';

            AppNotification::create([
                'user_id'     => $lead->assigned_to,
                'type'        => 'lead_follow_up',
                'title'       => 'Follow-up Due',
                'body'        => "Follow up with {$clientName} — {$lead->machine_type} deal is due for a check-in.",
                'entity_type' => 'sales_lead',
                'entity_id'   => $lead->id,
                'is_read'     => false,
            ]);

            $sent++;
        }

        return $sent;
    }
}
