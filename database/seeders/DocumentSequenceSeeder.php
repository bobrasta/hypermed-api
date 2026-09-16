<?php

namespace Database\Seeders;

use App\Models\DocumentSequence;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\Quotation;
use App\Models\SalesOrder;
use App\Models\VendorBill;
use App\Models\VendorBillPayment;
use Illuminate\Database\Seeder;

// Seeds next_number by inspecting the highest number ALREADY issued this
// year for each type — never blindly starting at 1, which would collide
// with real documents. Some legacy rows predate the current PREFIX-YEAR-N
// format entirely (e.g. old invoices seeded as "INV-1006"); those are
// intentionally excluded by the same year-prefix filter the old per-
// controller helpers already used, so behavior is unchanged, not just
// carried over.
class DocumentSequenceSeeder extends Seeder
{
    public function run(): void
    {
        $year = (int) now()->format('Y');

        $types = [
            ['invoice', 'Invoice', 'INV', Invoice::class, 'invoice_number'],
            ['purchase_order', 'Purchase Order', 'PO', PurchaseOrder::class, 'po_number'],
            ['purchase_requisition', 'Purchase Requisition', 'PR', PurchaseRequisition::class, 'pr_number'],
            ['quotation', 'Quotation', 'QT', Quotation::class, 'quotation_number'],
            ['sales_order', 'Sales Order', 'SO', SalesOrder::class, 'order_number'],
            ['vendor_bill', 'Vendor Bill', 'BILL', VendorBill::class, 'bill_number'],
            ['vendor_bill_payment', 'Vendor Bill Payment', 'BPAY', VendorBillPayment::class, 'payment_number'],
            ['payment', 'Customer Payment', 'PAY', Payment::class, 'payment_number'],
        ];

        foreach ($types as [$type, $label, $prefix, $modelClass, $column]) {
            if (DocumentSequence::where('document_type', $type)->exists()) {
                continue;
            }

            $last = $modelClass::where($column, 'like', "{$prefix}-{$year}-%")
                ->orderByDesc('id')
                ->value($column);
            $nextNumber = $last ? ((int) substr($last, -4) + 1) : 1;

            DocumentSequence::create([
                'document_type' => $type,
                'label'         => $label,
                'prefix'        => $prefix,
                'digits'        => 4,
                'reset_yearly'  => true,
                'next_number'   => $nextNumber,
                'year'          => $year,
            ]);
        }
    }
}
