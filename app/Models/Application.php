<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Application extends Model
{
    protected $fillable = ['applicant_id', 'vacancy_id', 'status', 'applied_at', 'notes'];

    protected $casts = [
        'applied_at' => 'date',
    ];

    public const STAGES = ['applied', 'shortlisted', 'interviewed', 'offered', 'hired', 'rejected'];

    public function applicant()
    {
        return $this->belongsTo(Applicant::class);
    }

    public function vacancy()
    {
        return $this->belongsTo(Vacancy::class);
    }

    public function interviews()
    {
        return $this->hasMany(Interview::class);
    }
}
