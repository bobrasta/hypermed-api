<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Section 15.7/15.8: a staff member's payment details, editable only by
 * themselves. Never expose account_number in full outside the owner,
 * the accountant/finance tier and admin-tier — see
 * PerDiemController::paymentProfile() for the masking rule. Every change
 * is audit-logged (LogsActivity), matching every other sensitive model
 * in this codebase.
 */
class StaffPaymentProfile extends Model
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['provider', 'account_name'])
            // account_number deliberately excluded from the activity log
            // itself (15.8: "Do not log account numbers"), even though
            // the change is still recorded (provider/account_name diffs
            // plus the timestamp make "something changed, by whom, when"
            // fully auditable without persisting the number a second
            // time outside this table).
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected $fillable = ['user_id', 'provider', 'account_number', 'account_name'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function maskedAccountNumber(): string
    {
        $n = (string) $this->account_number;

        return strlen($n) <= 4 ? $n : str_repeat('*', strlen($n) - 4).substr($n, -4);
    }
}
