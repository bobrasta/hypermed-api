<?php

namespace Hypermed\Messaging\Contracts;

interface AuditPolicy
{
    /** Whether chat review is switched on at all (company-wide). */
    public function enabled(): bool;
}
