<?php

use App\Messaging\FakeEntityLinkResolver;
use Hypermed\Messaging\Models\Message;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

it('sends a text message and returns the contract shape', function () {
    [$a, $b] = msgUsers(2);
    $c = msgDirect($a, $b);
    actingAsMsg($a);
    $uuid = (string) Str::uuid();

    $this->postJson(msgApi("conversations/{$c->id}/messages"), ['client_uuid' => $uuid, 'type' => 'text', 'body' => 'Hello'])
        ->assertCreated()
        ->assertJsonStructure(['data' => ['id', 'conversation_id', 'sender_id', 'sender' => ['id', 'name', 'avatar_url'], 'type',
            'body', 'meta', 'client_uuid', 'reply_to_id', 'reply_to', 'attachments', 'entity', 'is_deleted', 'edited_at', 'created_at']])
        ->assertJsonPath('data.body', 'Hello')
        ->assertJsonPath('data.client_uuid', $uuid)
        ->assertJsonPath('data.sender.id', $a->id)
        ->assertJsonPath('data.is_deleted', false);

    $c->refresh();
    expect($c->last_message_id)->toBe(Message::query()->max('id'))
        ->and(msgParticipant($c, $a)->last_read_message_id)->toBe($c->last_message_id);
});

it('is idempotent on client_uuid', function () {
    [$a, $b] = msgUsers(2);
    $c = msgDirect($a, $b);
    actingAsMsg($a);
    $payload = ['client_uuid' => (string) Str::uuid(), 'type' => 'text', 'body' => 'once'];

    $first = $this->postJson(msgApi("conversations/{$c->id}/messages"), $payload)->assertCreated();
    $second = $this->postJson(msgApi("conversations/{$c->id}/messages"), $payload + ['body' => 'changed'])->assertOk();

    expect($second->json('data.id'))->toBe($first->json('data.id'))
        ->and($second->json('data.body'))->toBe('once')
        ->and(Message::query()->where('client_uuid', $payload['client_uuid'])->count())->toBe(1);
});

it('rejects reusing another sender\'s client_uuid', function () {
    [$a, $b] = msgUsers(2);
    $c = msgDirect($a, $b);
    $m = msgSend($c, $a, 'mine');

    actingAsMsg($b);
    $this->postJson(msgApi("conversations/{$c->id}/messages"), ['client_uuid' => $m->client_uuid, 'type' => 'text', 'body' => 'x'])
        ->assertStatus(422)->assertJsonPath('code', 'validation_failed')->assertJsonStructure(['errors' => ['client_uuid']]);
});

it('validates message payloads', function () {
    [$a, $b] = msgUsers(2);
    $c = msgDirect($a, $b);
    actingAsMsg($a);

    $this->postJson(msgApi("conversations/{$c->id}/messages"), ['type' => 'text', 'body' => 'x'])
        ->assertStatus(422)->assertJsonStructure(['errors' => ['client_uuid']]);
    $this->postJson(msgApi("conversations/{$c->id}/messages"), ['client_uuid' => (string) Str::uuid(), 'type' => 'system', 'body' => 'x'])
        ->assertStatus(422)->assertJsonStructure(['errors' => ['type']]);
    $this->postJson(msgApi("conversations/{$c->id}/messages"), ['client_uuid' => (string) Str::uuid(), 'type' => 'text'])
        ->assertStatus(422)->assertJsonStructure(['errors' => ['body']]);
});

it('supports replies within the same conversation only', function () {
    [$a, $b, $x] = msgUsers(3);
    $c = msgDirect($a, $b);
    $other = msgDirect($a, $x);
    $original = msgSend($c, $b, str_repeat('long text ', 30));
    $foreign = msgSend($other, $x, 'elsewhere');
    actingAsMsg($a);

    $this->postJson(msgApi("conversations/{$c->id}/messages"), ['client_uuid' => (string) Str::uuid(), 'type' => 'text', 'body' => 'ok', 'reply_to_id' => $original->id])
        ->assertCreated()
        ->assertJsonPath('data.reply_to.id', $original->id)
        ->assertJsonPath('data.reply_to.sender_id', $b->id)
        ->assertJsonPath('data.reply_to.is_deleted', false)
        ->assertJson(fn ($json) => $json->where('data.reply_to.body', fn ($body) => mb_strlen($body) <= 120)->etc());

    $this->postJson(msgApi("conversations/{$c->id}/messages"), ['client_uuid' => (string) Str::uuid(), 'type' => 'text', 'body' => 'bad', 'reply_to_id' => $foreign->id])
        ->assertStatus(422)->assertJsonStructure(['errors' => ['reply_to_id']]);
});

it('sends entity references resolved through EntityLinkResolver', function () {
    [$a, $b] = msgUsers(2);
    $c = msgDirect($a, $b);
    actingAsMsg($a);

    $this->postJson(msgApi("conversations/{$c->id}/messages"), [
        'client_uuid' => (string) Str::uuid(), 'type' => 'entity_ref', 'body' => 'Look', 'meta' => ['entity_type' => 'ticket', 'entity_id' => 42],
    ])->assertCreated()
        ->assertJsonPath('data.type', 'entity_ref')
        ->assertJsonPath('data.entity.type', 'ticket')
        ->assertJsonPath('data.entity.id', 42)
        ->assertJsonPath('data.entity.title', 'Ticket #42');

    // canView() false (fake resolver restricts id 13) → forbidden.
    $this->postJson(msgApi("conversations/{$c->id}/messages"), [
        'client_uuid' => (string) Str::uuid(), 'type' => 'entity_ref', 'meta' => ['entity_type' => 'ticket', 'entity_id' => 13],
    ])->assertForbidden()->assertJsonPath('code', 'forbidden');
})->skip(fn () => ! class_exists(FakeEntityLinkResolver::class), 'needs the harness entity resolver');

it('requires the messaging.send gate', function () {
    [$a, $b] = msgUsers(2);
    $c = msgDirect($a, $b);
    Gate::define('messaging.send', fn () => false);
    actingAsMsg($a);

    $this->postJson(msgApi("conversations/{$c->id}/messages"), ['client_uuid' => (string) Str::uuid(), 'type' => 'text', 'body' => 'x'])
        ->assertForbidden()->assertJsonPath('code', 'forbidden');
});

it('rate limits sending to the configured messages per minute', function () {
    config(['messaging.rate_limit.send_per_minute' => 5]);
    [$a, $b] = msgUsers(2);
    $c = msgDirect($a, $b);
    actingAsMsg($a);

    foreach (range(1, 5) as $i) {
        $this->postJson(msgApi("conversations/{$c->id}/messages"), ['client_uuid' => (string) Str::uuid(), 'type' => 'text', 'body' => "m{$i}"])->assertCreated();
    }
    $this->postJson(msgApi("conversations/{$c->id}/messages"), ['client_uuid' => (string) Str::uuid(), 'type' => 'text', 'body' => 'too many'])
        ->assertStatus(429)->assertJsonPath('code', 'rate_limited')->assertHeader('Retry-After');

    $this->travel(61)->seconds();
    $this->postJson(msgApi("conversations/{$c->id}/messages"), ['client_uuid' => (string) Str::uuid(), 'type' => 'text', 'body' => 'later'])->assertCreated();
});

it('defaults to 30 messages per minute', function () {
    expect(config('messaging.rate_limit.send_per_minute'))->toBe(30);
});

it('edits own text messages only', function () {
    [$a, $b] = msgUsers(2);
    $c = msgDirect($a, $b);
    $mine = msgSend($c, $a, 'typo');
    $theirs = msgSend($c, $b, 'theirs');
    actingAsMsg($a);

    $this->patchJson(msgApi("messages/{$mine->id}"), ['body' => 'fixed'])
        ->assertOk()->assertJsonPath('data.body', 'fixed')
        ->assertJson(fn ($j) => $j->whereType('data.edited_at', 'string')->etc());
    $this->patchJson(msgApi("messages/{$theirs->id}"), ['body' => 'hacked'])->assertForbidden()->assertJsonPath('code', 'forbidden');
    $this->patchJson(msgApi('messages/999999'), ['body' => 'x'])->assertNotFound()->assertJsonPath('code', 'not_found');
});

it('soft-deletes own messages and renders them as deleted placeholders everywhere', function () {
    [$a, $b] = msgUsers(2);
    $c = msgDirect($a, $b);
    $original = msgSend($c, $a, 'secret', ['meta' => ['k' => 'v']]);
    $reply = msgSend($c, $b, 'replying', ['reply_to_id' => $original->id]);
    actingAsMsg($a);

    $this->deleteJson(msgApi("messages/{$original->id}"))
        ->assertOk()
        ->assertJsonPath('data.id', $original->id)
        ->assertJsonPath('data.is_deleted', true)
        ->assertJsonPath('data.body', null)
        ->assertJsonPath('data.meta', null)
        ->assertJsonPath('data.attachments', []);

    expect(Message::query()->find($original->id))->not->toBeNull(); // row kept (soft delete)

    $history = $this->getJson(msgApi("conversations/{$c->id}/messages"))->assertOk();
    $deleted = collect($history->json('data'))->firstWhere('id', $original->id);
    $replyRow = collect($history->json('data'))->firstWhere('id', $reply->id);
    expect($deleted['is_deleted'])->toBeTrue()->and($deleted['body'])->toBeNull()
        ->and($replyRow['reply_to']['is_deleted'])->toBeTrue()->and($replyRow['reply_to']['body'])->toBeNull();

    $this->patchJson(msgApi("messages/{$original->id}"), ['body' => 'resurrect'])->assertForbidden();
    actingAsMsg($b);
    $this->deleteJson(msgApi("messages/{$reply->id}"))->assertOk();
    $this->getJson(msgApi('conversations'))->assertJsonPath('data.0.last_message.is_deleted', true)
        ->assertJsonPath('data.0.last_message.body', null);
});
