<?php

namespace Hypermed\Messaging\Support;

use Hypermed\Messaging\Contracts\OrgUnitResolver;

class NullOrgUnitResolver implements OrgUnitResolver
{
    public function membersOf(string $orgUnitKey): array
    {
        return [];
    }

    public function unitsFor(int $userId): array
    {
        return [];
    }
}
