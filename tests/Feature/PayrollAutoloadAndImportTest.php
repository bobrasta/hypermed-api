<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\PayrollRun;
use App\Models\SalaryAdjustment;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

// 2026-10-24: payroll loads everyone with pay on record (nobody added by
// hand); staff pay comes in from a spreadsheet; health insurance is a
// company-paid monthly premium, posted as its own expense when paid.
class PayrollAutoloadAndImportTest extends TestCase
{
    use DatabaseTransactions;

    private User $accountant;

    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('needs a migrated Postgres database');
        }
        if (! Schema::hasColumn('contracts', 'health_insurance_amount')) {
            (require database_path('migrations/2026_10_24_000004_health_insurance_and_payroll_autoload.php'))->up();
        }
        // Only these people are "active staff" for the test.
        User::query()->update(['is_active' => false]);
        $this->accountant = $this->user('Accountant PI', 'accountant');
    }

    private function user(string $name, string $role, array $extra = []): User
    {
        $u = User::forceCreate($extra + ['name' => $name, 'email' => str_replace(' ', '.', strtolower($name)) . '@example.com',
            'password' => bcrypt('x'), 'role' => $role, 'is_active' => true]);
        $u->assignRole($role);

        return $u;
    }

    private function sheet(array $rows): UploadedFile
    {
        $book = new Spreadsheet();
        $book->getActiveSheet()->fromArray($rows);
        $path = tempnam(sys_get_temp_dir(), 'pay') . '.xlsx';
        (new Xlsx($book))->save($path);

        return new UploadedFile($path, 'staff pay.xlsx', null, null, true);
    }

    private function import(array $rows, bool $commit): array
    {
        return $this->post('/api/v1/payroll/staff-import', ['file' => $this->sheet($rows), 'commit' => $commit ? '1' : '0'],
            ['Accept' => 'application/json'])->assertOk()->json('data');
    }

    public function test_import_then_payroll_loads_everyone_and_posts_health_insurance(): void
    {
        $tito = $this->user('Yusuph Kulwa Tito', 'team_leader');
        $diana = $this->user('Diana Francis Mvukiye', 'procurement_manager');
        $noPay = $this->user('No Pay Yet', 'technician');
        Sanctum::actingAs($this->accountant);

        $rows = [
            ['HYPERMED STAFF PAY — OCTOBER 2026'],
            ['Name', 'Email', 'Basic Salary', 'Allowances', 'Health Insurance', 'NSSF No', 'TIN', 'Bank', 'Account Number'],
            ['Yusuph Tito', '', '1,200,000', '100,000', '45,000', 'NS-778', '123-456-789', 'CRDB', '0152 4410 22'],
            ['Someone Unknown', '', '900000', '', '', '', '', '', ''],
            ['', $diana->email, '2,000,000', '', '60000', '', '', '', ''],
        ];
        $p = $this->import($rows, false);
        $this->assertSame([false, 2, 1], [$p['committed'], $p['ready'], $p['unmatched']]);
        $this->assertSame($tito->id, $p['rows'][0]['user']['id']); // "Yusuph Tito" → "Yusuph Kulwa Tito"
        $this->assertNull($tito->fresh()->activeContract); // preview saved nothing

        $c = $this->import($rows, true);
        $this->assertTrue($c['committed']);
        $tito->refresh()->load('activeContract.allowances', 'paymentProfile');
        $this->assertSame([1_200_000, 45_000, 100_000, 'NS-778', '0152 4410 22'], [(int) $tito->activeContract->base_salary,
            (int) $tito->activeContract->health_insurance_amount, (int) $tito->activeContract->allowances->sum('amount'),
            $tito->nssf_number, $tito->paymentProfile->account_number]);

        // A new run is loaded with everyone who has pay; the rest are listed.
        $run = $this->postJson('/api/v1/payroll-runs', ['period_month' => 10, 'period_year' => 2026])->assertCreated()->json();
        $items = collect($run['data']['items'])->keyBy('user_id');
        $this->assertEqualsCanonicalizing([$tito->id, $diana->id], $items->keys()->all());
        $this->assertSame([1_300_000, 45_000], [$items[$tito->id]['gross_pay'], $items[$tito->id]['health_insurance_employer']]);
        $this->assertContains($noPay->id, collect($run['staff_without_salary'])->pluck('id')->all());

        // Approve (Director) and pay: net pay and health insurance post separately.
        $id = $run['data']['id'];
        $this->postJson("/api/v1/payroll-runs/{$id}/review")->assertOk();
        Sanctum::actingAs($this->user('Director PI', 'admin'));
        $this->assertSame(105_000, $this->postJson("/api/v1/payroll-runs/{$id}/approve")->assertOk()->json('data.health_insurance_total'));
        Sanctum::actingAs($this->accountant);
        $this->postJson("/api/v1/payroll-runs/{$id}/mark-paid")->assertOk();
        $health = Expense::find(PayrollRun::find($id)->health_insurance_expense_id);
        $this->assertSame([105_000, 'paid'], [(int) $health->amount, $health->status]);

        // Re-import with a new salary: not changed directly — sent to the Director.
        $rows[2][2] = '1,350,000';
        $this->import($rows, true);
        $this->assertSame(1_200_000, (int) $tito->fresh()->activeContract->base_salary);
        $this->assertTrue(SalaryAdjustment::where('user_id', $tito->id)->where('new_salary', 1_350_000)->where('status', 'pending')->exists());
    }

    public function test_only_payroll_authority_can_import(): void
    {
        Sanctum::actingAs($this->user('Tech PI', 'technician'));
        $this->post('/api/v1/payroll/staff-import', ['file' => $this->sheet([['Name', 'Salary'], ['X', '1']])], ['Accept' => 'application/json'])
            ->assertForbidden();
    }
}
