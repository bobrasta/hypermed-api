<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttendanceRecord extends Model
{
    protected $fillable = [
        'user_id', 'date', 'clock_in', 'clock_out', 'status',
        'overtime_hours', 'source', 'attendance_import_id', 'marked_by',
    ];

    protected $casts = [
        'date'            => 'date',
        'overtime_hours'  => 'float',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function markedBy()
    {
        return $this->belongsTo(User::class, 'marked_by');
    }

    public function attendanceImport()
    {
        return $this->belongsTo(AttendanceImport::class);
    }
}
