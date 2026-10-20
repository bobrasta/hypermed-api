<?php

namespace App\Services\Email;

use App\Models\EmailAccount;
use App\Models\SyncedEmail;
use App\Models\SyncedEmailAttachment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Message;

/**
 * Email attachments kept on the server (private `local` disk), so users open,
 * save and forward them inside Hypermed instead of another mail client.
 *
 * - Sync stores every attachment as it reads each message.
 * - Emails synced before that only have names; ensureStored() fetches the
 *   message from the mail server once and stores its files.
 * - Files picked in compose/reply are uploaded first (one per request, to
 *   stay under the PHP upload limit) and referenced by token when sending.
 */
class EmailAttachmentStore
{
    public const DISK = 'local';

    /** Inline images below this are signature logos/icons, not content. */
    private const MIN_INLINE_IMAGE_BYTES = 20 * 1024;

    /** Outgoing total: the mail server takes 30 MB, base64 adds about a third. */
    public const MAX_OUTGOING_BYTES = 22 * 1024 * 1024;

    /** The mail server rejects anything over 30 MB, so 1 GB covers parsing any message. */
    public static function raiseMemoryLimit(): void
    {
        $current = ini_get('memory_limit');
        if ($current !== '-1' && self::bytes($current) < 1024 * 1024 * 1024) {
            @ini_set('memory_limit', '1024M');
        }
    }

    private static function bytes(string $v): int
    {
        $n = (int) $v;

        return match (strtoupper(substr(trim($v), -1))) {
            'G' => $n * 1024 ** 3, 'M' => $n * 1024 ** 2, 'K' => $n * 1024, default => $n,
        };
    }

    public static function client(EmailAccount $account)
    {
        return (new ClientManager())->make([
            'host'          => $account->imap_host,
            'port'          => $account->imap_port,
            'encryption'    => $account->imap_encryption,
            'validate_cert' => false,
            'username'      => $account->username,
            'password'      => $account->password,
            'protocol'      => 'imap',
        ]);
    }

    /** Stores the message's attachments for $email; returns how many. */
    public function storeFromMessage(SyncedEmail $email, Message $msg): int
    {
        $n = 0;
        foreach ($msg->getAttachments() as $att) {
            $content = (string) $att->getContent();
            $size = strlen($content);
            // The type the sender declared; sniffing the bytes is only a fallback.
            $mime = strtolower(trim(explode(';', (string) $att->content_type)[0])) ?: ($att->getMimeType() ?: 'application/octet-stream');
            $inline = strtolower((string) $att->disposition) === 'inline' || $att->id;
            $isImage = str_starts_with($mime, 'image/');
            if ($inline && $isImage && $size < self::MIN_INLINE_IMAGE_BYTES) {
                continue;
            }
            $name = $this->safeName($att->getName() ?: ($isImage ? 'image' : 'attachment'), $att->getExtension());
            $path = sprintf('email-attachments/%d/%d/%d-%s', $email->email_account_id, $email->id, ++$n, $name);
            Storage::disk(self::DISK)->put($path, $content);
            $email->attachments()->create([
                'filename'     => $name,
                'mime_type'    => $mime,
                'size'         => $size,
                'storage_path' => $path,
            ]);
        }

        return $n;
    }

    /**
     * Emails synced before files were kept: fetch the message once and store
     * its attachments (replacing the name-only rows). Returns false when the
     * message is no longer on the mail server.
     */
    public function ensureStored(SyncedEmail $email): bool
    {
        $missing = $email->attachments->contains(fn ($a) => ! $a->storage_path || ! Storage::disk(self::DISK)->exists($a->storage_path));
        if (! $missing) {
            return true;
        }
        // Copies of mail sent from Hypermed have no IMAP UID to fetch from.
        if (! ctype_digit((string) $email->uid)) {
            return false;
        }

        self::raiseMemoryLimit();
        $client = self::client($email->account);
        try {
            $client->connect();
            $box = $client->getFolder($email->folder);
            $msg = $box?->query()->leaveUnread()->getMessageByUid((int) $email->uid);
            if (! $msg) {
                return false;
            }
            foreach ($email->attachments as $old) {
                if ($old->storage_path) {
                    Storage::disk(self::DISK)->delete($old->storage_path);
                }
                $old->delete();
            }
            $this->storeFromMessage($email, $msg);
            $email->update(['has_attachments' => $email->attachments()->exists()]);
        } catch (\Throwable) {
            return false;
        } finally {
            try { $client->disconnect(); } catch (\Throwable) {}
        }
        $email->load('attachments');

        return true;
    }

    // ------------------------------------------------- outgoing (compose/reply)

    /** Keeps an uploaded file for a later send; returns its token. */
    public function stage(int $userId, UploadedFile $file): array
    {
        $token = (string) Str::uuid();
        $name = $this->safeName($file->getClientOriginalName(), $file->getClientOriginalExtension());
        Storage::disk(self::DISK)->putFileAs("email-uploads/{$userId}/{$token}", $file, $name);

        return ['token' => $token, 'filename' => $name, 'size' => $file->getSize(), 'mime_type' => $file->getMimeType()];
    }

    /**
     * The caller's staged files for $tokens as [path, name, mime, size].
     * Unknown tokens (or someone else's) are a 422.
     */
    public function staged(int $userId, array $tokens): array
    {
        $files = [];
        foreach (array_unique($tokens) as $token) {
            abort_unless(is_string($token) && Str::isUuid($token), 422, 'Unknown attachment.');
            $found = Storage::disk(self::DISK)->files("email-uploads/{$userId}/{$token}");
            abort_if($found === [], 422, 'An attachment expired — attach it again.');
            $path = $found[0];
            $files[] = [
                'path' => $path,
                'name' => basename($path),
                'mime' => Storage::disk(self::DISK)->mimeType($path) ?: 'application/octet-stream',
                'size' => Storage::disk(self::DISK)->size($path),
            ];
        }

        return $files;
    }

    /** A received email's stored attachments in the same shape, for forwarding. */
    public function forwardable(SyncedEmail $email): array
    {
        $this->ensureStored($email);

        return $email->attachments->filter(fn ($a) => $a->storage_path && Storage::disk(self::DISK)->exists($a->storage_path))
            ->map(fn ($a) => ['path' => $a->storage_path, 'name' => $a->filename, 'mime' => $a->mime_type, 'size' => (int) $a->size])
            ->values()->all();
    }

    public static function assertWithinLimit(array $files): void
    {
        $total = array_sum(array_column($files, 'size'));
        abort_if($total > self::MAX_OUTGOING_BYTES, 422,
            'Attachments total ' . round($total / 1048576, 1) . ' MB — the limit is 22 MB per email.');
    }

    /** Copies sent files onto the Sent copy so it shows them too; drops staged uploads. */
    public function keepOnSentCopy(SyncedEmail $sent, array $files, int $userId): void
    {
        $n = 0;
        foreach ($files as $f) {
            $path = sprintf('email-attachments/%d/%d/%d-%s', $sent->email_account_id, $sent->id, ++$n, $f['name']);
            Storage::disk(self::DISK)->copy($f['path'], $path);
            $sent->attachments()->create(['filename' => $f['name'], 'mime_type' => $f['mime'], 'size' => $f['size'], 'storage_path' => $path]);
        }
        if ($files) {
            $sent->update(['has_attachments' => true]);
        }
        foreach ($files as $f) {
            if (str_starts_with($f['path'], "email-uploads/{$userId}/")) {
                Storage::disk(self::DISK)->deleteDirectory(dirname($f['path']));
            }
        }
    }

    public function deleteFor(SyncedEmail $email): void
    {
        Storage::disk(self::DISK)->deleteDirectory("email-attachments/{$email->email_account_id}/{$email->id}");
    }

    private function safeName(string $name, ?string $ext = null): string
    {
        $name = trim(preg_replace('/[\x00-\x1F\/\\\\:*?"<>|]+/', '_', $name), " ._");
        if ($name === '') {
            $name = 'attachment';
        }
        if ($ext && ! str_contains($name, '.')) {
            $name .= '.' . $ext;
        }

        return mb_substr($name, 0, 150);
    }
}
