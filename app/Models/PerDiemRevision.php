<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PerDiemRevision extends Model
{
    protected $fillable = [
        'per_diem_request_id', 'edited_by', 'editor_role', 'status', 'reason',
        'before', 'after', 'proposed', 'reviewed_by', 'reviewed_at',
    ];

    protected $casts = [
        'before'      => 'array',
        'after'       => 'array',
        'proposed'    => 'array',
        'reviewed_at' => 'datetime',
    ];

    public function perDiemRequest()
    {
        return $this->belongsTo(PerDiemRequest::class);
    }

    public function editor()
    {
        return $this->belongsTo(User::class, 'edited_by');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
