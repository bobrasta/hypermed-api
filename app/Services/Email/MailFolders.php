<?php

namespace App\Services\Email;

use Illuminate\Database\Eloquent\Builder;

/**
 * The mailbox folders the apps show — Inbox, Sent, Spam, Trash, Archive —
 * whatever the mail server calls them (INBOX.Junk, Spam, Deleted Items…).
 *
 * Synced rows keep the server's own folder path, so lists match on the
 * folder's last segment; moves find the server folder by the same names and
 * create it under INBOX when the server has none.
 */
class MailFolders
{
    /** Last-segment names per kind, lower case. */
    public const NAMES = [
        'spam'    => ['junk', 'spam', 'junk e-mail', 'junk email', 'junk mail', 'bulk mail'],
        'trash'   => ['trash', 'deleted', 'deleted items', 'deleted messages', 'bin'],
        'archive' => ['archive', 'archives'],
        'sent'    => ['sent', 'sent items', 'sent messages', 'sent mail'],
    ];

    /** Folder created when the server has none of the names above. */
    private const CREATE_AS = ['spam' => 'Junk', 'trash' => 'Trash', 'archive' => 'Archive'];

    public static function leaf(?string $path): string
    {
        $parts = preg_split('#[./]#', (string) $path);

        return strtolower(trim((string) end($parts)));
    }

    /** 'inbox', 'sent', 'spam', 'trash', 'archive', or null for any other folder. */
    public static function kindOf(?string $folder): ?string
    {
        if (strcasecmp((string) $folder, 'INBOX') === 0) {
            return 'inbox';
        }
        $leaf = self::leaf($folder);
        foreach (self::NAMES as $kind => $names) {
            if (in_array($leaf, $names, true)) {
                return $kind;
            }
        }

        return null;
    }

    /** Limits a SyncedEmail query to one kind of folder (database-neutral LIKE). */
    public static function scope(Builder $query, string $kind): Builder
    {
        if ($kind === 'inbox') {
            return $query->whereRaw('UPPER(folder) = ?', ['INBOX']);
        }

        return $query->where(function ($q) use ($kind) {
            foreach (self::NAMES[$kind] as $name) {
                $q->orWhereRaw('LOWER(folder) = ?', [$name])
                  ->orWhereRaw('LOWER(folder) LIKE ?', ["%.{$name}"])
                  ->orWhereRaw('LOWER(folder) LIKE ?', ["%/{$name}"]);
            }
        });
    }

    /** Every folder except Spam and Trash — where Flagged looks. */
    public static function scopeNotBinned(Builder $query): Builder
    {
        return $query->whereNot(fn ($q) => self::scope($q, 'spam'))
                     ->whereNot(fn ($q) => self::scope($q, 'trash'));
    }

    /**
     * The server path for [kind] on a connected client; with [create], makes
     * the folder (under INBOX when the server nests folders there) if missing.
     */
    public static function resolve($client, string $kind, bool $create = false): ?string
    {
        if ($kind === 'inbox') {
            return 'INBOX';
        }
        $folders = $client->getFolders(false);
        foreach ($folders as $f) {
            if (self::kindOf($f->path) === $kind) {
                return $f->path;
            }
        }
        if (! $create || ! isset(self::CREATE_AS[$kind])) {
            return null;
        }

        // CyberPanel/Dovecot keeps everything under INBOX with "." (INBOX.Sent).
        $nested = null;
        foreach ($folders as $f) {
            if (stripos($f->path, 'INBOX') === 0 && strlen($f->path) > 5) {
                $nested = substr($f->path, 0, 6);
                break;
            }
        }
        $path = ($nested ?? '') . self::CREATE_AS[$kind];
        $client->createFolder($path, false);

        return $path;
    }
}
