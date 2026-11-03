<?php

use Hypermed\Messaging\Contracts\PresenceChecker;
use Hypermed\Messaging\Contracts\PushNotifier;
use Hypermed\Messaging\Models\DeviceToken;
use Hypermed\Messaging\Push\FcmPushNotifier;
use Hypermed\Messaging\Push\NullPushNotifier;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

function msgFakePresence(array $online): void
{
    app()->instance(PresenceChecker::class, new class($online) implements PresenceChecker
    {
        public function __construct(private array $online) {}

        public function isOnline(int $userId): bool
        {
            return in_array($userId, $this->online, true);
        }
    });
}

it('pushes only to offline, unmuted participants other than the sender', function () {
    $push = new NullPushNotifier;
    app()->instance(PushNotifier::class, $push);
    [$sender, $online, $muted, $offline, $expiredMute] = msgUsers(5);
    $group = msgGroup($sender, [$online, $muted, $offline, $expiredMute], 'Team');
    msgFakePresence([$online->id]);
    msgParticipant($group, $muted)->forceFill(['muted_until' => now()->addHour()])->save();
    msgParticipant($group, $expiredMute)->forceFill(['muted_until' => now()->subMinute()])->save();
    $push->sent = [];

    actingAsMsg($sender);
    $id = $this->postJson(msgApi("conversations/{$group->id}/messages"), ['client_uuid' => (string) Str::uuid(), 'type' => 'text', 'body' => 'Ping'])
        ->assertCreated()->json('data.id');

    expect($push->sent)->toHaveCount(1);
    $sent = $push->sent[0];
    expect(collect($sent['user_ids'])->sort()->values()->all())->toBe(collect([$offline->id, $expiredMute->id])->sort()->values()->all())
        ->and($sent['data'])->toMatchArray([
            'kind' => 'message', 'conversation_id' => (string) $group->id, 'message_id' => (string) $id,
            'sender_name' => $sender->name, 'preview' => 'Ping', 'type' => 'text',
        ]);
    foreach ($sent['data'] as $value) {
        expect($value)->toBeString();
    }
});

it('does not push when everyone is online', function () {
    $push = new NullPushNotifier;
    app()->instance(PushNotifier::class, $push);
    [$a, $b] = msgUsers(2);
    $c = msgDirect($a, $b);
    msgFakePresence([$a->id, $b->id]);

    msgSend($c, $a, 'hi');

    expect($push->sent)->toBe([]);
});

it('signs RS256 JWTs with openssl that verify against the public key', function () {
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);
    $public = openssl_pkey_get_details($key)['key'];

    $jwt = FcmPushNotifier::signJwt(['iss' => 'svc@example.iam.gserviceaccount.com', 'exp' => time() + 60], $pem);
    [$h, $p, $s] = explode('.', $jwt);
    $sig = base64_decode(strtr($s, '-_', '+/'));

    expect(openssl_verify("{$h}.{$p}", $sig, $public, OPENSSL_ALGO_SHA256))->toBe(1)
        ->and(json_decode(base64_decode(strtr($h, '-_', '+/')), true))->toBe(['alg' => 'RS256', 'typ' => 'JWT']);
});

it('sends FCM v1 data messages, caches the OAuth token and prunes dead tokens', function () {
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);
    $path = tempnam(sys_get_temp_dir(), 'fcm');
    file_put_contents($path, json_encode([
        'type' => 'service_account', 'project_id' => 'demo-project', 'client_email' => 'svc@demo.iam.gserviceaccount.com',
        'private_key' => $pem, 'token_uri' => 'https://oauth2.googleapis.com/token',
    ]));

    $user = msgUser();
    DeviceToken::query()->create(['user_id' => $user->id, 'token' => 'good-token', 'token_hash' => DeviceToken::hashToken('good-token'), 'platform' => 'android']);
    DeviceToken::query()->create(['user_id' => $user->id, 'token' => 'dead-token', 'token_hash' => DeviceToken::hashToken('dead-token'), 'platform' => 'android']);

    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.test', 'expires_in' => 3599]),
        'fcm.googleapis.com/*' => function (Request $request) {
            return $request['message']['token'] === 'dead-token'
                ? Http::response(['error' => ['code' => 404, 'status' => 'NOT_FOUND', 'message' => 'Requested entity was not found.',
                    'details' => [['errorCode' => 'UNREGISTERED']]]], 404)
                : Http::response(['name' => 'projects/demo-project/messages/1']);
        },
    ]);

    $fcm = new FcmPushNotifier($path, null);
    $fcm->send([$user->id], ['kind' => 'message', 'conversation_id' => 5]);
    $fcm->send([$user->id], ['kind' => 'message', 'conversation_id' => 6]);

    Http::assertSentCount(1 + 2 + 1); // one OAuth exchange (cached), 2 + 1 sends
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'oauth2.googleapis.com')
        && $r['grant_type'] === 'urn:ietf:params:oauth:grant-type:jwt-bearer' && substr_count($r['assertion'], '.') === 2);
    Http::assertSent(fn (Request $r) => $r->url() === 'https://fcm.googleapis.com/v1/projects/demo-project/messages:send'
        && $r->hasHeader('Authorization', 'Bearer ya29.test')
        && $r['message']['android']['priority'] === 'HIGH'
        && (array) $r['message']['data'] === ['kind' => 'message', 'conversation_id' => '5']
        && ! isset($r['message']['notification']));

    expect(DeviceToken::query()->pluck('token')->all())->toBe(['good-token']);
    @unlink($path);
});

it('resolves the push driver from config', function () {
    config(['messaging.push.driver' => 'fcm']);
    app()->forgetInstance(PushNotifier::class);
    expect(app(PushNotifier::class))->toBeInstanceOf(FcmPushNotifier::class);

    config(['messaging.push.driver' => 'null']);
    app()->forgetInstance(PushNotifier::class);
    expect(app(PushNotifier::class))->toBeInstanceOf(NullPushNotifier::class);
});
