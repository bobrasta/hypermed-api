<?php

use Hypermed\Messaging\Events\MessageSent;
use Hypermed\Messaging\Events\UserMessageCreated;
use Hypermed\Messaging\Exceptions\MessagingException;
use Hypermed\Messaging\Models\Message;
use Hypermed\Messaging\Services\MessagingService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;

it('sends system messages that broadcast like normal messages', function () {
    Event::fake([MessageSent::class, UserMessageCreated::class]);
    [$a, $b] = msgUsers(2);
    $c = msgDirect($a, $b);

    $m = app(MessagingService::class)->sendSystemMessage($c->id, 'Approval #5 was approved', ['approval_id' => 5]);

    expect($m)->toBeInstanceOf(Message::class)
        ->and($m->sender_id)->toBeNull()
        ->and($m->type)->toBe('system')
        ->and($m->meta)->toBe(['approval_id' => 5])
        ->and($c->fresh()->last_message_id)->toBe($m->id);
    Event::assertDispatched(MessageSent::class);
    Event::assertDispatched(UserMessageCreated::class, fn ($e) => $e->payload['sender_id'] === null && count($e->userIds) === 2);

    actingAsMsg($a);
    $this->getJson(msgApi('conversations'))->assertJsonPath('data.0.unread_count', 1)
        ->assertJsonPath('data.0.last_message.sender', null);
});

it('posts entity messages from modules, optionally on behalf of a participant', function () {
    [$a, $b, $outsider] = msgUsers(3);
    $c = msgDirect($a, $b);
    $service = app(MessagingService::class);

    $card = $service->postEntityMessage($c->id, 'approval', 77);
    expect($card->type)->toBe('entity_ref')->and($card->sender_id)->toBeNull()
        ->and($card->meta)->toBe(['entity_type' => 'approval', 'entity_id' => 77]);

    $byUser = $service->postEntityMessage($c->id, 'ticket', 8, $a->id, 'Can you check?');
    expect($byUser->sender_id)->toBe($a->id)->and($byUser->body)->toBe('Can you check?');

    expect(fn () => $service->postEntityMessage($c->id, 'ticket', 8, $outsider->id))->toThrow(MessagingException::class);
    expect(fn () => $service->sendSystemMessage(999999, 'x'))->toThrow(MessagingException::class);
});

it('registers default gates without overriding host definitions', function () {
    // Host (harness) defined these; package defaults must not have replaced them.
    expect(Gate::has('messaging.send'))->toBeTrue()
        ->and(Gate::has('messaging.group.create'))->toBeTrue()
        ->and(Gate::has('messaging.broadcast.org_unit'))->toBeTrue()
        ->and(Gate::has('messaging.audit.view'))->toBeTrue()
        ->and(Gate::has('messaging.call'))->toBeTrue();

    // Harness stub: audit.view only for user id 1 (a package default would return false).
    $user = msgUser();
    expect(Gate::forUser($user)->allows('messaging.audit.view'))->toBe((int) $user->id === 1);
});
