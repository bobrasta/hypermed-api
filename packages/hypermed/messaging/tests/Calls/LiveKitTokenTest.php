<?php

require_once __DIR__.'/Support.php';

use Hypermed\Messaging\Calls\Jobs\RingTimeoutJob;
use Hypermed\Messaging\Calls\Jobs\SendCallPushJob;
use Hypermed\Messaging\Calls\LiveKit\Jwt;
use Hypermed\Messaging\Calls\Models\Call;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    calls_configure();
    Queue::fake([RingTimeoutJob::class, SendCallPushJob::class]);
});

it('issues a short-lived token whose grants are scoped to the call room only', function () {
    $a = calls_user(['name' => 'Alice']);
    $b = calls_user(['name' => 'Bob']);
    $conv = calls_conversation([$a, $b], 'direct');

    Sanctum::actingAs($a);
    $res = $this->postJson("/api/messaging/conversations/{$conv->id}/calls", ['type' => 'audio'])->assertCreated();
    $callId = $res->json('data.call.id');
    $token = $res->json('data.livekit.token');

    // Header: HS256 JWT.
    $header = json_decode(Jwt::b64urlDecode(explode('.', $token)[0]), true);
    expect($header)->toBe(['alg' => 'HS256', 'typ' => 'JWT']);

    // Signature verifies with the API secret only.
    $claims = calls_jwt_claims($token);
    expect(fn () => Jwt::decode($token, 'wrong-secret'))->toThrow(InvalidArgumentException::class);

    expect($claims['iss'])->toBe(CALLS_TEST_LK_KEY)
        ->and($claims['sub'])->toBe((string) $a->id)
        ->and($claims['name'])->toBe('Alice')
        ->and($claims['exp'] - $claims['nbf'])->toBe(300)
        ->and($claims['nbf'])->toBeLessThanOrEqual(time())
        ->and($claims['video'])->toBe([
            'room' => 'call_'.$callId,
            'roomJoin' => true,
            'canPublish' => true,
            'canSubscribe' => true,
            'canPublishData' => true,
        ]);

    // No admin / server-side grants of any kind.
    foreach (['roomAdmin', 'roomCreate', 'roomList', 'roomRecord', 'ingressAdmin', 'hidden', 'recorder', 'agent'] as $grant) {
        expect($claims['video'])->not->toHaveKey($grant);
    }
    expect($claims)->not->toHaveKey('sip');

    // The callee's token is for the same room, with their own identity.
    Sanctum::actingAs($b);
    $bToken = $this->postJson("/api/messaging/calls/{$callId}/accept")->assertOk()->json('data.livekit.token');
    $bClaims = calls_jwt_claims($bToken);
    expect($bClaims['sub'])->toBe((string) $b->id)
        ->and($bClaims['name'])->toBe('Bob')
        ->and($bClaims['video']['room'])->toBe('call_'.$callId);
});

it('uses the configured token ttl', function () {
    config(['messaging-calls.livekit.token_ttl' => 120]);
    $a = calls_user();
    $b = calls_user();
    $conv = calls_conversation([$a, $b], 'direct');

    Sanctum::actingAs($a);
    $token = $this->postJson("/api/messaging/conversations/{$conv->id}/calls", ['type' => 'video'])->json('data.livekit.token');
    $claims = calls_jwt_claims($token);

    expect($claims['exp'] - $claims['nbf'])->toBe(120);
});

it('refuses to start calls when LiveKit is not configured', function () {
    config(['messaging-calls.livekit.api_secret' => '']);
    $a = calls_user();
    $b = calls_user();
    $conv = calls_conversation([$a, $b], 'direct');

    Sanctum::actingAs($a);
    $this->postJson("/api/messaging/conversations/{$conv->id}/calls", ['type' => 'video'])
        ->assertStatus(503)
        ->assertJsonPath('code', 'calls_unavailable');

    expect(Call::count())->toBe(0);
});
