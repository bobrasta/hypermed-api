<?php

namespace App\Services\Nest;

use App\Models\NestSyncRun;
use App\Models\NestTender;
use App\Models\NestTenderChange;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Pulls every page of NeST's public published-tender listing (capped) and
 * upserts by entity_uuid. Changes to closing date, addendum, lot count and
 * description are logged. A failed run stops where it is and changes nothing
 * it didn't fetch — tenders are never closed or deleted because of a failure.
 *
 * Politeness: one run at a time, at most max_runs_per_day (EAT day), a random
 * pause between pages.
 */
class NestSyncService
{
    public const TRACKED_FIELDS = ['closing_at', 'has_addendum', 'lot_count', 'description'];

    public function __construct(private NestClient $client, private NestNotifier $notifier) {}

    public static function runsToday(): int
    {
        $tz = config('nest.timezone');
        $start = now($tz)->startOfDay()->utc();

        return NestSyncRun::where('started_at', '>=', $start)->where('status', '!=', 'skipped')->count();
    }

    public static function canRunToday(): bool
    {
        return self::runsToday() < (int) config('nest.max_runs_per_day');
    }

    /**
     * @return NestSyncRun the finished run (status ok or failed)
     *
     * @throws NestException when the daily cap is reached or another run is going
     */
    public function run(string $trigger = 'schedule', ?int $userId = null, ?int $maxPages = null, bool $notify = true, bool $force = false): NestSyncRun
    {
        if (! $force && ! self::canRunToday()) {
            throw new NestException('NeST has already been checked ' . config('nest.max_runs_per_day') . ' times today — the limit is there to go easy on the government server.');
        }

        $lock = Cache::lock('nest:sync', 1800);
        if (! $lock->get()) {
            throw new NestException('A NeST sync is already running.');
        }

        $run = NestSyncRun::create(['trigger' => $trigger, 'triggered_by' => $userId, 'status' => 'running', 'started_at' => now()]);
        $engine = new RelevanceEngine;
        $newRelevant = [];
        $changed = [];

        try {
            $maxPages = min($maxPages ?? (int) config('nest.max_pages'), (int) config('nest.max_pages'));
            $pageSize = (int) config('nest.page_size');

            for ($page = 1; $page <= $maxPages; $page++) {
                if ($page > 1) {
                    $this->pause();
                }
                try {
                    $result = $this->client->fetchPage($page, $pageSize);
                } catch (NestException $e) {
                    // NeST may refuse a large page size: retry the first page smaller.
                    if ($page === 1 && $pageSize !== (int) config('nest.fallback_page_size')) {
                        $pageSize = (int) config('nest.fallback_page_size');
                        Log::warning('NeST rejected page size; falling back', ['error' => $e->getMessage()]);
                        $this->pause();
                        $result = $this->client->fetchPage($page, $pageSize);
                    } else {
                        throw $e;
                    }
                }

                DB::transaction(function () use ($result, $engine, $run, &$newRelevant, &$changed) {
                    foreach ($result['rows'] as $row) {
                        $this->upsert($row, $engine, $run, $newRelevant, $changed);
                    }
                });
                $run->increment('pages');
                $run->increment('records', count($result['rows']));

                if (! $result['hasNext'] || $page >= $result['totalPages']) {
                    break;
                }
            }

            $run->update(['status' => 'ok', 'finished_at' => now(), 'new_relevant' => count($newRelevant), 'changes' => count($changed)]);
        } catch (\Throwable $e) {
            Log::error('NeST sync failed', ['run' => $run->id, 'error' => $e->getMessage()]);
            $run->update(['status' => 'failed', 'finished_at' => now(), 'error' => mb_substr($e->getMessage(), 0, 1000),
                'new_relevant' => count($newRelevant), 'changes' => count($changed)]);
        } finally {
            $lock->release();
        }

        if ($notify) {
            // Even after a failure, what was fetched before it is real news.
            $this->notifier->afterSync($newRelevant, $changed, firstRun: NestSyncRun::where('status', 'ok')->where('id', '<', $run->id)->doesntExist());
        }

        return $run->fresh();
    }

    /** Parse a NeST local timestamp ("2026-10-16T14:00", EAT, no offset) to UTC. */
    public static function parseEat(?string $value): ?Carbon
    {
        if (! $value) {
            return null;
        }
        try {
            return Carbon::parse($value, config('nest.timezone'))->utc();
        } catch (\Throwable) {
            return null;
        }
    }

    /** Re-score every stored tender (after a rule or threshold change). */
    public function recomputeAll(): int
    {
        $engine = new RelevanceEngine;
        $n = 0;
        NestTender::query()->chunkById(200, function ($chunk) use ($engine, &$n) {
            foreach ($chunk as $t) {
                $t->fill($engine->evaluate($t->only(['description', 'sub_category_name', 'sub_category_acronym', 'procurement_category', 'procuring_entity_name'])));
                if ($t->isDirty()) {
                    $t->save();
                    $n++;
                }
            }
        });

        return $n;
    }

    private function upsert(array $row, RelevanceEngine $engine, NestSyncRun $run, array &$newRelevant, array &$changed): void
    {
        $uuid = $row['entityUuid'] ?? $row['uuid'] ?? null;
        if (! $uuid) {
            return;
        }

        $attrs = [
            'entity_id' => $row['entityId'] ?? null,
            'entity_number' => $row['entityNumber'] ?? null,
            'reference_number' => $row['referenceNumber'] ?? null,
            'entity_type' => $row['entityType'] ?? null,
            'entity_status' => $row['entityStatus'] ?? null,
            'description' => trim((string) ($row['descriptionOfTheProcurement'] ?? '')),
            'sub_category_acronym' => $row['entitySubCategoryAcronym'] ?? null,
            'sub_category_name' => $row['entitySubCategoryName'] ?? null,
            'procurement_category' => $row['procurementCategoryAcronym'] ?? null,
            'procurement_category_name' => $row['procurementCategoryName'] ?? null,
            'procuring_entity_name' => $row['procuringEntityName'] ?? null,
            'procuring_entity_uuid' => $row['procuringEntityUuid'] ?? null,
            'financial_year' => $row['financialYearCode'] ?? null,
            'lot_count' => isset($row['lotCount']) ? (int) $row['lotCount'] : null,
            'has_addendum' => (bool) ($row['hasAddendum'] ?? false),
            'eligible_types' => $row['eligibleTypes'] ?? null,
            'invitation_at' => self::parseEat($row['invitationDate'] ?? null),
            'closing_at' => self::parseEat($row['submissionOrOpeningDate'] ?? null),
            'raw' => $row,
        ];
        $attrs += $engine->evaluate($attrs);

        $tender = NestTender::with('tender')->where('entity_uuid', $uuid)->first();
        $now = now();

        if (! $tender) {
            $tender = NestTender::create($attrs + ['entity_uuid' => $uuid, 'first_seen_at' => $now, 'last_seen_at' => $now, 'triage_status' => 'new']);
            $run->increment('new_count');
            if ($tender->is_relevant) {
                $newRelevant[] = $tender;
            }

            return;
        }

        $diffs = [];
        foreach (self::TRACKED_FIELDS as $field) {
            $old = $this->comparable($field, $tender->{$field});
            $new = $this->comparable($field, $attrs[$field]);
            if ($old !== $new) {
                $diffs[] = ['field' => $field, 'old' => $old, 'new' => $new];
            }
        }

        $tender->fill($attrs + ['last_seen_at' => $now])->save();

        foreach ($diffs as $d) {
            NestTenderChange::create([
                'nest_tender_id' => $tender->id, 'field' => $d['field'],
                'old_value' => $d['old'], 'new_value' => $d['new'], 'detected_at' => $now,
            ]);
        }

        if ($diffs) {
            $this->followDeadline($tender, $diffs);
            if ($tender->isTracked()) {
                $changed[] = ['tender' => $tender, 'diffs' => $diffs];
            }
        }
    }

    /** Keep a linked bid's submission deadline in step with NeST until the bid is in. */
    private function followDeadline(NestTender $t, array $diffs): void
    {
        $tender = $t->tender;
        if (! $tender || ! $t->closing_at || $tender->reached('bid_submitted')) {
            return;
        }
        if (collect($diffs)->contains('field', 'closing_at')) {
            $tender->update(['bid_submission_deadline' => $t->closing_at->copy()->setTimezone(config('nest.timezone'))->toDateString()]);
        }
    }

    private function comparable(string $field, mixed $v): ?string
    {
        return match ($field) {
            'closing_at' => $v ? Carbon::parse($v)->utc()->format('Y-m-d H:i') : null,
            'has_addendum' => $v ? 'yes' : 'no',
            'lot_count' => $v === null ? null : (string) (int) $v,
            default => $v === null ? null : trim((string) $v),
        };
    }

    private function pause(): void
    {
        [$min, $max] = config('nest.delay_ms');
        if ($max > 0) {
            usleep(random_int(max(0, $min), max($min, $max)) * 1000);
        }
    }
}
