<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SyncedEmailResource;
use App\Jobs\SyncEmailsJob;
use App\Models\EmailAccount;
use App\Models\SyncedEmail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Mail\Message;
use Webklex\PHPIMAP\ClientManager;

class EmailController extends Controller
{
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

        SyncEmailsJob::dispatch($account->id, $folder, $limit);

        return response()->json([
            'data' => ['queued' => true, 'folder' => $folder],
        ], 202);
    }

    // ── List views ────────────────────────────────────────────────────────────

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

        return SyncedEmailResource::collection($query->paginate(30));
    }

    public function sent(Request $request)
    {
        $account = $this->resolveAccount($request, $request->integer('account_id') ?: null);

        $sentFolder = $request->input('folder', 'Sent');

        $emails = SyncedEmail::where('email_account_id', $account->id)
            ->where('folder', 'ilike', "%sent%")
            ->orderByDesc('email_date')
            ->paginate(30);

        return SyncedEmailResource::collection($emails);
    }

    public function drafts(Request $request)
    {
        $account = $this->resolveAccount($request, $request->integer('account_id') ?: null);

        $emails = SyncedEmail::where('email_account_id', $account->id)
            ->where('is_draft', true)
            ->orderByDesc('updated_at')
            ->paginate(30);

        return SyncedEmailResource::collection($emails);
    }

    public function folder(Request $request, string $folderName)
    {
        $account = $this->resolveAccount($request, $request->integer('account_id') ?: null);

        $emails = SyncedEmail::where('email_account_id', $account->id)
            ->where('folder', $folderName)
            ->orderByDesc('email_date')
            ->paginate(30);

        return SyncedEmailResource::collection($emails);
    }

    public function unreadCount(Request $request)
    {
        $account = $this->resolveAccount($request, $request->integer('account_id') ?: null);

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

        // Mark as read
        if (! $syncedEmail->is_read) {
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
        ]);

        $account = $this->resolveAccount($request, $data['account_id'] ?? null);

        $this->sendViaSMTP($account, $data);

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

        return response()->json(['data' => new SyncedEmailResource($sent)], 201);
    }

    private function sendViaSMTP(EmailAccount $account, array $data): void
    {
        config([
            'mail.mailers.smtp.host'       => $account->smtp_host,
            'mail.mailers.smtp.port'       => $account->smtp_port,
            'mail.mailers.smtp.encryption' => $account->smtp_encryption === 'none' ? null : $account->smtp_encryption,
            'mail.mailers.smtp.username'   => $account->username,
            'mail.mailers.smtp.password'   => $account->password,
            'mail.from.address'            => $account->from_email,
            'mail.from.name'               => $account->from_name ?? $account->username,
        ]);

        Mail::mailer('smtp')->send([], [], function (Message $msg) use ($data, $account) {
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
            if (! empty($data['in_reply_to'])) {
                $msg->getHeaders()->addTextHeader('In-Reply-To', $data['in_reply_to']);
                $msg->getHeaders()->addTextHeader('References', $data['in_reply_to']);
            }
        });
    }

    // ── Reply ─────────────────────────────────────────────────────────────────

    public function reply(Request $request, SyncedEmail $syncedEmail)
    {
        abort_if($syncedEmail->account->user_id !== $request->user()->id, 403);

        $data = $request->validate([
            'body'        => ['required', 'string'],
            'reply_all'   => ['nullable', 'boolean'],
        ]);

        $account = $syncedEmail->account;

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

        return response()->json(['data' => new SyncedEmailResource($sent)], 201);
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
        ]);

        $account = $syncedEmail->account;

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

        return response()->json(['data' => new SyncedEmailResource($sent)], 201);
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

        $syncedEmail->delete();

        return response()->json(null, 204);
    }
}
