<?php

namespace Hypermed\Messaging\Support;

use Illuminate\Database\Eloquent\Model;

final class UserSummary
{
    /** @return array{id: int, name: string, avatar_url: ?string} */
    public static function make(Model $user): array
    {
        $avatarCol = MessagingConfig::avatarColumn();

        return self::fromValues(
            (int) $user->getKey(),
            $user->getAttribute(MessagingConfig::nameColumn()),
            $avatarCol ? $user->getAttribute($avatarCol) : null,
        );
    }

    /** @return array{id: int, name: string, avatar_url: ?string} */
    public static function fromValues(int $id, mixed $name, mixed $avatar): array
    {
        return [
            'id' => $id,
            'name' => (string) ($name ?? ''),
            'avatar_url' => $avatar !== null && $avatar !== '' ? (string) $avatar : null,
        ];
    }

    /** Placeholder for a user row that no longer exists. */
    public static function unknown(int $id): array
    {
        return self::fromValues($id, 'Unknown user', null);
    }
}
