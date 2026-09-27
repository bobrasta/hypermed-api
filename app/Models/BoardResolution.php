<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Register of board resolutions referenced by Powers of Attorney. The
// numbers come from the board (entered by hand, never generated); the
// register only catches duplicates and shows which tenders share one.
class BoardResolution extends Model
{
    protected $fillable = ['number', 'resolution_date', 'notes', 'created_by'];

    protected $casts = ['resolution_date' => 'date'];

    public function tenders()
    {
        return $this->hasMany(Tender::class);
    }
}
