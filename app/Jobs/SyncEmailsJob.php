<?php

namespace App\Jobs;

use App\Models\EmailAccount;
use App\Models\SyncedEmail;
use App\Services\Email\EmailAttachmentStore;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Webklex\PHPIMAP\IMAP;

// Runs synchronously (dispatch() call is instantiate-and-handle() directly, see
// EmailController::sync()) — this used to implement ShouldQueue, but nothing in this
// deployment ever runs `queue:work`, so jobs just piled up unprocessed and sync silently
// never happened. Each sync fetches up to $limit new messages, one at a time, in
// full (the mail server caps messages at 30 MB), and keeps their attachments.
class SyncEmailsJob
{
    use Queueable;

    private const BUDGET_SECONDS = 15;

    public function __construct(
        public readonly int    $accountId,
        public string $folder,
        public readonly int    $limit,
    ) {}

    /** Stand-in folder name: the server's own Sent folder, whatever it's called (INBOX.Sent here). */
    public const SENT = '@sent';

    public static function sentFolderPath($client): ?string
    {
        foreach ($client->getFolders(false) as $f) {
            $leaf = strtolower(trim((string) last(explode($f->delimiter ?: '.', $f->path))));
            if (in_array($leaf, ['sent', 'sent items', 'sent messages', 'sent mail'], true)) {
                return $f->path;
            }
        }

        return null;
    }

    /** Returns the count of newly-synced (non-duplicate) emails. */
    public function handle(): int
    {
        $syncedCount = 0;

        $account = EmailAccount::findOrFail($this->accountId);

        $client = EmailAttachmentStore::client($account);
        $store  = app(EmailAttachmentStore::class);
        EmailAttachmentStore::raiseMemoryLimit();

        $client->connect();
        if ($this->folder === self::SENT) {
            $this->folder = self::sentFolderPath($client) ?? throw new \RuntimeException('No Sent folder on the mail server');
        }
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

        @set_time_limit(max(120, (int) ini_get('max_execution_time')));

        $mapAddresses = function ($attr) {
            if (! $attr) return [];
            return collect($attr->toArray())
                ->map(fn ($a) => ['email' => $a->mail ?? '', 'name' => $a->personal ?? ''])
                ->filter(fn ($a) => $a['email'] !== '')
                ->values()->toArray();
        };

        // Keep each call inside the app's request timeout: stop starting new
        // messages after ~15 s; the next sync carries on from there.
        $started = microtime(true);
        foreach ($uids as $uid) {
            if (microtime(true) - $started > self::BUDGET_SECONDS) {
                break;
            }
            // One message in memory at a time. One unreadable message is
            // skipped (and logged), not the whole sync.
            try {
                $msg = $box->query()->leaveUnread()->getMessageByUid($uid);
                // A failure part-way (e.g. disk) rolls the row back so the next sync retries it.
                DB::transaction(fn () => $this->store($account, $msg, $mapAddresses, $store));
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

    private function store(EmailAccount $account, $msg, callable $mapAddresses, EmailAttachmentStore $store): void
    {
        $uid = (string) $msg->getUid();
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
            'has_attachments'  => false,
            'email_date'       => $emailDate,
        ]);

        if ($msg->hasAttachments() && $store->storeFromMessage($email, $msg) > 0) {
            $email->update(['has_attachments' => true]);
        }
    }
}
