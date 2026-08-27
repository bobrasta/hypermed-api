<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApplicantCvVersion extends Model
{
    public $timestamps = false;

    protected $fillable = ['applicant_id', 'file_path', 'original_name', 'version', 'uploaded_at'];

    protected $casts = [
        'uploaded_at' => 'datetime',
    ];

    public function applicant()
    {
        return $this->belongsTo(Applicant::class);
    }
}
