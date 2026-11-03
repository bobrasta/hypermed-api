<?php

// Email copies of in-app notifications (Section 11's notification engine).
// First only Sections 18.3/19.2's shipment/tender/device types; since
// 2026-10-07 every workflow notification. Off by default: Railway blocks
// outbound SMTP, so only the VPS — whose own Postfix relays for localhost
// and DKIM-signs @hypermed.co.tz — turns it on.
return [
    'enabled' => (bool) env('NOTIFICATION_MAIL_ENABLED', false),

    // notifications.type values that also go out by email. '*' (the
    // default) means every workflow notification — each approval step,
    // assignment, payment and rejection — except the types excluded below.
    'types' => array_values(array_filter(array_map('trim', explode(',', (string) env(
        'NOTIFICATION_MAIL_TYPES',
        '*',
    ))))),

    // Never emailed even when 'types' is '*': automatic alerts that can fire
    // many times a day and stay visible in the app.
    'exclude_types' => array_values(array_filter(array_map('trim', explode(',', (string) env(
        'NOTIFICATION_MAIL_EXCLUDE_TYPES',
        'low_stock_alert',
    ))))),

    // Shared mailboxes that get only the important emails (user, 2026-11-03:
    // "reduce emails to info@ unless important"). Their routine alerts —
    // below — stay in the app; approvals, shipments, deadlines still email.
    'important_only' => array_values(array_filter(array_map('trim', explode(',', strtolower((string) env(
        'NOTIFICATION_MAIL_IMPORTANT_ONLY',
        'info@hypermed.co.tz',
    )))))),

    // Routine alerts not emailed to the 'important_only' mailboxes: every
    // new NeST tender and every NeST change (the daily "closing within 3
    // days" digest still goes).
    'routine_types' => array_values(array_filter(array_map('trim', explode(',', (string) env(
        'NOTIFICATION_MAIL_ROUTINE_TYPES',
        'nest_tender_new,nest_tender_changed',
    ))))),

    // Never mail these domains — the seeded test logins use @hypermed.tz,
    // which is a real outside domain, not ours.
    'skip_domains' => array_values(array_filter(array_map('trim', explode(',', (string) env(
        'NOTIFICATION_MAIL_SKIP_DOMAINS',
        'hypermed.tz,example.com',
    ))))),

    // Where "Open in Hypermed" links point (the web app).
    'web_url' => rtrim((string) env('NOTIFICATION_MAIL_WEB_URL', 'https://app.hypermed.co.tz'), '/'),
];
