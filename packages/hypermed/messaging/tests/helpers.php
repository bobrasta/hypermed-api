<?php

/*
| Shared helpers for hypermed/messaging Pest tests. Loaded by the harness
| tests/Pest.php. Only relies on the configured user model having
| name/email/password columns.
*/

use Hypermed\Messaging\MessagingServiceProvider;
use Hypermed\Messaging\Models\Conversation;
use Hypermed\Messaging\Models\Message;
use Hypermed\Messaging\Models\Participant;
use Hypermed\Messaging\Services\ConversationService;
use Hypermed\Messaging\Services\MessagingService;
use Hypermed\Messaging\Support\MessagingConfig;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

if (! function_exists('msgUser')) {
    function msgUser(array $attributes = []): Model
    {
        static $n = 0;
        $n++;
        $class = MessagingConfig::userModel();

        return $class::query()->forceCreate($attributes + [
            'name' => 'Test User '.$n.' '.Str::random(4),
            'email' => 'msg-test-'.$n.'-'.Str::random(6).'@example.test',
            'password' => bcrypt('password'),
        ]);
    }

    /** @return array<int, Model> */
    function msgUsers(int $count): array
    {
        return array_map(fn () => msgUser(), range(1, $count));
    }

    /** Authenticate subsequent requests as $user on the sanctum guard. */
    function actingAsMsg(Model $user): void
    {
        if (class_exists(Sanctum::class)) {
            Sanctum::actingAs($user, ['*']);
        } else {
            test()->actingAs($user);
        }
    }

    function msgDirect(Model $a, Model $b): Conversation
    {
        return app(ConversationService::class)->findOrCreateDirect((int) $a->getKey(), (int) $b->getKey())[0];
    }

    /** @param array<int, Model> $members */
    function msgGroup(Model $admin, array $members, string $title = 'Test group'): Conversation
    {
        return app(ConversationService::class)->createGroup(
            (int) $admin->getKey(),
            $title,
            array_map(fn ($m) => (int) $m->getKey(), $members),
        );
    }

    function msgSend(Conversation $c, Model $sender, string $body = 'hello', array $extra = []): Message
    {
        return app(MessagingService::class)->createMessage($c, (int) $sender->getKey(), $extra + [
            'type' => Message::TYPE_TEXT,
            'body' => $body,
            'client_uuid' => (string) Str::uuid(),
        ])['message'];
    }

    function msgParticipant(Conversation $c, Model $user): ?Participant
    {
        return Participant::query()->where('conversation_id', $c->id)->where('user_id', $user->getKey())->first();
    }

    function msgApi(string $path = ''): string
    {
        return '/'.trim(config('messaging.route_prefix', 'api/messaging'), '/').($path !== '' ? '/'.ltrim($path, '/') : '');
    }

    /**
     * Switch broadcasting to the Pusher-protocol (Reverb) driver with dummy
     * credentials so /broadcasting/auth really signs/authorizes channels, and
     * re-register the package's channel callbacks on the new driver.
     */
    function msgUseReverbBroadcaster(): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app',
            'broadcasting.connections.reverb.options.host' => '127.0.0.1',
            'broadcasting.connections.reverb.options.port' => 1,
        ]);
        app(BroadcastManager::class)->purge();
        Broadcast::clearResolvedInstances();
        MessagingServiceProvider::registerChannels();
    }
}
