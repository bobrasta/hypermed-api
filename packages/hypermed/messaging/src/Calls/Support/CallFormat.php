<?php

namespace Hypermed\Messaging\Calls\Support;

use DateTimeInterface;
use Hypermed\Messaging\Support\UserSummary;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Small formatting helpers shared by the calls resources, events and system messages.
 */
final class CallFormat
{
    /** ISO-8601 UTC string (contract: "Timestamps ISO-8601 UTC strings"). */
    public static function iso(?DateTimeInterface $value): ?string
    {
        return $value ? Carbon::instance($value)->utc()->toIso8601ZuluString() : null;
    }

    public static function userName(?Model $user): string
    {
        if (! $user) {
            return '';
        }

        return UserSummary::make($user)['name'];
    }

    /** "12:34" or "1:02:03". */
    public static function duration(int $seconds): string
    {
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;

        return $h > 0 ? sprintf('%d:%02d:%02d', $h, $m, $s) : sprintf('%d:%02d', $m, $s);
    }

    /** Human label used in system messages: "Video call" / "Voice call". */
    public static function kind(string $type): string
    {
        return $type === 'video' ? 'video call' : 'voice call';
    }
}
