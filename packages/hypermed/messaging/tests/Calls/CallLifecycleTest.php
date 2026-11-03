<?php

require_once __DIR__.'/Support.php';

use Hypermed\Messaging\Calls\Events\CallAccepted;
use Hypermed\Messaging\Calls\Events\CallAnsweredElsewhere;
use Hypermed\Messaging\Calls\Events\CallCancelled;
use Hypermed\Messaging\Calls\Events\CallDeclined;
use Hypermed\Messaging\Calls\Events\CallEnded;
use Hypermed\Messaging\Calls\Events\CallIncoming;
use Hypermed\Messaging\Calls\Events\CallParticipantChanged;
use Hypermed\Messaging\Calls\Jobs\RingTimeoutJob;
use Hypermed\Messaging\Calls\Jobs\SendCallPushJob;
use Hypermed\Messaging\Calls\Models\Call;
use Hypermed\Messaging\Calls\Services\CallService;
use Hypermed\Messaging\Models\Message;
use Hypermed\Messaging\Models\Participant;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

const CALL_EVENTS = [
    CallIncoming::class, CallAccepted::class, CallDeclined::class, CallCancelled::class,
    CallEnded::class, CallParticipantChanged::class, CallAnsweredElsewhere::class,
];

beforeEach(function () {
    calls_configure();
    Queue::fake([RingTimeoutJob::class, SendCallPushJob::class]);
    Event::fake(CALL_EVENTS);
});

function calls_start($user, $conversation, string $type = 'video')
{
    Sanctum::actingAs($user);

    return test()->postJson("/api/messaging/conversations/{$conversation->id}/calls", ['type' => $type]);
}

function calls_act($user, int $callId, string $action)
{
    Sanctum::actingAs($user);

    return test()->postJson("/api/messaging/calls/{$callId}/{$action}");
}

function calls_last_system_message(int $conversationId): ?Message
{
    return Message::query()
        ->where('conversation_id', $conversationId)
        ->where('type', 'system')
        ->orderByDesc('id')
        ->first();
}

it('rings, is accepted and ends when a side hangs up (1:1)', function () {
    [$a, $b] = [calls_user(['name' => 'Alice']), calls_user(['name' => 'Bob'])];
    $conv = calls_conversation([$a, $b], 'direct');

    $res = calls_start($a, $conv)->assertCreated();
    $res->assertJsonPath('data.call.status', 'ringing')
        ->assertJsonPath('data.call.type', 'video')
        ->assertJsonPath('data.call.initiated_by', $a->id)
        ->assertJsonPath('data.livekit.url', CALLS_TEST_LK_URL)
        ->assertJsonPath('data.livekit.room', 'call_'.$res->json('data.call.id'));
    $callId = $res->json('data.call.id');

    $parts = collect($res->json('data.call.participants'))->keyBy('user_id');
    expect($parts[$a->id]['status'])->toBe('joined')
        ->and($parts[$b->id]['status'])->toBe('ringing')
        ->and($parts[$b->id]['name'])->toBe('Bob');

    Event::assertDispatched(CallIncoming::class, fn (CallIncoming $e) => $e->recipientIds === [$b->id]
        && $e->broadcastAs() === 'call.incoming'
        && $e->broadcastOn()[0]->name === 'private-messaging.user.'.$b->id
        && $e->broadcastWith()['call']['id'] === $callId);
    Queue::assertPushed(RingTimeoutJob::class, fn (RingTimeoutJob $j) => $j->callId === $callId && $j->delay !== null);
    Queue::assertPushed(SendCallPushJob::class, fn (SendCallPushJob $j) => $j->userIds === [$b->id]
        && $j->onlyOffline
        && $j->data === [
            'kind' => 'call.incoming', 'call_id' => (string) $callId, 'conversation_id' => (string) $conv->id,
            'call_type' => 'video', 'caller_id' => (string) $a->id, 'caller_name' => 'Alice',
        ]);

    $this->travel(3)->seconds();
    calls_act($b, $callId, 'accept')->assertOk()
        ->assertJsonPath('data.call.status', 'ongoing')
        ->assertJsonPath('data.livekit.room', 'call_'.$callId);
    expect(Call::find($callId)->answered_at)->not->toBeNull();

    Event::assertDispatched(CallAccepted::class, fn (CallAccepted $e) => $e->userId === $b->id
        && $e->broadcastAs() === 'call.accepted'
        && collect($e->recipientIds)->sort()->values()->all() === collect([$a->id, $b->id])->sort()->values()->all());
    Event::assertDispatched(CallAnsweredElsewhere::class, fn (CallAnsweredElsewhere $e) => $e->userId === $b->id
        && $e->broadcastWith() === ['call_id' => $callId]
        && $e->broadcastAs() === 'call.answered_elsewhere');

    $this->travel(754)->seconds(); // 12:34
    calls_act($a, $callId, 'leave')->assertOk()
        ->assertJsonPath('data.call.status', 'ended')
        ->assertJsonPath('data.call.end_reason', 'completed');

    $call = Call::with('participants')->find($callId);
    expect($call->participants->pluck('status')->unique()->values()->all())->toBe(['left'])
        ->and($call->durationSeconds())->toBe(754);
    Event::assertDispatched(CallEnded::class, fn (CallEnded $e) => $e->broadcastAs() === 'call.ended'
        && $e->call['status'] === 'ended');

    // System message posted in the conversation.
    $msg = calls_last_system_message($conv->id);
    expect($msg)->not->toBeNull()
        ->and($msg->body)->toBe('Video call · 12:34')
        ->and($msg->sender_id)->toBeNull()
        ->and((array) $msg->meta)->toMatchArray([
            'call_id' => $callId, 'call_type' => 'video', 'duration_seconds' => 754, 'status' => 'ended',
        ]);
});

it('marks the call missed when nobody answers before the ring timeout', function () {
    [$a, $b] = [calls_user(), calls_user()];
    $conv = calls_conversation([$a, $b], 'direct');
    $callId = calls_start($a, $conv, 'audio')->assertCreated()->json('data.call.id');

    // Running early (sync driver ignores delays) must not expire the call.
    (new RingTimeoutJob($callId))->handle(app(CallService::class));
    expect(Call::find($callId)->status)->toBe('ringing');

    $this->travel(46)->seconds();
    (new RingTimeoutJob($callId))->handle(app(CallService::class));

    $call = Call::with('participants')->find($callId);
    expect($call->status)->toBe('missed')
        ->and($call->end_reason)->toBe('no_answer')
        ->and($call->participantFor($b->id)->status)->toBe('missed')
        ->and($call->participantFor($a->id)->status)->toBe('left');

    Event::assertDispatched(CallEnded::class, fn (CallEnded $e) => $e->call['status'] === 'missed');
    Queue::assertPushed(SendCallPushJob::class, fn (SendCallPushJob $j) => $j->data['kind'] === 'call.ended' && $j->userIds === [$b->id]);

    $msg = calls_last_system_message($conv->id);
    expect($msg->body)->toBe('Missed voice call')
        ->and((array) $msg->meta)->toMatchArray(['call_id' => $callId, 'status' => 'missed', 'duration_seconds' => 0, 'call_type' => 'audio']);

    // Accepting afterwards is rejected.
    calls_act($b, $callId, 'accept')->assertStatus(409)->assertJsonPath('code', 'invalid_call_state');
});

it('lets the caller cancel before anyone answers', function () {
    [$a, $b] = [calls_user(), calls_user()];
    $conv = calls_conversation([$a, $b], 'direct');
    $callId = calls_start($a, $conv)->json('data.call.id');

    calls_act($b, $callId, 'cancel')->assertForbidden()->assertJsonPath('code', 'forbidden');

    calls_act($a, $callId, 'cancel')->assertOk()
        ->assertJsonPath('data.call.status', 'cancelled')
        ->assertJsonPath('data.call.end_reason', 'cancelled');

    expect(Call::with('participants')->find($callId)->participantFor($b->id)->status)->toBe('missed');
    Event::assertDispatched(CallCancelled::class, fn (CallCancelled $e) => $e->broadcastAs() === 'call.cancelled'
        && in_array($b->id, $e->recipientIds, true));
    Queue::assertPushed(SendCallPushJob::class, fn (SendCallPushJob $j) => $j->data['kind'] === 'call.cancelled');

    // Idempotent; but an answered call cannot be cancelled.
    calls_act($a, $callId, 'cancel')->assertOk();
    $call2 = calls_start($a, $conv)->assertCreated()->json('data.call.id');
    calls_act($b, $call2, 'accept')->assertOk();
    calls_act($a, $call2, 'cancel')->assertStatus(409);
});

it('becomes declined when every invitee declines', function () {
    [$a, $b, $c] = [calls_user(), calls_user(), calls_user()];
    $conv = calls_conversation([$a, $b, $c], 'group');
    $callId = calls_start($a, $conv)->json('data.call.id');

    calls_act($b, $callId, 'decline')->assertOk()->assertJsonPath('data.call.status', 'ringing');
    calls_act($c, $callId, 'decline')->assertOk()
        ->assertJsonPath('data.call.status', 'declined')
        ->assertJsonPath('data.call.end_reason', 'declined');

    Event::assertDispatchedTimes(CallDeclined::class, 2);
    Event::assertDispatched(CallDeclined::class, fn (CallDeclined $e) => $e->userId === $c->id && $e->broadcastAs() === 'call.declined');
    Event::assertDispatchedTimes(CallEnded::class, 1);

    expect(calls_last_system_message($conv->id)->body)->toBe('Declined video call');
});

it('keeps a group call going until the last participant leaves', function () {
    [$a, $b, $c] = [calls_user(), calls_user(), calls_user()];
    $conv = calls_conversation([$a, $b, $c], 'group');
    $callId = calls_start($a, $conv)->json('data.call.id');

    calls_act($b, $callId, 'accept')->assertOk();
    calls_act($a, $callId, 'leave')->assertOk()->assertJsonPath('data.call.status', 'ongoing');

    // Ring timeout while ongoing: only the unanswered invitee is marked missed.
    $this->travel(50)->seconds();
    (new RingTimeoutJob($callId))->handle(app(CallService::class));
    $call = Call::with('participants')->find($callId);
    expect($call->status)->toBe('ongoing')->and($call->participantFor($c->id)->status)->toBe('missed');

    // A missed member can still join the running call.
    calls_act($c, $callId, 'accept')->assertOk()->assertJsonPath('data.call.status', 'ongoing');
    calls_act($b, $callId, 'leave')->assertOk()->assertJsonPath('data.call.status', 'ongoing');
    calls_act($c, $callId, 'leave')->assertOk()->assertJsonPath('data.call.status', 'ended');

    Event::assertDispatched(CallParticipantChanged::class, fn (CallParticipantChanged $e) => $e->userId === $a->id
        && $e->status === 'left' && $e->broadcastAs() === 'call.participant_changed');
});

it('allows a single active call per conversation and returns the existing one', function () {
    [$a, $b] = [calls_user(), calls_user()];
    $conv = calls_conversation([$a, $b], 'direct');

    $first = calls_start($a, $conv)->assertCreated()->json('data.call.id');
    calls_start($a, $conv)->assertOk()->assertJsonPath('data.call.id', $first);

    // Callee pressing "call" at the same time joins (answers) the existing call.
    calls_start($b, $conv, 'audio')->assertOk()
        ->assertJsonPath('data.call.id', $first)
        ->assertJsonPath('data.call.status', 'ongoing')
        ->assertJsonPath('data.livekit.room', 'call_'.$first);

    expect(Call::where('conversation_id', $conv->id)->count())->toBe(1);

    calls_act($a, $first, 'leave')->assertJsonPath('data.call.status', 'ended');
    $second = calls_start($a, $conv)->assertCreated()->json('data.call.id');
    expect($second)->not->toBe($first);
});

it('expires a stale ringing call lazily when the timeout job never ran', function () {
    [$a, $b] = [calls_user(), calls_user()];
    $conv = calls_conversation([$a, $b], 'direct');
    $first = calls_start($a, $conv)->json('data.call.id');

    $this->travel(60)->seconds();
    $second = calls_start($a, $conv)->assertCreated()->json('data.call.id');

    expect($second)->not->toBe($first)
        ->and(Call::find($first)->status)->toBe('missed');
});

it('forbids non-participants from creating, joining, reading or getting a token', function () {
    [$a, $b, $outsider] = [calls_user(), calls_user(), calls_user()];
    $conv = calls_conversation([$a, $b], 'direct');
    $callId = calls_start($a, $conv)->json('data.call.id');

    calls_start($outsider, $conv)->assertForbidden()->assertJsonPath('code', 'not_participant');
    foreach (['accept', 'decline', 'cancel', 'leave'] as $action) {
        calls_act($outsider, $callId, $action)->assertForbidden()->assertJsonPath('code', 'not_participant');
    }

    Sanctum::actingAs($outsider);
    $this->getJson("/api/messaging/calls/{$callId}")->assertForbidden()->assertJsonPath('code', 'not_participant');
    $this->getJson("/api/messaging/calls?conversation_id={$conv->id}")->assertForbidden();

    // A participant removed from the conversation loses access too.
    Participant::where('conversation_id', $conv->id)->where('user_id', $b->id)
        ->update(['left_at' => now()]);
    calls_act($b, $callId, 'accept')->assertForbidden();

    expect(Call::with('participants')->find($callId)->participantFor($outsider->id))->toBeNull();
    $this->getJson('/api/messaging/calls/999999')->assertNotFound()->assertJsonPath('code', 'not_found');
});

it('requires the messaging.call gate to start or join', function () {
    [$a, $b] = [calls_user(), calls_user()];
    $conv = calls_conversation([$a, $b], 'direct');
    $callId = calls_start($a, $conv)->json('data.call.id');

    Gate::define('messaging.call', fn ($user) => false);

    calls_start($a, $conv)->assertForbidden()->assertJsonPath('code', 'forbidden');
    calls_act($b, $callId, 'accept')->assertForbidden()->assertJsonPath('code', 'forbidden');
});

it('validates the call type', function () {
    [$a, $b] = [calls_user(), calls_user()];
    $conv = calls_conversation([$a, $b], 'direct');

    calls_start($a, $conv, 'hologram')->assertStatus(422)->assertJsonPath('code', 'validation_failed');
});

it('shows a call and paginates call history with a cursor', function () {
    [$a, $b] = [calls_user(), calls_user()];
    $conv = calls_conversation([$a, $b], 'direct');

    $ids = [];
    foreach (range(1, 3) as $i) {
        $ids[] = $id = calls_start($a, $conv)->json('data.call.id');
        calls_act($a, $id, 'cancel')->assertOk();
    }

    Sanctum::actingAs($b);
    $this->getJson("/api/messaging/calls/{$ids[0]}")->assertOk()
        ->assertJsonPath('data.call.id', $ids[0])
        ->assertJsonStructure(['data' => ['call' => [
            'id', 'conversation_id', 'initiated_by', 'type', 'status', 'started_at', 'answered_at',
            'ended_at', 'end_reason', 'created_at', 'participants' => [['user_id', 'name', 'status', 'joined_at', 'left_at']],
        ]]]);

    $page1 = $this->getJson("/api/messaging/calls?conversation_id={$conv->id}&limit=2")->assertOk();
    expect(collect($page1->json('data'))->pluck('id')->all())->toBe([$ids[2], $ids[1]]);
    $cursor = $page1->json('meta.next_cursor');
    expect($cursor)->toBeString();

    $page2 = $this->getJson('/api/messaging/calls?'.http_build_query(['conversation_id' => $conv->id, 'limit' => 2, 'cursor' => $cursor]))->assertOk();
    expect(collect($page2->json('data'))->pluck('id')->all())->toBe([$ids[0]])
        ->and($page2->json('meta.next_cursor'))->toBeNull();
});
