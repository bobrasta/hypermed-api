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

    // Never mail these domains — the seeded test logins use @hypermed.tz,
    // which is a real outside domain, not ours.
    'skip_domains' => array_values(array_filter(array_map('trim', explode(',', (string) env(
        'NOTIFICATION_MAIL_SKIP_DOMAINS',
        'hypermed.tz,example.com',
    ))))),

    // Where "Open in Hypermed" links point (the web app).
    'web_url' => rtrim((string) env('NOTIFICATION_MAIL_WEB_URL', 'https://app.hypermed.co.tz'), '/'),
];
