<?php

namespace Tests\Feature;

use App\Models\Hospital;
use App\Models\Machine;
use App\Models\ServiceTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// A ticket's checklist: the assignee adds an item and ticks it, and both
// survive a reload (update() never took checklist items, so the app's Add
// and ticks used to be dropped). Also: Register new machine saves the model
// number and manufacturer.
//
// Needs a migrated Postgres database (DB_* env vars); skipped on the default
// in-memory SQLite. Each test runs in a rolled-back transaction.
class TicketChecklistAndMachineFieldsTest extends TestCase
{
    use DatabaseTransactions;

    private User $tech;
    private User $outsider;
    private Hospital $hospital;

    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('needs a migrated Postgres database');
        }
        // Same as the 2026_10_15 migration, in case it hasn't run locally.
        if (! Schema::hasColumn('machines', 'model_number')) {
            DB::statement('ALTER TABLE machines ADD COLUMN model_number VARCHAR(100) NULL');
        }

        $this->tech = $this->user('tech');
        $this->outsider = $this->user('outsider');
        $this->hospital = Hospital::forceCreate(['name' => 'Checklist Test Hospital', 'type' => 'public']);
    }

    private function user(string $tag): User
    {
        return User::forceCreate([
            'name' => "Test {$tag}", 'email' => "checklist-test-{$tag}@example.com",
            'password' => bcrypt('x'), 'role' => 'technician', 'is_active' => true,
        ]);
    }

    private function ticket(): ServiceTicket
    {
        $machine = Machine::forceCreate([
            'serial_no' => 'CHK-TEST-001', 'model' => 'Checklist Test Analyzer', 'type' => 'Hematology Analyzer',
            'hospital_id' => $this->hospital->id, 'status' => 'operational',
        ]);

        return ServiceTicket::forceCreate([
            'ticket_number' => 'TK-CHK-TEST', 'machine_id' => $machine->id, 'hospital_id' => $this->hospital->id,
            'assigned_to' => $this->tech->id, 'status' => 'open',
        ]);
    }

    public function test_assignee_adds_an_item_and_ticks_it_and_both_persist(): void
    {
        $ticket = $this->ticket();
        Sanctum::actingAs($this->tech);

        $add = $this->postJson("/api/v1/tickets/{$ticket->id}/checklist", ['label' => '  Check power supply  ']);
        $add->assertCreated()->assertJsonPath('data.label', 'Check power supply')->assertJsonPath('data.is_checked', false);
        $itemId = $add->json('data.id');

        $this->postJson("/api/v1/tickets/{$ticket->id}/checklist/{$itemId}")
            ->assertOk()->assertJsonPath('data.is_checked', true);

        $this->getJson("/api/v1/tickets/{$ticket->id}")
            ->assertOk()
            ->assertJsonPath('data.checklist.0.label', 'Check power supply')
            ->assertJsonPath('data.checklist.0.is_checked', true);
    }

    public function test_someone_not_on_the_ticket_cannot_add_items(): void
    {
        $ticket = $this->ticket();
        Sanctum::actingAs($this->outsider);

        $this->postJson("/api/v1/tickets/{$ticket->id}/checklist", ['label' => 'Nope'])->assertForbidden();
        $this->assertSame(0, $ticket->checklistItems()->count());
    }

    public function test_register_machine_saves_model_number_and_manufacturer(): void
    {
        Sanctum::actingAs($this->tech);

        $res = $this->postJson('/api/v1/machines', [
            'model' => 'Checklist Test Ultrasound', 'model_number' => 'DC-70',
            'serial_no' => 'CHK-TEST-002', 'manufacturer' => 'Mindray',
            'type' => 'Ultrasound Unit', 'hospital_id' => $this->hospital->id,
            'status' => 'pending_installation',
        ]);

        $res->assertCreated()
            ->assertJsonPath('data.model_number', 'DC-70')
            ->assertJsonPath('data.manufacturer', 'Mindray')
            ->assertJsonPath('data.type', 'Ultrasound Unit');
        $this->assertDatabaseHas('machines', ['serial_no' => 'CHK-TEST-002', 'model_number' => 'DC-70', 'manufacturer' => 'Mindray']);
    }

    public function test_register_machine_fills_missing_facility_code_and_coordinates(): void
    {
        Sanctum::actingAs($this->tech);

        $this->postJson('/api/v1/machines', [
            'model' => 'Checklist Test X-Ray', 'serial_no' => 'CHK-TEST-003', 'type' => 'X-Ray',
            'hospital_id' => $this->hospital->id, 'status' => 'pending_installation',
            'facility_code' => ' 111144-2 ', 'facility_latitude' => -3.36642, 'facility_longitude' => 36.69498,
        ])->assertCreated();

        $h = $this->hospital->fresh();
        $this->assertSame('111144-2', $h->short_code);
        $this->assertEqualsWithDelta(-3.36642, $h->latitude, 0.00001);
        $this->assertEqualsWithDelta(36.69498, $h->longitude, 0.00001);
    }

    public function test_register_machine_never_overwrites_facility_data(): void
    {
        $this->hospital->forceFill(['short_code' => 'KEEP-1', 'latitude' => -6.8, 'longitude' => 39.2])->save();
        Sanctum::actingAs($this->tech);

        $this->postJson('/api/v1/machines', [
            'model' => 'Checklist Test X-Ray', 'serial_no' => 'CHK-TEST-004', 'type' => 'X-Ray',
            'hospital_id' => $this->hospital->id, 'status' => 'pending_installation',
            'facility_code' => 'OTHER', 'facility_latitude' => 1, 'facility_longitude' => 1,
        ])->assertCreated();

        $h = $this->hospital->fresh();
        $this->assertSame('KEEP-1', $h->short_code);
        $this->assertEqualsWithDelta(-6.8, $h->latitude, 0.00001);
    }

    public function test_facility_coordinates_must_come_as_a_valid_pair(): void
    {
        Sanctum::actingAs($this->tech);

        $this->postJson('/api/v1/machines', [
            'model' => 'Checklist Test X-Ray', 'serial_no' => 'CHK-TEST-005', 'type' => 'X-Ray',
            'hospital_id' => $this->hospital->id, 'status' => 'pending_installation',
            'facility_latitude' => -3.3,
        ])->assertUnprocessable()->assertJsonValidationErrors('facility_longitude');

        $this->postJson('/api/v1/machines', [
            'model' => 'Checklist Test X-Ray', 'serial_no' => 'CHK-TEST-006', 'type' => 'X-Ray',
            'hospital_id' => $this->hospital->id, 'status' => 'pending_installation',
            'facility_latitude' => 120, 'facility_longitude' => 36.6,
        ])->assertUnprocessable()->assertJsonValidationErrors('facility_latitude');
    }
}
