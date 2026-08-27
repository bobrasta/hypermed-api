<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttendanceImport extends Model
{
    protected $fillable = [
        'filename', 'uploaded_by', 'row_count', 'matched_count', 'unmatched_rows',
    ];

    protected $casts = [
        'unmatched_rows' => 'array',
    ];

    public function uploadedBy()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function records()
    {
        return $this->hasMany(AttendanceRecord::class);
    }
}
