<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\InvoiceResource;
use App\Models\Invoice;
use Illuminate\Http\Request;

class InvoiceController extends Controller
{
    public function index(Request $request)
    {
        $query = Invoice::with(['hospital', 'machine']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('hospital_id')) {
            $query->where('hospital_id', $request->hospital_id);
        }
        if ($request->filled('date_from')) {
            $query->where('issue_date', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->where('issue_date', '<=', $request->date_to);
        }

        return InvoiceResource::collection($query->latest('issue_date')->paginate(20));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'hospital_id'  => ['required', 'exists:hospitals,id'],
            'machine_id'   => ['nullable', 'exists:machines,id'],
            'issue_date'   => ['required', 'date'],
            'due_date'     => ['required', 'date', 'after_or_equal:issue_date'],
            'tax_rate'     => ['nullable', 'numeric', 'min:0', 'max:100'],
            'status'       => ['required', 'in:pending,partial,paid,overdue,waived'],
            'currency'     => ['nullable', 'string', 'max:10'],
            'notes'        => ['nullable', 'string'],
            'line_items'   => ['required', 'array', 'min:1'],
            'line_items.*.description' => ['required', 'string'],
            'line_items.*.quantity'    => ['required', 'numeric', 'min:0.01'],
            'line_items.*.unit_price'  => ['required', 'integer', 'min:0'],
        ]);

        $lineItems = $data['line_items'];
        unset($data['line_items']);

        $subtotal = collect($lineItems)->sum(fn ($i) => (int) ($i['quantity'] * $i['unit_price']));
        $taxRate  = $data['tax_rate'] ?? 18.0;
        $taxAmount = (int) round($subtotal * $taxRate / 100);

        $data['subtotal']   = $subtotal;
        $data['tax_rate']   = $taxRate;
        $data['tax_amount'] = $taxAmount;
        $data['total']      = $subtotal + $taxAmount;
        $data['amount_paid'] = 0;

        $lastInvoice = Invoice::orderByDesc('id')->first();
        $nextNum = $lastInvoice ? ((int) substr($lastInvoice->invoice_number, 4) + 1) : 1001;
        $data['invoice_number'] = 'INV-' . str_pad($nextNum, 4, '0', STR_PAD_LEFT);

        $invoice = Invoice::create($data);

        foreach ($lineItems as $item) {
            $invoice->lineItems()->create([
                'description' => $item['description'],
                'quantity'    => $item['quantity'],
                'unit_price'  => $item['unit_price'],
                'total'       => (int) ($item['quantity'] * $item['unit_price']),
            ]);
        }

        return response()->json([
            'data' => new InvoiceResource($invoice->load(['hospital', 'machine', 'lineItems'])),
        ], 201);
    }

    public function show(Invoice $invoice)
    {
        $invoice->load(['hospital', 'machine', 'lineItems']);

        return response()->json(['data' => new InvoiceResource($invoice)]);
    }

    public function update(Request $request, Invoice $invoice)
    {
        $data = $request->validate([
            'hospital_id'  => ['sometimes', 'exists:hospitals,id'],
            'machine_id'   => ['nullable', 'exists:machines,id'],
            'issue_date'   => ['sometimes', 'date'],
            'due_date'     => ['sometimes', 'date'],
            'amount_paid'  => ['nullable', 'integer', 'min:0'],
            'status'       => ['sometimes', 'in:pending,partial,paid,overdue,waived'],
            'notes'        => ['nullable', 'string'],
        ]);

        $invoice->update($data);

        return response()->json(['data' => new InvoiceResource($invoice->load(['hospital', 'machine', 'lineItems']))]);
    }

    public function destroy(Invoice $invoice)
    {
        $invoice->delete();

        return response()->json(null, 204);
    }
}
