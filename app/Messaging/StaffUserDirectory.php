<?php

namespace App\Messaging;

use Hypermed\Messaging\Support\EloquentUserDirectory;
use Illuminate\Database\Eloquent\Builder;

/** "New chat" search lists active staff only: no vendor portal accounts, no deactivated users. */
class StaffUserDirectory extends EloquentUserDirectory
{
    protected function scope(Builder $query): void
    {
        $query->where('is_active', true)->whereNull('vendor_id')->where('role', '<>', 'vendor_staff');
    }
}
