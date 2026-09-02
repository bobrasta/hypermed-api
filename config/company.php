<?php

return [
    'name'    => 'Hypermed Health Care',
    'tagline' => 'Biomedical Equipment Supplier — Tanzania',
    'address' => env('COMPANY_ADDRESS', 'Dar es Salaam, Tanzania'),
    'phone'   => env('COMPANY_PHONE', '+255 XXX XXX XXX'),
    'email'   => env('COMPANY_EMAIL', 'info@hypermed.co.tz'),
    'brand_color' => '#22C55E',

    // Real letterhead used on the mPDF quotation/invoice template
    // (resources/pdf-assets), copied from the company's own printed
    // documents — kept exactly as-is, including the header's "HYPERMED
    // HEALTH CARE LIMITED" vs the payment box's "HYPERMED HEALTHCARE
    // LIMITED" (no space), since both appear that way on the real thing.
    'letterhead' => [
        'name_header' => 'HYPERMED HEALTH CARE LIMITED',
        'name_payee'  => 'HYPERMED HEALTHCARE LIMITED',
        'address_lines' => [
            'KINONDONI, MWANANYAMALA KOMA KOMA',
            'MWIJUMA ROAD, PLOT NO: 58/29B',
            'TRUST HOUSE BUILDING, 2ND FLOOR',
            'P.O.BOX 14118',
            'DAR ES SALAAM',
            'Email: info@hypermed.co.tz',
            'Mobile phone: + 255 787 880 100',
            'Tel: + 255 222 761 006',
            'Website: www.hypermed.co.tz',
        ],
        'tin' => 'TIN : 138 -960 -609',
    ],

    'banks' => [
        ['name' => 'MWANGA HAKIKA BANK', 'currency' => 'TZS', 'account' => '2090050000435'],
        ['name' => 'CRDB BANK', 'currency' => 'TZS', 'account' => '0150595585700'],
    ],

    'default_terms' => [
        ['label' => 'Payment', 'text' => '100% advance payment for cash purchase and 7 to 21 days credit facility for certified customers.'],
        ['label' => 'Delivery', 'text' => '1 to 2 days after order confirmation and receipt of payment'],
        ['label' => 'Installation and training', 'text' => 'As agreed.'],
    ],
];
