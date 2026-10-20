<?php

use App\Models\AccountCategory;
use App\Models\ChartOfAccount;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Creditors (suppliers + vendors) linked through to the books:
// - supplier invoices (vendor_bills) in the supplier's currency with the
//   invoice's exchange rate and their TZS value;
// - supplier payments as their own records, each through initiate →
//   Director final approval, posting from the bank/cash account they left;
// - allocations matching payments to invoices/opening balances (FIFO), so
//   realised exchange differences can post to FX gain/loss;
// - an opening balance per creditor at the cut-over date;
// - LPOs can be approved on credit (no payment up front);
// - vendor fees and per diem record the account they were paid from.
return new class extends Migration
{
    private const PRIOR_TYPES = [
        'service_due', 'ticket_assigned', 'ticket_updated', 'payment_overdue',
        'warranty_expiring', 'deal_updated', 'system', 'lead_follow_up',
        'task_assigned', 'task_completed', 'stock_pull_required',
        'leave_requested', 'leave_approved', 'leave_rejected', 'late_arrival',
        'stock_out_requested', 'stock_out_approved', 'stock_out_rejected',
        'per_diem_requested', 'per_diem_forwarded', 'per_diem_approved', 'per_diem_rejected',
        'expense_requested', 'expense_escalated', 'expense_approved', 'expense_rejected',
        'po_submitted', 'po_sales_approved', 'po_director_reviewed', 'po_payment_initiated',
        'po_approved', 'po_rejected', 'low_stock_alert', 'per_diem_paid', 'hr_alert',
        'expense_paid', 'ticket_billing_overridden',
        'vendor_fee_ready_for_payment', 'vendor_fee_paid', 'vendor_fee_rejected',
        'tender_deadline', 'tender_overdue', 'device_renewal',
        'shipment_created', 'shipment_status',
        'meeting_scheduled', 'meeting_minutes',
        'nest_tender_new', 'nest_tender_changed', 'nest_tender_digest',
    ];

    private const NEW_TYPES = ['supplier_payment_requested', 'supplier_payment_paid', 'supplier_payment_rejected'];

    private function setNotificationTypes(array $types): void
    {
        DB::statement('ALTER TABLE notifications DROP CONSTRAINT IF EXISTS notifications_type_check');
        DB::statement("ALTER TABLE notifications ADD CONSTRAINT notifications_type_check CHECK (type IN ('" . implode("','", $types) . "'))");
    }

    public function up(): void
    {
        $category = fn (string $type) => AccountCategory::where('type', $type)->value('id');
        $account = fn (string $code, string $name, string $type) => ChartOfAccount::firstOrCreate(['code' => $code], [
            'name' => $name, 'category_id' => $category($type), 'currency' => 'TZS', 'balance' => 0, 'status' => 'active',
        ]);
        $account('4005', 'Foreign Exchange Gain', 'revenue');
        $account('5059', 'Foreign Exchange Loss', 'expense');
        // Suspense for creditor opening balances until the full opening trial
        // balance is entered; the accountant reclassifies it then.
        $account('3006', 'Opening Balances — Creditors', 'equity');

        // ── Supplier invoices: amounts in the invoice currency (2 dp) ────────
        foreach (['subtotal', 'tax_amount', 'total', 'amount_paid'] as $c) {
            DB::statement("ALTER TABLE vendor_bills ALTER COLUMN {$c} TYPE numeric(16,2)");
        }
        foreach (['unit_price', 'total'] as $c) {
            DB::statement("ALTER TABLE vendor_bill_line_items ALTER COLUMN {$c} TYPE numeric(16,2)");
        }
        Schema::table('vendor_bills', function (Blueprint $t) {
            $t->string('supplier_reference')->nullable()->after('bill_number'); // the supplier's own invoice number
            $t->decimal('exchange_rate', 14, 4)->default(1)->after('currency');  // TZS per unit
            $t->bigInteger('total_tzs')->default(0)->after('exchange_rate');     // as posted (advances at their own rate)
        });

        // ── Payments to suppliers ───────────────────────────────────────────
        Schema::create('supplier_payments', function (Blueprint $t) {
            $t->id();
            $t->string('payment_number')->unique();
            // A supplier, or a vendor (e.g. paying off a vendor's opening balance).
            $t->foreignId('supplier_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('vendor_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('purchase_order_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('vendor_bill_id')->nullable()->constrained()->nullOnDelete(); // settle this invoice first
            $t->string('currency', 10);
            $t->decimal('amount', 16, 2);
            $t->decimal('exchange_rate', 14, 4)->default(1);
            $t->bigInteger('amount_tzs');
            $t->foreignId('paid_from_account_id')->constrained('chart_of_accounts');
            $t->date('payment_date');
            $t->string('reference')->nullable();  // bank / transfer reference
            $t->text('notes')->nullable();
            $t->string('status')->default('pending_director'); // pending_director | paid | rejected
            $t->bigInteger('fx_difference_tzs')->default(0);     // + loss, − gain, set when posted
            $t->foreignId('initiated_by')->constrained('users');
            $t->timestamp('initiated_at')->nullable();
            $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('approved_at')->nullable();
            $t->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('rejected_at')->nullable();
            $t->text('rejection_reason')->nullable();
            $t->timestamps();
            $t->index(['supplier_id', 'status']);
        });
        DB::statement("ALTER TABLE supplier_payments ADD CONSTRAINT supplier_payments_status_check CHECK (status IN ('pending_director','paid','rejected'))");
        DB::statement('ALTER TABLE supplier_payments ADD CONSTRAINT supplier_payments_creditor_check CHECK ((supplier_id IS NULL) <> (vendor_id IS NULL))');

        // ── Opening balance per creditor at the cut-over date ───────────────
        Schema::create('creditor_opening_balances', function (Blueprint $t) {
            $t->id();
            $t->string('creditor_type');            // supplier | vendor
            $t->unsignedBigInteger('creditor_id');
            $t->date('as_at');
            $t->string('currency', 10);
            $t->decimal('amount', 16, 2);          // + we owe them, − we paid in advance
            $t->decimal('exchange_rate', 14, 4)->default(1);
            $t->bigInteger('amount_tzs');
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['creditor_type', 'creditor_id']);
        });
        DB::statement("ALTER TABLE creditor_opening_balances ADD CONSTRAINT creditor_opening_balances_type_check CHECK (creditor_type IN ('supplier','vendor'))");

        // ── Payment ↔ invoice/opening matching (supplier currency) ─────────
        Schema::create('creditor_allocations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('supplier_payment_id')->nullable()->constrained()->cascadeOnDelete();
            $t->foreignId('opening_balance_id')->nullable()->constrained('creditor_opening_balances')->cascadeOnDelete(); // opening advance used as the payment side
            $t->foreignId('vendor_bill_id')->nullable()->constrained()->cascadeOnDelete();
            $t->foreignId('opening_owed_id')->nullable()->constrained('creditor_opening_balances')->cascadeOnDelete(); // opening owed settled
            $t->decimal('amount', 16, 2);
            // payment = a payment settled something already owed (FX posted with
            // the payment); advance = an invoice used up an earlier payment.
            $t->string('kind');
            $t->timestamps();
        });

        // ── LPO on credit; payment accounts on vendor fees / per diem ───────
        Schema::table('purchase_orders', function (Blueprint $t) {
            $t->boolean('on_credit')->default(false)->after('payment_status');
        });
        Schema::table('vendor_fees', function (Blueprint $t) {
            $t->foreignId('paid_from_account_id')->nullable()->after('payment_reference')->constrained('chart_of_accounts');
        });
        Schema::table('per_diem_requests', function (Blueprint $t) {
            $t->foreignId('paid_from_account_id')->nullable()->after('paid_at')->constrained('chart_of_accounts');
        });

        if (DB::getDriverName() === 'pgsql') {
            $this->setNotificationTypes([...self::PRIOR_TYPES, ...self::NEW_TYPES]);
        }

        // Vendor fees already recorded and not rejected are owed: into the books now.
        foreach (\App\Models\VendorFee::where('status', '!=', 'rejected')->get() as $fee) {
            app(\App\Services\Creditors\CreditorService::class)->postVendorFee($fee);
            if ($fee->status === 'paid') {
                app(\App\Services\Creditors\CreditorService::class)->postVendorFeePaid($fee);
            }
        }
        foreach ([
            ['supplier_payment.requested', 'supplier_payment_requested', 'Payment {payment_number} awaits your approval',
                '{actor} asks to pay {creditor} {amount} from {account}{po_suffix}.', 'Director — a supplier/vendor payment needs final approval'],
            ['supplier_payment.paid', 'supplier_payment_paid', 'Payment {payment_number} approved',
                '{actor} approved paying {creditor} {amount} from {account}.', 'Whoever initiated the payment — it was approved and posted'],
            ['supplier_payment.rejected', 'supplier_payment_rejected', 'Payment {payment_number} rejected',
                '{actor} rejected paying {creditor} {amount}. Reason: {reason}', 'Whoever initiated the payment — it was rejected'],
        ] as [$key, $type, $title, $body, $description]) {
            DB::table('notification_templates')->insertOrIgnore([
                'template_key' => $key, 'notification_type' => $type, 'title_template' => $title,
                'body_template' => $body, 'description' => $description, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        DB::table('document_sequences')->insertOrIgnore([
            'document_type' => 'supplier_payment', 'label' => 'Supplier payment', 'prefix' => 'SPAY', 'digits' => 4,
            'reset_yearly' => true, 'next_number' => 1, 'year' => (int) now()->format('Y'),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('notification_templates')->where('template_key', 'like', 'supplier_payment.%')->delete();
        DB::table('notifications')->whereIn('type', self::NEW_TYPES)->delete();
        if (DB::getDriverName() === 'pgsql') {
            $this->setNotificationTypes(self::PRIOR_TYPES);
        }
        DB::table('document_sequences')->where('document_type', 'supplier_payment')->delete();
        Schema::table('per_diem_requests', fn (Blueprint $t) => $t->dropConstrainedForeignId('paid_from_account_id'));
        Schema::table('vendor_fees', fn (Blueprint $t) => $t->dropConstrainedForeignId('paid_from_account_id'));
        Schema::table('purchase_orders', fn (Blueprint $t) => $t->dropColumn('on_credit'));
        Schema::dropIfExists('creditor_allocations');
        Schema::dropIfExists('creditor_opening_balances');
        Schema::dropIfExists('supplier_payments');
        Schema::table('vendor_bills', fn (Blueprint $t) => $t->dropColumn(['supplier_reference', 'exchange_rate', 'total_tzs']));
        foreach (['subtotal', 'tax_amount', 'total', 'amount_paid'] as $c) {
            DB::statement("ALTER TABLE vendor_bills ALTER COLUMN {$c} TYPE bigint USING round({$c})");
        }
        foreach (['unit_price', 'total'] as $c) {
            DB::statement("ALTER TABLE vendor_bill_line_items ALTER COLUMN {$c} TYPE bigint USING round({$c})");
        }
        ChartOfAccount::whereIn('code', ['4005', '5059', '3006'])->where('balance', 0)->delete();
    }
};
