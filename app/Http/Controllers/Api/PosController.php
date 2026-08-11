<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\InvoiceResource;
use App\Http\Resources\SalesOrderResource;
use App\Services\PosSaleService;
use Illuminate\Http\Request;

class PosController extends Controller
{
    // One-shot counter sale: create + confirm + deliver + invoice + pay, all at once.
    public function checkout(Request $request, PosSaleService $pos)
    {
        $data = $request->validate([
            'client_name'                => 'nullable|string|max:200',
            'hospital_id'                => 'nullable|exists:hospitals,id',
            'location_id'                => 'required|exists:locations,id',
            'commission_agent_id'        => 'nullable|exists:users,id',
            'discount_amount'            => 'nullable|integer|min:0',
            'tax_amount'                 => 'nullable|integer|min:0',
            'payment_method'             => 'required|in:cash,bank_transfer,mobile_money,cheque',
            'payment_reference'          => 'nullable|string|max:100',
            'items'                      => 'required|array|min:1',
            'items.*.inventory_item_id'  => 'nullable|exists:inventory_items,id',
            'items.*.description'       => 'required|string|max:300',
            'items.*.unit_of_measure'    => 'nullable|string|max:30',
            'items.*.quantity'           => 'required|integer|min:1',
            'items.*.unit_price'         => 'required|integer|min:0',
        ]);

        $result = $pos->checkout($request->user(), $data);

        if ($result['type'] === 'pending_approval') {
            return response()->json([
                'type'    => 'pending_approval',
                'message' => 'This sale exceeds your discount/value limits and needs manager approval before it can be completed. '
                             . $result['sales_order']->approval_reason,
                'data'    => new SalesOrderResource($result['sales_order']),
            ], 202);
        }

        return response()->json([
            'type'        => 'invoice',
            'sales_order' => new SalesOrderResource($result['sales_order']),
            'invoice'     => new InvoiceResource($result['invoice']),
        ], 201);
    }
}
