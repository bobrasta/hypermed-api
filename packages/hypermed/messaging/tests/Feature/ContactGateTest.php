<?php

use Hypermed\Messaging\Contracts\AvatarUrlResolver;
use Illuminate\Support\Facades\Gate;

/* messaging.contact decides who can be messaged; AvatarUrlResolver shapes avatar URLs. */

it('refuses a direct chat, a group or an added member the contact gate rejects', function () {
    [$me, $ok, $blocked, $other] = msgUsers(4);
    Gate::define('messaging.contact', fn ($user, int $target) => $target !== (int) $blocked->getKey());
    actingAsMsg($me);

    $this->postJson(msgApi('conversations/direct'), ['user_id' => $blocked->id])
        ->assertStatus(422)->assertJsonPath('code', 'validation_failed')->assertJsonPath('errors.user_id.0', "You can't message this person.");
    $this->postJson(msgApi('conversations/direct'), ['user_id' => $ok->id])->assertCreated();

    $this->postJson(msgApi('conversations/group'), ['title' => 'G', 'user_ids' => [$ok->id, $blocked->id]])
        ->assertStatus(422)->assertJsonPath('errors', ['user_ids.1' => ["You can't message this person."]]);

    $group = msgGroup($me, [$ok]);
    $this->postJson(msgApi("conversations/{$group->id}/participants"), ['user_ids' => [$blocked->id]])->assertStatus(422);
    $this->postJson(msgApi("conversations/{$group->id}/participants"), ['user_ids' => [$other->id]])->assertOk();
});

it('runs avatar values through the bound resolver', function () {
    [$me, $them] = msgUsers(2);
    $resolver = new class implements AvatarUrlResolver
    {
        public function url(?string $stored): ?string
        {
            return 'https://cdn.test/'.($stored ?? 'none');
        }
    };
    app()->instance(AvatarUrlResolver::class, $resolver);
    actingAsMsg($me);

    $this->getJson(msgApi('users').'?search='.urlencode($them->name))->assertOk()
        ->assertJsonPath('data.0.avatar_url', 'https://cdn.test/none');
});
