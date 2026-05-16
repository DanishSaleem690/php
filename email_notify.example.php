<?php
/**
 * Copy to `email_notify.php` (gitignored) for local dev, OR set Railway Variables:
 *   BREVO_API_KEY, EMAIL_PROVIDER=brevo, EMAIL_FROM, EMAIL_TO (comma-separated).
 * Never commit API keys or paste them into .dockerignore / any tracked file.
 *
 * Sends one notification email over HTTPS (no SMTP in php.ini). Runs only after MySQL insert succeeds.
 * The contact form SPA posts only to contact.php (Resend or Brevo via this file).
 *
 * Resend: https://resend.com/api-keys — verify your domain at https://resend.com/domains, then set
 *         `from_email` to your address on that domain (e.g. Colin@klashclothing.com). Test-only: onboarding@resend.dev
 *
 * Brevo: https://app.brevo.com → SMTP & API → API keys → `xkeysib-...` (not xsmtpsib-).
 *        Senders → verify `from_email` via confirmation link.
 */
declare(strict_types=1);

return [
    /** `resend` or `brevo` */
    'provider' => 'resend',

    /** Resend: re_... | Brevo: xkeysib-... */
    'api_key' => '',

    /** Address on a domain verified in Resend (not @outlook.com). */
    'from_email' => 'Colin@klashclothing.com',

    /** Shown together with the visitor’s name: "{name} · {from_label}" <from_email> */
    'from_label' => 'Klash Clothing',

    /** One address or a list — each gets every contact notification */
    'to' => [
        'you@example.com',
        'colleague@example.com',
    ],
];
