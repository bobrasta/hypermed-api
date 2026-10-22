<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NestRelevanceRule extends Model
{
    public const TYPES = ['keyword', 'exclude', 'health_subcategory', 'entity_hint'];
    public const TAGS = ['DEVICES', 'REAGENTS', 'CONSUMABLES', 'SERVICE'];
    /** When a keyword only counts for health tenders — see config/nest.php health_*_terms. */
    public const CONTEXTS = ['text', 'health'];

    protected $fillable = ['type', 'tag', 'term', 'weight', 'active', 'context'];

    protected $casts = ['active' => 'boolean', 'weight' => 'integer'];
}
