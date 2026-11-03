<?php

use Hypermed\Messaging\Events\ConversationRead;
use Hypermed\Messaging\Events\DeliveryReceiptUpdated;
use Hypermed\Messaging\Events\ReadReceiptUpdated;
use Illuminate\Support\Facades\Event;

it('advances the delivered pointer and broadcasts delivered.updated', function () {
    Event::fake([DeliveryReceiptUpdated::class]);
    [$me, $b] = msgUsers(2);
    $c = msgDirect($me, $b);
    $m1 = msgSend($c, $b, '1');
    $m2 = msgSend($c, $b, '2');
    actingAsMsg($me);

    $this->postJson(msgApi("conversations/{$c->id}/delivered"), ['last_delivered_message_id' => $m1->id])
        ->assertOk()
        ->assertJsonPath('data', ['conversation_id' => $c->id, 'user_id' => $me->id, 'last_delivered_message_id' => $m1->id]);
    $this->postJson(msgApi("conversations/{$c->id}/delivered"), ['last_delivered_message_id' => $m2->id])
        ->assertOk()->assertJsonPath('data.last_delivered_message_id', $m2->id);

    expect(msgParticipant($c, $me)->last_delivered_message_id)->toBe($m2->id)
        ->and(msgParticipant($c, $me)->last_read_message_id)->toBeNull(); // delivered does not imply read
    Event::assertDispatchedTimes(DeliveryReceiptUpdated::class, 2);
    Event::assertDispatched(DeliveryReceiptUpdated::class, fn ($e) => $e->broadcastOn()[0]->name === "private-messaging.conversation.{$c->id}"
        && $e->broadcastWith() === ['conversation_id' => $c->id, 'user_id' => $me->id, 'last_delivered_message_id' => $m2->id]);
});

it('never moves the delivered pointer backwards and does not broadcast a no-op', function () {
    Event::fake([DeliveryReceiptUpdated::class]);
    [$me, $b] = msgUsers(2);
    $c = msgDirect($me, $b);
    $m1 = msgSend($c, $b, '1');
    $m2 = msgSend($c, $b, '2');
    actingAsMsg($me);

    $this->postJson(msgApi("conversations/{$c->id}/delivered"), ['last_delivered_message_id' => $m2->id])->assertOk();
    $this->postJson(msgApi("conversations/{$c->id}/delivered"), ['last_delivered_message_id' => $m1->id])
        ->assertOk()->assertJsonPath('data.last_delivered_message_id', $m2->id);
    $this->postJson(msgApi("conversations/{$c->id}/delivered"), ['last_delivered_message_id' => $m2->id])
        ->assertOk()->assertJsonPath('data.last_delivered_message_id', $m2->id);

    expect(msgParticipant($c, $me)->last_delivered_message_id)->toBe($m2->id);
    Event::assertDispatchedTimes(DeliveryReceiptUpdated::class, 1);
});

it('clamps the delivered pointer to the newest message of the conversation', function () {
    Event::fake([DeliveryReceiptUpdated::class]);
    [$me, $b, $x] = msgUsers(3);
    $c = msgDirect($me, $b);
    $m = msgSend($c, $b, 'only');
    $elsewhere = msgSend(msgDirect($b, $x), $b, 'other conversation'); // higher id, other conversation
    actingAsMsg($me);

    $this->postJson(msgApi("conversations/{$c->id}/delivered"), ['last_delivered_message_id' => $elsewhere->id + 1000])
        ->assertOk()->assertJsonPath('data.last_delivered_message_id', $m->id);
    $this->postJson(msgApi("conversations/{$c->id}/delivered"), ['last_delivered_message_id' => $elsewhere->id + 2000])
        ->assertOk()->assertJsonPath('data.last_delivered_message_id', $m->id); // clamped again → no advance

    Event::assertDispatchedTimes(DeliveryReceiptUpdated::class, 1);
    Event::assertDispatched(DeliveryReceiptUpdated::class, fn ($e) => $e->lastDeliveredMessageId === $m->id);
});

it('returns a null pointer without broadcasting when the conversation has no messages', function () {
    Event::fake([DeliveryReceiptUpdated::class]);
    [$me, $b] = msgUsers(2);
    $c = msgDirect($me, $b);
    actingAsMsg($me);

    $this->postJson(msgApi("conversations/{$c->id}/delivered"), ['last_delivered_message_id' => 5])
        ->assertOk()
        ->assertJsonPath('data', ['conversation_id' => $c->id, 'user_id' => $me->id, 'last_delivered_message_id' => null]);

    Event::assertNotDispatched(DeliveryReceiptUpdated::class);
});

it('rejects non-participants with 403 not_participant', function () {
    Event::fake([DeliveryReceiptUpdated::class]);
    [$a, $b, $outsider] = msgUsers(3);
    $c = msgDirect($a, $b);
    $m = msgSend($c, $a, 'private');
    actingAsMsg($outsider);

    $this->postJson(msgApi("conversations/{$c->id}/delivered"), ['last_delivered_message_id' => $m->id])
        ->assertForbidden()->assertJsonPath('code', 'not_participant');

    Event::assertNotDispatched(DeliveryReceiptUpdated::class);
});

it('validates last_delivered_message_id', function (array $body) {
    [$me, $b] = msgUsers(2);
    $c = msgDirect($me, $b);
    msgSend($c, $b, 'x');
    actingAsMsg($me);

    $this->postJson(msgApi("conversations/{$c->id}/delivered"), $body)
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonValidationErrors('last_delivered_message_id');
})->with([
    'missing' => [[]],
    'not an integer' => [['last_delivered_message_id' => 'abc']],
    'zero' => [['last_delivered_message_id' => 0]],
    'negative' => [['last_delivered_message_id' => -3]],
]);

it('raises delivered to the read id when marking read, sending only read.updated', function () {
    Event::fake([DeliveryReceiptUpdated::class, ReadReceiptUpdated::class, ConversationRead::class]);
    [$me, $b] = msgUsers(2);
    $c = msgDirect($me, $b);
    $m1 = msgSend($c, $b, '1');
    $m2 = msgSend($c, $b, '2');
    $m3 = msgSend($c, $b, '3');
    actingAsMsg($me);

    $this->postJson(msgApi("conversations/{$c->id}/read"), ['last_read_message_id' => $m2->id])->assertOk();
    expect(msgParticipant($c, $me)->last_delivered_message_id)->toBe($m2->id);
    Event::assertDispatchedTimes(ReadReceiptUpdated::class, 1);
    Event::assertNotDispatched(DeliveryReceiptUpdated::class);

    // Delivering at or below read is a no-op.
    $this->postJson(msgApi("conversations/{$c->id}/delivered"), ['last_delivered_message_id' => $m1->id])
        ->assertOk()->assertJsonPath('data.last_delivered_message_id', $m2->id);
    Event::assertNotDispatched(DeliveryReceiptUpdated::class);

    // A read beyond delivered raises it again, still without delivered.updated.
    $this->postJson(msgApi("conversations/{$c->id}/read"), ['last_read_message_id' => $m3->id])->assertOk();
    expect(msgParticipant($c, $me)->last_delivered_message_id)->toBe($m3->id);
    Event::assertDispatchedTimes(ReadReceiptUpdated::class, 2);
    Event::assertNotDispatched(DeliveryReceiptUpdated::class);
});

it('keeps delivered when a lower read moves the read pointer', function () {
    [$me, $b] = msgUsers(2);
    $c = msgDirect($me, $b);
    $m1 = msgSend($c, $b, '1');
    $m2 = msgSend($c, $b, '2');
    actingAsMsg($me);

    $this->postJson(msgApi("conversations/{$c->id}/delivered"), ['last_delivered_message_id' => $m2->id])->assertOk();
    $this->postJson(msgApi("conversations/{$c->id}/read"), ['last_read_message_id' => $m1->id])->assertOk();

    $p = msgParticipant($c, $me);
    expect($p->last_read_message_id)->toBe($m1->id)->and($p->last_delivered_message_id)->toBe($m2->id);
});

it('exposes last_delivered_message_id on participants in list and detail', function () {
    [$me, $b, $d] = msgUsers(3);
    $c = msgDirect($me, $b);
    $m = msgSend($c, $me, 'hi');
    $group = msgGroup($me, [$b, $d], 'G');

    actingAsMsg($b);
    $this->postJson(msgApi("conversations/{$c->id}/delivered"), ['last_delivered_message_id' => $m->id])->assertOk();

    actingAsMsg($me);
    $list = $this->getJson(msgApi('conversations'))->assertOk()
        ->assertJsonStructure(['data' => [['participants' => [['id', 'role', 'last_read_message_id', 'last_delivered_message_id']]]]]);
    $direct = collect($list->json('data'))->firstWhere('id', $c->id);
    expect(collect($direct['participants'])->firstWhere('id', $b->id)['last_delivered_message_id'])->toBe($m->id)
        ->and(collect($direct['participants'])->firstWhere('id', $me->id)['last_delivered_message_id'])->toBe($m->id); // own send = read

    $detail = $this->getJson(msgApi("conversations/{$group->id}"))->assertOk();
    expect($detail->json('data.participants'))->toHaveCount(3);
    foreach ($detail->json('data.participants') as $p) {
        expect($p)->toHaveKey('last_delivered_message_id');
    }

    expect(collect($this->getJson(msgApi("conversations/{$c->id}"))->json('data.participants'))
        ->firstWhere('id', $b->id)['last_delivered_message_id'])->toBe($m->id);
});
