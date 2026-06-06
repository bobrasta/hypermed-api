<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'sku'             => $this->sku,
            'name'            => $this->name,
            'description'     => $this->description,
            'category'        => $this->category,
            'unit_of_measure' => $this->unit_of_measure,
            'unit_cost'       => $this->unit_cost,
            'currency'        => $this->currency,
            'stock_qty'       => $this->stock_qty,
            'reorder_level'   => $this->reorder_level,
            'is_low_stock'    => $this->stock_qty <= $this->reorder_level,
            'supplier'        => $this->supplier,
            'is_active'       => $this->is_active,
            'compatible_models' => $this->whenLoaded('compatibleModels', fn () =>
                $this->compatibleModels->pluck('machine_model')
            ),
        ];
    }
}
