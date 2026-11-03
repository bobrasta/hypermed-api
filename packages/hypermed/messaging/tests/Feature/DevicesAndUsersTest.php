<?php

use Hypermed\Messaging\Models\DeviceToken;

it('registers, refreshes and removes device tokens', function () {
    [$a, $b] = msgUsers(2);
    actingAsMsg($a);
    $token = str_repeat('fcm-token-', 30);

    $this->postJson(msgApi('devices'), ['token' => $token, 'platform' => 'android'])
        ->assertOk()->assertJsonPath('data', ['token' => $token, 'platform' => 'android']);
    $this->postJson(msgApi('devices'), ['token' => $token, 'platform' => 'android'])->assertOk();
    expect(DeviceToken::query()->count())->toBe(1);

    // Same physical device, other user logs in → token moves.
    actingAsMsg($b);
    $this->postJson(msgApi('devices'), ['token' => $token, 'platform' => 'android'])->assertOk();
    expect(DeviceToken::query()->sole()->user_id)->toBe($b->id);

    $this->postJson(msgApi('devices'), ['token' => 'x', 'platform' => 'symbian'])->assertStatus(422)->assertJsonPath('code', 'validation_failed');

    $this->deleteJson(msgApi('devices'), ['token' => $token])->assertOk()->assertJsonPath('data.deleted', true);
    expect(DeviceToken::query()->count())->toBe(0);
});

it('searches users excluding the current user', function () {
    $me = msgUser(['name' => 'Zed Me']);
    msgUser(['name' => 'Anna Smith']);
    msgUser(['name' => 'Bob Smithers']);
    msgUser(['name' => 'Carl Other']);
    actingAsMsg($me);

    $this->getJson(msgApi('users').'?search=smith')->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.name', 'Anna Smith')
        ->assertJsonStructure(['data' => [['id', 'name', 'avatar_url']]]);

    $all = $this->getJson(msgApi('users').'?limit=50')->assertOk()->json('data');
    expect(collect($all)->pluck('id'))->not->toContain($me->id);

    // LIKE wildcards are escaped.
    $this->getJson(msgApi('users').'?search=%25')->assertOk()->assertJsonCount(0, 'data');
});
