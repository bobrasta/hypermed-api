<?php

namespace Hypermed\Messaging\Contracts;

interface UserDirectory
{
    /**
     * Users matching a search term, excluding $excludeUserId.
     *
     * @return array<int, array{id: int, name: string, avatar_url: ?string}>
     */
    public function search(?string $term, int $limit, ?int $excludeUserId = null): array;
}
