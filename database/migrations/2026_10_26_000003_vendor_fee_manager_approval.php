<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Clearing & vendor fees (user, 2026-10-26): the recorder's own manager
 * (Patrick for Tender & Procurement) approves first, then Finance requests
 * payment, then the Director pays — each step notified and in Approvals.
 * Before this, Finance was told nothing and the fees sat "awaiting Finance".
 */
return new class extends Migration
{
    private const STATUSES = ['pending_receipt', 'ready_for_payment', 'paid', 'rejected'];

    public function up(): void
    {
        Schema::table('vendor_fees', function (Blueprint $t) {
            $t->foreignId('approver_id')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            $t->foreignId('manager_approved_by')->nullable()->after('approver_id')->constrained('users')->nullOnDelete();
            $t->timestamp('manager_approved_at')->nullable()->after('manager_approved_by');
        });
        if (DB::getDriverName() === 'pgsql') {
            $this->setStatuses([...self::STATUSES, 'pending_manager']);
        }

        foreach ([
            ['vendor_fee.manager_review', 'Fee to approve — {vendor_name}',
                '{name} recorded a fee for {vendor_name}: TZS {billed_amount} — {description}. Approve it to pass it to Finance.',
                'The recorder\'s manager — a clearing/vendor fee waits on their approval'],
            ['vendor_fee.to_pay', 'Fee to pay — {vendor_name}',
                '{vendor_name}: TZS {billed_amount} — {description}. Approved by {manager}. Request the payment in Approvals.',
                'Finance — a clearing/vendor fee is approved and waits on the payment request'],
            ['vendor_fee.receipt_to_verify', 'Receipt to verify — {vendor_name}',
                'A receipt of TZS {amount} was attached to the fee for {vendor_name} ({description}). Verify it in Vendors & Delivery.',
                'Finance — a receipt was attached to a vendor/clearing fee'],
        ] as [$key, $title, $body, $description]) {
            DB::table('notification_templates')->insertOrIgnore([
                'template_key' => $key, 'notification_type' => 'vendor_fee_ready_for_payment', 'title_template' => $title,
                'body_template' => $body, 'description' => $description, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('notification_templates')->whereIn('template_key', ['vendor_fee.manager_review', 'vendor_fee.to_pay', 'vendor_fee.receipt_to_verify'])->delete();
        if (DB::getDriverName() === 'pgsql') {
            DB::table('vendor_fees')->where('status', 'pending_manager')->update(['status' => 'pending_receipt']);
            $this->setStatuses(self::STATUSES);
        }
        Schema::table('vendor_fees', function (Blueprint $t) {
            $t->dropConstrainedForeignId('manager_approved_by');
            $t->dropConstrainedForeignId('approver_id');
            $t->dropColumn('manager_approved_at');
        });
    }

    private function setStatuses(array $statuses): void
    {
        $list = implode(',', array_map(fn ($s) => "'{$s}'", $statuses));
        DB::statement('ALTER TABLE vendor_fees DROP CONSTRAINT IF EXISTS vendor_fees_status_check');
        DB::statement("ALTER TABLE vendor_fees ADD CONSTRAINT vendor_fees_status_check CHECK (status IN ({$list}))");
    }
};
