<?php

namespace App\Services;

use App\Models\NotificationTemplate;
use Illuminate\Support\Facades\Log;

/**
 * Renders the title/body/type for an AppNotification from an editable
 * template row instead of a hardcoded string baked into a controller —
 * ported concept from clickhuduma's notification_templates table (same
 * {shortcode} substitution approach, simplified: Hypermed has no email/SMS
 * channel split to template separately, just the one in-app notification).
 *
 * Usage at a call site:
 *   AppNotification::create([
 *       'user_id' => $id,
 *       ...app(NotificationTemplateService::class)->render('expense.paid', [
 *           'expense_name' => $expense->name,
 *       ]),
 *       'entity_type' => 'expense',
 *       'entity_id'   => $expense->id,
 *       'is_read'     => false,
 *   ]);
 *
 * A missing template never blocks the action that triggered it — it logs a
 * warning and falls back to a generic message, since a degraded
 * notification is recoverable but a 500 on (e.g.) approving an expense is
 * not an acceptable failure mode for a missing row.
 */
class NotificationTemplateService
{
    public function render(string $templateKey, array $vars = []): array
    {
        $template = NotificationTemplate::where('template_key', $templateKey)->first();

        if (! $template) {
            Log::warning("No notification template found for key '{$templateKey}' — sending a generic fallback message instead of blocking the triggering action.");

            return [
                'type'  => $templateKey,
                'title' => 'Notification',
                'body'  => 'This event does not yet have a configured notification message.',
            ];
        }

        return [
            'type'  => $template->notification_type,
            'title' => $this->substitute($template->title_template, $vars),
            'body'  => $this->substitute($template->body_template, $vars),
        ];
    }

    private function substitute(string $text, array $vars): string
    {
        foreach ($vars as $key => $value) {
            $text = str_replace('{' . $key . '}', (string) $value, $text);
        }

        return $text;
    }
}
