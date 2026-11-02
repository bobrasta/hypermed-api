<?php

namespace Tests\Feature;

use App\Models\ProcuringEntity;
use App\Models\Tender;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// Uploading a signed copy (contract and the generated documents) to a tender.
class TenderExecutedUploadTest extends TestCase
{
    use DatabaseTransactions;

    private Tender $tender;

    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('needs a migrated Postgres database');
        }
        Storage::fake('public');
        $u = User::forceCreate([
            'name' => 'Test tender-up', 'email' => 'tender-up@example.com',
            'password' => bcrypt('x'), 'role' => 'procurement_manager', 'is_active' => true,
        ]);
        $u->assignRole('procurement_manager');
        Sanctum::actingAs($u);
        $entity = ProcuringEntity::create(['name' => 'ZZ Test Hospital']);
        $this->tender = Tender::create([
            'tender_number' => 'ZZ/UP/001', 'title' => 'Upload test', 'procuring_entity_id' => $entity->id,
            'status' => 'contract_signed', 'contract_number' => 'C-1', 'created_by' => $u->id, 'owner_id' => $u->id,
        ]);
    }

    public function test_signed_contract_pdf_uploads(): void
    {
        $res = $this->post("/api/v1/tenders/{$this->tender->id}/documents/contract/executed", [
            'file' => UploadedFile::fake()->create('Signed Contract.pdf', 900, 'application/pdf'),
        ], ['Accept' => 'application/json']);

        $res->assertOk();
        $slot = collect($res->json('data.documents'))->firstWhere('type', 'contract');
        $this->assertTrue($slot['has_executed']);
        $this->get("/api/v1/tenders/{$this->tender->id}/documents/contract/executed")->assertOk();
    }
}
