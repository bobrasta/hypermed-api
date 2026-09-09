<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FlowDepartment extends Model
{
    protected $fillable = ['key', 'title', 'nodes', 'edges', 'updated_by', 'updated_role'];

    protected $casts = [
        'nodes' => 'array',
        'edges' => 'array',
    ];
}
