<?php

namespace Hypermed\Messaging\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $token
 * @property string $token_hash
 * @property string $platform
 * @property Carbon|null $last_seen_at
 */
class DeviceToken extends Model
{
    public const PLATFORMS = ['android', 'ios', 'windows', 'linux', 'macos'];

    protected $table = 'msg_device_tokens';

    protected $guarded = ['id'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'last_seen_at' => 'datetime',
        ];
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /** @param array<int,int> $userIds @return array<int, string> */
    public static function tokensFor(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        return static::query()->whereIn('user_id', $userIds)->pluck('token')->all();
    }
}
