<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppNotification extends Model
{
    protected $table = 'notifications';

    protected $fillable = [
        'user_id', 'type', 'title', 'body',
        'entity_type', 'entity_id', 'is_read',
    ];

    protected $casts = [
        'is_read' => 'boolean',
        'entity_id' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
