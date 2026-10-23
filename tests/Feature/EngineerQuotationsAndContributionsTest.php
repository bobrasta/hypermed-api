<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Machine;
use App\Models\Quotation;
use App\Models\ServiceTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

// 2026-10-23: engineers prepare quotations (credited to them, no orders or
// invoices), and the Director's staff contributions record counts who did
// what — quotations, those that became invoices, installations, services.
//
// Needs a migrated Postgres database with the seeded roles; skipped on
// SQLite. The new migration runs inside the test transaction if needed.
class EngineerQuotationsAndContributionsTest extends TestCase
{
    use DatabaseTransactions;

    private User $engineer;

    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('needs a migrated Postgres database');
        }
        if (! Permission::where('name', 'sales.prepare_quotation')->exists()) {
            (require database_path('migrations/2026_10_23_000004_engineers_prepare_quotations_and_contributions.php'))->up();
        }
        $this->engineer = $this->user('Innocent Test', 'technician');
    }

    private function user(string $name, string $role): User
    {
        $u = User::forceCreate([
            'name' => $name, 'email' => str_replace(' ', '.', strtolower($name)) . '.contrib@example.com',
            'password' => bcrypt('x'), 'role' => $role, 'is_active' => true,
        ]);
        $u->assignRole($role);

        return $u;
    }

    private function quote(array $over = []): array
    {
        return $this->postJson('/api/v1/quotations', $over + [
            'client_name' => 'Muhimbili National Hospital', 'client_tin' => '100-200-300',
            'items' => [['description' => 'Liaison XL service kit', 'quantity' => 1, 'unit_price' => 1_500_000]],
        ])->assertCreated()->json('data');
    }

    public function test_engineer_prepares_but_cannot_send_or_convert(): void
    {
        $manager = $this->user('Sales Manager Test', 'sales_manager');
        Sanctum::actingAs($this->engineer);

        $q = $this->quote();
        $this->assertSame($this->engineer->id, Quotation::find($q['id'])->created_by);
        $this->assertTrue(AppNotification::where('user_id', $manager->id)->where('type', 'quotation_prepared')
            ->where('entity_id', $q['id'])->exists());

        $this->putJson("/api/v1/quotations/{$q['id']}", ['notes' => 'Site visit 21 Oct'])->assertOk();
        $this->postJson("/api/v1/quotations/{$q['id']}/send")->assertForbidden();
        $this->postJson('/api/v1/invoices', [])->assertForbidden();

        // Someone else's quotation: hands off.
        Sanctum::actingAs($manager);
        $other = $this->quote();
        Sanctum::actingAs($this->engineer);
        $this->putJson("/api/v1/quotations/{$other['id']}", ['notes' => 'x'])->assertForbidden();
        $this->deleteJson("/api/v1/quotations/{$other['id']}")->assertForbidden();

        // Sales sends it — it stays credited to the engineer.
        Sanctum::actingAs($manager);
        $this->postJson("/api/v1/quotations/{$q['id']}/send")->assertOk();
        $this->assertSame($this->engineer->id, Quotation::find($q['id'])->created_by);
    }

    public function test_contributions_count_who_did_what_and_show_the_records(): void
    {
        Sanctum::actingAs($this->engineer);
        $won = $this->quote();
        $this->quote();
        $this->quote();

        // One of the three reaches a final invoice through a sales order.
        $so = DB::table('sales_orders')->insertGetId($this->row('sales_orders', [
            'order_number' => 'SO-TEST-1', 'quotation_id' => $won['id'], 'client_name' => 'Muhimbili National Hospital',
            'status' => 'confirmed', 'created_by' => $this->engineer->id,
        ]));
        DB::table('invoices')->insert($this->row('invoices', [
            'invoice_number' => 'INV-TEST-1', 'sales_order_id' => $so, 'client_name' => 'Muhimbili National Hospital',
            'status' => 'sent', 'total' => 1_500_000, 'issue_date' => now()->toDateString(),
        ]));

        $machine = Machine::first() ?? $this->markTestSkipped('needs a machine row');
        Machine::whereKey($machine->id)->update(['installed_by' => $this->engineer->id, 'installed_at' => now()]);
        $ticket = ServiceTicket::where('type', 'repair')->first() ?? $this->markTestSkipped('needs a repair ticket');
        ServiceTicket::whereKey($ticket->id)->update(['assigned_to' => $this->engineer->id, 'status' => 'resolved', 'resolved_at' => now()]);

        // Only the Director/CTO see it.
        $this->getJson('/api/v1/contributions')->assertForbidden();

        Sanctum::actingAs($this->user('Director Test', 'super_admin'));
        $row = collect($this->getJson('/api/v1/contributions')->assertOk()->json('data.people'))->firstWhere('id', $this->engineer->id);
        $this->assertSame([3, 1, 1, 1, 1_500_000],
            [$row['quotations'], $row['quotations_invoiced'], $row['installations'], $row['services'], $row['invoiced_value']]);

        $items = $this->getJson("/api/v1/contributions/{$this->engineer->id}/quotations_invoiced")->assertOk()->json('data.items');
        $this->assertSame([[$won['quotation_number'], 'INV-TEST-1']], collect($items)->map(fn ($i) => [$i['reference'], $i['invoice']])->all());

        // Another period: nothing on record.
        $none = $this->getJson('/api/v1/contributions?date_from=2020-01-01&date_to=2020-12-31')->assertOk()->json('data.people');
        $this->assertNull(collect($none)->firstWhere('id', $this->engineer->id));
        $this->getJson("/api/v1/contributions/{$this->engineer->id}/bogus")->assertNotFound();
    }

    /** Fills the NOT NULL columns of $table that have no default, so the test survives schema growth. */
    private function row(string $table, array $values): array
    {
        $required = DB::select("SELECT column_name, data_type FROM information_schema.columns
            WHERE table_name = ? AND is_nullable = 'NO' AND column_default IS NULL", [$table]);
        foreach ($required as $c) {
            if (array_key_exists($c->column_name, $values) || $c->column_name === 'id') {
                continue;
            }
            $values[$c->column_name] = match (true) {
                str_contains($c->data_type, 'timestamp') => now(),
                $c->data_type === 'date' => now()->toDateString(),
                in_array($c->data_type, ['integer', 'bigint', 'numeric', 'smallint'], true) => 0,
                $c->data_type === 'boolean' => false,
                default => 'x',
            };
        }

        return $values + ['created_at' => now(), 'updated_at' => now()];
    }
}
