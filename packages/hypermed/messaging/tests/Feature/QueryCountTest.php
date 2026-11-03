<?php

use Hypermed\Messaging\Models\Attachment;
use Hypermed\Messaging\Models\Message;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Conversation list must be <= 3 queries regardless of size:
 *  1. conversations + my participant row + last message (+ sender, reply-to) + counts
 *  2. participants + user summaries (window-limited for groups)
 *  3. attachments of file-type last messages (only when present)
 */
function buildBusyInbox(int $directs, int $groups): Model
{
    $me = msgUser();
    $others = msgUsers(max($directs, 8));

    foreach (array_slice($others, 0, $directs) as $i => $other) {
        $c = msgDirect($me, $other);
        $first = msgSend($c, $other, 'first '.$i);
        // Mix of last-message kinds: reply, file with attachment, entity ref, deleted.
        match ($i % 4) {
            0 => msgSend($c, $me, 'reply '.$i, ['reply_to_id' => $first->id]),
            1 => tap(msgSend($c, $other, 'file', ['type' => Message::TYPE_FILE]), function ($m) {
                Attachment::query()->create(['message_id' => $m->id, 'disk' => 'local', 'path' => 'x/'.Str::uuid().'.png',
                    'mime' => 'image/png', 'size' => 10, 'original_name' => 'a.png', 'thumb_path' => 'x/t.png']);
            }),
            2 => msgSend($c, $other, 'see ticket', ['type' => Message::TYPE_ENTITY_REF, 'meta' => ['entity_type' => 'ticket', 'entity_id' => 7]]),
            3 => tap(msgSend($c, $other, 'oops'), fn ($m) => $m->forceFill(['deleted_at' => now()])->save()),
        };
    }
    for ($g = 0; $g < $groups; $g++) {
        $c = msgGroup($me, array_slice($others, 0, 8), 'Group '.$g);
        msgSend($c, $others[$g % 8], 'group msg');
    }

    return $me;
}

function countListQueries(Model $me, string $query = ''): array
{
    actingAsMsg($me);
    DB::flushQueryLog();
    DB::enableQueryLog();
    $res = test()->getJson(msgApi('conversations').$query)->assertOk();
    $log = DB::getQueryLog();
    DB::disableQueryLog();

    return [count($log), $res, $log];
}

it('lists conversations in at most 3 queries (small inbox)', function () {
    $me = buildBusyInbox(4, 1);
    [$count, $res] = countListQueries($me);

    expect($res->json('data'))->toHaveCount(5)->and($count)->toBeLessThanOrEqual(3);
});

it('lists conversations in at most 3 queries regardless of size', function () {
    $me = buildBusyInbox(24, 6);
    [$count, $res, $log] = countListQueries($me, '?limit=50');

    expect($res->json('data'))->toHaveCount(30)
        ->and($count)->toBeLessThanOrEqual(3, 'Queries: '.json_encode(array_column($log, 'query'), JSON_PRETTY_PRINT));

    // Everything the client needs is present without extra requests.
    $items = collect($res->json('data'));
    expect($items->pluck('last_message')->filter()->count())->toBe(30)
        ->and($items->firstWhere('last_message.type', 'file')['last_message']['attachments'][0]['thumb_url'])->toBeString()
        ->and($items->firstWhere('last_message.type', 'entity_ref')['last_message']['entity']['title'])->toBe('Ticket #7')
        ->and($items->firstWhere('last_message.is_deleted', true)['last_message']['body'])->toBeNull()
        ->and($items->first(fn ($i) => $i['last_message']['reply_to'] !== null)['last_message']['reply_to']['body'])->toStartWith('first');
});

it('uses 2 queries when no last message has attachments, and the detail view stays within 3', function () {
    $me = msgUser();
    foreach (msgUsers(10) as $o) {
        msgSend(msgDirect($me, $o), $o, 'hi');
    }
    [$count] = countListQueries($me);
    expect($count)->toBe(2);

    $group = msgGroup($me, msgUsers(30));
    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->getJson(msgApi("conversations/{$group->id}"))->assertOk()->assertJsonCount(31, 'data.participants');
    $detailQueries = count(DB::getQueryLog());
    DB::disableQueryLog();

    // +1 for the participation middleware check.
    expect($detailQueries)->toBeLessThanOrEqual(4);
});
