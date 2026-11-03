<?php

namespace App\Providers;

use App\Messaging\LogChatReview;
use App\Messaging\PublicDiskAvatarUrl;
use App\Messaging\SettingAuditPolicy;
use App\Messaging\StaffUserDirectory;
use App\Models\User;
use App\Services\EffectivePermissionResolver;
use Hypermed\Messaging\Contracts\AuditPolicy;
use Hypermed\Messaging\Contracts\AvatarUrlResolver;
use Hypermed\Messaging\Contracts\UserDirectory;
use Hypermed\Messaging\Events\AuditAccessed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Hypermed's wiring for the in-app messaging package: who may chat with
 * whom, and chat review.
 *
 * Chat review needs BOTH the Director's switch (Settings,
 * messaging_admin_access, off by default) AND the messaging.read_others
 * permission, which is reserved: admin-tier doesn't imply it and only the
 * Director can grant it.
 */
class MessagingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(UserDirectory::class, StaffUserDirectory::class);
        $this->app->singleton(AuditPolicy::class, SettingAuditPolicy::class);
        $this->app->singleton(AvatarUrlResolver::class, PublicDiskAvatarUrl::class);
    }

    public function boot(): void
    {
        Gate::define('messaging.send', fn (User $user) => self::isStaff($user));
        Gate::define('messaging.group.create', fn (User $user) => self::isStaff($user));
        Gate::define('messaging.contact', function (User $user, int $targetId) {
            $target = User::find($targetId);

            return self::isStaff($user) && $target !== null && self::isStaff($target);
        });
        // Calls (LiveKit) and department broadcasts aren't set up yet.
        Gate::define('messaging.call', fn (User $user) => false);
        Gate::define('messaging.broadcast.org_unit', fn (User $user) => false);
        Gate::define('messaging.audit.view', fn (User $user) => app(AuditPolicy::class)->enabled()
            && app(EffectivePermissionResolver::class)->can($user, 'messaging.read_others'));

        Event::listen(AuditAccessed::class, LogChatReview::class);
    }

    /** Active Hypermed staff; vendor portal accounts can't use messaging. */
    public static function isStaff(User $user): bool
    {
        return (bool) $user->is_active && $user->vendor_id === null && ! $user->isVendorStaff();
    }
}
