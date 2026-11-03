<?php

require_once __DIR__.'/Support.php';

use Hypermed\Messaging\Calls\Jobs\RingTimeoutJob;
use Hypermed\Messaging\Calls\Jobs\SendCallPushJob;
use Hypermed\Messaging\Calls\LiveKit\Jwt;
use Hypermed\Messaging\Calls\Models\Call;
use Hypermed\Messaging\Models\Message;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    calls_configure();
    Queue::fake([RingTimeoutJob::class, SendCallPushJob::class]);
});

function calls_webhook(string $body, ?string $auth)
{
    $server = ['CONTENT_TYPE' => 'application/webhook+json'];
    if ($auth !== null) {
        $server['HTTP_AUTHORIZATION'] = $auth;
    }

    return test()->call('POST', '/api/messaging/livekit/webhook', [], [], [], $server, $body);
}

function calls_ongoing_group_call(): array
{
    [$a, $b, $c] = [calls_user(), calls_user(), calls_user()];
    $conv = calls_conversation([$a, $b, $c], 'group');
    Sanctum::actingAs($a);
    $callId = test()->postJson("/api/messaging/conversations/{$conv->id}/calls", ['type' => 'video'])->json('data.call.id');
    Sanctum::actingAs($b);
    test()->postJson("/api/messaging/calls/{$callId}/accept")->assertOk();

    return [$callId, $a, $b, $c, $conv];
}

it('rejects webhooks with a missing, forged or mismatched signature', function () {
    [$callId] = calls_ongoing_group_call();
    $body = json_encode(['event' => 'room_finished', 'room' => ['name' => 'call_'.$callId], 'createdAt' => (string) time()]);

    calls_webhook($body, null)->assertStatus(401)->assertJsonPath('code', 'invalid_signature');
    calls_webhook($body, 'garbage')->assertStatus(401);
    calls_webhook($body, calls_webhook_auth($body, 'some-other-secret'))->assertStatus(401);
    calls_webhook($body, calls_webhook_auth($body, CALLS_TEST_LK_SECRET, 'other-key'))->assertStatus(401);

    // Signature valid but for a different body (tampered payload).
    $other = json_encode(['event' => 'room_started', 'room' => ['name' => 'call_'.$callId]]);
    calls_webhook($body, calls_webhook_auth($other))->assertStatus(401);

    // Expired token.
    $expired = Jwt::encode([
        'iss' => CALLS_TEST_LK_KEY, 'nbf' => time() - 3600, 'exp' => time() - 600,
        'sha256' => base64_encode(hash('sha256', $body, true)),
    ], CALLS_TEST_LK_SECRET);
    calls_webhook($body, $expired)->assertStatus(401);

    expect(Call::find($callId)->status)->toBe('ongoing');
});

it('ends an ongoing call on a signed room_finished webhook', function () {
    [$callId, , , , $conv] = calls_ongoing_group_call();
    $body = json_encode(['event' => 'room_finished', 'room' => ['name' => 'call_'.$callId], 'createdAt' => (string) time()]);

    calls_webhook($body, calls_webhook_auth($body))->assertOk()->assertJsonPath('data.handled', true);

    $call = Call::with('participants')->find($callId);
    expect($call->status)->toBe('ended')
        ->and($call->end_reason)->toBe('room_finished')
        ->and($call->participants->where('status', 'joined')->count())->toBe(0);

    $msg = Message::where('conversation_id', $conv->id)->where('type', 'system')->latest('id')->first();
    expect((array) $msg->meta)->toMatchArray(['call_id' => $callId, 'status' => 'ended']);
});

it('accepts a "Bearer"-prefixed authorization header too', function () {
    [$callId] = calls_ongoing_group_call();
    $body = json_encode(['event' => 'room_finished', 'room' => ['name' => 'call_'.$callId]]);

    calls_webhook($body, 'Bearer '.calls_webhook_auth($body))->assertOk();
    expect(Call::find($callId)->status)->toBe('ended');
});

it('marks crashed participants as left and ends the call when the room is empty', function () {
    [$callId, $a, $b] = calls_ongoing_group_call();
    $this->travel(5)->seconds();

    $left = fn ($user) => json_encode([
        'event' => 'participant_left',
        'room' => ['name' => 'call_'.$callId],
        'participant' => ['identity' => (string) $user->id],
        'createdAt' => (string) now()->timestamp,
    ]);

    $body = $left($a);
    calls_webhook($body, calls_webhook_auth($body))->assertOk();
    $call = Call::with('participants')->find($callId);
    expect($call->status)->toBe('ongoing')->and($call->participantFor($a->id)->status)->toBe('left');

    // A stale "left" event older than the (re)join is ignored.
    $stale = json_encode([
        'event' => 'participant_left', 'room' => ['name' => 'call_'.$callId],
        'participant' => ['identity' => (string) $b->id], 'createdAt' => (string) (now()->timestamp - 3600),
    ]);
    calls_webhook($stale, calls_webhook_auth($stale))->assertOk();
    expect(Call::with('participants')->find($callId)->participantFor($b->id)->status)->toBe('joined');

    $body = $left($b);
    calls_webhook($body, calls_webhook_auth($body))->assertOk();
    $call = Call::with('participants')->find($callId);
    expect($call->status)->toBe('ended')->and($call->end_reason)->toBe('all_left');
});

it('ignores webhooks for rooms that are not calls', function () {
    $body = json_encode(['event' => 'room_finished', 'room' => ['name' => 'something_else']]);
    calls_webhook($body, calls_webhook_auth($body))->assertOk()->assertJsonPath('data.handled', false);
});
