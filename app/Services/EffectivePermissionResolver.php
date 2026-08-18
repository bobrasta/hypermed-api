<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Single source of truth for "does this user currently hold permission X,
 * and at what scope?" — merges the user's Spatie role permissions with any
 * per-user allow/deny overrides. Deny always wins (a user can only have one
 * override row per permission, enforced by a unique constraint, so there's
 * never an allow-vs-deny conflict to arbitrate — whichever effect is stored
 * simply replaces the role's grant). An override's scope replaces the role's
 * scope for that permission when present.
 *
 * Cached per user with the same TTL-based Cache::remember pattern already
 * used elsewhere in this app (RevenueController::summary(),
 * FinanceReportController::monthlyTrend()) rather than event-driven
 * invalidation across Spatie's pivot tables, which don't fire clean Eloquent
 * events on attach/detach. invalidate() gives callers (e.g. the Role Builder)
 * a best-effort immediate bust; the TTL is the safety net.
 */
class EffectivePermissionResolver
{
    private const TTL = 600;

    /** @return Collection<int, array{key: string, scope: string}> */
    public function resolve(User $user): Collection
    {
        return Cache::remember($this->cacheKey($user), self::TTL, function () use ($user) {
            $rolePermissions = DB::table('role_has_permissions')
                ->join('model_has_roles', 'model_has_roles.role_id', '=', 'role_has_permissions.role_id')
                ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
                ->where('model_has_roles.model_id', $user->id)
                ->where('model_has_roles.model_type', User::class)
                ->select('permissions.name as key', 'role_has_permissions.scope')
                ->get()
                ->keyBy('key');

            // Spatie also supports assigning a permission straight to a model
            // (not just via a role) — treat that as an implicit allow at 'all'
            // scope, same as a role grant, unless a role already covers it.
            $directPermissions = DB::table('model_has_permissions')
                ->join('permissions', 'permissions.id', '=', 'model_has_permissions.permission_id')
                ->where('model_has_permissions.model_id', $user->id)
                ->where('model_has_permissions.model_type', User::class)
                ->select('permissions.name as key')
                ->get();

            foreach ($directPermissions as $p) {
                if (! $rolePermissions->has($p->key)) {
                    $rolePermissions->put($p->key, (object) ['key' => $p->key, 'scope' => 'all']);
                }
            }

            $effective = $rolePermissions->map(fn ($p) => ['key' => $p->key, 'scope' => $p->scope ?? 'all'])
                ->keyBy('key');

            $overrides = DB::table('user_permission_overrides')
                ->join('permissions', 'permissions.id', '=', 'user_permission_overrides.permission_id')
                ->where('user_permission_overrides.user_id', $user->id)
                ->select('permissions.name as key', 'user_permission_overrides.effect', 'user_permission_overrides.scope')
                ->get();

            foreach ($overrides as $o) {
                if ($o->effect === 'deny') {
                    $effective->forget($o->key);
                } else {
                    $effective->put($o->key, ['key' => $o->key, 'scope' => $o->scope ?? 'all']);
                }
            }

            return $effective->values();
        });
    }

    public function can(User $user, string $key): bool
    {
        return $this->resolve($user)->contains(fn ($p) => $p['key'] === $key);
    }

    public function scopeFor(User $user, string $key): ?string
    {
        return $this->resolve($user)->firstWhere('key', $key)['scope'] ?? null;
    }

    public function invalidate(User $user): void
    {
        Cache::forget($this->cacheKey($user));
    }

    private function cacheKey(User $user): string
    {
        return "user:{$user->id}:permissions";
    }
}
