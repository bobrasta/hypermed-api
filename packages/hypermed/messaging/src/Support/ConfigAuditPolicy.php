<?php

namespace Hypermed\Messaging\Support;

use Hypermed\Messaging\Contracts\AuditPolicy;

class ConfigAuditPolicy implements AuditPolicy
{
    public function enabled(): bool
    {
        return (bool) config('messaging.audit.enabled', false);
    }
}
