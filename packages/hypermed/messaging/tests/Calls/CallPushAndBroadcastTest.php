<?php

require_once __DIR__.'/Support.php';

use Hypermed\Messaging\Calls\Models\Call;
use Hypermed\Messaging\Contracts\PresenceChecker;
use Hypermed\Messaging\Contracts\PushNotifier;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Broadcasting\BroadcastManager;
use Laravel\Sanctum\Sanctum;

/*
 * No fakes for events/queue here: broadcasts go through the real (null) broadcaster and
 * the sync queue runs SendCallPushJob / RingTimeoutJob inline.
 */

beforeEach(function () {
    calls_configure();

    $this->pushes = new class implements PushNotifier
    {
        public array $sent = [];

        public function send(array $userIds, array $data): void
        {
            $this->sent[] = ['user_ids' => $userIds, 'data' => $data];
        }
    };
    $this->online = [];
    $online = &$this->online;
    app()->instance(PushNotifier::class, $this->pushes);
    app()->instance(PresenceChecker::class, new class($online) implements PresenceChecker
    {
        public function __construct(private array &$online) {}

        public function isOnline(int $userId): bool
        {
            return in_array($userId, $this->online, true);
        }
    });
});

it('pushes incoming calls only to offline invitees, with the contract payload', function () {
    [$a, $b, $c] = [calls_user(['name' => 'Alice']), calls_user(), calls_user()];
    $conv = calls_conversation([$a, $b, $c], 'group');
    $this->online[] = $b->id; // b has a live socket; c does not

    Sanctum::actingAs($a);
    $callId = $this->postJson("/api/messaging/conversations/{$conv->id}/calls", ['type' => 'audio'])
        ->assertCreated()->json('data.call.id');

    // Sync queue ran the delayed RingTimeoutJob immediately: it must not expire the call.
    expect(Call::find($callId)->status)->toBe('ringing');

    $incoming = collect($this->pushes->sent)->firstWhere('data.kind', 'call.incoming');
    expect($incoming['user_ids'])->toBe([$c->id])
        ->and($incoming['data'])->toBe([
            'kind' => 'call.incoming',
            'call_id' => (string) $callId,
            'conversation_id' => (string) $conv->id,
            'call_type' => 'audio',
            'caller_id' => (string) $a->id,
            'caller_name' => 'Alice',
        ]);
    foreach ($incoming['data'] as $value) {
        expect($value)->toBeString();
    }

    // Caller cancels: every still-ringing invitee gets a dismissal push.
    $this->postJson("/api/messaging/calls/{$callId}/cancel")->assertOk();
    $cancel = collect($this->pushes->sent)->firstWhere('data.kind', 'call.cancelled');
    expect($cancel['user_ids'])->toEqualCanonicalizing([$b->id, $c->id]);
});

it('sends answered_elsewhere pushes to the answering user', function () {
    [$a, $b] = [calls_user(), calls_user()];
    $conv = calls_conversation([$a, $b], 'direct');

    Sanctum::actingAs($a);
    $callId = $this->postJson("/api/messaging/conversations/{$conv->id}/calls", ['type' => 'video'])->json('data.call.id');
    Sanctum::actingAs($b);
    $this->postJson("/api/messaging/calls/{$callId}/accept")->assertOk();

    $elsewhere = collect($this->pushes->sent)->firstWhere('data.kind', 'call.answered_elsewhere');
    expect($elsewhere['user_ids'])->toBe([$b->id])
        ->and($elsewhere['data']['call_id'])->toBe((string) $callId);
});

it('broadcasts call events on the private user channels with contract names', function () {
    [$a, $b] = [calls_user(), calls_user()];
    $conv = calls_conversation([$a, $b], 'direct');

    $sent = [];
    $broadcaster = new class($sent) extends Broadcaster
    {
        public function __construct(public array &$sent) {}

        public function auth($request) {}

        public function validAuthenticationResponse($request, $result) {}

        public function broadcast(array $channels, $event, array $payload = [])
        {
            $this->sent[] = ['channels' => array_map('strval', $channels), 'event' => $event, 'payload' => $payload];
        }
    };
    app(BroadcastManager::class)->extend('calls-test', fn () => $broadcaster);
    config(['broadcasting.connections.calls-test' => ['driver' => 'calls-test'], 'broadcasting.default' => 'calls-test']);
    app(BroadcastManager::class)->purge('calls-test');

    Sanctum::actingAs($a);
    $callId = $this->postJson("/api/messaging/conversations/{$conv->id}/calls", ['type' => 'video'])->json('data.call.id');
    Sanctum::actingAs($b);
    $this->postJson("/api/messaging/calls/{$callId}/accept")->assertOk();
    $this->postJson("/api/messaging/calls/{$callId}/leave")->assertOk();

    $calls = collect($sent)->filter(fn ($s) => str_starts_with($s['event'], 'call.'));
    $byName = $calls->groupBy('event');

    expect($byName->keys()->all())->toContain('call.incoming', 'call.accepted', 'call.answered_elsewhere', 'call.participant_changed', 'call.ended');

    $incoming = $byName['call.incoming']->first();
    expect($incoming['channels'])->toBe(['private-messaging.user.'.$b->id])
        ->and($incoming['payload']['call']['id'])->toBe($callId)
        ->and($incoming['payload']['call']['status'])->toBe('ringing');

    $accepted = $byName['call.accepted']->first();
    expect($accepted['channels'])->toEqualCanonicalizing(['private-messaging.user.'.$a->id, 'private-messaging.user.'.$b->id])
        ->and($accepted['payload']['user_id'])->toBe($b->id)
        ->and($accepted['payload']['call']['status'])->toBe('ongoing');

    expect($byName['call.answered_elsewhere']->first()['channels'])->toBe(['private-messaging.user.'.$b->id])
        ->and($byName['call.answered_elsewhere']->first()['payload'])->toMatchArray(['call_id' => $callId]);

    $ended = $byName['call.ended']->first();
    expect($ended['payload']['call']['status'])->toBe('ended')
        ->and($ended['channels'])->toHaveCount(2);
});
