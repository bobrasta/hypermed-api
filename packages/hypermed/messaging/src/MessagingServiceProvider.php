<?php

namespace Hypermed\Messaging;

use Hypermed\Messaging\Contracts\EntityLinkResolver;
use Hypermed\Messaging\Contracts\OrgUnitResolver;
use Hypermed\Messaging\Contracts\PresenceChecker;
use Hypermed\Messaging\Contracts\PushNotifier;
use Hypermed\Messaging\Contracts\UserDirectory;
use Hypermed\Messaging\Presence\BroadcasterPresenceChecker;
use Hypermed\Messaging\Presence\NullPresenceChecker;
use Hypermed\Messaging\Push\FcmPushNotifier;
use Hypermed\Messaging\Push\NullPushNotifier;
use Hypermed\Messaging\Services\AttachmentService;
use Hypermed\Messaging\Services\ConversationQuery;
use Hypermed\Messaging\Services\ConversationService;
use Hypermed\Messaging\Services\MessagingService;
use Hypermed\Messaging\Support\EloquentUserDirectory;
use Hypermed\Messaging\Support\NullEntityLinkResolver;
use Hypermed\Messaging\Support\NullOrgUnitResolver;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class MessagingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/messaging.php', 'messaging');

        // Host apps override any of these with their own bindings.
        $this->app->bindIf(OrgUnitResolver::class, NullOrgUnitResolver::class, true);
        $this->app->bindIf(EntityLinkResolver::class, NullEntityLinkResolver::class, true);
        $this->app->bindIf(UserDirectory::class, EloquentUserDirectory::class, true);

        $this->app->bindIf(PushNotifier::class, function ($app) {
            $driver = (string) config('messaging.push.driver', 'null');

            return match ($driver) {
                'fcm' => FcmPushNotifier::fromConfig(),
                'null', '' => new NullPushNotifier,
                default => $app->make($driver),
            };
        }, true);

        $this->app->bindIf(PresenceChecker::class, function ($app) {
            $checker = (string) config('messaging.presence.checker', 'broadcaster');

            return match ($checker) {
                'broadcaster' => new BroadcasterPresenceChecker,
                'null', '' => new NullPresenceChecker,
                default => $app->make($checker),
            };
        }, true);

        $this->app->singleton(ConversationQuery::class);
        $this->app->singleton(MessagingService::class);
        $this->app->singleton(ConversationService::class);
        $this->app->singleton(AttachmentService::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if (config('messaging.register_routes', true) && ! $this->app->routesAreCached()) {
            $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
        }

        if (config('messaging.register_channels', true)) {
            static::registerChannels();
        }

        // Defaults only for gates the host did not define (checked after every
        // provider booted, so the host's AppServiceProvider always wins).
        $this->app->booted(function () {
            foreach ((array) config('messaging.default_gates', []) as $ability => $result) {
                if (! Gate::has($ability)) {
                    Gate::define($ability, fn ($user = null) => (bool) $result);
                }
            }
        });

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/messaging.php' => config_path('messaging.php'),
            ], 'messaging-config');
            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'messaging-migrations');
        }
    }

    /** Registers the channel authorization callbacks on the current broadcaster. */
    public static function registerChannels(): void
    {
        require __DIR__.'/../routes/channels.php';
    }
}
