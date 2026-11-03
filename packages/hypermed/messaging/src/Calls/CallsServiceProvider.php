<?php

namespace Hypermed\Messaging\Calls;

use Hypermed\Messaging\Calls\LiveKit\LiveKitTokenIssuer;
use Hypermed\Messaging\Calls\LiveKit\WebhookVerifier;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Voice/video calls (LiveKit) for hypermed/messaging.
 * Self-contained: config, migrations, routes and bindings for the calls feature.
 */
class CallsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/messaging-calls.php', 'messaging-calls');

        $this->app->bind(LiveKitTokenIssuer::class, fn () => LiveKitTokenIssuer::fromConfig());
        $this->app->bind(WebhookVerifier::class, fn () => WebhookVerifier::fromConfig());
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

        if (config('messaging-calls.enabled', true) && ! $this->app->routesAreCached()) {
            $this->loadRoutesFrom(__DIR__.'/../../routes/calls.php');
        }

        // Default gate: allow, unless the host app defined its own (checked once all
        // providers have booted, so the host's definition always wins).
        $this->app->booted(function () {
            if (! Gate::has('messaging.call')) {
                Gate::define('messaging.call', fn ($user) => true);
            }
        });

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../../config/messaging-calls.php' => config_path('messaging-calls.php'),
            ], 'messaging-calls-config');
            $this->publishes([
                __DIR__.'/../../database/migrations' => database_path('migrations'),
            ], 'messaging-calls-migrations');
        }
    }
}
