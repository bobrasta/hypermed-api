<?php

namespace Hypermed\Messaging\Contracts;

interface EntityLinkResolver
{
    /**
     * Card data for a host entity referenced from a message, or null if unknown.
     *
     * @return array{title: string, subtitle: ?string, url: ?string, status: ?string}|null
     */
    public function resolve(string $type, int $id): ?array;

    /** Whether the user may see (and therefore share) the entity. */
    public function canView(int $userId, string $type, int $id): bool;
}
