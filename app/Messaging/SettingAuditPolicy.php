<?php

namespace App\Messaging;

use App\Models\Setting;
use Hypermed\Messaging\Contracts\AuditPolicy;

/**
 * Chat review is the Director's company-wide switch (settings key
 * messaging_admin_access, '1' = on). Missing or anything else = off.
 */
class SettingAuditPolicy implements AuditPolicy
{
    public const KEY = 'messaging_admin_access';

    public function enabled(): bool
    {
        return Setting::get(self::KEY, '0') === '1';
    }
}
