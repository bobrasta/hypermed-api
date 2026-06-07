<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContactInteraction extends Model
{
    protected $fillable = [
        'contact_id', 'type', 'summary', 'outcome',
        'next_action', 'next_action_date',
    ];

    protected $casts = [
        'next_action_date' => 'date',
    ];

    public function contact()
    {
        return $this->belongsTo(Contact::class);
    }
}
