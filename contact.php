<?php
declare(strict_types=1);

/**
 * Contact API — saves submissions to MySQL; optional HTTPS email via Resend or Brevo (see `email_notify.example.php`).
 *
 * Setup: copy `database.example.php` → `database.php`, run `sql/contact_submissions.sql`
 * on the same database as `dbname` in that file.
 *
 * Optional mail: copy `email_notify.example.php` → `email_notify.php`, add `api_key`, choose `provider`.
 */

require_once __DIR__ . '/bootstrap.php';
bootstrap_require_lib();

ini_set('display_errors', '0');
ob_start();

$GLOBALS['CONTACT_JSON_DONE'] = false;

register_shutdown_function(static function (): void {
    if (!empty($GLOBALS['CONTACT_JSON_DONE'])) {
        return;
    }
    $err = error_get_last();
    if ($err === null) {
        return;
    }
    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];
    if (!in_array($err['type'], $fatalTypes, true)) {
        return;
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    if (headers_sent()) {
        return;
    }
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    cors_apply(['methods' => 'POST, OPTIONS', 'headers' => 'Content-Type']);
    error_log(sprintf(
        'contact.php fatal: %s in %s:%d',
        (string)($err['message'] ?? ''),
        (string)($err['file'] ?? ''),
        (int)($err['line'] ?? 0)
    ));
    echo json_encode([
        'success' => false,
        'error' => 'fatal',
        'message' => 'PHP fatal error. Check the PHP error log (Laragon: PHP → Errors log). Often a syntax error in database.php.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
});

function contact_emit_json(int $httpCode, array $payload): void
{
    $GLOBALS['CONTACT_JSON_DONE'] = true;
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($httpCode);
    header('Content-Type: application/json; charset=utf-8');
    cors_apply(['methods' => 'POST, OPTIONS', 'headers' => 'Content-Type']);
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    $json = json_encode($payload, $flags);
    if ($json === false) {
        $json = json_encode([
            'success' => false,
            'message' => 'Could not build JSON response.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    echo $json;
    exit;
}

/** Set to true when including this file only for mail helpers (e.g. SMTP test script). */
if (defined('CONTACT_SKIP_ROUTER')) {
    return;
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    $GLOBALS['CONTACT_JSON_DONE'] = true;
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    cors_apply(['methods' => 'POST, OPTIONS', 'headers' => 'Content-Type']);
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    contact_emit_json(405, [
        'success' => false,
        'message' => 'Method not allowed. Use POST.',
    ]);
}

$raw = file_get_contents('php://input');
$data = json_decode($raw ?: '', true);

if (!is_array($data)) {
    contact_emit_json(400, [
        'success' => false,
        'message' => 'Invalid JSON payload.',
    ]);
}

$name = trim((string)($data['name'] ?? ''));
$email = trim((string)($data['email'] ?? ''));
$phone = trim((string)($data['phone'] ?? ''));
$message = trim((string)($data['message'] ?? ''));

if ($name === '' || $email === '' || $phone === '' || $message === '') {
    contact_emit_json(422, [
        'success' => false,
        'message' => 'All fields are required.',
    ]);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    contact_emit_json(422, [
        'success' => false,
        'message' => 'Please provide a valid email address.',
    ]);
}

if (strlen($name) > 200 || strlen($email) > 255 || strlen($phone) > 50 || strlen($message) > 65535) {
    contact_emit_json(422, [
        'success' => false,
        'message' => 'One or more fields exceed the maximum length allowed.',
    ]);
}

/**
 * @return 'saved'|'no_database_php'|'no_pdo_mysql'|'bad_config'|'config_leak'
 * @throws PDOException
 */
function contact_save_to_db(string $name, string $email, string $phone, string $message): string
{
    bootstrap_require_db();

    if (!extension_loaded('pdo_mysql')) {
        return 'no_pdo_mysql';
    }

    try {
        $pdo = db_pdo();
    } catch (RuntimeException $e) {
        if (str_contains($e->getMessage(), 'not configured')) {
            return 'no_database_php';
        }
        throw $e;
    }

    $sql = 'INSERT INTO `contact_submissions` (`name`, `email`, `phone`, `message`)
            VALUES (:name, :email, :phone, :message)';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':name' => $name,
        ':email' => $email,
        ':phone' => $phone,
        ':message' => $message,
    ]);

    return 'saved';
}

function contact_db_error_message(Throwable $e): string
{
    $msg = $e->getMessage();
    $oneLine = static function (string $s): string {
        $t = preg_replace('/\s+/', ' ', $s) ?? $s;

        return strlen($t) > 220 ? substr($t, 0, 217) . '...' : $t;
    };

    if ($e instanceof PDOException) {
        $sqlState = (string) ($e->errorInfo[0] ?? '');
        $mysqlErr = (int) ($e->errorInfo[1] ?? 0);
        if (stripos($msg, 'could not find driver') !== false || !in_array('mysql', PDO::getAvailableDrivers(), true)) {
            return 'PHP extension pdo_mysql is not enabled. In Laragon: Menu → PHP → Extensions → enable pdo_mysql, then restart Apache or your PHP process.';
        }
        if ($mysqlErr === 2002 || stripos($msg, 'Connection refused') !== false || stripos($msg, 'No connection could be made') !== false) {
            return 'Cannot connect to MySQL. In Laragon: start MySQL, set database.php `host` to 127.0.0.1 (not "localhost" if socket issues), and confirm port 3306.';
        }
        if ($mysqlErr === 1045 || stripos($msg, 'Access denied') !== false) {
            return 'MySQL rejected the login (user/password) in database.php. Fix credentials to match Laragon / HeidiSQL.';
        }
        if ($mysqlErr === 1049 || stripos($msg, 'Unknown database') !== false) {
            return 'The database in database.php (`dbname`) does not exist. Create it in HeidiSQL / Laragon, then run sql/contact_submissions.sql on that database.';
        }
        if ($sqlState === '42S02' || $mysqlErr === 1146 || stripos($msg, "doesn't exist") !== false) {
            return 'MySQL table `contact_submissions` does not exist in this database. Run `sql/contact_submissions.sql` on the database named in database.php (`dbname`).';
        }
        if ($mysqlErr === 1062 || stripos($msg, 'Duplicate entry') !== false) {
            return 'Duplicate row (old unique index on name/email/phone). Run: ALTER TABLE contact_submissions DROP INDEX uq_contact_submission;';
        }
        if ($mysqlErr === 2006 || $mysqlErr === 2013 || stripos($msg, 'gone away') !== false) {
            return 'MySQL closed the connection (server has gone away). Restart MySQL in Laragon and try again.';
        }

        return 'Could not save to the database. '
            . sprintf('SQLSTATE %s, MySQL code %s: ', $sqlState !== '' ? $sqlState : '—', $mysqlErr > 0 ? (string) $mysqlErr : '—')
            . $oneLine($msg);
    }

    if ($e instanceof Error && (stripos($msg, 'database.php') !== false || stripos($msg, 'Parse error') !== false)) {
        return 'Error loading database.php (syntax or path). ' . $oneLine($msg);
    }

    return 'Could not save to the database. ' . $oneLine($msg);
}

/** Strip characters that break email display names / RFC5322-ish `From` text. */
function contact_display_name_for_email(string $name): string
{
    $n = preg_replace('/[\r\n\t<>]+/', ' ', $name) ?? $name;
    $n = trim(str_replace(['"', '\\'], '', $n));
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($n, 'UTF-8') > 70) {
            $n = mb_substr($n, 0, 67, 'UTF-8') . '...';
        }
    } elseif (strlen($n) > 70) {
        $n = substr($n, 0, 67) . '...';
    }

    return $n;
}

/**
 * POST JSON over HTTPS (no local SMTP). Uses curl if available, else file_get_contents.
 *
 * @return array{code: int, body: string}
 */
function contact_http_post_json(string $url, array $headerLines, string $jsonBody): array
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch === false) {
            return ['code' => 0, 'body' => ''];
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_POSTFIELDS => $jsonBody,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
        ]);
        $out = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($out === false) {
            error_log('contact.php curl: ' . curl_error($ch));
        }
        curl_close($ch);

        return ['code' => $code, 'body' => is_string($out) ? $out : ''];
    }

    if (!ini_get('allow_url_fopen')) {
        error_log('contact.php: enable PHP curl extension or allow_url_fopen for Resend/Brevo.');

        return ['code' => 0, 'body' => ''];
    }

    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headerLines),
            'content' => $jsonBody,
            'timeout' => 20,
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);
    $out = @file_get_contents($url, false, $ctx);
    $code = 0;
    $headers = function_exists('http_get_last_response_headers')
        ? http_get_last_response_headers()
        : ($GLOBALS['http_response_header'] ?? null);
    if (is_array($headers) && isset($headers[0]) && preg_match('/\s(\d{3})\s/', (string) $headers[0], $m)) {
        $code = (int) $m[1];
    }
    if ($out === false) {
        error_log('contact.php HTTP POST failed (no curl; allow_url_fopen fallback).');

        return ['code' => $code, 'body' => ''];
    }

    return ['code' => $code, 'body' => $out];
}

/** @return string Human-readable API error or empty string */
function contact_parse_mail_api_error(string $body): string
{
    if ($body === '') {
        return '';
    }
    $j = json_decode($body, true);
    if (!is_array($j)) {
        return '';
    }
    foreach (['message', 'error'] as $key) {
        if (!empty($j[$key]) && is_string($j[$key])) {
            return trim($j[$key]);
        }
    }

    return '';
}

/**
 * @return list<string> Valid recipient emails from email_notify `to` (string or array).
 */
function contact_normalize_notify_recipients(mixed $toConfig): array
{
    $raw = [];
    if (is_string($toConfig)) {
        $raw = preg_split('/[\s,;]+/', $toConfig) ?: [];
    } elseif (is_array($toConfig)) {
        $raw = $toConfig;
    }
    $out = [];
    foreach ($raw as $item) {
        $addr = trim((string) $item);
        if ($addr === '' || !filter_var($addr, FILTER_VALIDATE_EMAIL)) {
            continue;
        }
        $out[] = $addr;
    }

    return array_values(array_unique($out));
}

/**
 * @param list<string> $recipients
 * @return array{ok: bool, error: string}
 */
function contact_email_send_resend(
    string $apiKey,
    string $fromRfc,
    array $recipients,
    string $replyTo,
    string $subject,
    string $text
): array {
    $payload = json_encode([
        'from' => $fromRfc,
        'to' => $recipients,
        'reply_to' => [$replyTo],
        'subject' => $subject,
        'text' => $text,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($payload === false) {
        return ['ok' => false, 'error' => 'Could not build email payload.'];
    }
    $res = contact_http_post_json(
        'https://api.resend.com/emails',
        [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ],
        $payload
    );
    if ($res['code'] < 200 || $res['code'] >= 300) {
        $detail = contact_parse_mail_api_error($res['body']);
        error_log('contact.php Resend HTTP ' . $res['code'] . ': ' . substr($res['body'], 0, 800));
        if ($detail === '') {
            if ($res['code'] === 0) {
                $detail = 'Could not reach Resend (enable PHP openssl/curl in Laragon: Menu → PHP → Extensions).';
            } else {
                $detail = 'Resend rejected the email (HTTP ' . $res['code'] . ').';
            }
        }

        return ['ok' => false, 'error' => $detail];
    }

    return ['ok' => true, 'error' => ''];
}

/**
 * @param list<string> $recipients
 * @return array{ok: bool, error: string}
 */
function contact_email_send_brevo(
    string $apiKey,
    string $fromEmail,
    string $senderDisplayName,
    array $recipients,
    string $replyTo,
    string $subject,
    string $text
): array {
    $toPayload = [];
    foreach ($recipients as $addr) {
        $toPayload[] = ['email' => $addr];
    }
    $sender = ['email' => $fromEmail];
    if ($senderDisplayName !== '') {
        $sender['name'] = $senderDisplayName;
    }
    $payload = json_encode([
        'sender' => $sender,
        'to' => $toPayload,
        'replyTo' => ['email' => $replyTo],
        'subject' => $subject,
        'textContent' => $text,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($payload === false) {
        return ['ok' => false, 'error' => 'Could not build email payload.'];
    }
    $res = contact_http_post_json(
        'https://api.brevo.com/v3/smtp/email',
        [
            'api-key: ' . $apiKey,
            'Content-Type: application/json',
        ],
        $payload
    );
    if ($res['code'] < 200 || $res['code'] >= 300) {
        $detail = contact_parse_mail_api_error($res['body']);
        error_log('contact.php Brevo HTTP ' . $res['code'] . ': ' . substr($res['body'], 0, 800));
        if ($detail === '') {
            $detail = $res['code'] === 0
                ? 'Could not reach Brevo (enable PHP openssl/curl in Laragon).'
                : 'Brevo rejected the email (HTTP ' . $res['code'] . ').';
        }

        return ['ok' => false, 'error' => $detail];
    }

    return ['ok' => true, 'error' => ''];
}

/** @param resource $fp */
function contact_smtp_expect($fp, array $okCodes): ?string
{
    $line = '';
    while (is_resource($fp) && !feof($fp)) {
        $buf = fgets($fp, 515);
        if ($buf === false) {
            break;
        }
        $line .= $buf;
        if (isset($buf[3]) && $buf[3] === ' ') {
            break;
        }
    }
    if ($line === '') {
        return 'SMTP: no response from server';
    }
    $code = (int) substr($line, 0, 3);

    return in_array($code, $okCodes, true) ? null : trim($line);
}

/** @param resource $fp */
function contact_smtp_cmd($fp, string $cmd, array $okCodes): ?string
{
    if (fwrite($fp, $cmd) === false) {
        return 'SMTP: could not send command';
    }

    return contact_smtp_expect($fp, $okCodes);
}

/**
 * Brevo SMTP (xsmtpsib- key). Login = smtp_user from Brevo → SMTP & API → SMTP.
 *
 * @param list<string> $recipients
 * @return array{ok: bool, error: string}
 */
function contact_email_send_brevo_smtp(
    string $host,
    int $port,
    string $smtpUser,
    string $smtpPass,
    string $fromEmail,
    string $fromName,
    array $recipients,
    string $replyTo,
    string $subject,
    string $text
): array {
    $errno = 0;
    $errstr = '';
    $fp = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 25);
    if (!is_resource($fp)) {
        return ['ok' => false, 'error' => 'SMTP connect failed: ' . ($errstr ?: (string) $errno)];
    }

    stream_set_timeout($fp, 25);

    $fail = static function (string $msg) use ($fp): array {
        if (is_resource($fp)) {
            @fwrite($fp, "QUIT\r\n");
            fclose($fp);
        }

        return ['ok' => false, 'error' => $msg];
    };

    $err = contact_smtp_expect($fp, [220]);
    if ($err !== null) {
        return $fail($err);
    }

    $err = contact_smtp_cmd($fp, "EHLO localhost\r\n", [250]);
    if ($err !== null) {
        return $fail($err);
    }

    $err = contact_smtp_cmd($fp, "STARTTLS\r\n", [220]);
    if ($err !== null) {
        return $fail($err);
    }

    $cryptoOk = stream_socket_enable_crypto(
        $fp,
        true,
        STREAM_CRYPTO_METHOD_TLS_CLIENT
    );
    if ($cryptoOk !== true) {
        return $fail('SMTP STARTTLS failed (enable PHP openssl in Laragon).');
    }

    $err = contact_smtp_cmd($fp, "EHLO localhost\r\n", [250]);
    if ($err !== null) {
        return $fail($err);
    }

    $err = contact_smtp_cmd($fp, "AUTH LOGIN\r\n", [334]);
    if ($err !== null) {
        return $fail($err);
    }
    $err = contact_smtp_cmd($fp, base64_encode($smtpUser) . "\r\n", [334]);
    if ($err !== null) {
        return $fail($err);
    }
    $err = contact_smtp_cmd($fp, base64_encode($smtpPass) . "\r\n", [235]);
    if ($err !== null) {
        if (stripos($err, 'Unauthorized IP') !== false || str_contains($err, '525')) {
            return $fail(
                'Brevo SMTP blocked this IP (525). Use API instead: in email_notify.php set provider to brevo and api_key to xkeysib-... from Brevo → API keys (not xsmtpsib-). Or deactivate SMTP IP restriction under Brevo → Security.'
            );
        }

        return $fail('SMTP login failed: ' . $err);
    }

    $err = contact_smtp_cmd($fp, 'MAIL FROM:<' . $fromEmail . ">\r\n", [250]);
    if ($err !== null) {
        return $fail($err);
    }
    $err = contact_smtp_cmd($fp, 'RCPT TO:<' . $to . ">\r\n", [250]);
    if ($err !== null) {
        return $fail($err);
    }

    $headers = 'From: <' . $fromEmail . ">\r\n";
    if ($fromName !== '') {
        $safeName = str_replace(['"', "\r", "\n"], '', $fromName);
        $headers = 'From: "' . $safeName . '" <' . $fromEmail . ">\r\n";
    }
    $headers .= 'Reply-To: <' . $replyTo . ">\r\n";
    $headers .= 'To: <' . $to . ">\r\n";
    $headers .= 'Subject: ' . $subject . "\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $headers .= "Content-Transfer-Encoding: 8bit\r\n";

    $body = str_replace(["\r\n", "\r"], "\n", $text);
    $body = str_replace("\n.", "\n..", $body);

    $err = contact_smtp_cmd($fp, "DATA\r\n", [354]);
    if ($err !== null) {
        return $fail($err);
    }
    if (fwrite($fp, $headers . "\r\n" . $body . "\r\n.\r\n") === false) {
        return $fail('SMTP: could not send message body');
    }
    $err = contact_smtp_expect($fp, [250]);
    if ($err !== null) {
        return $fail($err);
    }

    contact_smtp_cmd($fp, "QUIT\r\n", [221]);
    fclose($fp);

    return ['ok' => true, 'error' => ''];
}

/**
 * email_notify.php (local, gitignored) and/or Railway env: BREVO_API_KEY, EMAIL_FROM, EMAIL_TO, EMAIL_PROVIDER.
 *
 * @return array<string, mixed>|null
 */
function contact_load_email_notify_config(): ?array
{
    $cfg = [];
    $path = __DIR__ . '/email_notify.php';
    if (is_readable($path)) {
        ob_start();
        try {
            $loaded = require $path;
        } finally {
            $leak = (string) ob_get_clean();
        }
        if ($leak !== '') {
            error_log('contact.php: email_notify.php must not output before/after the return array.');
        }
        if (is_array($loaded)) {
            $cfg = $loaded;
        }
    }

    foreach (
        [
            'EMAIL_PROVIDER' => 'provider',
            'EMAIL_FROM' => 'from_email',
            'EMAIL_FROM_LABEL' => 'from_label',
        ] as $envKey => $cfgKey
    ) {
        $v = getenv($envKey);
        if (is_string($v) && trim($v) !== '') {
            $cfg[$cfgKey] = trim($v);
        }
    }

    $envTo = getenv('EMAIL_TO');
    if (is_string($envTo) && trim($envTo) !== '') {
        $cfg['to'] = array_map('trim', explode(',', $envTo));
    }

    $envKey = getenv('BREVO_API_KEY') ?: getenv('RESEND_API_KEY') ?: getenv('EMAIL_API_KEY');
    if (is_string($envKey) && trim($envKey) !== '') {
        $cfg['api_key'] = trim($envKey);
        if (empty($cfg['provider'])) {
            $cfg['provider'] = str_starts_with(trim($envKey), 're_') ? 'resend' : 'brevo';
        }
    }

    return $cfg !== [] ? $cfg : null;
}

/**
 * Optional: copy email_notify.example.php → email_notify.php with api_key + from_email + to.
 *
 * @return array{configured: bool, sent: bool, error: string}
 */
function contact_try_email_notify(string $name, string $email, string $phone, string $message): array
{
    $cfg = contact_load_email_notify_config();
    if ($cfg === null) {
        return ['configured' => false, 'sent' => false, 'error' => ''];
    }
    $apiKey = trim((string) ($cfg['api_key'] ?? ''));
    $smtpPass = trim((string) ($cfg['smtp_pass'] ?? ''));
    if ($smtpPass === '' && str_starts_with($apiKey, 'xsmtpsib-')) {
        $smtpPass = $apiKey;
    }
    $smtpUser = trim((string) ($cfg['smtp_user'] ?? ''));
    $smtpHost = trim((string) ($cfg['smtp_host'] ?? 'smtp-relay.brevo.com'));
    $smtpPort = (int) ($cfg['smtp_port'] ?? 587);
    $provider = strtolower(trim((string) ($cfg['provider'] ?? 'resend')));
    $useBrevoSmtp = $provider === 'brevo_smtp'
        || ($provider === 'brevo' && str_starts_with($smtpPass, 'xsmtpsib-'));

    if (!$useBrevoSmtp && $apiKey === '') {
        return ['configured' => false, 'sent' => false, 'error' => ''];
    }
    if ($useBrevoSmtp && ($smtpUser === '' || $smtpPass === '')) {
        return [
            'configured' => true,
            'sent' => false,
            'error' => 'Set smtp_user and smtp_pass (xsmtpsib key) in email_notify.php — see Brevo → SMTP & API → SMTP.',
        ];
    }
    $fromEmail = trim((string) ($cfg['from_email'] ?? ''));
    if ($fromEmail === '' || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
        error_log('contact.php email_notify: invalid or missing from_email in email_notify.php');

        return ['configured' => true, 'sent' => false, 'error' => 'Invalid from_email in email_notify.php.'];
    }
    $recipients = contact_normalize_notify_recipients($cfg['to'] ?? 'info@klashclothing.com');
    if ($recipients === []) {
        error_log('contact.php email_notify: no valid to address in email_notify.php');

        return ['configured' => true, 'sent' => false, 'error' => 'Invalid to address in email_notify.php.'];
    }
    $label = trim((string) ($cfg['from_label'] ?? 'Klash'));
    if ($label === '') {
        $label = 'Klash';
    }
    $safeName = contact_display_name_for_email($name);
    /** Inbox “From” line: email only (visitor name is in subject + body + Reply-To). */
    $senderDisplay = '';
    $subject = $safeName !== ''
        ? sprintf('[Sales Enquiries] Message from %s', $safeName)
        : '[Sales Enquiries] New website contact';
    $text = sprintf(
        "Name: %s\nEmail: %s\nPhone: %s\n\n%s",
        $name,
        $email,
        $phone,
        $message
    );
    $replyTo = filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : $recipients[0];

    try {
        if ($useBrevoSmtp) {
            $out = contact_email_send_brevo_smtp(
                $smtpHost,
                $smtpPort > 0 ? $smtpPort : 587,
                $smtpUser,
                $smtpPass,
                $fromEmail,
                $senderDisplay,
                $recipients,
                $replyTo,
                $subject,
                $text
            );
            $sent = $out['ok'];
            $err = $out['error'];
        } elseif ($provider === 'brevo') {
            $out = contact_email_send_brevo($apiKey, $fromEmail, $senderDisplay, $recipients, $replyTo, $subject, $text);
            $sent = $out['ok'];
            $err = $out['error'];
        } else {
            $fromRfc = $fromEmail;
            $out = contact_email_send_resend($apiKey, $fromRfc, $recipients, $replyTo, $subject, $text);
            $sent = $out['ok'];
            $err = $out['error'];
        }

        return ['configured' => true, 'sent' => $sent, 'error' => $err];
    } catch (Throwable $e) {
        error_log('contact.php email_notify: ' . $e->getMessage());

        return ['configured' => true, 'sent' => false, 'error' => $e->getMessage()];
    }
}

try {
    $dbOutcome = contact_save_to_db($name, $email, $phone, $message);
} catch (Throwable $e) {
    error_log('contact.php DB error: ' . $e->getMessage());
    contact_emit_json(500, [
        'success' => false,
        'error' => 'db_error',
        'message' => contact_db_error_message($e),
    ]);
}

if ($dbOutcome === 'no_database_php') {
    contact_emit_json(503, [
        'success' => false,
        'error' => 'no_database_config',
        'message' => 'MySQL is not configured: create database.php next to contact.php (copy database.example.php and set host, dbname, user, pass).',
    ]);
}

if ($dbOutcome === 'no_pdo_mysql') {
    contact_emit_json(500, [
        'success' => false,
        'error' => 'no_pdo_mysql',
        'message' => 'PHP extension pdo_mysql is not enabled. Laragon: Menu → PHP → Extensions → check pdo_mysql, then restart Apache or PHP.',
    ]);
}

if ($dbOutcome === 'bad_config') {
    contact_emit_json(500, [
        'success' => false,
        'error' => 'bad_database_config',
        'message' => 'database.php must return an array with non-empty `host` and `dbname` (and usually `user` / `pass`).',
    ]);
}

if ($dbOutcome === 'config_leak') {
    contact_emit_json(500, [
        'success' => false,
        'error' => 'config_leak',
        'message' => 'database.php must be PHP only: no closing ?> tag and no spaces or output after the return array.',
    ]);
}

if ($dbOutcome === 'saved') {
    $mail = contact_try_email_notify($name, $email, $phone, $message);
    $savedMsg = 'Thank you. We have received your message.';
    if ($mail['sent']) {
        $savedMsg = 'Thank you. We have received your message and will get back to you soon.';
    }
    $payload = [
        'success' => true,
        'message' => $savedMsg,
        'email_configured' => $mail['configured'],
        'email_sent' => $mail['sent'],
    ];
    if (!$mail['sent'] && ($mail['error'] ?? '') !== '') {
        $payload['email_error'] = $mail['error'];
    }
    contact_emit_json(200, $payload);
}

contact_emit_json(500, [
    'success' => false,
    'error' => 'unexpected',
    'message' => 'Unexpected state after database save.',
]);
