<?php

namespace Tests\Feature;

use App\Jobs\RecomputeNestRelevance;
use App\Models\AppNotification;
use App\Models\NestRelevanceRule;
use App\Models\NestSyncRun;
use App\Models\NestTender;
use App\Models\Tender;
use App\Models\User;
use App\Services\Nest\NestException;
use App\Services\Nest\NestSyncService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// NeST sync (Http::fake'd GraphQL in the real response shape), change
// detection, EAT parsing, recompute, the API filters and access.
//
// Needs a Postgres database with the seeded roles; skipped on SQLite. The
// NeST migration runs inside the test transaction when it hasn't been
// applied yet, so nothing persists.
class NestTenderSyncTest extends TestCase
{
    use DatabaseTransactions;

    private User $officer;
    private User $technician;

    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('needs a migrated Postgres database');
        }
        if (! Schema::hasTable('nest_tenders')) {
            (require database_path('migrations/2026_10_19_000001_create_nest_tender_tables.php'))->up();
        }
        DB::table('nest_tenders')->delete();
        DB::table('nest_sync_runs')->delete();
        Cache::flush();
        config(['nest.delay_ms' => [0, 0], 'nest.page_size' => 2]);
        Http::fake(['nest.go.tz/*' => fn ($req) => $this->handler
            ? ($this->handler)($req)
            : Http::response(array_shift($this->responses) ?? ['errors' => [['message' => 'no more fake pages']]])]);

        $this->officer = $this->user('procurement_manager', 'nest-officer');
        $this->technician = $this->user('technician', 'nest-tech');
    }

    private function user(string $role, string $slug): User
    {
        $u = User::forceCreate([
            'name' => "Test {$slug}", 'email' => "{$slug}@example.com",
            'password' => bcrypt('x'), 'role' => $role, 'is_active' => true,
        ]);
        $u->assignRole($role);

        return $u;
    }

    private function row(string $uuid, string $description, array $over = []): array
    {
        return $over + [
            'descriptionOfTheProcurement' => $description,
            'entityId' => crc32($uuid) % 100000,
            'entityNumber' => 'TR1/2026/2027/G/' . substr($uuid, 0, 4),
            'entityStatus' => 'PUBLISHED',
            'entitySubCategoryAcronym' => 'HEALTH_SECTOR_GOODS',
            'entitySubCategoryName' => 'Health Sector Goods',
            'entityType' => 'TENDER',
            'uuid' => $uuid, 'entityUuid' => $uuid,
            'financialYearCode' => '2026/2027',
            'invitationDate' => '2026-10-09T00:00',
            'submissionOrOpeningDate' => now('Africa/Dar_es_Salaam')->addDays(10)->format('Y-m-d') . 'T14:00',
            'lotCount' => 1, 'hasAddendum' => false,
            'eligibleTypes' => 'COMPANY_LOCAL, COMPANY_FOREIGN',
            'procurementCategoryName' => 'Goods', 'procurementCategoryAcronym' => 'G',
            'procuringEntityName' => 'MUHIMBILI NATIONAL HOSPITAL',
            'procuringEntityUuid' => '11111111-1111-1111-1111-111111111111',
            'referenceNumber' => 'TR1/2026/2027/G/' . substr($uuid, 0, 4) . '-S001',
        ];
    }

    /** Responses NeST will give, in order (one fake for the whole test). */
    private array $responses = [];

    /** Or route each request yourself. */
    private ?\Closure $handler = null;

    /** @param list<list<array>> $pages */
    private function fakeNest(array $pages): void
    {
        $total = array_sum(array_map('count', $pages));
        $this->responses = [];
        foreach ($pages as $i => $rows) {
            $this->responses[] = ['data' => ['items' => [
                'totalPages' => count($pages), 'totalRecords' => $total, 'currentPage' => $i + 1,
                'hasNext' => $i + 1 < count($pages), 'pageSize' => 2, 'rows' => $rows,
            ]]];
        }
    }

    private const A = 'aaaaaaaa-0000-0000-0000-000000000001';
    private const B = 'bbbbbbbb-0000-0000-0000-000000000002';
    private const C = 'cccccccc-0000-0000-0000-000000000003';

    private function threePages(array $overA = []): array
    {
        return [
            [$this->row(self::A, 'Procurement of Ophthalmology Medical Equipment', $overA),
             $this->row(self::B, 'Supply of medicines and laboratory reagents')],
            [$this->row(self::C, 'Supply of fuel and lubricants', ['entitySubCategoryAcronym' => 'GOODS', 'procuringEntityName' => 'TANROADS'])],
        ];
    }

    public function test_sync_stores_rows_parses_eat_and_scores(): void
    {
        $this->fakeNest($this->threePages(['submissionOrOpeningDate' => '2030-10-16T14:00']));
        $run = app(NestSyncService::class)->run(notify: false);

        $this->assertSame('ok', $run->status);
        $this->assertSame(2, $run->pages);
        $this->assertSame(3, NestTender::count());

        $a = NestTender::where('entity_uuid', self::A)->first();
        // 14:00 in Dar es Salaam is 11:00 UTC.
        $this->assertSame('2030-10-16 11:00:00', $a->closing_at->utc()->format('Y-m-d H:i:s'));
        $this->assertTrue($a->is_relevant);
        $this->assertSame(['DEVICES'], $a->tags);

        $this->assertTrue(NestTender::where('entity_uuid', self::B)->value('is_relevant'));
        $this->assertFalse(NestTender::where('entity_uuid', self::C)->value('is_relevant'));

        Http::assertSent(fn ($req) => $req['variables']['input']['page'] === 1 && $req['variables']['input']['pageSize'] === 2);
    }

    public function test_second_run_is_idempotent_and_logs_changes(): void
    {
        $this->fakeNest($this->threePages());
        app(NestSyncService::class)->run(notify: false);
        $firstSeen = NestTender::where('entity_uuid', self::A)->value('first_seen_at');

        $this->fakeNest($this->threePages());
        app(NestSyncService::class)->run(notify: false);
        $this->assertSame(3, NestTender::count());
        $this->assertSame(0, DB::table('nest_tender_changes')->count());

        $this->fakeNest($this->threePages(['submissionOrOpeningDate' => '2031-01-05T10:00', 'hasAddendum' => true, 'lotCount' => 3]));
        app(NestSyncService::class)->run(notify: false, force: true);
        $a = NestTender::where('entity_uuid', self::A)->first();
        $this->assertEquals($firstSeen, $a->first_seen_at);
        $this->assertEqualsCanonicalizing(['closing_at', 'has_addendum', 'lot_count'],
            DB::table('nest_tender_changes')->where('nest_tender_id', $a->id)->pluck('field')->all());
    }

    public function test_failed_run_stops_and_closes_nothing(): void
    {
        $this->fakeNest($this->threePages());
        app(NestSyncService::class)->run(notify: false);

        // Page 1 is retried once at the fallback page size before giving up.
        $denied = ['errors' => [['message' => 'Access is denied']], 'data' => ['items' => null]];
        $this->responses = [$denied, $denied];
        $run = app(NestSyncService::class)->run(notify: false);

        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('Access is denied', $run->error);
        $this->assertSame(3, NestTender::count());
    }

    public function test_daily_limit(): void
    {
        foreach (range(1, 3) as $_) {
            NestSyncRun::create(['status' => 'ok', 'started_at' => now(), 'finished_at' => now()]);
        }
        $this->expectException(NestException::class);
        app(NestSyncService::class)->run(notify: false);
    }

    public function test_new_relevant_tenders_notify_once_per_user(): void
    {
        NestSyncRun::create(['status' => 'ok', 'started_at' => now()->subDay(), 'finished_at' => now()->subDay()]);
        $this->fakeNest($this->threePages());
        app(NestSyncService::class)->run();

        $mine = AppNotification::where('user_id', $this->officer->id)->where('type', 'nest_tender_new')->get();
        $this->assertCount(2, $mine);
        $this->assertSame(0, AppNotification::where('user_id', $this->technician->id)->where('type', 'like', 'nest_%')->count());
    }

    public function test_change_on_a_bid_moves_its_deadline_and_notifies(): void
    {
        $this->fakeNest($this->threePages());
        app(NestSyncService::class)->run(notify: false);
        $a = NestTender::where('entity_uuid', self::A)->first();

        Sanctum::actingAs($this->officer);
        $this->postJson("/api/v1/nest/tenders/{$a->id}/start-bid")->assertOk()->assertJsonPath('data.pipeline_status', 'bidding');
        $tender = Tender::find($a->fresh()->tender_id);
        $this->assertSame('preparing_bid', $tender->status);

        $this->fakeNest($this->threePages(['submissionOrOpeningDate' => '2031-02-01T10:00']));
        app(NestSyncService::class)->run(force: true);

        $this->assertSame('2031-02-01', $tender->fresh()->bid_submission_deadline->toDateString());
        $this->assertTrue(AppNotification::where('user_id', $this->officer->id)->where('type', 'nest_tender_changed')->exists());
    }

    public function test_recompute_after_rule_edit(): void
    {
        $this->fakeNest($this->threePages());
        app(NestSyncService::class)->run(notify: false);
        $this->assertFalse(NestTender::where('entity_uuid', self::C)->value('is_relevant'));

        Queue::fake();
        Sanctum::actingAs($this->officer);
        foreach (NestRelevanceRule::where('type', 'exclude')->whereIn('term', ['fuel', 'lubricants'])->get() as $exclude) {
            $this->putJson("/api/v1/nest/relevance-rules/{$exclude->id}", ['active' => false])->assertOk();
        }
        $this->postJson('/api/v1/nest/relevance-rules', ['type' => 'keyword', 'tag' => 'CONSUMABLES', 'term' => 'fuel', 'weight' => 12])->assertCreated();
        Queue::assertPushed(RecomputeNestRelevance::class);

        (new RecomputeNestRelevance)->handle(app(NestSyncService::class));
        $c = NestTender::where('entity_uuid', self::C)->first();
        $this->assertTrue($c->is_relevant);
        $this->assertSame(['CONSUMABLES'], $c->tags);
    }

    public function test_api_filters_and_access(): void
    {
        $this->fakeNest($this->threePages(['submissionOrOpeningDate' => now('Africa/Dar_es_Salaam')->addDays(2)->format('Y-m-d') . 'T10:00']));
        app(NestSyncService::class)->run(notify: false);

        $this->getJson('/api/v1/nest/tenders')->assertUnauthorized();
        Sanctum::actingAs($this->technician);
        $this->getJson('/api/v1/nest/tenders')->assertForbidden();

        Sanctum::actingAs($this->officer);
        $this->getJson('/api/v1/nest/tenders')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.entity_uuid', self::A)
            ->assertJsonPath('data.0.is_closing_soon', true)
            ->assertJsonPath('data.0.closing_in_days', 2);
        $this->getJson('/api/v1/nest/tenders?relevance=all')->assertJsonCount(3, 'data');
        $this->getJson('/api/v1/nest/tenders?tags[]=REAGENTS')->assertJsonCount(1, 'data')->assertJsonPath('data.0.entity_uuid', self::B);
        $this->getJson('/api/v1/nest/tenders?closing_within=3')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/nest/tenders?q=ophthalm')->assertJsonCount(1, 'data');

        $b = NestTender::where('entity_uuid', self::B)->first();
        $this->patchJson("/api/v1/nest/tenders/{$b->id}", ['triage_status' => 'reviewing', 'assigned_to' => $this->officer->id, 'notes' => 'Check lot 2'])
            ->assertOk()->assertJsonPath('data.pipeline_status', 'reviewing');
        $this->getJson('/api/v1/nest/tenders?assigned_to=me')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/nest/tenders?pipeline_status[]=reviewing')->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/nest/tenders/{$b->id}")->assertJsonPath('data.notes', 'Check lot 2')
            ->assertJsonPath('data.closing_at', fn ($v) => str_ends_with($v, '+03:00'));

        $dash = $this->getJson('/api/v1/nest/dashboard')->assertOk()->json('data');
        $this->assertSame(2, $dash['open_relevant']);
        $this->assertSame(1, $dash['by_tag']['REAGENTS']);
        $this->assertSame(1, $dash['by_pipeline']['reviewing']);

        $this->getJson('/api/v1/nest/procuring-entities')->assertOk()->assertJsonPath('data.0.relevant_count', 2);
    }

    public function test_nest_password_is_stored_encrypted_and_never_returned(): void
    {
        Sanctum::actingAs($this->officer);
        $res = $this->putJson('/api/v1/nest/settings', ['nest_username' => 'tenders@hypermed.co.tz', 'nest_password' => 's3cret-pass'])->assertOk();
        $this->assertStringNotContainsString('s3cret-pass', $res->getContent());
        $this->assertTrue($res->json('data.nest_login.has_password'));
        $stored = DB::table('settings')->where('key', 'nest.password')->value('value');
        $this->assertNotSame('s3cret-pass', $stored);

        Sanctum::actingAs($this->technician);
        $this->putJson('/api/v1/nest/settings', ['nest_username' => 'x', 'nest_password' => 'y'])->assertForbidden();
    }

    public function test_documents_lists_every_source_and_serves_only_listed_files(): void
    {
        $this->fakeNest($this->threePages());
        app(NestSyncService::class)->run(notify: false);
        $a = NestTender::where('entity_uuid', self::A)->first();
        $a->update(['has_addendum' => true]);

        config(['nest.auth.client_secret' => 'test-secret']);
        \App\Services\Nest\NestClient::storeCredentials('tenders@example.com', 'pw', null);

        $doc = '11111111-aaaa-4aaa-8aaa-000000000001';
        $clarFile = '11111111-aaaa-4aaa-8aaa-000000000002';
        $minutes = '11111111-aaaa-4aaa-8aaa-000000000003';
        $spec = '11111111-aaaa-4aaa-8aaa-000000000004';
        $this->handler = function ($req) use ($doc, $clarFile, $minutes, $spec) {
            $url = $req->url();
            $q = $req['query'] ?? '';
            return match (true) {
                str_contains($url, 'oauth/token') => Http::response(['access_token' => 'tok', 'refresh_token' => 'r', 'expires_in' => 3600]),
                str_contains($url, 'attachment/download') => Http::response('%PDF-1.4 fake', 200, ['Content-Disposition' => 'attachment; filename="x.pdf"']),
                str_contains($q, 'getDocumentByItemUuidAndType') => Http::response(['data' => ['results' => ['code' => 9000, 'data' => ['fileUuid' => $doc]]]]),
                str_contains($q, 'getClarificationByTenderUuid') => Http::response(['data' => ['getClarificationByTenderUuid' => ['totalRecords' => 1, 'rows' => [[
                    'title' => 'Lot 2 specs', 'question' => 'Is a 3T magnet acceptable?', 'isPE' => false, 'createdAt' => '2026-10-10T09:00',
                    'clarificationAttachments' => [],
                    'clarificationResponses' => [['isPE' => true, 'reply_or_question' => 'Yes, see attached.', 'createdAt' => '2026-10-11T09:00',
                        'clarificationResponseAttachments' => [['attachmentUuid' => $clarFile]]]],
                ]]]]]),
                str_contains($q, 'getPreBidMeetingByEntityUuid') => Http::response(['data' => ['getPreBidMeetingByEntityUuid' => ['code' => 9000,
                    'data' => ['meetingDate' => '2026-10-12', 'meetingLocationAndVenue' => 'Board room', 'attachmentUuid' => $minutes, 'isDraft' => false]]]]),
                str_contains($q, 'getProcurementRequisitionItemByTenderUuid') => Http::response(['data' => ['getProcurementRequisitionItemByTenderUuid' => ['code' => 9000,
                    'data' => [['procurementRequisitionAttachments' => [['attachmentType' => 'SPECIFICATION', 'title' => 'Technical specifications', 'attachmentUuid' => $spec]]]]]]]),
                str_contains($q, 'getPublishedEntityAddendumByEntityUuid') => Http::response(['data' => ['getPublishedEntityAddendumByEntityUuid' => ['code' => 9000,
                    'data' => ['approvalStatus' => 'APPROVED', 'publishedEntityAddendumAreas' => [['reason' => 'More time', 'addendumArea' => ['name' => 'Update Tender Submission Deadline']]]]]]]),
                default => Http::response(['errors' => [['message' => 'unexpected']]]),
            };
        };

        Sanctum::actingAs($this->officer);
        $data = $this->getJson("/api/v1/nest/tenders/{$a->id}/documents")->assertOk()->json('data');
        $this->assertSame([$doc, $clarFile, $minutes, $spec], array_column($data['documents'], 'id'));
        $this->assertSame('Tender document', $data['documents'][0]['title']);
        $this->assertSame('Yes, see attached.', $data['clarifications'][0]['replies'][0]['text']);
        $this->assertSame('Update Tender Submission Deadline', $data['addendum']['changes'][0]['area']);
        $this->assertSame([], $data['errors']);

        $res = $this->get("/api/v1/nest/tenders/{$a->id}/documents/{$spec}")->assertOk();
        $this->assertStringContainsString('Technical_specifications.pdf', $res->headers->get('content-disposition'));

        // Not on this tender's list: refused, never fetched from NeST.
        $this->getJson("/api/v1/nest/tenders/{$a->id}/documents/22222222-bbbb-4bbb-8bbb-000000000009")->assertStatus(422);
        // Apps on v1.1.8 still get the main document.
        $this->get("/api/v1/nest/tenders/{$a->id}/document")->assertOk();

        \Illuminate\Support\Facades\Storage::disk('local')->deleteDirectory("nest-documents/{$a->entity_uuid}");
    }
}
