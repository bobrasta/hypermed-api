<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SparePartMachineModel extends Model
{
    protected $fillable = ['spare_part_id', 'machine_model'];

    public function sparePart()
    {
        return $this->belongsTo(SparePart::class);
    }
}
