<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NestRelevanceRule extends Model
{
    public const TYPES = ['keyword', 'exclude', 'health_subcategory', 'entity_hint'];
    public const TAGS = ['DEVICES', 'REAGENTS', 'CONSUMABLES', 'SERVICE'];

    protected $fillable = ['type', 'tag', 'term', 'weight', 'active'];

    protected $casts = ['active' => 'boolean', 'weight' => 'integer'];
}
