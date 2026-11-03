<?php

use Hypermed\Messaging\Events\ConversationAdded;
use Hypermed\Messaging\Events\ConversationRead;
use Hypermed\Messaging\Events\ConversationRemoved;
use Hypermed\Messaging\Events\DeliveryReceiptUpdated;
use Hypermed\Messaging\Events\MessageDeleted;
use Hypermed\Messaging\Events\MessageEdited;
use Hypermed\Messaging\Events\MessageSent;
use Hypermed\Messaging\Events\ReadReceiptUpdated;
use Hypermed\Messaging\Events\UserMessageCreated;
use Hypermed\Messaging\Jobs\SendPushJob;
use Hypermed\Messaging\Services\MessagingService;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

$allEvents = [MessageSent::class, MessageEdited::class, MessageDeleted::class, ReadReceiptUpdated::class,
    UserMessageCreated::class, ConversationAdded::class, ConversationRemoved::class, ConversationRead::class,
    DeliveryReceiptUpdated::class];

it('declares every socket event as broadcast-now and after-commit with contract names', function () use ($allEvents) {
    $names = [];
    foreach ($allEvents as $class) {
        $r = new ReflectionClass($class);
        expect($r->implementsInterface(ShouldBroadcastNow::class))->toBeTrue($class)
            ->and($r->implementsInterface(ShouldDispatchAfterCommit::class))->toBeTrue($class);
    }
    $names[] = (new MessageSent(1, []))->broadcastAs();
    $names[] = (new MessageEdited(1, []))->broadcastAs();
    $names[] = (new MessageDeleted(1, []))->broadcastAs();
    $names[] = (new ReadReceiptUpdated(1, 2, 3))->broadcastAs();
    $names[] = (new UserMessageCreated([1], []))->broadcastAs();
    $names[] = (new ConversationAdded([1], 1))->broadcastAs();
    $names[] = (new ConversationRemoved([1], 1))->broadcastAs();
    $names[] = (new ConversationRead(1, 1, 1, 0))->broadcastAs();
    $names[] = (new DeliveryReceiptUpdated(1, 2, 3))->broadcastAs();

    expect($names)->toBe(['message.sent', 'message.edited', 'message.deleted', 'read.updated', 'message.created',
        'conversation.added', 'conversation.removed', 'conversation.read', 'delivered.updated']);
});

it('broadcasts a sent message to the conversation and to every participant\'s user channel', function () {
    Event::fake([MessageSent::class, UserMessageCreated::class]);
    [$a, $b, $c] = msgUsers(3);
    $group = msgGroup($a, [$b, $c]);
    actingAsMsg($a);
    $uuid = (string) Str::uuid();

    $id = $this->postJson(msgApi("conversations/{$group->id}/messages"), ['client_uuid' => $uuid, 'type' => 'text', 'body' => str_repeat('x', 200)])
        ->assertCreated()->json('data.id');

    Event::assertDispatched(MessageSent::class, function (MessageSent $e) use ($group, $id) {
        return $e->broadcastOn()[0]->name === "private-messaging.conversation.{$group->id}"
            && $e->broadcastWith()['message']['id'] === $id;
    });
    Event::assertDispatched(UserMessageCreated::class, function (UserMessageCreated $e) use ($a, $b, $c, $group, $id, $uuid) {
        $channels = collect($e->broadcastOn())->pluck('name')->sort()->values()->all();
        $payload = $e->broadcastWith();

        return $channels === collect([$a, $b, $c])->map(fn ($u) => "private-messaging.user.{$u->id}")->sort()->values()->all()
            && $payload['conversation_id'] === $group->id && $payload['message_id'] === $id
            && $payload['client_uuid'] === $uuid && mb_strlen($payload['preview']) <= 120
            && array_keys($payload) === ['conversation_id', 'message_id', 'sender_id', 'type', 'preview', 'client_uuid', 'created_at'];
    });
});

it('does not broadcast when the surrounding transaction rolls back', function () {
    Event::fake([MessageSent::class, UserMessageCreated::class]);
    [$a, $b] = msgUsers(2);
    $c = msgDirect($a, $b);

    try {
        DB::transaction(function () use ($c) {
            app(MessagingService::class)->sendSystemMessage($c->id, 'will roll back');
            throw new RuntimeException('boom');
        });
    } catch (RuntimeException) {
    }

    Event::assertNotDispatched(MessageSent::class);
    Event::assertNotDispatched(UserMessageCreated::class);
    expect($c->messages()->count())->toBe(0);
});

it('only broadcasts after the surrounding transaction commits', function () {
    Event::fake([MessageSent::class]);
    [$a, $b] = msgUsers(2);
    $c = msgDirect($a, $b);

    DB::transaction(function () use ($c) {
        app(MessagingService::class)->sendSystemMessage($c->id, 'deferred');
        Event::assertNotDispatched(MessageSent::class);
    });

    Event::assertDispatched(MessageSent::class, fn (MessageSent $e) => $e->message['body'] === 'deferred' && $e->message['sender_id'] === null);
});

it('broadcasts synchronously (never via the queue) while push goes to the queue', function () {
    Queue::fake();
    [$a, $b] = msgUsers(2);
    $c = msgDirect($a, $b);
    actingAsMsg($a);

    $this->postJson(msgApi("conversations/{$c->id}/messages"), ['client_uuid' => (string) Str::uuid(), 'type' => 'text', 'body' => 'q'])->assertCreated();

    Queue::assertPushed(SendPushJob::class, 1);
    Queue::assertNotPushed(BroadcastEvent::class);
});

it('broadcasts edits, deletes and read receipts', function () {
    Event::fake([MessageEdited::class, MessageDeleted::class, ReadReceiptUpdated::class, ConversationRead::class]);
    [$a, $b] = msgUsers(2);
    $c = msgDirect($a, $b);
    $m = msgSend($c, $a, 'v1');
    actingAsMsg($a);

    $this->patchJson(msgApi("messages/{$m->id}"), ['body' => 'v2'])->assertOk();
    $this->deleteJson(msgApi("messages/{$m->id}"))->assertOk();
    Event::assertDispatched(MessageEdited::class, fn ($e) => $e->message['body'] === 'v2');
    Event::assertDispatched(MessageDeleted::class, fn ($e) => $e->message['is_deleted'] === true && $e->message['body'] === null);

    $m2 = msgSend($c, $a, 'for b');
    actingAsMsg($b);
    $this->postJson(msgApi("conversations/{$c->id}/read"), ['last_read_message_id' => $m2->id])->assertOk();
    $this->postJson(msgApi("conversations/{$c->id}/read"), ['last_read_message_id' => $m2->id])->assertOk(); // no move → no event

    Event::assertDispatchedTimes(ReadReceiptUpdated::class, 1);
    Event::assertDispatched(ReadReceiptUpdated::class, fn ($e) => $e->broadcastWith() === ['conversation_id' => $c->id, 'user_id' => $b->id, 'last_read_message_id' => $m2->id]);
    Event::assertDispatched(ConversationRead::class, fn ($e) => $e->broadcastOn()[0]->name === "private-messaging.user.{$b->id}"
        && $e->broadcastWith() === ['conversation_id' => $c->id, 'last_read_message_id' => $m2->id, 'unread_count' => 0]);
});

it('notifies users when they are added to or removed from conversations', function () {
    Event::fake([ConversationAdded::class, ConversationRemoved::class]);
    [$a, $b, $c] = msgUsers(3);
    actingAsMsg($a);

    $direct = $this->postJson(msgApi('conversations/direct'), ['user_id' => $b->id])->json('data.id');
    Event::assertDispatched(ConversationAdded::class, fn ($e) => $e->conversationId === $direct && count($e->broadcastOn()) === 2);

    $group = msgGroup($a, [$b]);
    $this->postJson(msgApi("conversations/{$group->id}/participants"), ['user_ids' => [$c->id]])->assertOk();
    Event::assertDispatched(ConversationAdded::class, fn ($e) => $e->conversationId === $group->id && $e->userIds === [$c->id]);

    $this->deleteJson(msgApi("conversations/{$group->id}/participants/{$c->id}"))->assertOk();
    Event::assertDispatched(ConversationRemoved::class, fn ($e) => $e->broadcastOn()[0]->name === "private-messaging.user.{$c->id}"
        && $e->broadcastWith() === ['conversation_id' => $group->id]);
});

it('still succeeds when the broadcaster is unreachable', function () {
    msgUseReverbBroadcaster(); // points at 127.0.0.1:1 → connection refused
    [$a, $b] = msgUsers(2);
    $c = msgDirect($a, $b);
    actingAsMsg($a);

    $this->postJson(msgApi("conversations/{$c->id}/messages"), ['client_uuid' => (string) Str::uuid(), 'type' => 'text', 'body' => 'resilient'])
        ->assertCreated()->assertJsonPath('data.body', 'resilient');
});
