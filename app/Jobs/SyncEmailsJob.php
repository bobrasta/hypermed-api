<?php

namespace App\Jobs;

use App\Models\EmailAccount;
use App\Models\SyncedEmail;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\IMAP;

// Runs synchronously (dispatch() call is instantiate-and-handle() directly, see
// EmailController::sync()) — this used to implement ShouldQueue, but nothing in this
// deployment ever runs `queue:work`, so jobs just piled up unprocessed and sync silently
// never happened. Each sync fetches up to $limit new messages, one at a time.
class SyncEmailsJob
{
    use Queueable;

    /** Messages above this come in headers-only (no body/attachments in memory). */
    private const MAX_BODY_BYTES = 15 * 1024 * 1024;

    public function __construct(
        public readonly int    $accountId,
        public readonly string $folder,
        public readonly int    $limit,
    ) {}

    /** Returns the count of newly-synced (non-duplicate) emails. */
    public function handle(): int
    {
        $syncedCount = 0;

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
        $box = $client->getFolder($this->folder);
        if (! $box) {
            throw new \RuntimeException("Folder {$this->folder} not found on the mail server");
        }
        $client->openFolder($box->path);
        $conn = $client->getConnection();

        // Pick the newest messages we don't have yet. The old all()->limit()->get()
        // took the OLDEST N and loaded them all (attachments included) at once — a
        // mailbox with big attachments (83 MB) ran PHP out of memory/time on every
        // sync, and any inbox over N messages never showed new mail.
        $known = SyncedEmail::where('email_account_id', $account->id)
            ->where('folder', $this->folder)->pluck('uid')
            ->mapWithKeys(fn ($u) => [(int) $u => true]);
        $uids = array_map('intval', (array) $conn->search(['ALL'], IMAP::ST_UID));
        $uids = array_values(array_filter($uids, fn ($u) => ! isset($known[$u])));
        rsort($uids);
        $uids = array_slice($uids, 0, $this->limit);
        $sizes = $uids ? (array) $conn->fetch('RFC822.SIZE', $uids, null, IMAP::ST_UID) : [];

        @set_time_limit(max(120, (int) ini_get('max_execution_time')));

        $mapAddresses = function ($attr) {
            if (! $attr) return [];
            return collect($attr->toArray())
                ->map(fn ($a) => ['email' => $a->mail ?? '', 'name' => $a->personal ?? ''])
                ->filter(fn ($a) => $a['email'] !== '')
                ->values()->toArray();
        };

        foreach ($uids as $uid) {
            // One message in memory at a time; very large ones come in headers-only.
            $tooBig = (int) ($sizes[$uid] ?? 0) > self::MAX_BODY_BYTES;
            // One unreadable message is skipped (and logged), not the whole sync.
            try {
                $msg = $box->query()->leaveUnread()->setFetchBody(! $tooBig)->getMessageByUid($uid);
                $this->store($account, $msg, $mapAddresses, $tooBig);
                $syncedCount++;
            } catch (\Throwable $e) {
                Log::warning("Email sync: skipped UID {$uid} for account {$account->id}: {$e->getMessage()}");
            }
            unset($msg);
        }

        $client->disconnect();

        $account->update(['last_synced_at' => now()]);

        return $syncedCount;
    }

    private function store(EmailAccount $account, $msg, callable $mapAddresses, bool $tooBig): void
    {
        $uid = (string) $msg->getUid();
        $bodyHtml = $tooBig ? null : ($msg->getHTMLBody() ?: null);
        $bodyText = $tooBig
            ? 'This message is too large to show here — open it in your mail client.'
            : ($msg->getTextBody() ?: null);

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
        try { $emailDate = $msg->getDate()?->toDate(); } catch (\Throwable) {}

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
            'has_attachments'  => $tooBig || $msg->hasAttachments(),
            'email_date'       => $emailDate,
        ]);

        if (! $tooBig && $msg->hasAttachments()) {
            foreach ($msg->getAttachments() as $att) {
                $email->attachments()->create([
                    'filename'  => $att->getName() ?? 'attachment',
                    'mime_type' => $att->getMimeType() ?? null,
                    'size'      => $att->getSize() ?? 0,
                ]);
            }
        }
    }
}
