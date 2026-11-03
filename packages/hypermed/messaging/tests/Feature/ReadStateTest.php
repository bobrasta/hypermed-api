<?php

use Hypermed\Messaging\Services\MessagingService;

it('counts unread correctly (others\' live messages after my pointer, incl. system)', function () {
    [$me, $b, $c] = msgUsers(3);
    $group = msgGroup($b, [$me, $c]);           // 1 system message (unread for me)
    msgSend($group, $b, 'one');
    msgSend($group, $c, 'two');
    msgSend($group, $me, 'mine');               // own messages advance my pointer
    $x = msgSend($group, $b, 'three');
    $y = msgSend($group, $c, 'four');
    $y->forceFill(['deleted_at' => now()])->save(); // deleted messages don't count
    app(MessagingService::class)->sendSystemMessage($group->id, 'System notice');

    actingAsMsg($me);
    $this->getJson(msgApi('conversations'))->assertOk()->assertJsonPath('data.0.unread_count', 2);
    $this->getJson(msgApi('unread-summary'))->assertOk()
        ->assertJsonPath('data.total', 2)
        ->assertJsonPath('data.conversations', [['conversation_id' => $group->id, 'unread_count' => 2]]);

    $this->postJson(msgApi("conversations/{$group->id}/read"), ['last_read_message_id' => $x->id])
        ->assertOk()
        ->assertJsonPath('data', ['conversation_id' => $group->id, 'last_read_message_id' => $x->id, 'unread_count' => 1]);

    actingAsMsg($b);
    // b's pointer sits on its own "three"; after it: "four" (deleted, ignored) + system notice = 1.
    $this->getJson(msgApi('unread-summary'))->assertJsonPath('data.total', 1);
});

it('never moves the read pointer backwards', function () {
    [$me, $b] = msgUsers(2);
    $c = msgDirect($me, $b);
    $m1 = msgSend($c, $b, '1');
    $m2 = msgSend($c, $b, '2');
    $m3 = msgSend($c, $b, '3');
    actingAsMsg($me);

    $this->postJson(msgApi("conversations/{$c->id}/read"), ['last_read_message_id' => $m3->id])
        ->assertOk()->assertJsonPath('data.last_read_message_id', $m3->id)->assertJsonPath('data.unread_count', 0);
    $this->postJson(msgApi("conversations/{$c->id}/read"), ['last_read_message_id' => $m1->id])
        ->assertOk()->assertJsonPath('data.last_read_message_id', $m3->id)->assertJsonPath('data.unread_count', 0);

    expect(msgParticipant($c, $me)->last_read_message_id)->toBe($m3->id);
});

it('clamps the read pointer to the newest message of the conversation', function () {
    [$me, $b] = msgUsers(2);
    $c = msgDirect($me, $b);
    $m = msgSend($c, $b, 'only');
    actingAsMsg($me);

    $this->postJson(msgApi("conversations/{$c->id}/read"), ['last_read_message_id' => $m->id + 1000])
        ->assertOk()->assertJsonPath('data.last_read_message_id', $m->id);

    $this->postJson(msgApi("conversations/{$c->id}/read"), [])->assertStatus(422)->assertJsonPath('code', 'validation_failed');
});

it('summarises unread across conversations and omits zero counts', function () {
    [$me, $b, $c] = msgUsers(3);
    $d1 = msgDirect($me, $b);
    $d2 = msgDirect($me, $c);
    msgDirect($b, $c);
    msgSend($d1, $b, 'x');
    msgSend($d1, $b, 'y');
    msgSend($d2, $c, 'z');
    msgSend($d2, $me, 'answer');

    actingAsMsg($me);
    $this->getJson(msgApi('unread-summary'))->assertOk()
        ->assertJsonPath('data.total', 2)
        ->assertJsonPath('data.conversations', [['conversation_id' => $d1->id, 'unread_count' => 2]]);
});
