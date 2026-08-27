<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DisciplinaryCase extends Model
{
    protected $fillable = [
        'user_id', 'stage', 'incident_date', 'description', 'action_taken',
        'raised_by', 'handled_by', 'status',
    ];

    protected $casts = [
        'incident_date' => 'date',
    ];

    public const STAGES = ['verbal_warning', 'written_warning', 'final_warning', 'action_taken'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function raisedBy()
    {
        return $this->belongsTo(User::class, 'raised_by');
    }

    public function handledBy()
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    public function notes()
    {
        return $this->hasMany(DisciplinaryCaseNote::class)->latest();
    }

    public function nextStage(): ?string
    {
        $index = array_search($this->stage, self::STAGES, true);
        return $index !== false && $index < count(self::STAGES) - 1 ? self::STAGES[$index + 1] : null;
    }
}
