<?php

namespace Hypermed\Messaging\Support;

use Illuminate\Support\Facades\DB;

/**
 * Dispatches socket events once the current transaction (if any) commits.
 * A broadcaster outage (Reverb down) is reported but never fails the request:
 * the data is already committed and clients catch up via `after=` on reconnect.
 */
final class Realtime
{
    public static function dispatch(object ...$events): void
    {
        foreach ($events as $event) {
            DB::afterCommit(static function () use ($event) {
                rescue(static fn () => event($event), null, true);
            });
        }
    }
}
