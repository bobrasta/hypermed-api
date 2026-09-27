<?php

// Email copies of in-app notifications (Section 11's notification engine,
// asked for by Sections 18.3 and 19.2). Off by default: Railway blocks
// outbound SMTP, so only the VPS — whose own Postfix relays for localhost
// and DKIM-signs @hypermed.co.tz — turns it on.
return [
    'enabled' => (bool) env('NOTIFICATION_MAIL_ENABLED', false),

    // notifications.type values that also go out by email. The specs only
    // ask for these; add more here (or via the env) to widen it.
    'types' => array_values(array_filter(array_map('trim', explode(',', (string) env(
        'NOTIFICATION_MAIL_TYPES',
        'shipment_created,shipment_status,tender_deadline,tender_overdue,device_renewal',
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
