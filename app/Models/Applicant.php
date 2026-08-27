<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Applicant extends Model
{
    protected $fillable = [
        'name', 'phone', 'email', 'cover_letter', 'source_channel',
        'talent_pool', 'skills_tags', 'notes',
    ];

    protected $casts = [
        'talent_pool' => 'boolean',
    ];

    public function cvVersions()
    {
        return $this->hasMany(ApplicantCvVersion::class)->orderByDesc('version');
    }

    public function applications()
    {
        return $this->hasMany(Application::class);
    }

    public function latestCv()
    {
        return $this->hasOne(ApplicantCvVersion::class)->latestOfMany('version');
    }
}
