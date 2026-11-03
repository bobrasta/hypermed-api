<?php

use Hypermed\Messaging\Models\Message;
use Illuminate\Support\Facades\DB;

it('pages history backwards with `before` without gaps or duplicates', function () {
    [$a, $b] = msgUsers(2);
    $c = msgDirect($a, $b);
    foreach (range(1, 125) as $i) {
        msgSend($c, $i % 2 ? $a : $b, "m{$i}");
    }
    $all = Message::query()->where('conversation_id', $c->id)->orderByDesc('id')->pluck('id')->all();
    actingAsMsg($a);

    $seen = [];
    $before = null;
    $pages = 0;
    do {
        $res = $this->getJson(msgApi("conversations/{$c->id}/messages").'?limit=50'.($before ? "&before={$before}" : ''))->assertOk();
        $ids = array_column($res->json('data'), 'id');
        $seen = array_merge($seen, $ids);
        $before = end($ids);
        $pages++;
    } while ($res->json('meta.has_more') && $pages < 10);

    expect($seen)->toBe($all)->and($pages)->toBe(3)
        ->and(count($seen))->toBe(count(array_unique($seen)));
});

it('catches up with `after` in ascending order, including messages that arrive mid-sync', function () {
    [$a, $b] = msgUsers(2);
    $c = msgDirect($a, $b);
    foreach (range(1, 30) as $i) {
        msgSend($c, $b, "m{$i}");
    }
    $lastKnown = Message::query()->where('conversation_id', $c->id)->orderBy('id')->skip(9)->value('id');
    actingAsMsg($a);

    $seen = [];
    $after = $lastKnown;
    $pages = 0;
    do {
        $res = $this->getJson(msgApi("conversations/{$c->id}/messages")."?after={$after}&limit=7")->assertOk();
        $ids = array_column($res->json('data'), 'id');
        $sorted = $ids;
        sort($sorted);
        expect($ids)->toBe($sorted); // ascending
        $seen = array_merge($seen, $ids);
        $after = $ids ? end($ids) : $after;
        if ($pages === 1) {
            msgSend($c, $b, 'arrived while syncing');
        }
        $pages++;
    } while ($res->json('meta.has_more') && $pages < 20);

    $expected = Message::query()->where('conversation_id', $c->id)->where('id', '>', $lastKnown)->orderBy('id')->pluck('id')->all();
    expect($seen)->toBe($expected)->and(count($expected))->toBe(21);
});

it('returns newest first by default and validates cursor params', function () {
    [$a, $b] = msgUsers(2);
    $c = msgDirect($a, $b);
    $m1 = msgSend($c, $a, 'one');
    $m2 = msgSend($c, $a, 'two');
    actingAsMsg($a);

    $this->getJson(msgApi("conversations/{$c->id}/messages"))->assertOk()
        ->assertJsonPath('data.0.id', $m2->id)->assertJsonPath('data.1.id', $m1->id)->assertJsonPath('meta.has_more', false);
    $this->getJson(msgApi("conversations/{$c->id}/messages")."?before={$m2->id}&after={$m1->id}")->assertStatus(422);
    $this->getJson(msgApi("conversations/{$c->id}/messages").'?limit=101')->assertStatus(422);
});

it('loads history with a constant number of queries', function () {
    [$a, $b] = msgUsers(2);
    $c = msgDirect($a, $b);
    $first = msgSend($c, $a, 'root');
    foreach (range(1, 40) as $i) {
        msgSend($c, $i % 2 ? $a : $b, "m{$i}", ['reply_to_id' => $first->id]);
    }
    actingAsMsg($a);

    DB::enableQueryLog();
    $this->getJson(msgApi("conversations/{$c->id}/messages"))->assertOk();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    // participation check (participant + conversation) + messages + senders + attachments
    // + reply_to + reply senders = 7, independent of page size.
    expect($count)->toBeLessThanOrEqual(7);
});
