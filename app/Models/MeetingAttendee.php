<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MeetingAttendee extends Model
{
    public const ATTENDANCE = ['invited', 'present', 'absent', 'apologies'];

    protected $fillable = ['meeting_id', 'user_id', 'guest_name', 'guest_organisation', 'guest_email', 'attendance'];

    public function meeting()
    {
        return $this->belongsTo(Meeting::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
