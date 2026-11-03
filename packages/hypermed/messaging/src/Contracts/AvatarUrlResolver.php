<?php

namespace Hypermed\Messaging\Contracts;

interface AvatarUrlResolver
{
    /** Turns the stored avatar column value into an absolute URL (or null). */
    public function url(?string $stored): ?string;
}
