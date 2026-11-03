<?php

namespace Hypermed\Messaging\Support;

use Hypermed\Messaging\Contracts\EntityLinkResolver;

/** Default: no entities are known and none may be shared. Hosts bind their own. */
class NullEntityLinkResolver implements EntityLinkResolver
{
    public function resolve(string $type, int $id): ?array
    {
        return null;
    }

    public function canView(int $userId, string $type, int $id): bool
    {
        return false;
    }
}
