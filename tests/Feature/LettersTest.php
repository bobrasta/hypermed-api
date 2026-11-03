<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// Letters: serial reference numbers (HHL/{year}/0001…), the signatory's own
// signature only when they sign, an optional company stamp, the PDF on the
// letterhead; others only see their own letters.
//
// Needs a migrated Postgres database with the seeded roles; skipped on SQLite.
class LettersTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('needs a migrated Postgres database');
        }
        Cache::flush();
        Storage::fake('local');
        DB::table('document_sequences')->where('document_type', 'letter')->update(['next_number' => 1, 'year' => (int) now()->format('Y')]);
    }

    private function user(string $role, string $slug): User
    {
        $u = User::forceCreate(['name' => "Test {$slug}", 'email' => "{$slug}@example.com", 'password' => bcrypt('x'), 'role' => $role, 'is_active' => true]);
        $u->assignRole($role);

        return $u;
    }

    public function test_letters_are_numbered_signed_by_the_signatory_and_printed(): void
    {
        $writer = $this->user('administrator', 'letter-writer');
        $director = $this->user('admin', 'letter-director');
        $other = $this->user('sales', 'letter-other');
        $y = now()->format('Y');

        Sanctum::actingAs($writer);
        $body = ['letter_date' => '2026-11-03', 'recipient' => "The Director\nMuhimbili National Hospital\nDar es Salaam",
            'subject' => 'Supply of surgical gloves', 'body' => "Dear Sir,\n\nWe are pleased to quote.\n\nRegards.",
            'signatory_id' => $director->id, 'signatory_position' => 'Managing Director', 'include_stamp' => true];
        $this->getJson('/api/v1/letters/signatories')->assertOk()->assertJsonFragment(['id' => $director->id, 'name' => 'Test letter-director']);
        $id = $this->postJson('/api/v1/letters', $body)->assertCreated()->assertJsonPath('data.reference_number', "HHL/{$y}/0001")->json('data.id');
        $this->postJson('/api/v1/letters', $body)->assertCreated()->assertJsonPath('data.reference_number', "HHL/{$y}/0002");

        // Only the signatory signs, and only with a signature on file.
        $this->postJson("/api/v1/letters/{$id}/sign")->assertForbidden();
        Sanctum::actingAs($director);
        $this->postJson("/api/v1/letters/{$id}/sign")->assertStatus(422);
        $this->post('/api/v1/letters/my-signature', ['file' => UploadedFile::fake()->image('sig.png', 300, 100)])->assertOk();
        $this->postJson("/api/v1/letters/{$id}/sign")->assertOk()->assertJsonPath('data.signed_at', fn ($v) => $v !== null);
        $this->post('/api/v1/letters/stamp', ['file' => UploadedFile::fake()->image('stamp.png', 200, 200)])->assertOk();

        $pdf = $this->get("/api/v1/letters/{$id}/pdf")->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());

        // Editing a signed letter takes the signature off; a signed letter can't be deleted.
        Sanctum::actingAs($writer);
        $this->putJson("/api/v1/letters/{$id}", $body + ['subject' => 'Supply of gloves (revised)'])->assertOk()->assertJsonPath('data.signed_at', null);

        // Someone else sees neither letter.
        Sanctum::actingAs($other);
        $this->getJson('/api/v1/letters')->assertOk()->assertJsonCount(0, 'data');
        $this->get("/api/v1/letters/{$id}/pdf")->assertForbidden();
        $this->post('/api/v1/letters/stamp', ['file' => UploadedFile::fake()->image('s.png')])->assertForbidden();
    }
}
