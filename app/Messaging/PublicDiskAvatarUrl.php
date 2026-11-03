<?php

namespace App\Messaging;

use Hypermed\Messaging\Contracts\AvatarUrlResolver;
use Illuminate\Support\Facades\Storage;

/** users.avatar_path is a path on the public disk, like UserResource's avatar_url. */
class PublicDiskAvatarUrl implements AvatarUrlResolver
{
    public function url(?string $stored): ?string
    {
        if ($stored === null || $stored === '') {
            return null;
        }

        return preg_match('#^https?://#i', $stored) ? $stored : Storage::disk('public')->url($stored);
    }
}
