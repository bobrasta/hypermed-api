<?php

use Hypermed\Messaging\Contracts\AuditPolicy;
use Hypermed\Messaging\Events\AuditAccessed;
use Hypermed\Messaging\Models\MessageRevision;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;

/*
| Chat review: off by default; when on, only users passing
| messaging.audit.view can read, read-only, and every read is reported.
*/

function msgAuditOn(bool $on = true): void
{
    config(['messaging.audit.enabled' => $on]);
}

function msgAuditScenario(): array
{
    [$a, $b, $reviewer] = msgUsers(3);
    $chat = msgDirect($a, $b);
    msgSend($chat, $a, 'first');
    $edited = msgSend($chat, $b, 'original words');
    $deleted = msgSend($chat, $a, 'regretted words');

    actingAsMsg($b);
    test()->patchJson(msgApi("messages/{$edited->id}"), ['body' => 'softer words'])->assertOk();
    actingAsMsg($a);
    test()->deleteJson(msgApi("messages/{$deleted->id}"))->assertOk();

    Gate::define('messaging.audit.view', fn ($user) => (int) $user->getKey() === (int) $reviewer->getKey());

    return [$a, $b, $reviewer, $chat, $edited, $deleted];
}

it('keeps the old text of an edited message', function () {
    [$a, $b] = msgUsers(2);
    $m = msgSend(msgDirect($a, $b), $a, 'v1');
    actingAsMsg($a);
    $this->patchJson(msgApi("messages/{$m->id}"), ['body' => 'v2'])->assertOk()->assertJsonPath('data.body', 'v2');
    $this->patchJson(msgApi("messages/{$m->id}"), ['body' => 'v3'])->assertOk();

    expect(MessageRevision::query()->where('message_id', $m->id)->orderBy('id')->pluck('body')->all())->toBe(['v1', 'v2']);
    // Participants never see the history.
    $this->getJson(msgApi("conversations/{$m->conversation_id}/messages"))->assertOk()
        ->assertJsonMissingPath('data.0.revisions');
});

it('is off by default, even for someone passing the gate', function () {
    [, $b, $reviewer, $chat] = msgAuditScenario();
    actingAsMsg($reviewer);

    $this->getJson(msgApi("audit/users/{$b->id}/conversations"))->assertForbidden()->assertJsonPath('code', 'forbidden');
    $this->getJson(msgApi("audit/conversations/{$chat->id}/messages"))->assertForbidden();
    $this->getJson(msgApi('settings'))->assertOk()
        ->assertJsonPath('data.audit_enabled', false)->assertJsonPath('data.can_audit', false);
});

it('refuses people without the gate when it is on', function () {
    [$a, $b, , $chat] = msgAuditScenario();
    msgAuditOn();
    actingAsMsg($a);

    $this->getJson(msgApi("audit/users/{$b->id}/conversations"))->assertForbidden();
    // Even a participant of the chat can't use the review view of it.
    $this->getJson(msgApi("audit/conversations/{$chat->id}/messages"))->assertForbidden();
    $this->getJson(msgApi('settings'))->assertOk()
        ->assertJsonPath('data.audit_enabled', true)->assertJsonPath('data.can_audit', false);
});

it('lets a reviewer read chats, deleted and edited text included, and reports each read', function () {
    [$a, $b, $reviewer, $chat, $edited, $deleted] = msgAuditScenario();
    msgAuditOn();
    Event::fake([AuditAccessed::class]);
    actingAsMsg($reviewer);

    $this->getJson(msgApi('settings'))->assertOk()->assertJsonPath('data.can_audit', true);

    $this->getJson(msgApi("audit/users/{$b->id}/conversations"))->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $chat->id)
        ->assertJsonPath('meta.subject.id', $b->id);

    $res = $this->getJson(msgApi("audit/conversations/{$chat->id}/messages"))->assertOk()
        ->assertJsonPath('meta.participant_ids', collect([$a->id, $b->id])->sort()->values()->all());
    $byId = collect($res->json('data'))->keyBy('id');
    expect($byId[$deleted->id]['is_deleted'])->toBeTrue()
        ->and($byId[$deleted->id]['body'])->toBe('regretted words')
        ->and($byId[$deleted->id]['deleted_at'])->not->toBeNull()
        ->and($byId[$edited->id]['body'])->toBe('softer words')
        ->and($byId[$edited->id]['revisions'][0]['body'])->toBe('original words');

    Event::assertDispatched(AuditAccessed::class, fn ($e) => $e->viewerId === $reviewer->id
        && $e->action === 'conversations' && $e->subjectUserId === $b->id);
    Event::assertDispatched(AuditAccessed::class, fn ($e) => $e->viewerId === $reviewer->id
        && $e->action === 'messages' && $e->conversationId === $chat->id && $e->participantIds === collect([$a->id, $b->id])->sort()->values()->all());
});

it('changes nothing when a reviewer reads', function () {
    [$a, $b, $reviewer, $chat] = msgAuditScenario();
    msgAuditOn();
    $before = msgParticipant($chat, $b)->only(['last_read_message_id', 'last_delivered_message_id']);
    actingAsMsg($reviewer);
    $this->getJson(msgApi("audit/conversations/{$chat->id}/messages"))->assertOk();

    expect(msgParticipant($chat, $b)->only(['last_read_message_id', 'last_delivered_message_id']))->toBe($before)
        ->and(msgParticipant($chat, $reviewer))->toBeNull();
    // Still not a participant for the normal endpoints.
    $this->getJson(msgApi("conversations/{$chat->id}/messages"))->assertForbidden()->assertJsonPath('code', 'not_participant');
});

it('uses a bound AuditPolicy over the config flag', function () {
    [, $b, $reviewer] = msgAuditScenario();
    msgAuditOn(false);
    app()->instance(AuditPolicy::class, new class implements AuditPolicy
    {
        public function enabled(): bool
        {
            return true;
        }
    });
    actingAsMsg($reviewer);
    $this->getJson(msgApi("audit/users/{$b->id}/conversations"))->assertOk();
});

it('answers 404 for unknown users and chats', function () {
    [, , $reviewer] = msgAuditScenario();
    msgAuditOn();
    actingAsMsg($reviewer);
    $this->getJson(msgApi('audit/users/999999/conversations'))->assertNotFound();
    $this->getJson(msgApi('audit/conversations/999999/messages'))->assertNotFound();
});

it('hands apps the realtime connection only when Reverb is the broadcaster', function () {
    actingAsMsg(msgUser());
    config(['broadcasting.default' => 'log', 'messaging.realtime.key' => 'k1']);
    $this->getJson(msgApi('settings'))->assertOk()->assertJsonPath('data.realtime', null);

    config(['broadcasting.default' => 'reverb', 'messaging.realtime.host' => 'live.example.test', 'messaging.realtime.auth_path' => '/api/broadcasting/auth']);
    $this->getJson(msgApi('settings'))->assertOk()
        ->assertJsonPath('data.realtime', ['key' => 'k1', 'host' => 'live.example.test', 'port' => 443, 'tls' => true, 'auth_path' => '/api/broadcasting/auth']);
});
