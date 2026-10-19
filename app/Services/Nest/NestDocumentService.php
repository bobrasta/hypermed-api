<?php

namespace App\Services\Nest;

use App\Models\NestTender;
use Illuminate\Support\Facades\Storage;

/**
 * Fetches a tender's published document (PDF) from NeST as the company
 * account, the way NeST's own "Tender Document" button does:
 *   tender → its merged procurement requisition (nest-app)
 *          → getDocumentByItemUuidAndType (nest-templates) → file uuid
 *          → nest-dsms attachment list → base64 PDF.
 * The PDF is kept under storage/app/nest-documents so it is fetched once.
 */
class NestDocumentService
{
    private const DOC_QUERY = <<<'GQL'
query getDocumentByItemUuidAndType($itemUuid: String, $type: DocumentType) {
  results: getDocumentByItemUuidAndType(itemUuid: $itemUuid, type: $type) {
    code message data { uuid fileUuid itemUuid documentType createdAt }
  }
}
GQL;

    private const REQUISITION_QUERY = <<<'GQL'
query getMergedMainProcurementRequisitionData($input: DataRequestInputInput) {
  items: getMergedMainProcurementRequisitionData(input: $input, withMetaData: false) {
    rows: data { uuid tenderNumber tender { uuid } }
  }
}
GQL;

    public function __construct(private NestClient $client) {}

    public function path(NestTender $t): string
    {
        return "nest-documents/{$t->entity_uuid}.pdf";
    }

    public function cached(NestTender $t): bool
    {
        return Storage::disk('local')->exists($this->path($t));
    }

    /** @return string local path (relative to the local disk) of the PDF */
    public function fetch(NestTender $t, bool $refresh = false): string
    {
        $path = $this->path($t);
        if (! $refresh && $this->cached($t)) {
            return $path;
        }

        $fileUuid = $this->findFileUuid($t);
        if (! $fileUuid) {
            throw new NestException('NeST has no published document for this tender yet (or it is only visible inside NeST). Use "Open in NeST".');
        }

        $res = $this->client->authedPost('nest-dsms/api/attachment/list/', [$fileUuid]);
        $item = $res[0] ?? ($res['data'][0] ?? null);
        $b64 = $item['signedDocBase64Attachment'] ?? $item['base64Attachment'] ?? null;
        $pdf = $b64 ? base64_decode($b64, true) : false;
        if (! $pdf || ! str_starts_with($pdf, '%PDF')) {
            throw new NestException('NeST returned the document record but not a PDF.');
        }

        Storage::disk('local')->put($path, $pdf);

        return $path;
    }

    private function findFileUuid(NestTender $t): ?string
    {
        $type = match ($t->entity_type) {
            'PLANNED_TENDER' => 'PRE_QUALIFICATION_DOCUMENT',
            default => 'TENDER_DOCUMENT',
        };

        foreach ($this->candidateItems($t) as $itemUuid) {
            $json = $this->client->authedGraphql('nest-templates', self::DOC_QUERY, ['itemUuid' => $itemUuid, 'type' => $type]);
            $file = $json['data']['results']['data']['fileUuid'] ?? null;
            if ($file) {
                return $file;
            }
        }

        return null;
    }

    /** @return list<string> uuids NeST may file the document under, most likely first */
    private function candidateItems(NestTender $t): array
    {
        $items = [];
        try {
            $json = $this->client->authedGraphql('nest-app', self::REQUISITION_QUERY, ['input' => [
                'page' => 1, 'pageSize' => 5, 'fields' => [],
                'mustHaveFilters' => [['fieldName' => 'tenderNumber', 'operation' => 'EQ', 'value1' => $t->entity_number]],
            ]]);
            foreach ($json['data']['items']['rows'] ?? [] as $row) {
                if (($row['tenderNumber'] ?? null) === $t->entity_number && ! empty($row['uuid'])) {
                    $items[] = $row['uuid'];
                }
            }
        } catch (NestException) {
            // Not visible to a tenderer account — fall back to the tender's own uuid.
        }
        $items[] = $t->entity_uuid;

        return array_values(array_unique($items));
    }
}
