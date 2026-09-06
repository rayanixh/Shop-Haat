<?php
/**
 * Meta WhatsApp Cloud API webhook.
 *
 *   GET  — Meta verification handshake (hub.mode / hub.verify_token / hub.challenge)
 *   POST — inbound messages and delivery statuses
 *
 * Contract: this endpoint must answer fast and must never emit a 500, a PHP
 * warning, a stack trace or any credential. Every failure path is caught and
 * logged server-side; Meta receives a short, safe response.
 *
 * Callback URL to register with Meta:  https://your-domain.com/webhook.php
 */
declare(strict_types=1);

// Marks this as a non-HTML context so the shared error handler stays quiet.
define('SH_JSON_CONTEXT', true);

// Absolute last line of defence: if anything below fails catastrophically we
// still return 200 rather than letting PHP print an error page to Meta.
register_shutdown_function(static function (): void {
    $e = error_get_last();
    if ($e !== null && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (!headers_sent()) {
            http_response_code(200);
            header('Content-Type: text/plain; charset=utf-8');
        }
        error_log('ShopHaat webhook fatal: ' . $e['message']);
        echo 'EVENT_RECEIVED';
    }
});

/** Send a plain response and stop. */
function sh_wh_respond(int $code, string $body = ''): void
{
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
    }
    echo $body;
    exit;
}

try {
    require_once __DIR__ . '/config/config.php';
} catch (Throwable $e) {
    error_log('ShopHaat webhook bootstrap failure: ' . $e->getMessage());
    sh_wh_respond(200, 'EVENT_RECEIVED');
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

// Only these two verbs exist here.
if (!in_array($method, ['GET', 'POST'], true)) {
    sh_wh_respond(405, 'Method Not Allowed');
}

// The store must be installed before settings/tables are usable.
if (sh_db_config() === null || !sh_is_locked()) {
    error_log('ShopHaat webhook called before installation completed.');
    sh_wh_respond($method === 'POST' ? 200 : 503, $method === 'POST' ? 'EVENT_RECEIVED' : 'Not installed');
}

try {
    sh_db();
    require_once SH_ROOT . '/includes/whatsapp.php';
} catch (Throwable $e) {
    // Database down: acknowledge POSTs so Meta does not hammer us, but log it.
    error_log('ShopHaat webhook dependency failure: ' . $e->getMessage());
    sh_wh_respond($method === 'POST' ? 200 : 503, $method === 'POST' ? 'EVENT_RECEIVED' : 'Service unavailable');
}

/* ------------------------------------------------------------------ *
 * GET — verification handshake
 * ------------------------------------------------------------------ */
if ($method === 'GET') {
    $mode      = (string)($_GET['hub_mode'] ?? $_GET['hub.mode'] ?? '');
    $token     = (string)($_GET['hub_verify_token'] ?? $_GET['hub.verify_token'] ?? '');
    $challenge = (string)($_GET['hub_challenge'] ?? $_GET['hub.challenge'] ?? '');

    // A bare visit with no hub.* parameters: report liveness, reveal nothing.
    if ($mode === '' && $token === '' && $challenge === '') {
        sh_wh_respond(200, 'ShopHaat WhatsApp webhook is active.');
    }

    $expected = trim((string)sh_setting('whatsapp_verify_token', ''));

    if ($expected === '') {
        sh_wa_log('Verification attempted but no verify token is configured.', true);
        sh_wa_touch('whatsapp_last_error', date('c') . ' — verification failed: no verify token configured');
        sh_wh_respond(403, 'Forbidden');
    }

    // hash_equals prevents timing comparison of the token.
    if ($mode === 'subscribe' && $token !== '' && hash_equals($expected, $token)) {
        sh_wa_log('Webhook verified successfully by Meta.');
        sh_wa_touch('whatsapp_last_webhook', date('c') . ' — verification succeeded');
        sh_wa_touch('whatsapp_last_error', '');
        // Meta expects the raw challenge string, nothing else.
        sh_wh_respond(200, $challenge);
    }

    sh_wa_log('Verification rejected (mode=' . substr($mode, 0, 20) . ', token mismatch).', true);
    sh_wa_touch('whatsapp_last_error', date('c') . ' — verification rejected: token mismatch');
    sh_wh_respond(403, 'Forbidden');
}

/* ------------------------------------------------------------------ *
 * POST — events
 * ------------------------------------------------------------------ */

// Reject absurd bodies before reading them into memory.
$declared = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($declared > 3145728) { // 3 MB
    sh_wa_log('Rejected oversized webhook body (' . $declared . ' bytes).', true);
    sh_wh_respond(413, 'Payload too large');
}

$raw = file_get_contents('php://input');
if ($raw === false) { $raw = ''; }
if (strlen($raw) > 3145728) {
    sh_wa_log('Rejected oversized webhook body after read.', true);
    sh_wh_respond(413, 'Payload too large');
}

// Optional but recommended: verify Meta's payload signature.
$sig = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? null;
if (!sh_wa_verify_signature($raw, $sig)) {
    sh_wa_log('Rejected webhook with an invalid X-Hub-Signature-256.', true);
    sh_wa_touch('whatsapp_last_error', date('c') . ' — rejected: invalid payload signature');
    sh_wh_respond(403, 'Forbidden');
}

$payload = json_decode($raw, true);
if (!is_array($payload)) {
    // Malformed JSON is not retryable; acknowledge so Meta stops resending.
    sh_wa_log('Malformed JSON payload ignored (' . strlen($raw) . ' bytes).', true);
    sh_wa_touch('whatsapp_last_error', date('c') . ' — malformed JSON payload');
    sh_wh_respond(200, 'EVENT_RECEIVED');
}

// Answer Meta first when the server supports it, then finish the work.
$flush = static function (): void {
    if (headers_sent()) { return; }
    http_response_code(200);
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Connection: close');
    $out = 'EVENT_RECEIVED';
    header('Content-Length: ' . strlen($out));
    echo $out;
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        // Best effort on Apache/mod_php; harmless if buffers are already gone.
        // ob_end_flush() warns when a buffer is not flushable; handle it explicitly.
        set_error_handler(static fn(): bool => true);
        try { while (ob_get_level() > 0) { ob_end_flush(); } } finally { restore_error_handler(); }
        flush();
    }
};

try {
    $object = (string)($payload['object'] ?? '');
    if ($object !== '' && $object !== 'whatsapp_business_account') {
        // Not ours (e.g. a Page subscription). Acknowledge and ignore.
        sh_wa_log('Ignored webhook for object "' . substr($object, 0, 40) . '".');
        sh_wh_respond(200, 'EVENT_RECEIVED');
    }

    $flush();

    $summary = sh_wa_process_payload($payload);

    sh_wa_log(sprintf(
        'POST processed — messages=%d duplicates=%d statuses=%d errors=%d%s',
        $summary['messages'], $summary['duplicates'], $summary['statuses'], $summary['errors'],
        $summary['ids'] ? ' ids=' . implode(',', array_slice($summary['ids'], 0, 5)) : ''
    ));

    sh_wa_touch('whatsapp_last_webhook', sprintf(
        '%s — %d message(s), %d status(es)%s',
        date('c'), $summary['messages'], $summary['statuses'],
        $summary['duplicates'] ? ', ' . $summary['duplicates'] . ' duplicate(s) ignored' : ''
    ));
    if ($summary['errors'] > 0) {
        sh_wa_touch('whatsapp_last_error', date('c') . ' — ' . $summary['errors'] . ' item(s) could not be stored');
    }
} catch (Throwable $e) {
    // Log privately; Meta already has (or is about to get) its 200.
    try { sh_log_exception($e, 'whatsapp-webhook'); } catch (Throwable $ignored) {}
    try { sh_wa_touch('whatsapp_last_error', date('c') . ' — internal error while processing (logged)'); } catch (Throwable $ignored) {}
    if (!headers_sent()) { sh_wh_respond(200, 'EVENT_RECEIVED'); }
}

exit;
