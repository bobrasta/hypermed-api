<?php

namespace App\Support;

/**
 * While a Director fast-track runs (FastTrackService), the "you can't
 * approve your own request" checks step aside: the Director's own requests
 * are approved at once (user, 2026-11-04). Every other check still applies,
 * e.g. the person who initiated a payment still can't release it.
 */
final class DirectorFastTrack
{
    private static bool $active = false;

    public static function active(): bool
    {
        return self::$active;
    }

    /** @template T  @param callable(): T $fn  @return T */
    public static function run(callable $fn): mixed
    {
        $previous = self::$active;
        self::$active = true;
        try {
            return $fn();
        } finally {
            self::$active = $previous;
        }
    }
}
