<?php

namespace Hypermed\Messaging\Support;

use Hypermed\Messaging\Contracts\AvatarUrlResolver;

/** The avatar column already holds an absolute URL. */
class PassthroughAvatarUrlResolver implements AvatarUrlResolver
{
    public function url(?string $stored): ?string
    {
        return $stored !== null && $stored !== '' ? $stored : null;
    }
}
