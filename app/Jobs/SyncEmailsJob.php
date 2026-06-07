<?php

namespace App\Jobs;

use App\Models\EmailAccount;
use App\Models\SyncedEmail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Webklex\PHPIMAP\ClientManager;

class SyncEmailsJob implements ShouldQueue
{
    use Queueable;

    public int $tries   = 2;
    public int $timeout = 120;

    public function __construct(
        public readonly int    $accountId,
        public readonly string $folder,
        public readonly int    $limit,
    ) {}

    public function handle(): void
    {
        $account = EmailAccount::findOrFail($this->accountId);

        $cm     = new ClientManager();
        $client = $cm->make([
            'host'          => $account->imap_host,
            'port'          => $account->imap_port,
            'encryption'    => $account->imap_encryption,
            'validate_cert' => false,
            'username'      => $account->username,
            'password'      => $account->password,
            'protocol'      => 'imap',
        ]);

        $client->connect();
        $box      = $client->getFolder($this->folder);
        $messages = $box->messages()->all()->leaveUnread()->limit($this->limit)->get();

        $mapAddresses = function ($attr) {
            if (! $attr) return [];
            return collect($attr->toArray())
                ->map(fn ($a) => ['email' => $a->mail ?? '', 'name' => $a->personal ?? ''])
                ->filter(fn ($a) => $a['email'] !== '')
                ->values()->toArray();
        };

        foreach ($messages as $msg) {
            $uid = (string) $msg->getUid();

            if (SyncedEmail::where('email_account_id', $account->id)
                ->where('uid', $uid)->where('folder', $this->folder)->exists()) {
                continue;
            }

            $bodyHtml = $msg->getHTMLBody() ?: null;
            $bodyText = $msg->getTextBody() ?: null;

            if (empty($bodyText) && ! empty($bodyHtml)) {
                $stripped = preg_replace('/<style[^>]*>.*?<\/style>/is', '', $bodyHtml);
                $stripped = preg_replace('/<script[^>]*>.*?<\/script>/is', '', $stripped);
                $stripped = preg_replace('/<br\s*\/?>/i', "\n", $stripped);
                $stripped = preg_replace('/<\/?(p|div|tr|li|h[1-6]|blockquote)[^>]*>/i', "\n", $stripped);
                $bodyText = trim(html_entity_decode(strip_tags($stripped), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            }

            $flags     = $msg->getFlags();
            $fromAttr  = $msg->getFrom()->first();
            $emailDate = null;
            try { $emailDate = $msg->getDate()->toDate(); } catch (\Exception) {}

            $email = SyncedEmail::create([
                'email_account_id' => $account->id,
                'uid'              => $uid,
                'message_id'       => (string) $msg->getMessageId() ?: null,
                'folder'           => $this->folder,
                'subject'          => (string) $msg->getSubject() ?: null,
                'from_email'       => $fromAttr?->mail ?? '',
                'from_name'        => $fromAttr?->personal ?? null,
                'to_addresses'     => $mapAddresses($msg->getTo()),
                'cc_addresses'     => $mapAddresses($msg->getCc()) ?: null,
                'bcc_addresses'    => $mapAddresses($msg->getBcc()) ?: null,
                'body_html'        => $bodyHtml,
                'body_text'        => $bodyText ?: null,
                'in_reply_to'      => (string) $msg->getInReplyTo() ?: null,
                'is_read'          => $flags->contains('Seen')    || $flags->contains('\Seen'),
                'is_flagged'       => $flags->contains('Flagged') || $flags->contains('\Flagged'),
                'is_draft'         => $flags->contains('Draft')   || $flags->contains('\Draft'),
                'has_attachments'  => $msg->hasAttachments(),
                'email_date'       => $emailDate,
            ]);

            if ($msg->hasAttachments()) {
                foreach ($msg->getAttachments() as $att) {
                    $email->attachments()->create([
                        'filename'  => $att->getName() ?? 'attachment',
                        'mime_type' => $att->getMimeType() ?? null,
                        'size'      => $att->getSize() ?? 0,
                    ]);
                }
            }
        }

        $client->disconnect();

        $account->update(['last_synced_at' => now()]);
    }
}
