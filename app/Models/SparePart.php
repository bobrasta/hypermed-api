<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SparePart extends Model
{
    use HasFactory;

    protected $fillable = [
        'part_number', 'name', 'description', 'unit_cost',
        'currency', 'stock_qty', 'reorder_level', 'supplier',
    ];

    protected $casts = [
        'unit_cost' => 'integer',
        'stock_qty' => 'integer',
        'reorder_level' => 'integer',
    ];

    public function compatibleModels()
    {
        return $this->hasMany(SparePartMachineModel::class);
    }

    public function partsUsed()
    {
        return $this->hasMany(PartUsed::class);
    }
}
