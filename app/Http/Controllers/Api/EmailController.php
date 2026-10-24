<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SyncedEmailResource;
use App\Jobs\SyncEmailsJob;
use App\Models\EmailAccount;
use App\Models\SyncedEmail;
use App\Models\SyncedEmailAttachment;
use App\Services\Email\EmailAttachmentStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Mail;
use Illuminate\Mail\Message;
use Webklex\PHPIMAP\ClientManager;

class EmailController extends Controller
{
    public function __construct(private EmailAttachmentStore $files) {}

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function resolveAccount(Request $request, ?int $accountId): EmailAccount
    {
        $query = EmailAccount::where('user_id', $request->user()->id)->where('is_active', true);

        $account = $accountId
            ? $query->findOrFail($accountId)
            : $query->where('is_default', true)->firstOr(fn () => $query->firstOrFail());

        abort_if($account->user_id !== $request->user()->id, 403);

        return $account;
    }

    private function imapClient(EmailAccount $account)
    {
        $cm = new ClientManager();

        return $cm->make([
            'host'          => $account->imap_host,
            'port'          => $account->imap_port,
            'encryption'    => $account->imap_encryption,
            'validate_cert' => false,
            'username'      => $account->username,
            'password'      => $account->password,
            'protocol'      => 'imap',
        ]);
    }

    // ── Folders ───────────────────────────────────────────────────────────────

    public function folders(Request $request)
    {
        $account = $this->resolveAccount($request, $request->integer('account_id') ?: null);

        $client = $this->imapClient($account);
        $client->connect();
        $folders = $client->getFolders(false);
        $client->disconnect();

        $list = collect($folders)->map(fn ($f) => [
            'name'       => $f->full_name,
            'delimiter'  => $f->delimiter ?? '/',
            'attributes' => $f->attributes ?? [],
        ])->values();

        return response()->json(['data' => $list]);
    }

    // ── Sync (IMAP → local DB) ────────────────────────────────────────────────

    public function sync(Request $request)
    {
        $request->validate([
            'account_id' => ['nullable', 'integer'],
            'folder'     => ['nullable', 'string'],
            'limit'      => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $account = $this->resolveAccount($request, $request->integer('account_id') ?: null);
        $folder  = $request->input('folder', 'INBOX');
        $limit   = $request->integer('limit', 50);

        // The apps name their tabs: "sent" is the server's own Sent folder
        // (INBOX.Sent here — a literal "SENT" doesn't exist and failed), and
        // drafts live only in this system, so there's nothing to fetch.
        if (strcasecmp($folder, 'drafts') === 0) {
            return response()->json(['data' => ['synced' => 0, 'folder' => $folder, 'sent_synced' => 0]]);
        }
        if (strcasecmp($folder, 'sent') === 0) {
            $folder = SyncEmailsJob::SENT;
        }

        // Runs inline, not queued — see SyncEmailsJob's class doc comment for why.
        // Caught here (not left to bubble) because this used to fail silently inside a
        // queue worker that never ran; now that it's synchronous, an uncaught IMAP
        // exception would leak a full stack trace/server file paths into the response.
        try {
            $synced = (new SyncEmailsJob($account->id, $folder, $limit))->handle();
        } catch (\Exception $e) {
            return response()->json([
                'data' => ['synced' => 0, 'folder' => $folder, 'error' => $e->getMessage()],
            ], 422);
        }

        // Mail sent from a phone, webmail or Outlook sits in the server's Sent
        // folder (INBOX.Sent here), which was never fetched — only what was
        // sent from inside the app showed under Sent. An inbox sync also
        // fetches it, at most every 2 minutes per account (the apps poll every
        // 30 s). Reported apart, so sent mail never counts as new mail.
        $sentSynced = 0;
        if (strcasecmp($folder, 'INBOX') === 0 && \Illuminate\Support\Facades\Cache::add("email-sent-sync:{$account->id}", 1, 120)) {
            try {
                $sentSynced = (new SyncEmailsJob($account->id, SyncEmailsJob::SENT, min($limit, 50)))->handle();
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning("Sent folder sync failed for account {$account->id}: {$e->getMessage()}");
            }
        }

        return response()->json([
            'data' => ['synced' => $synced, 'folder' => $folder, 'sent_synced' => $sentSynced],
        ]);
    }

    // ── List views ────────────────────────────────────────────────────────────

    /** 100 a page by default (the apps load the next page as you scroll); was a fixed 30 the apps never paged past. */
    private function pageSize(Request $request): int
    {
        return max(1, min($request->integer('per_page', 100), 200));
    }

    public function inbox(Request $request)
    {
        $account = $this->resolveAccount($request, $request->integer('account_id') ?: null);

        $query = SyncedEmail::where('email_account_id', $account->id)
            ->where('folder', 'INBOX')
            ->where('is_draft', false)
            ->orderByDesc('email_date');

        if ($request->filled('unread')) {
            $query->where('is_read', false);
        }
        if ($request->filled('search')) {
            $term = $request->search;
            $query->where(fn ($q) => $q
                ->where('subject', 'ilike', "%$term%")
                ->orWhere('from_email', 'ilike', "%$term%")
                ->orWhere('from_name', 'ilike', "%$term%")
            );
        }

        return SyncedEmailResource::collection($query->paginate($this->pageSize($request)));
    }

    public function sent(Request $request)
    {
        $account = $this->resolveAccount($request, $request->integer('account_id') ?: null);

        $sentFolder = $request->input('folder', 'Sent');

        $emails = SyncedEmail::where('email_account_id', $account->id)
            ->where('folder', 'ilike', "%sent%")
            ->orderByDesc('email_date')
            ->paginate($this->pageSize($request));

        return SyncedEmailResource::collection($emails);
    }

    public function drafts(Request $request)
    {
        $account = $this->resolveAccount($request, $request->integer('account_id') ?: null);

        $emails = SyncedEmail::where('email_account_id', $account->id)
            ->where('is_draft', true)
            ->orderByDesc('updated_at')
            ->paginate($this->pageSize($request));

        return SyncedEmailResource::collection($emails);
    }

    public function folder(Request $request, string $folderName)
    {
        $account = $this->resolveAccount($request, $request->integer('account_id') ?: null);

        $emails = SyncedEmail::where('email_account_id', $account->id)
            ->where('folder', $folderName)
            ->orderByDesc('email_date')
            ->paginate($this->pageSize($request));

        return SyncedEmailResource::collection($emails);
    }

    public function unreadCount(Request $request)
    {
        try {
            $account = $this->resolveAccount($request, $request->integer('account_id') ?: null);
        } catch (\Throwable) {
            return response()->json(['data' => ['unread' => 0]]);
        }

        $count = SyncedEmail::where('email_account_id', $account->id)
            ->where('folder', 'INBOX')
            ->where('is_read', false)
            ->count();

        return response()->json(['data' => ['unread' => $count]]);
    }

    // ── Single email ──────────────────────────────────────────────────────────

    public function show(Request $request, SyncedEmail $syncedEmail)
    {
        abort_if($syncedEmail->account->user_id !== $request->user()->id, 403);

        $syncedEmail->load('attachments');
        // Emails synced before attachments were kept: fetch their files once.
        if ($syncedEmail->has_attachments) {
            $this->files->ensureStored($syncedEmail);
        }

        // Mark as read (mark_read=0: the app fetching attachments for an email
        // whose read state it toggles itself).
        if (! $syncedEmail->is_read && $request->boolean('mark_read', true)) {
            $syncedEmail->update(['is_read' => true]);
            $this->imapMarkRead($syncedEmail);
        }

        return response()->json(['data' => new SyncedEmailResource($syncedEmail)]);
    }

    private function imapMarkRead(SyncedEmail $syncedEmail): void
    {
        try {
            $account = $syncedEmail->account;
            $client  = $this->imapClient($account);
            $client->connect();
            $box = $client->getFolder($syncedEmail->folder);
            $msg = $box->query()->uid($syncedEmail->uid)->setFetchBody(false)->first();
            $msg?->setFlag('Seen');
            $client->disconnect();
        } catch (\Exception) {
            // Silent — don't fail the request if IMAP flag update fails
        }
    }

    // ── Compose / Send ────────────────────────────────────────────────────────

    public function compose(Request $request)
    {
        $data = $request->validate([
            'account_id'  => ['nullable', 'integer'],
            'to'          => ['required', 'array', 'min:1'],
            'to.*.email'  => ['required', 'email'],
            'to.*.name'   => ['nullable', 'string'],
            'cc'          => ['nullable', 'array'],
            'cc.*.email'  => ['required_with:cc', 'email'],
            'cc.*.name'   => ['nullable', 'string'],
            'bcc'         => ['nullable', 'array'],
            'bcc.*.email' => ['required_with:bcc', 'email'],
            'bcc.*.name'  => ['nullable', 'string'],
            'subject'     => ['required', 'string'],
            'body'        => ['required', 'string'],
            'in_reply_to' => ['nullable', 'string'],
            'attachments'   => ['nullable', 'array', 'max:20'],
            'attachments.*' => ['string'],
        ]);

        $account = $this->resolveAccount($request, $data['account_id'] ?? null);
        $files = $this->files->staged($request->user()->id, $data['attachments'] ?? []);
        EmailAttachmentStore::assertWithinLimit($files);

        $this->sendViaSMTP($account, $data + ['files' => $files]);

        // Store in sent folder locally
        $sent = SyncedEmail::create([
            'email_account_id' => $account->id,
            'uid'              => uniqid('sent_', true),
            'folder'           => 'Sent',
            'subject'          => $data['subject'],
            'from_email'       => $account->from_email,
            'from_name'        => $account->from_name,
            'to_addresses'     => $data['to'],
            'cc_addresses'     => $data['cc'] ?? null,
            'bcc_addresses'    => $data['bcc'] ?? null,
            'body_html'        => $data['body'],
            'body_text'        => strip_tags($data['body']),
            'in_reply_to'      => $data['in_reply_to'] ?? null,
            'is_read'          => true,
            'email_date'       => now(),
        ]);
        $this->files->keepOnSentCopy($sent, $files, $request->user()->id);

        return response()->json(['data' => new SyncedEmailResource($sent->load('attachments'))], 201);
    }

    private function sendViaSMTP(EmailAccount $account, array $data): void
    {
        // An on-demand mailer per account, not the shared 'smtp' mailer: on
        // the VPS that one carries MAIL_URL (local Postfix for notification
        // emails), and Laravel lets the URL override host/port — so every
        // compose went out through Postfix without the account's own login.
        $mailer = Mail::build([
            'transport' => 'smtp',
            // Laravel 12 reads `scheme`, not `encryption`: smtps = implicit
            // TLS (465); smtp upgrades with STARTTLS when the server offers it.
            'scheme'    => $account->smtp_encryption === 'ssl' ? 'smtps' : 'smtp',
            'host'      => $account->smtp_host,
            'port'      => $account->smtp_port,
            'username'  => $account->username,
            'password'  => $account->password,
            'auto_tls'  => $account->smtp_encryption !== 'none',
            // Left at the socket default (often 60s+) a slow/unreachable mail
            // server outlived the app's own 20s receive timeout.
            'timeout'   => 10,
        ]);

        try {
            $mailer->send([], [], function (Message $msg) use ($data, $account) {
                $msg->from($account->from_email, $account->from_name ?? '');
                $msg->subject($data['subject']);
                $msg->html($data['body']);

                foreach ($data['to'] as $recipient) {
                    $msg->to($recipient['email'], $recipient['name'] ?? null);
                }
                foreach ($data['cc'] ?? [] as $recipient) {
                    $msg->cc($recipient['email'], $recipient['name'] ?? null);
                }
                foreach ($data['bcc'] ?? [] as $recipient) {
                    $msg->bcc($recipient['email'], $recipient['name'] ?? null);
                }
                foreach ($data['files'] ?? [] as $f) {
                    $msg->attach(Storage::disk(EmailAttachmentStore::DISK)->path($f['path']), ['as' => $f['name'], 'mime' => $f['mime']]);
                }
                if (! empty($data['in_reply_to'])) {
                    $msg->getHeaders()->addTextHeader('In-Reply-To', $data['in_reply_to']);
                    $msg->getHeaders()->addTextHeader('References', $data['in_reply_to']);
                }
            });
        } catch (\Throwable $e) {
            $cause = $e;
            while ($cause->getPrevious()) {
                $cause = $cause->getPrevious();
            }
            $causeMsg = $cause->getMessage();

            $hint = match (true) {
                str_contains($causeMsg, 'Authentication'), str_contains($causeMsg, 'credentials')
                    => 'SMTP authentication failed — check the account password.',
                str_contains($causeMsg, 'timed out'), str_contains($causeMsg, 'timeout')
                    => 'Could not reach the mail server in time — check the SMTP host, port and encryption.',
                str_contains($causeMsg, 'refused')
                    => 'Connection refused — verify the SMTP host and port are correct.',
                default => 'Failed to send: ' . $causeMsg,
            };

            // detail carries the raw exception message alongside the hint —
            // same pattern as EmailAccountController@test's IMAP check —
            // since the categorized hint alone isn't enough to tell a
            // platform-level egress block from a genuine host/port/auth
            // problem.
            abort(response()->json([
                'message' => $hint,
                'detail'  => class_basename($cause) . ': ' . $causeMsg,
            ], 422));
        }
    }

    // ── Reply ─────────────────────────────────────────────────────────────────

    public function reply(Request $request, SyncedEmail $syncedEmail)
    {
        abort_if($syncedEmail->account->user_id !== $request->user()->id, 403);

        $data = $request->validate([
            'body'        => ['required', 'string'],
            'reply_all'   => ['nullable', 'boolean'],
            'attachments'   => ['nullable', 'array', 'max:20'],
            'attachments.*' => ['string'],
        ]);

        $account = $syncedEmail->account;
        $files = $this->files->staged($request->user()->id, $data['attachments'] ?? []);
        EmailAttachmentStore::assertWithinLimit($files);

        $to = [['email' => $syncedEmail->from_email, 'name' => $syncedEmail->from_name]];

        $cc = [];
        if ($request->boolean('reply_all')) {
            $cc = collect($syncedEmail->to_addresses ?? [])
                ->where('email', '!=', $account->from_email)
                ->merge($syncedEmail->cc_addresses ?? [])
                ->values()->toArray();
        }

        $subject = str_starts_with(strtolower($syncedEmail->subject ?? ''), 're:')
            ? $syncedEmail->subject
            : 'Re: ' . ($syncedEmail->subject ?? '');

        $quotedBody = '<br><br><blockquote style="border-left:2px solid #ccc;padding-left:10px;color:#555;">'
            . '<p>On ' . ($syncedEmail->email_date?->format('D, d M Y H:i') ?? '') . ', '
            . e($syncedEmail->from_name ?? $syncedEmail->from_email) . ' wrote:</p>'
            . $syncedEmail->body_html
            . '</blockquote>';

        $this->sendViaSMTP($account, [
            'to'          => $to,
            'cc'          => $cc,
            'subject'     => $subject,
            'body'        => $data['body'] . $quotedBody,
            'in_reply_to' => $syncedEmail->message_id,
            'files'       => $files,
        ]);

        $sent = SyncedEmail::create([
            'email_account_id' => $account->id,
            'uid'              => uniqid('sent_', true),
            'folder'           => 'Sent',
            'subject'          => $subject,
            'from_email'       => $account->from_email,
            'from_name'        => $account->from_name,
            'to_addresses'     => $to,
            'cc_addresses'     => $cc ?: null,
            'body_html'        => $data['body'] . $quotedBody,
            'body_text'        => strip_tags($data['body']),
            'in_reply_to'      => $syncedEmail->message_id,
            'is_read'          => true,
            'email_date'       => now(),
        ]);
        $this->files->keepOnSentCopy($sent, $files, $request->user()->id);

        return response()->json(['data' => new SyncedEmailResource($sent->load('attachments'))], 201);
    }

    // ── Forward ───────────────────────────────────────────────────────────────

    public function forward(Request $request, SyncedEmail $syncedEmail)
    {
        abort_if($syncedEmail->account->user_id !== $request->user()->id, 403);

        $data = $request->validate([
            'to'         => ['required', 'array', 'min:1'],
            'to.*.email' => ['required', 'email'],
            'to.*.name'  => ['nullable', 'string'],
            'body'       => ['nullable', 'string'],
            'attachments'   => ['nullable', 'array', 'max:20'],
            'attachments.*' => ['string'],
            // The original email's attachments go along unless switched off.
            'include_attachments' => ['nullable', 'boolean'],
        ]);

        $account = $syncedEmail->account;
        $files = array_merge(
            $request->boolean('include_attachments', true) ? $this->files->forwardable($syncedEmail->load('attachments')) : [],
            $this->files->staged($request->user()->id, $data['attachments'] ?? []),
        );
        EmailAttachmentStore::assertWithinLimit($files);

        $subject = str_starts_with(strtolower($syncedEmail->subject ?? ''), 'fwd:')
            ? $syncedEmail->subject
            : 'Fwd: ' . ($syncedEmail->subject ?? '');

        $fwdBody = ($data['body'] ?? '') . '<br><br>'
            . '<p>---------- Forwarded message ----------</p>'
            . '<p><strong>From:</strong> ' . e($syncedEmail->from_name ?? '') . ' &lt;' . e($syncedEmail->from_email) . '&gt;</p>'
            . '<p><strong>Date:</strong> ' . ($syncedEmail->email_date?->format('D, d M Y H:i') ?? '') . '</p>'
            . '<p><strong>Subject:</strong> ' . e($syncedEmail->subject ?? '') . '</p>'
            . '<br>'
            . $syncedEmail->body_html;

        $this->sendViaSMTP($account, [
            'to'      => $data['to'],
            'subject' => $subject,
            'body'    => $fwdBody,
            'files'   => $files,
        ]);

        $sent = SyncedEmail::create([
            'email_account_id' => $account->id,
            'uid'              => uniqid('sent_', true),
            'folder'           => 'Sent',
            'subject'          => $subject,
            'from_email'       => $account->from_email,
            'from_name'        => $account->from_name,
            'to_addresses'     => $data['to'],
            'body_html'        => $fwdBody,
            'is_read'          => true,
            'email_date'       => now(),
        ]);
        $this->files->keepOnSentCopy($sent, $files, $request->user()->id);

        return response()->json(['data' => new SyncedEmailResource($sent->load('attachments'))], 201);
    }

    // ── Mark read / flag ──────────────────────────────────────────────────────

    public function markRead(Request $request, SyncedEmail $syncedEmail)
    {
        abort_if($syncedEmail->account->user_id !== $request->user()->id, 403);

        $syncedEmail->update(['is_read' => ! $syncedEmail->is_read]);

        return response()->json(['data' => ['id' => $syncedEmail->id, 'is_read' => $syncedEmail->is_read]]);
    }

    public function flag(Request $request, SyncedEmail $syncedEmail)
    {
        abort_if($syncedEmail->account->user_id !== $request->user()->id, 403);

        $syncedEmail->update(['is_flagged' => ! $syncedEmail->is_flagged]);

        return response()->json(['data' => ['id' => $syncedEmail->id, 'is_flagged' => $syncedEmail->is_flagged]]);
    }

    // ── Trash ─────────────────────────────────────────────────────────────────

    public function destroy(Request $request, SyncedEmail $syncedEmail)
    {
        abort_if($syncedEmail->account->user_id !== $request->user()->id, 403);

        try {
            $account = $syncedEmail->account;
            $client  = $this->imapClient($account);
            $client->connect();
            $box = $client->getFolder($syncedEmail->folder);
            $msg = $box->query()->uid($syncedEmail->uid)->setFetchBody(false)->first();
            $msg?->delete();
            $client->expunge();
            $client->disconnect();
        } catch (\Exception) {
            // Continue even if IMAP delete fails — remove from local DB
        }

        $this->files->deleteFor($syncedEmail);
        $syncedEmail->delete();

        return response()->json(null, 204);
    }

    // ── Attachments ───────────────────────────────────────────────────────────

    /** One file picked in compose/reply/forward; sent later by its token. */
    public function uploadAttachment(Request $request)
    {
        $request->validate(['file' => ['required', 'file', 'max:20480']]);

        return response()->json(['data' => $this->files->stage($request->user()->id, $request->file('file'))], 201);
    }

    public function downloadAttachment(Request $request, SyncedEmail $syncedEmail, SyncedEmailAttachment $attachment)
    {
        abort_if($syncedEmail->account->user_id !== $request->user()->id, 403);
        abort_if($attachment->synced_email_id !== $syncedEmail->id, 404);

        $disk = Storage::disk(EmailAttachmentStore::DISK);
        if (! $attachment->storage_path || ! $disk->exists($attachment->storage_path)) {
            abort(404, 'This attachment is no longer on the mail server.');
        }

        return $disk->download($attachment->storage_path, $attachment->filename, [
            'Content-Type' => $attachment->mime_type ?: 'application/octet-stream',
        ]);
    }
}
