<?php

namespace App\Models;

use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

/**
 * Every incoming authenticated request resolves a token through this model
 * before anything else runs, so it can't go through the default,
 * env()-resolved connection: under XAMPP's threaded Apache (mpm_winnt,
 * one process, many threads), concurrent requests' putenv() calls during
 * Laravel's dotenv bootstrap can race and transiently return no
 * DB_CONNECTION, silently falling back to config/database.php's hardcoded
 * 'sqlite' default. Pinning the connection here removes token lookups from
 * that race entirely, regardless of what 'default' resolves to.
 */
class PersonalAccessToken extends SanctumPersonalAccessToken
{
    protected $connection = 'pgsql';
}
