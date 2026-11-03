<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Every conversation/message-scoped endpoint must answer 403 not_participant to
 * outsiders (and to users who left), before any validation runs.
 */
function msgOutsiderScenario(): array
{
    [$a, $b, $outsider] = msgUsers(3);
    $group = msgGroup($a, [$b], 'Private');
    $message = msgSend($group, $a, 'secret');

    return [$a, $b, $outsider, $group, $message];
}

dataset('participant endpoints', [
    'show conversation' => fn () => ['GET', 'conversations/{c}', []],
    'mute' => fn () => ['POST', 'conversations/{c}/mute', ['muted_until' => null]],
    'add participants' => fn () => ['POST', 'conversations/{c}/participants', ['user_ids' => ['{outsider}']]],
    'remove participant' => fn () => ['DELETE', 'conversations/{c}/participants/{b}', []],
    'leave' => fn () => ['DELETE', 'conversations/{c}/participants/{outsider}', []],
    'history' => fn () => ['GET', 'conversations/{c}/messages', []],
    'history before' => fn () => ['GET', 'conversations/{c}/messages?before={m}', []],
    'catch-up after' => fn () => ['GET', 'conversations/{c}/messages?after=0', []],
    'send message' => fn () => ['POST', 'conversations/{c}/messages', ['client_uuid' => (string) Str::uuid(), 'type' => 'text', 'body' => 'hi']],
    'send invalid message' => fn () => ['POST', 'conversations/{c}/messages', []],
    'upload attachment' => fn () => ['POST', 'conversations/{c}/attachments', ['client_uuid' => (string) Str::uuid(), 'file' => 'FILE']],
    'mark read' => fn () => ['POST', 'conversations/{c}/read', ['last_read_message_id' => '{m}']],
    'mark delivered' => fn () => ['POST', 'conversations/{c}/delivered', ['last_delivered_message_id' => '{m}']],
    'edit message' => fn () => ['PATCH', 'messages/{m}', ['body' => 'changed']],
    'delete message' => fn () => ['DELETE', 'messages/{m}', []],
]);

function msgResolveEndpoint(array $endpoint, array $ctx): array
{
    [$method, $path, $body] = $endpoint;
    $replace = ['{c}' => $ctx['group']->id, '{m}' => $ctx['message']->id, '{b}' => $ctx['b']->id, '{outsider}' => $ctx['outsider']->id];
    $path = strtr($path, $replace);
    array_walk_recursive($body, function (&$v) use ($replace) {
        if (is_string($v) && isset($replace[$v])) {
            $v = $replace[$v];
        } elseif ($v === 'FILE') {
            $v = UploadedFile::fake()->create('x.pdf', 5, 'application/pdf');
        }
    });

    return [$method, msgApi($path), $body];
}

it('returns 403 not_participant to non-participants', function (array $endpoint) {
    Storage::fake('local');
    [$a, $b, $outsider, $group, $message] = msgOutsiderScenario();
    [$method, $url, $body] = msgResolveEndpoint($endpoint, compact('group', 'message', 'b', 'outsider'));

    actingAsMsg($outsider);
    $this->json($method, $url, $body)->assertForbidden()->assertJsonPath('code', 'not_participant');
})->with('participant endpoints');

it('returns 403 not_participant to users who left the conversation', function (array $endpoint) {
    Storage::fake('local');
    [$a, $b, $outsider, $group, $message] = msgOutsiderScenario();
    actingAsMsg($b);
    $this->deleteJson(msgApi("conversations/{$group->id}/participants/{$b->id}"))->assertOk();

    [$method, $url, $body] = msgResolveEndpoint($endpoint, compact('group', 'message', 'b', 'outsider'));
    $this->json($method, $url, $body)->assertForbidden()->assertJsonPath('code', 'not_participant');
})->with('participant endpoints');

it('requires authentication on every API endpoint', function (array $endpoint) {
    [$a, $b, $outsider, $group, $message] = msgOutsiderScenario();
    [$method, $url, $body] = msgResolveEndpoint($endpoint, compact('group', 'message', 'b', 'outsider'));

    $this->json($method, $url, $body)->assertUnauthorized();
})->with('participant endpoints');

it('does not leak other people\'s conversations in list and unread summary', function () {
    [$a, $b, $outsider, $group] = msgOutsiderScenario();
    actingAsMsg($outsider);

    $this->getJson(msgApi('conversations'))->assertOk()->assertJsonCount(0, 'data');
    $this->getJson(msgApi('unread-summary'))->assertOk()->assertJsonPath('data.total', 0);
});

it('refuses attachment downloads without a valid signature', function () {
    Storage::fake('local');
    [$a, $b, $outsider, $group] = msgOutsiderScenario();
    actingAsMsg($a);
    $res = $this->postJson(msgApi("conversations/{$group->id}/attachments"), [
        'client_uuid' => (string) Str::uuid(), 'file' => UploadedFile::fake()->create('report.pdf', 10, 'application/pdf'),
    ])->assertCreated();
    $id = $res->json('data.attachments.0.id');

    $this->get(msgApi("attachments/{$id}/download"))->assertForbidden()->assertJsonPath('code', 'forbidden');
});

describe('channel authorization', function () {
    beforeEach(fn () => msgUseReverbBroadcaster());

    function msgChannelAuth(string $channel)
    {
        return test()->postJson('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => $channel]);
    }

    it('authorizes conversation channels for participants only', function () {
        [$a, $b, $outsider, $group] = msgOutsiderScenario();

        actingAsMsg($a);
        msgChannelAuth("private-messaging.conversation.{$group->id}")->assertOk()->assertJsonStructure(['auth']);

        actingAsMsg($outsider);
        msgChannelAuth("private-messaging.conversation.{$group->id}")->assertForbidden();
    });

    it('denies conversation channels after leaving', function () {
        [$a, $b, $outsider, $group] = msgOutsiderScenario();
        actingAsMsg($b);
        $this->deleteJson(msgApi("conversations/{$group->id}/participants/{$b->id}"))->assertOk();

        msgChannelAuth("private-messaging.conversation.{$group->id}")->assertForbidden();
    });

    it('authorizes the user channel only for its owner', function () {
        [$a, $b] = msgUsers(2);
        actingAsMsg($a);

        msgChannelAuth("private-messaging.user.{$a->id}")->assertOk();
        msgChannelAuth("private-messaging.user.{$b->id}")->assertForbidden();
    });

    it('joins the presence channel with {id, name}', function () {
        $a = msgUser(['name' => 'Alice Presence']);
        actingAsMsg($a);

        $res = msgChannelAuth('presence-messaging.presence')->assertOk()->assertJsonStructure(['auth', 'channel_data']);
        $data = json_decode($res->json('channel_data'), true);
        expect((int) $data['user_id'])->toBe($a->id)->and($data['user_info'])->toBe(['id' => $a->id, 'name' => 'Alice Presence']);
    });

    it('rejects unauthenticated channel auth', function () {
        msgChannelAuth('private-messaging.user.1')->assertUnauthorized();
    });
});
