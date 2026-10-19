<?php

namespace App\Services\Nest;

use App\Models\NestTender;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Everything a tenderer can open on a NeST tender, fetched as the company
 * account (verified against nest.go.tz on 2026-10-19):
 *
 *  - the tender document   nest-templates getDocumentByItemUuidAndType(entity uuid)
 *  - clarifications        nest-submission getClarificationByTenderUuid — Q&A text
 *                          plus files attached to questions and replies
 *  - pre-bid meeting       nest-submission getPreBidMeetingByEntityUuid — minutes
 *  - requisition files     nest-app getProcurementRequisitionItemByTenderUuid
 *                          (specifications, drawings, BOQs when the PE attached any)
 *  - addendum              nest-app getPublishedEntityAddendumByEntityUuid — what
 *                          changed and why (no file; NeST re-issues the document)
 *
 * Each source is tried on its own, so one NeST error never hides the rest.
 * The listing is cached for a few hours; files are downloaded once (nest-dsms
 * attachment/download) into storage/app/nest-documents/<tender uuid>/.
 */
class NestDocumentService
{
    private const LIST_TTL = 6 * 3600;

    private const DOC_QUERY = <<<'GQL'
query getDocumentByItemUuidAndType($itemUuid: String, $type: DocumentType) {
  results: getDocumentByItemUuidAndType(itemUuid: $itemUuid, type: $type) {
    code message data { uuid fileUuid itemUuid documentType createdAt }
  }
}
GQL;

    private const CLARIFICATIONS_QUERY = <<<'GQL'
query getClarificationByTenderUuid($input: DataRequestInputInput, $tenderUuid: String) {
  getClarificationByTenderUuid(input: $input, withMetaData: false, tenderUuid: $tenderUuid) {
    totalRecords
    rows: data {
      uuid title question isPE createdAt
      clarificationAttachments { attachmentUuid }
      clarificationResponses { isPE reply_or_question createdAt clarificationResponseAttachments { attachmentUuid } }
    }
  }
}
GQL;

    private const PREBID_QUERY = <<<'GQL'
query getPreBidMeetingByEntityUuid($entityUuid: String) {
  getPreBidMeetingByEntityUuid(entityUuid: $entityUuid) {
    code message data { uuid meetingDate meetingLocationAndVenue attachmentUuid isDraft }
  }
}
GQL;

    private const REQUISITION_QUERY = <<<'GQL'
query getProcurementRequisitionItemByTenderUuid($tenderUuid: String) {
  getProcurementRequisitionItemByTenderUuid(tenderUuid: $tenderUuid) {
    code message data { description procurementRequisitionAttachments { uuid attachmentType title description attachmentUuid } }
  }
}
GQL;

    private const ADDENDUM_QUERY = <<<'GQL'
query getPublishedEntityAddendumByEntityUuid($entityUuid: String) {
  getPublishedEntityAddendumByEntityUuid(entityUuid: $entityUuid) {
    code message data {
      uuid approvalStatus modificationApprovalDate submissionDeadlineBeforeModification submissionTimeBeforeModification
      publishedEntityAddendumAreas { reason addendumArea { name } }
    }
  }
}
GQL;

    public function __construct(private NestClient $client) {}

    // ------------------------------------------------------------ listing

    /**
     * @return array{documents: list<array>, clarifications: list<array>, addendum: ?array,
     *               pre_bid: ?array, errors: list<string>, fetched_at: string}
     */
    public function listing(NestTender $t, bool $refresh = false): array
    {
        $key = "nest:documents:{$t->id}";
        if (! $refresh && is_array($cached = Cache::get($key))) {
            return $cached;
        }

        $docs = [];
        $errors = [];
        $clarifications = [];
        $addendum = null;
        $preBid = null;

        $try = function (string $what, callable $fn) use (&$errors) {
            try {
                $fn();
            } catch (NestException $e) {
                $errors[] = "{$what}: {$e->getMessage()}";
            }
        };

        $try('Tender document', function () use ($t, &$docs) {
            if ($file = $this->tenderDocumentUuid($t)) {
                $docs[] = ['id' => $file, 'title' => 'Tender document', 'kind' => 'tender_document',
                    'note' => $t->has_addendum ? 'NeST re-issues this after an addendum — re-fetch to get the latest' : null];
            }
        });

        $try('Clarifications', function () use ($t, &$docs, &$clarifications) {
            $json = $this->client->authedGraphql('nest-submission', self::CLARIFICATIONS_QUERY, [
                'tenderUuid' => $t->entity_uuid,
                'input' => ['page' => 1, 'pageSize' => 100, 'fields' => [], 'mustHaveFilters' => []],
            ]);
            foreach ($json['data']['getClarificationByTenderUuid']['rows'] ?? [] as $row) {
                $title = trim((string) ($row['title'] ?? '')) ?: 'Clarification';
                $replies = [];
                foreach ($row['clarificationAttachments'] ?? [] as $i => $a) {
                    $this->addFile($docs, $a['attachmentUuid'] ?? null, "Clarification: {$title} — question attachment" . ($i ? ' ' . ($i + 1) : ''), 'clarification');
                }
                foreach ($row['clarificationResponses'] ?? [] as $r) {
                    $replies[] = [
                        'from' => ($r['isPE'] ?? false) ? 'procuring_entity' : 'tenderer',
                        'text' => $r['reply_or_question'] ?? null,
                        'at' => $r['createdAt'] ?? null,
                    ];
                    foreach ($r['clarificationResponseAttachments'] ?? [] as $a) {
                        $this->addFile($docs, $a['attachmentUuid'] ?? null,
                            "Clarification: {$title} — " . (($r['isPE'] ?? false) ? 'reply attachment' : 'follow-up attachment'), 'clarification');
                    }
                }
                $clarifications[] = [
                    'title' => $title,
                    'question' => $row['question'] ?? null,
                    'from' => ($row['isPE'] ?? false) ? 'procuring_entity' : 'tenderer',
                    'at' => $row['createdAt'] ?? null,
                    'replies' => $replies,
                ];
            }
        });

        $try('Pre-bid meeting', function () use ($t, &$docs, &$preBid) {
            $json = $this->client->authedGraphql('nest-submission', self::PREBID_QUERY, ['entityUuid' => $t->entity_uuid]);
            $m = $json['data']['getPreBidMeetingByEntityUuid']['data'] ?? null;
            if ($m && ! ($m['isDraft'] ?? false)) {
                $preBid = ['date' => $m['meetingDate'] ?? null, 'venue' => $m['meetingLocationAndVenue'] ?? null];
                $this->addFile($docs, $m['attachmentUuid'] ?? null, 'Pre-bid meeting minutes', 'pre_bid');
            }
        });

        $try('Requisition attachments', function () use ($t, &$docs) {
            $json = $this->client->authedGraphql('nest-app', self::REQUISITION_QUERY, ['tenderUuid' => $t->entity_uuid]);
            foreach ($json['data']['getProcurementRequisitionItemByTenderUuid']['data'] ?? [] as $item) {
                foreach ($item['procurementRequisitionAttachments'] ?? [] as $a) {
                    $title = trim((string) ($a['title'] ?? $a['description'] ?? '')) ?: ucfirst(strtolower(str_replace('_', ' ', (string) ($a['attachmentType'] ?? 'Attachment'))));
                    $this->addFile($docs, $a['attachmentUuid'] ?? null, $title, 'requisition');
                }
            }
        });

        if ($t->has_addendum) {
            $try('Addendum', function () use ($t, &$addendum) {
                $json = $this->client->authedGraphql('nest-app', self::ADDENDUM_QUERY, ['entityUuid' => $t->entity_uuid]);
                $a = $json['data']['getPublishedEntityAddendumByEntityUuid']['data'] ?? null;
                if ($a) {
                    $addendum = [
                        'status' => $a['approvalStatus'] ?? null,
                        'approved_at' => $a['modificationApprovalDate'] ?? null,
                        'deadline_before' => trim(($a['submissionDeadlineBeforeModification'] ?? '') . ' ' . ($a['submissionTimeBeforeModification'] ?? '')) ?: null,
                        'changes' => array_map(fn ($x) => [
                            'area' => $x['addendumArea']['name'] ?? null,
                            'reason' => $x['reason'] ?? null,
                        ], $a['publishedEntityAddendumAreas'] ?? []),
                    ];
                }
            });
        }

        $result = [
            'documents' => array_values($docs),
            'clarifications' => $clarifications,
            'addendum' => $addendum,
            'pre_bid' => $preBid,
            'errors' => $errors,
            'fetched_at' => now()->toIso8601String(),
        ];
        // Don't keep a listing that failed outright (e.g. login down) for hours.
        Cache::put($key, $result, $errors && ! $docs ? 300 : self::LIST_TTL);

        return $result;
    }

    private function addFile(array &$docs, ?string $uuid, string $title, string $kind): void
    {
        if ($uuid && ! isset($docs[$uuid])) {
            $docs[$uuid] = ['id' => $uuid, 'title' => $title, 'kind' => $kind, 'note' => null];
        }
    }

    private function tenderDocumentUuid(NestTender $t): ?string
    {
        $types = match ($t->entity_type) {
            'PLANNED_TENDER' => ['PRE_QUALIFICATION_DOCUMENT', 'TENDER_DOCUMENT'],
            'FRAMEWORK' => ['TENDER_DOCUMENT', 'AGREEMENT_DOCUMENT'],
            default => ['TENDER_DOCUMENT'],
        };
        foreach ($types as $type) {
            $json = $this->client->authedGraphql('nest-templates', self::DOC_QUERY, ['itemUuid' => $t->entity_uuid, 'type' => $type]);
            if ($file = $json['data']['results']['data']['fileUuid'] ?? null) {
                return $file;
            }
        }

        return null;
    }

    // -------------------------------------------------------------- files

    /**
     * Downloads one listed file (once) and returns [local path, download name].
     * Only files on this tender's own listing can be fetched.
     *
     * @return array{0: string, 1: string}
     */
    public function file(NestTender $t, string $fileUuid, bool $refresh = false): array
    {
        $doc = collect($this->listing($t)['documents'])->firstWhere('id', $fileUuid);
        if (! $doc) {
            throw new NestException('That document is not part of this tender.');
        }

        $dir = "nest-documents/{$t->entity_uuid}";
        $disk = Storage::disk('local');
        $existing = collect($disk->files($dir))->first(fn ($p) => str_starts_with(basename($p), $fileUuid . '.'));
        if ($existing && ! $refresh) {
            return [$existing, $this->downloadName($t, $doc, pathinfo($existing, PATHINFO_EXTENSION))];
        }

        $res = Http::withToken($this->client->accessToken())->timeout(120)
            ->withHeaders(['Origin' => config('nest.web_url'), 'Referer' => config('nest.web_url') . '/'])
            ->get(config('nest.gateway') . "/nest-dsms/api/attachment/download/{$fileUuid}");
        if (! $res->successful() || $res->body() === '') {
            throw new NestException('NeST would not hand over this file (HTTP ' . $res->status() . ').');
        }

        $ext = 'pdf';
        if (preg_match('/filename="?[^";]*\.([A-Za-z0-9]{2,5})"?/', (string) $res->header('Content-Disposition'), $m)) {
            $ext = strtolower($m[1]);
        } elseif (! str_starts_with($res->body(), '%PDF')) {
            $ext = 'bin';
        }

        if ($existing) {
            $disk->delete($existing);
        }
        $path = "{$dir}/{$fileUuid}.{$ext}";
        $disk->put($path, $res->body());

        return [$path, $this->downloadName($t, $doc, $ext)];
    }

    private function downloadName(NestTender $t, array $doc, string $ext): string
    {
        $base = preg_replace('/[^A-Za-z0-9._-]+/', '_', ($t->reference_number ?: $t->entity_number ?: 'NeST') . ' ' . $doc['title']);

        return trim(mb_substr($base, 0, 120), '_') . '.' . $ext;
    }

    /** The main tender document (kept for apps on v1.1.8, which call /document). */
    public function tenderDocument(NestTender $t, bool $refresh = false): array
    {
        $doc = collect($this->listing($t, $refresh)['documents'])->firstWhere('kind', 'tender_document');
        if (! $doc) {
            throw new NestException('NeST has no published tender document for this tender yet. Use "Open in NeST".');
        }

        return $this->file($t, $doc['id'], $refresh);
    }

    public function cached(NestTender $t): bool
    {
        return Cache::has("nest:documents:{$t->id}");
    }
}
