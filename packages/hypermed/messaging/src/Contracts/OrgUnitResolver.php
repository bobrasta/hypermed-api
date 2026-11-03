<?php

namespace Hypermed\Messaging\Contracts;

interface OrgUnitResolver
{
    /**
     * User ids that belong to an org unit (department, branch...).
     *
     * @return array<int, int>
     */
    public function membersOf(string $orgUnitKey): array;

    /**
     * Org unit keys a user belongs to.
     *
     * @return array<int, string>
     */
    public function unitsFor(int $userId): array;
}
