<?php
/**
 * Brevo — use REST API (xkeysib-). Works on localhost (no SMTP IP whitelist).
 *
 * `to` — one email (string) or several (array). All receive each contact notification.
 */
declare(strict_types=1);

return [
    'provider' => 'brevo',
    // Local only — or set BREVO_API_KEY on Railway (never commit the real key).
    'api_key' => '',

    'from_email' => 'KlashIt_PVT@outlook.com',
    'from_label' => 'Website contact',

    /** Everyone who receives contact form alerts */
    'to' => [
        'danish.it@klashpvt.com',
        'waseem.it@klashpvt.com',
    ],
];
