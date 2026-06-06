<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SyncedEmailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $plainText = $this->buildPlainText();

        return [
            'id'              => $this->id,
            'uid'             => $this->uid,
            'message_id'      => $this->message_id,
            'folder'          => $this->folder,
            'subject'         => $this->subject ?? '(no subject)',
            'from'            => [
                'email' => $this->from_email,
                'name'  => $this->from_name,
            ],
            'to'              => $this->to_addresses,
            'cc'              => $this->cc_addresses ?? [],
            'bcc'             => $this->bcc_addresses ?? [],

            // Always present — plain text for Flutter Text widgets
            'body_text'       => $plainText,

            // Short clean preview for list/card views (~200 chars)
            'body_preview'    => $this->buildPreview($plainText),

            // Full HTML for WebView rendering (style/script blocks stripped)
            'body_html'       => $this->body_html ? $this->cleanHtml($this->body_html) : null,

            'in_reply_to'     => $this->in_reply_to,
            'is_read'         => $this->is_read,
            'is_flagged'      => $this->is_flagged,
            'is_draft'        => $this->is_draft,
            'has_attachments' => $this->has_attachments,
            'attachments'     => $this->whenLoaded('attachments', fn () =>
                $this->attachments->map(fn ($a) => [
                    'id'        => $a->id,
                    'filename'  => $a->filename,
                    'mime_type' => $a->mime_type,
                    'size'      => $a->size,
                ])
            ),
            'date'            => $this->email_date?->toIso8601String(),
            'account_id'      => $this->email_account_id,
        ];
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function buildPlainText(): string
    {
        // Use stored body_text if it has meaningful content
        if (! empty($this->body_text) && strlen(trim($this->body_text)) > 5) {
            return trim($this->body_text);
        }

        // Auto-generate from HTML
        if (! empty($this->body_html)) {
            return $this->htmlToPlainText($this->body_html);
        }

        return '';
    }

    private function buildPreview(string $plainText): string
    {
        $text = preg_replace('/\s+/', ' ', $plainText);
        $text = trim($text);

        if (strlen($text) <= 200) {
            return $text;
        }

        return mb_substr($text, 0, 197) . '…';
    }

    private function htmlToPlainText(string $html): string
    {
        // Remove style and script blocks entirely
        $html = preg_replace('/<style[^>]*>.*?<\/style>/is', '', $html);
        $html = preg_replace('/<script[^>]*>.*?<\/script>/is', '', $html);

        // Convert common block elements to newlines
        $html = preg_replace('/<br\s*\/?>/i', "\n", $html);
        $html = preg_replace('/<\/?(p|div|tr|li|h[1-6]|blockquote)[^>]*>/i', "\n", $html);

        // Strip all remaining tags
        $text = strip_tags($html);

        // Decode HTML entities (&amp; &nbsp; etc.)
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Collapse runs of blank lines to max two
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }

    private function cleanHtml(string $html): string
    {
        // Strip <style> blocks — they are often huge and unnecessary for WebView
        $html = preg_replace('/<style[^>]*>.*?<\/style>/is', '', $html);
        // Strip <script> blocks
        $html = preg_replace('/<script[^>]*>.*?<\/script>/is', '', $html);

        return $html;
    }
}
