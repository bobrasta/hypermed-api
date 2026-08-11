<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Location;
use App\Models\Payment;
use App\Models\SalesOrder;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The fast path for a walk-in/counter sale: create a Sales Order, confirm it,
 * deliver everything in full, invoice it, and record full payment — all in
 * one transaction. Reuses the exact same approval/commission/stock/machine
 * logic as the formal Quotation -> Sales Order pipeline (via the shared
 * services below), it just runs all the steps back-to-back instead of one
 * HTTP call per step.
 *
 * If the sale needs manager approval (discount cap or value threshold), the
 * transaction stops after step 1 and returns the pending Sales Order instead
 * of forcing it through — a POS cashier can't self-approve a large discount
 * any more than a quotation could.
 */
class PosSaleService
{
    public function __construct(
        private ApprovalService $approvalService,
        private StockService $stockService,
        private MachineRegistrationService $machineRegistration,
        private FinancePostingService $financePosting,
    ) {}

    /**
     * @param array{
     *   location_id: int, client_name?: string, hospital_id?: int,
     *   commission_agent_id?: int, discount_amount?: int, tax_amount?: int,
     *   payment_method: string, payment_reference?: string,
     *   items: array<int, array{inventory_item_id?: int, description: string, unit_of_measure?: string, quantity: int, unit_price: int}>,
     * } $data
     * @return array{type: 'invoice'|'pending_approval', sales_order: SalesOrder, invoice: ?Invoice}
     */
    public function checkout(User $cashier, array $data): array
    {
        return DB::transaction(function () use ($cashier, $data) {
            $subtotal       = collect($data['items'])->sum(fn ($i) => $i['quantity'] * $i['unit_price']);
            $discountAmount = $data['discount_amount'] ?? 0;
            $taxAmount      = $data['tax_amount'] ?? 0;
            $totalAmount    = $subtotal - $discountAmount + $taxAmount;

            $approvalFields = $this->approvalService->evaluate($cashier, $subtotal, $discountAmount, $totalAmount);

            $year  = now()->format('Y');
            $count = SalesOrder::whereYear('created_at', $year)->count() + 1;

            $order = SalesOrder::create([
                'order_number'        => 'SO-' . $year . '-' . str_pad($count, 4, '0', STR_PAD_LEFT),
                'client_name'         => $data['client_name'] ?? 'Walk-in Customer',
                'hospital_id'         => $data['hospital_id'] ?? null,
                'location_id'         => $data['location_id'],
                'status'              => 'pending',
                'currency'            => 'TZS',
                'subtotal'            => $subtotal,
                'discount_amount'     => $discountAmount,
                'tax_amount'          => $taxAmount,
                'total_amount'        => $totalAmount,
                'created_by'          => $cashier->id,
                'commission_agent_id' => $data['commission_agent_id'] ?? $cashier->id,
                ...$approvalFields,
            ]);

            foreach ($data['items'] as $item) {
                $order->items()->create([
                    'inventory_item_id'  => $item['inventory_item_id'] ?? null,
                    'description'        => $item['description'],
                    'unit_of_measure'    => $item['unit_of_measure'] ?? 'pcs',
                    'quantity_ordered'   => $item['quantity'],
                    'quantity_delivered' => 0,
                    'unit_price'         => $item['unit_price'],
                    'total_price'        => $item['quantity'] * $item['unit_price'],
                ]);
            }

            if ($approvalFields['approval_status'] === 'pending') {
                return [
                    'type'        => 'pending_approval',
                    'sales_order' => $order->fresh(['createdBy', 'items.inventoryItem']),
                    'invoice'     => null,
                ];
            }

            // Confirm, locking commission at today's rate.
            $agent = $order->commissionAgent ?? $order->createdBy;
            $commissionPercent = $agent?->commission_percent;
            $order->update([
                'status'             => 'confirmed',
                'confirmed_by'       => $cashier->id,
                'confirmed_at'       => now(),
                'commission_percent' => $commissionPercent,
                'commission_amount'  => $commissionPercent !== null
                    ? (int) round($totalAmount * $commissionPercent / 100)
                    : null,
            ]);

            // Deliver everything in full — a POS sale hands over the goods
            // at the register, there's no partial fulfillment concept here.
            $location = Location::findOrFail($data['location_id']);
            foreach ($order->items as $soItem) {
                $qty = $soItem->quantity_ordered;
                $soItem->update(['quantity_delivered' => $qty]);

                if ($soItem->inventory_item_id) {
                    $this->stockService->deduct(
                        $soItem->inventoryItem, $location, $qty,
                        "POS sale {$order->order_number}", $order,
                    );

                    if ($order->hospital_id) {
                        $this->machineRegistration->registerForDelivery(
                            $soItem->inventoryItem, $order->hospital_id, $order->order_number, 0, $qty,
                        );
                    }
                }
            }
            $order->update(['status' => 'delivered', 'delivered_at' => now()]);

            // Invoice the whole thing and pay it off immediately.
            $invNumber = $this->nextInvoiceNumber();
            $invoice = Invoice::create([
                'invoice_number' => $invNumber,
                'sales_order_id' => $order->id,
                'hospital_id'    => $order->hospital_id,
                'client_name'    => $order->client_name,
                'issue_date'     => now()->toDateString(),
                'due_date'       => now()->toDateString(),
                'subtotal'       => $subtotal,
                'tax_rate'       => 0,
                'tax_amount'     => $taxAmount,
                'total'          => $totalAmount,
                'amount_paid'    => 0,
                'status'         => 'pending',
                'currency'       => 'TZS',
            ]);

            foreach ($order->items as $soItem) {
                $invoice->lineItems()->create([
                    'description' => $soItem->description,
                    'quantity'    => $soItem->quantity_ordered,
                    'unit_price'  => $soItem->unit_price,
                    'total'       => $soItem->total_price,
                ]);
                $soItem->update(['quantity_invoiced' => $soItem->quantity_ordered]);
            }

            $this->financePosting->postInvoiceIssued($invoice);

            $payment = Payment::create([
                'payment_number' => $this->nextPaymentNumber(),
                'invoice_id'     => $invoice->id,
                'amount'         => $totalAmount,
                'payment_method' => $data['payment_method'],
                'reference'      => $data['payment_reference'] ?? null,
                'paid_at'        => now()->toDateString(),
                'recorded_by'    => $cashier->id,
            ]);

            $invoice->update(['amount_paid' => $totalAmount, 'status' => 'paid', 'paid_at' => now()]);

            $this->financePosting->postPaymentReceived($invoice, $payment);

            return [
                'type'        => 'invoice',
                'sales_order' => $order->fresh(['createdBy', 'confirmedBy', 'commissionAgent', 'items.inventoryItem', 'location', 'hospital']),
                'invoice'     => $invoice->fresh(['hospital', 'salesOrder', 'lineItems', 'payments']),
            ];
        });
    }

    private function nextInvoiceNumber(): string
    {
        $year = date('Y');
        $last = Invoice::where('invoice_number', 'like', "INV-$year-%")
                       ->orderByDesc('id')->value('invoice_number');
        $seq  = $last ? ((int) substr($last, -4) + 1) : 1;
        return 'INV-' . $year . '-' . str_pad($seq, 4, '0', STR_PAD_LEFT);
    }

    private function nextPaymentNumber(): string
    {
        $year = date('Y');
        $last = Payment::where('payment_number', 'like', "PAY-$year-%")
                       ->orderByDesc('id')->value('payment_number');
        $seq  = $last ? ((int) substr($last, -4) + 1) : 1;
        return 'PAY-' . $year . '-' . str_pad($seq, 4, '0', STR_PAD_LEFT);
    }
}
