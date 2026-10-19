<?php

// NeST (nest.go.tz) tender tracker. The tender list is NeST's own public
// landing-page query, which needs no login; the company's NeST account
// (entered in the app, stored encrypted in settings) is used only to fetch
// tender documents. See README "NeST tender tracker" for how to re-capture
// the query when NeST changes it.
return [
    'enabled' => (bool) env('NEST_SYNC_ENABLED', true),

    'endpoint' => env('NEST_GRAPHQL_URL', 'https://nest.go.tz/gateway/nest-app/graphql'),
    'operation_name' => env('NEST_OPERATION_NAME', 'getPublishedEntityViewData'),

    // Captured from nest.go.tz's landing page ("Current tenders"). The
    // response root is aliased to `items` and the list to `rows`.
    'query' => <<<'GQL'
query getPublishedEntityViewData($input: DataRequestInputInput $withMetaData: Boolean) {
  items: getPublishedEntityViewData(input: $input withMetaData: $withMetaData) {
    totalPages totalRecords currentPage hasNext pageSize
    rows: data {
      descriptionOfTheProcurement entityId entityNumber entityStatus
      entitySubCategoryAcronym entitySubCategoryName entityType
      uuid: entityUuid entityUuid financialYearCode id invitationDate
      lotCount hasAddendum eligibleTypes procurementCategoryName
      procurementCategoryAcronym: entityCategoryAcronym
      procuringEntityName procuringEntityUuid submissionOrOpeningDate referenceNumber
    }
  }
}
GQL,

    // '__PAGE__' and '__PAGE_SIZE__' are replaced (anywhere in the tree)
    // on every request.
    'variables' => [
        'input' => [
            'fields' => [['fieldName' => 'invitationDate', 'orderDirection' => 'DESC']],
            'page' => '__PAGE__',
            'pageSize' => '__PAGE_SIZE__',
            'mustHaveFilters' => [['fieldName' => 'entityStatus', 'operation' => 'IN', 'inValues' => ['PUBLISHED']]],
        ],
        'withMetaData' => false,
    ],

    'page_size' => (int) env('NEST_PAGE_SIZE', 50),
    'fallback_page_size' => 10,
    'max_pages' => (int) env('NEST_MAX_PAGES', 30),

    // Politeness towards a government system.
    'max_runs_per_day' => (int) env('NEST_MAX_RUNS_PER_DAY', 3),
    'delay_ms' => [(int) env('NEST_DELAY_MIN_MS', 1500), (int) env('NEST_DELAY_MAX_MS', 3500)],
    'timeout' => 30,
    'retries' => 2,

    'timezone' => 'Africa/Dar_es_Salaam',

    // Relevance (the rules live in nest_relevance_rules, editable from the
    // app; the threshold can be changed there too and is kept in settings).
    'relevance_threshold' => (int) env('NEST_RELEVANCE_THRESHOLD', 10),
    'maybe_min' => 5,
    'eligible_categories' => ['G', 'NC'],
    'closing_soon_days' => 3,

    // Tender documents (needs the company's NeST account). The OAuth client
    // id/secret are NeST's web-client values — set them in .env, never here.
    'auth' => [
        'token_url' => env('NEST_TOKEN_URL', 'https://nest.go.tz/gateway/nest-uaa/oauth/token'),
        'client_id' => env('NEST_CLIENT_ID', 'nest'),
        'client_secret' => env('NEST_CLIENT_SECRET'),
    ],
    'gateway' => rtrim((string) env('NEST_GATEWAY_URL', 'https://nest.go.tz/gateway'), '/'),
    'web_url' => rtrim((string) env('NEST_WEB_URL', 'https://nest.go.tz'), '/'),
];
