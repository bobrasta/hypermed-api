<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FlowSuggestion extends Model
{
    const UPDATED_AT = null;

    protected $fillable = ['department_key', 'submitted_by', 'submitted_role', 'note', 'nodes_snapshot', 'edges_snapshot'];

    protected $casts = [
        'nodes_snapshot' => 'array',
        'edges_snapshot' => 'array',
    ];
}
