<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SparePartResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'part_number'     => $this->part_number,
            'name'            => $this->name,
            'description'     => $this->description,
            'unit_cost'       => $this->unit_cost,
            'currency'        => $this->currency,
            'stock_qty'       => $this->stock_qty,
            'reorder_level'   => $this->reorder_level,
            'supplier'        => $this->supplier,
            'is_low_stock'    => $this->stock_qty <= $this->reorder_level,
            'compatible_models' => $this->whenLoaded('compatibleModels', fn () =>
                $this->compatibleModels->pluck('machine_model')
            ),
        ];
    }
}
