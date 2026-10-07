<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// "Money is out" as the one name for a released payment (per diem's
// wording, now used by every money flow): the paid notifications get that
// title — only where nobody has edited the template — and payroll gets a
// paid notification it never had.
return new class extends Migration
{
    private const TITLES = [
        'per_diem.paid' => ['Per-Diem Paid', 'Money is out — per diem'],
        'expense.paid' => ['Expense Paid', 'Money is out — expense'],
        'vendor_fee.paid' => ['Vendor Fee Paid', 'Money is out — vendor fee'],
    ];

    public function up(): void
    {
        foreach (self::TITLES as $key => [$old, $new]) {
            DB::table('notification_templates')->where('template_key', $key)->where('title_template', $old)
                ->update(['title_template' => $new, 'updated_at' => now()]);
        }

        DB::table('notification_templates')->insertOrIgnore([
            'template_key' => 'payroll.paid', 'notification_type' => 'payroll_paid',
            'title_template' => 'Money is out — payroll {period}',
            'body_template' => 'Payroll for {period} has been paid: TZS {net_total} to {staff_count} staff.',
            'description' => 'Payroll preparer — the run they prepared was paid',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        foreach (self::TITLES as $key => [$old, $new]) {
            DB::table('notification_templates')->where('template_key', $key)->where('title_template', $new)
                ->update(['title_template' => $old, 'updated_at' => now()]);
        }
        DB::table('notification_templates')->where('template_key', 'payroll.paid')->delete();
    }
};
