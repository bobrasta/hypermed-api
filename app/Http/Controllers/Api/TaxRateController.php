<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TaxRate;
use Illuminate\Http\Request;

// Named, reusable tax rates (e.g. "VAT 18%", "Zero-rated") for the tax_rate
// percentage fields on Invoice/Expense/VendorBill — those columns still
// store a plain float, this just gives every screen that enters one the
// same short, named list instead of everyone typing "18" from memory.
class TaxRateController extends Controller
{
    public function index(Request $request)
    {
        $query = TaxRate::query();
        if (! $request->boolean('include_inactive')) {
            $query->where('is_active', true);
        }

        return response()->json(['data' => $query->orderByDesc('is_default')->orderBy('name')->get()]);
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->isAdminTier(), 403,
            'Access Denied: you do not have permission to manage tax rates.');

        $data = $request->validate([
            'name'       => ['required', 'string', 'max:60'],
            'rate'       => ['required', 'numeric', 'min:0', 'max:100'],
            'is_default' => ['sometimes', 'boolean'],
        ]);

        if ($data['is_default'] ?? false) {
            TaxRate::where('is_default', true)->update(['is_default' => false]);
        }

        $taxRate = TaxRate::create($data);

        return response()->json(['data' => $taxRate], 201);
    }

    public function update(Request $request, TaxRate $taxRate)
    {
        abort_unless($request->user()->isAdminTier(), 403,
            'Access Denied: you do not have permission to manage tax rates.');

        $data = $request->validate([
            'name'       => ['sometimes', 'string', 'max:60'],
            'rate'       => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'is_default' => ['sometimes', 'boolean'],
            'is_active'  => ['sometimes', 'boolean'],
        ]);

        if ($data['is_default'] ?? false) {
            TaxRate::where('id', '!=', $taxRate->id)->where('is_default', true)->update(['is_default' => false]);
        }

        $taxRate->update($data);

        return response()->json(['data' => $taxRate]);
    }

    public function destroy(Request $request, TaxRate $taxRate)
    {
        abort_unless($request->user()->isAdminTier(), 403,
            'Access Denied: you do not have permission to manage tax rates.');

        $taxRate->delete();

        return response()->json(null, 204);
    }
}
