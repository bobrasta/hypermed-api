<?php

use Hypermed\Messaging\Models\Conversation;
use Hypermed\Messaging\Models\Participant;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Gate;

it('creates a direct conversation once per user pair (find-or-create)', function () {
    [$a, $b] = msgUsers(2);

    actingAsMsg($a);
    $first = $this->postJson(msgApi('conversations/direct'), ['user_id' => $b->id])
        ->assertCreated()
        ->assertJsonPath('data.type', 'direct')
        ->assertJsonPath('data.participant_count', 2);

    $this->postJson(msgApi('conversations/direct'), ['user_id' => $b->id])
        ->assertOk()->assertJsonPath('data.id', $first->json('data.id'));

    actingAsMsg($b);
    $this->postJson(msgApi('conversations/direct'), ['user_id' => $a->id])
        ->assertOk()->assertJsonPath('data.id', $first->json('data.id'));

    expect(Conversation::query()->where('type', 'direct')->count())->toBe(1)
        ->and(Conversation::query()->first()->direct_key)->toBe(min($a->id, $b->id).':'.max($a->id, $b->id));
});

it('enforces direct uniqueness at the database level', function () {
    [$a, $b] = msgUsers(2);
    msgDirect($a, $b);

    expect(fn () => Conversation::query()->create(['type' => 'direct', 'direct_key' => Conversation::directKey($b->id, $a->id)]))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('rejects a direct conversation with yourself or an unknown user', function () {
    $a = msgUser();
    actingAsMsg($a);

    $this->postJson(msgApi('conversations/direct'), ['user_id' => $a->id])
        ->assertStatus(422)->assertJsonPath('code', 'validation_failed');
    $this->postJson(msgApi('conversations/direct'), ['user_id' => 999999])
        ->assertStatus(422)->assertJsonPath('code', 'validation_failed')->assertJsonStructure(['message', 'code', 'errors' => ['user_id']]);
});

it('creates a group with the creator as admin', function () {
    [$a, $b, $c] = msgUsers(3);
    actingAsMsg($a);

    $res = $this->postJson(msgApi('conversations/group'), ['title' => 'Ops', 'user_ids' => [$b->id, $c->id, $a->id]])
        ->assertCreated()
        ->assertJsonPath('data.type', 'group')
        ->assertJsonPath('data.title', 'Ops')
        ->assertJsonPath('data.my_role', 'admin')
        ->assertJsonPath('data.participant_count', 3)
        ->assertJsonPath('data.unread_count', 0)
        ->assertJsonPath('data.last_message.type', 'system');

    expect(collect($res->json('data.participants'))->pluck('id')->sort()->values()->all())
        ->toBe(collect([$a->id, $b->id, $c->id])->sort()->values()->all());
});

it('requires the messaging.group.create gate', function () {
    [$a, $b] = msgUsers(2);
    Gate::define('messaging.group.create', fn () => false);
    actingAsMsg($a);

    $this->postJson(msgApi('conversations/group'), ['title' => 'Nope', 'user_ids' => [$b->id]])
        ->assertForbidden()->assertJsonPath('code', 'forbidden');
});

it('lists conversations with the contract shape, sorted by last activity', function () {
    [$me, $b, $c, $d] = msgUsers(4);
    $direct = msgDirect($me, $b);
    $group = msgGroup($c, [$me, $b, $d], 'G');
    $this->travel(1)->seconds();
    msgSend($direct, $b, 'newest');

    actingAsMsg($me);
    $res = $this->getJson(msgApi('conversations'))->assertOk()
        ->assertJsonStructure([
            'data' => [['id', 'type', 'title', 'org_unit_key', 'entity_type', 'entity_id', 'created_by', 'created_at',
                'last_activity_at', 'last_message', 'unread_count', 'my_role', 'last_read_message_id', 'muted_until',
                'participant_count', 'participants' => [['id', 'name', 'avatar_url', 'role', 'last_read_message_id', 'last_delivered_message_id']]]],
            'meta' => ['next_cursor'],
        ]);

    expect($res->json('data.0.id'))->toBe($direct->id)
        ->and($res->json('data.0.last_message.body'))->toBe('newest')
        ->and($res->json('data.0.last_message.sender.id'))->toBe($b->id)
        ->and($res->json('data.0.unread_count'))->toBe(1)
        ->and($res->json('data.1.id'))->toBe($group->id)
        ->and($res->json('data.1.unread_count'))->toBe(1) // "created the group" system message
        ->and($res->json('meta.next_cursor'))->toBeNull();
});

it('paginates the conversation list with a cursor without gaps or duplicates', function () {
    $me = msgUser();
    $others = msgUsers(12);
    $expected = [];
    foreach ($others as $i => $o) {
        $c = msgDirect($me, $o);
        // Several conversations share the same second to exercise the id tie-breaker.
        Conversation::query()->whereKey($c->id)->update(['last_activity_at' => now()->subMinutes(intdiv($i, 3))]);
    }
    $expected = Conversation::query()->orderByDesc('last_activity_at')->orderByDesc('id')->pluck('id')->all();

    actingAsMsg($me);
    $seen = [];
    $cursor = null;
    $pages = 0;
    do {
        $res = $this->getJson(msgApi('conversations').'?limit=5'.($cursor ? '&cursor='.urlencode($cursor) : ''))->assertOk();
        $seen = array_merge($seen, array_column($res->json('data'), 'id'));
        $cursor = $res->json('meta.next_cursor');
        $pages++;
    } while ($cursor && $pages < 10);

    expect($seen)->toBe($expected)->and($pages)->toBe(3);
});

it('rejects a malformed cursor', function () {
    actingAsMsg(msgUser());
    $this->getJson(msgApi('conversations').'?cursor=not-a-cursor')->assertStatus(422)->assertJsonPath('code', 'validation_failed');
});

it('limits list participants to 5 for groups but shows all in detail', function () {
    $me = msgUser();
    $members = msgUsers(8);
    $group = msgGroup($me, $members);

    actingAsMsg($me);
    $this->getJson(msgApi('conversations'))->assertOk()
        ->assertJsonCount(5, 'data.0.participants')
        ->assertJsonPath('data.0.participant_count', 9);

    $this->getJson(msgApi("conversations/{$group->id}"))->assertOk()
        ->assertJsonCount(9, 'data.participants');
});

it('lets group admins add participants and members leave', function () {
    [$admin, $m1, $m2, $new] = msgUsers(4);
    $group = msgGroup($admin, [$m1, $m2]);

    actingAsMsg($m1);
    $this->postJson(msgApi("conversations/{$group->id}/participants"), ['user_ids' => [$new->id]])
        ->assertForbidden()->assertJsonPath('code', 'forbidden');

    actingAsMsg($admin);
    $this->postJson(msgApi("conversations/{$group->id}/participants"), ['user_ids' => [$new->id]])
        ->assertOk()->assertJsonPath('data.participant_count', 4);
    // New members start caught up (no historical unread).
    expect(msgParticipant($group, $new)->last_read_message_id)->not->toBeNull();

    $this->deleteJson(msgApi("conversations/{$group->id}/participants/{$m2->id}"))
        ->assertOk()->assertJsonPath('data.user_id', $m2->id);

    actingAsMsg($m1);
    $this->deleteJson(msgApi("conversations/{$group->id}/participants/{$m1->id}"))->assertOk();
    $this->getJson(msgApi("conversations/{$group->id}"))->assertForbidden()->assertJsonPath('code', 'not_participant');

    expect(Participant::query()->where('conversation_id', $group->id)->whereNull('left_at')->count())->toBe(2);
});

it('promotes another member when the last admin leaves', function () {
    [$admin, $m1] = msgUsers(2);
    $group = msgGroup($admin, [$m1]);

    actingAsMsg($admin);
    $this->deleteJson(msgApi("conversations/{$group->id}/participants/{$admin->id}"))->assertOk();

    expect(msgParticipant($group, $m1)->role)->toBe('admin');
});

it('does not allow managing participants of a direct conversation', function () {
    [$a, $b, $c] = msgUsers(3);
    $direct = msgDirect($a, $b);
    actingAsMsg($a);

    $this->postJson(msgApi("conversations/{$direct->id}/participants"), ['user_ids' => [$c->id]])->assertForbidden();
    $this->deleteJson(msgApi("conversations/{$direct->id}/participants/{$b->id}"))->assertForbidden();
});

it('mutes and unmutes a conversation', function () {
    [$a, $b] = msgUsers(2);
    $direct = msgDirect($a, $b);
    actingAsMsg($a);

    $until = now()->addHour()->startOfSecond();
    $this->postJson(msgApi("conversations/{$direct->id}/mute"), ['muted_until' => $until->toIso8601String()])
        ->assertOk()->assertJsonPath('data.muted_until', $until->toIso8601ZuluString());
    $this->postJson(msgApi("conversations/{$direct->id}/mute"), ['muted_until' => null])
        ->assertOk()->assertJsonPath('data.muted_until', null);
});

it('returns 404 not_found for a missing conversation', function () {
    actingAsMsg(msgUser());
    $this->getJson(msgApi('conversations/999999'))->assertNotFound()->assertJsonPath('code', 'not_found');
});
