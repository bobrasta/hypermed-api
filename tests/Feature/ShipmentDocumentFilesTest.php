<?php

namespace Tests\Feature;

use App\Models\ShipmentDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// 2026-11-09 (user): several files per shipment document type, and each
// uploaded file can be deleted.
//
// Needs a migrated Postgres database with the seeded roles; skipped on SQLite.
class ShipmentDocumentFilesTest extends TestCase
{
    use DatabaseTransactions;

    private User $officer;

    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('needs a migrated Postgres database');
        }
        if (collect(Schema::getIndexes('shipment_documents'))->contains(fn ($i) => $i['unique'] && $i['columns'] === ['shipment_id', 'type'])) {
            (require database_path('migrations/2026_11_09_000001_shipment_documents_allow_several_per_type.php'))->up();
        }
        Storage::fake('public');
        $this->officer = User::forceCreate(['name' => 'Test sd-officer', 'email' => 'sd-officer@example.com',
            'password' => bcrypt('x'), 'role' => 'procurement_manager', 'is_active' => true]);
        $this->officer->assignRole('procurement_manager');
        Sanctum::actingAs($this->officer);
    }

    private function shipment(): int
    {
        return $this->post('/api/v1/shipments', [
            'direction' => 'import', 'freight_mode' => 'air', 'description' => 'Liaison XL reagents', 'coa_required' => false,
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
    }

    private function upload(int $id, string $type, string $name): array
    {
        return $this->post("/api/v1/shipments/{$id}/documents",
            ['type' => $type, 'file' => UploadedFile::fake()->create($name, 10, 'application/pdf')],
            ['Accept' => 'application/json'])->assertOk()->json('data');
    }

    private function doc(array $detail, string $type): array
    {
        return collect($detail['documents'])->firstWhere('type', $type);
    }

    public function test_several_files_per_type_each_downloadable_and_deletable(): void
    {
        $id = $this->shipment();
        $this->upload($id, 'packing_list', 'packing-1.pdf');
        $d = $this->doc($this->upload($id, 'packing_list', 'packing-2.pdf'), 'packing_list');

        $this->assertSame(['packing-2.pdf', 'packing-1.pdf'], array_column($d['files'], 'file_name'));
        $this->assertSame('packing-2.pdf', $d['file_name']); // newest, for older apps
        $this->assertSame(2, ShipmentDocument::where('shipment_id', $id)->where('type', 'packing_list')->count());

        $first = $d['files'][1];
        $this->get("/api/v1/shipments/{$id}/document-files/{$first['id']}")->assertOk()->assertDownload('packing-1.pdf');
        $this->get("/api/v1/shipments/{$id}/documents/packing_list")->assertOk()->assertDownload('packing-2.pdf');

        $after = $this->deleteJson("/api/v1/shipments/{$id}/document-files/{$first['id']}")->assertOk()->json('data');
        $this->assertSame(['packing-2.pdf'], array_column($this->doc($after, 'packing_list')['files'], 'file_name'));
        $this->assertNull(ShipmentDocument::find($first['id']));
        $this->assertTrue(collect($after['events'])->contains(fn ($e) => str_contains((string) $e['note'], 'Document deleted: Packing list — packing-1.pdf')));
    }

    public function test_a_file_of_another_shipment_cannot_be_deleted_through_this_one(): void
    {
        $a = $this->shipment();
        $b = $this->shipment();
        $fileOfB = $this->doc($this->upload($b, 'packing_list', 'b.pdf'), 'packing_list')['files'][0]['id'];

        $this->deleteJson("/api/v1/shipments/{$a}/document-files/{$fileOfB}")->assertNotFound();
        $this->assertNotNull(ShipmentDocument::find($fileOfB));
    }
}
