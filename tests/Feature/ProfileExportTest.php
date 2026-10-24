<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

// 2026-10-24: everyone can export their own data (PDF or Excel).
class ProfileExportTest extends TestCase
{
    use DatabaseTransactions;

    public function test_exports_own_data_as_pdf_and_excel(): void
    {
        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('needs a migrated Postgres database');
        }
        foreach (['health_insurance_amount' => '2026_10_24_000004_health_insurance_and_payroll_autoload',
            'salary_placeholder' => '2026_10_24_000005_add_salary_placeholder_to_contracts'] as $col => $m) {
            if (! \Illuminate\Support\Facades\Schema::hasColumn('contracts', $col)) {
                (require database_path("migrations/{$m}.php"))->up();
            }
        }
        $u = User::forceCreate(['name' => 'Export Tester', 'email' => 'export.tester@example.com', 'password' => bcrypt('x'),
            'role' => 'technician', 'is_active' => true, 'nssf_number' => 'NS-1234567', 'next_of_kin_name' => 'Asha Tester']);
        $u->assignRole('technician');
        Contract::create(['user_id' => $u->id, 'contract_type' => 'permanent', 'start_date' => '2025-02-01',
            'base_salary' => 1_000_000, 'salary_placeholder' => true, 'status' => 'active']);
        $run = PayrollRun::create(['period_month' => 9, 'period_year' => 2026, 'status' => 'paid', 'gross_total' => 0, 'deductions_total' => 0, 'net_total' => 0]);
        PayrollItem::create(['payroll_run_id' => $run->id, 'user_id' => $u->id, 'base_salary' => 1_000_000, 'allowances_total' => 0,
            'overtime_amount' => 0, 'paye_amount' => 105_500, 'nssf_amount' => 100_000, 'heslb_amount' => 0, 'other_deductions' => 0,
            'gross_pay' => 1_000_000, 'net_pay' => 794_500]);
        Sanctum::actingAs($u);

        $pdf = $this->get('/api/v1/auth/profile/export?format=pdf')->assertOk();
        $this->assertStringStartsWith('%PDF', (string) $pdf->getContent());

        $res = $this->get('/api/v1/auth/profile/export?format=xlsx')->assertOk();
        $path = $res->getFile()->getPathname();
        $book = IOFactory::load($path);
        $this->assertSame(['Personal details', 'Statutory IDs', 'Next of kin', 'Payment details', 'Employment', 'Salary changes', 'Payslips', 'Leave', 'Expenses'],
            $book->getSheetNames());
        $this->assertSame('NS-1234567', $book->getSheetByName('Statutory IDs')->getCell('B1')->getValue());
        $this->assertSame('Asha Tester', $book->getSheetByName('Next of kin')->getCell('B1')->getValue());
        $this->assertStringContainsString('placeholder', $book->getSheetByName('Employment')->getCell('B5')->getValue());
        $this->assertSame('TSh 794,500', $book->getSheetByName('Payslips')->getCell('G2')->getValue());
    }
}
