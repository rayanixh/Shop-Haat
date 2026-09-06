<?php
/**
 * Controlled error handling: no blank pages, no leaked secrets.
 */

function sh_log_line(string $channel, string $message): void
{
    $dir = defined('SH_LOG_DIR') ? SH_LOG_DIR : dirname(__DIR__) . '/logs';
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        error_log('ShopHaat: log directory unavailable: ' . $dir);
        return;
    }
    $message = sh_scrub_secrets($message);
    // The URI can carry secrets in the query string (e.g. hub.verify_token on the
    // WhatsApp webhook). Mask those values before anything is written to disk.
    $uri = (string)($_SERVER['REQUEST_URI'] ?? 'cli');
    $uri = preg_replace(
        '/((?:hub[._])?(?:verify_token|access_token|token|secret|password|signature)=)[^&\s]+/i',
        '$1[redacted]',
        $uri
    ) ?? $uri;
    $line = sprintf(
        "[%s] [%s] %s | uri=%s%s",
        date('Y-m-d H:i:s'),
        $channel,
        $message,
        sh_scrub_secrets($uri),
        PHP_EOL
    );
    // The logger itself must never raise; fall back to the PHP error log.
    if (file_put_contents($dir . '/app-' . date('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX) === false) {
        error_log('ShopHaat: ' . trim($line));
    }
}

/** Strip anything that looks like a credential before it reaches a log file. */
function sh_scrub_secrets(string $text): string
{
    $patterns = [
        '/(pass(word)?|passwd|secret|token|api[_-]?key|smtp_pass|private[_-]?key)\s*[=:]\s*\'?"?[^\s\'",;)]+/i',
        '/\b\d{9,10}:[A-Za-z0-9_-]{30,}\b/', // telegram bot token
    ];
    foreach ($patterns as $p) {
        $text = preg_replace($p, '$1=[redacted]', $text) ?? $text;
    }
    return $text;
}

function sh_log_exception(Throwable $e, string $channel = 'app'): void
{
    sh_log_line($channel, sprintf(
        '%s: %s in %s:%d',
        get_class($e),
        $e->getMessage(),
        $e->getFile(),
        $e->getLine()
    ));
}

function sh_wants_json(): bool
{
    if (defined('SH_JSON_CONTEXT')) { return true; }
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    $xhr = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';
    $inApi = strpos($_SERVER['SCRIPT_NAME'] ?? '', '/api/') !== false;
    return $xhr || $inApi || strpos($accept, 'application/json') !== false;
}

/** Renders a clean, self-contained error page and terminates. */
function sh_render_error_page(int $code, string $title, string $message, ?string $actionUrl = null, string $actionLabel = 'Run the installer'): void
{
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: ' . (sh_wants_json() ? 'application/json' : 'text/html') . '; charset=utf-8');
    }
    if (sh_wants_json()) {
        echo json_encode(['success' => false, 'error' => $message, 'code' => $code]);
        exit;
    }
    $home = function_exists('sh_url') ? sh_url('index.php') : '/';
    $t = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $m = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    // Optional recovery action (e.g. reconfigure the database connection).
    $extra = '';
    if ($actionUrl !== null) {
        $au = htmlspecialchars($actionUrl, ENT_QUOTES, 'UTF-8');
        $al = htmlspecialchars($actionLabel, ENT_QUOTES, 'UTF-8');
        $extra = '<a class="sh-err__btn" href="' . $au . '">' . $al . '</a>'
               . '<a class="sh-err__btn sh-err__btn--ghost" href="' . $home . '">Try again</a>';
    } else {
        $extra = '<a class="sh-err__btn" href="' . $home . '">Back to homepage</a>';
    }
    echo <<<HTML
<!doctype html>
<html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex">
<title>{$code} — {$t}</title>
<style>
:root{color-scheme:light}
body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;
background:#f4f5f7;font-family:system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif;color:#1f2430;padding:24px}
.sh-err{background:#fff;border:1px solid #e3e6ec;border-radius:10px;max-width:520px;width:100%;
padding:32px;box-shadow:0 2px 10px rgba(20,25,40,.06);text-align:center}
.sh-err__code{font-size:44px;font-weight:700;color:#e8501b;line-height:1;margin:0 0 8px}
.sh-err__title{font-size:19px;margin:0 0 10px}
.sh-err__msg{font-size:14.5px;line-height:1.6;color:#5a6172;margin:0 0 22px}
.sh-err__btn{display:inline-block;background:#e8501b;color:#fff;text-decoration:none;
padding:10px 22px;border-radius:6px;font-size:14px;font-weight:600;margin:0 4px 6px}
.sh-err__btn--ghost{background:#fff;color:#1f2430;border:1px solid #d7dbe3}
</style></head><body>
<div class="sh-err">
<p class="sh-err__code">{$code}</p>
<h1 class="sh-err__title">{$t}</h1>
<p class="sh-err__msg">{$m}</p>
{$extra}
</div></body></html>
HTML;
    exit;
}

function sh_register_error_handlers(): void
{
    set_error_handler(function ($severity, $message, $file, $line) {
        if (!(error_reporting() & $severity)) { return false; }
        // Notices/warnings are logged but must not kill the page.
        if (in_array($severity, [E_WARNING, E_NOTICE, E_DEPRECATED, E_USER_WARNING, E_USER_NOTICE, E_USER_DEPRECATED], true)) {
            sh_log_line('php', "$message in $file:$line");
            return true;
        }
        throw new ErrorException($message, 0, $severity, $file, $line);
    });

    set_exception_handler(function (Throwable $e) {
        sh_log_exception($e, 'uncaught');
        while (ob_get_level() > 0) { ob_end_clean(); }
        sh_render_error_page(500, 'Something went wrong', 'Something went wrong. Please try again later.');
    });

    register_shutdown_function(function () {
        $err = error_get_last();
        if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            sh_log_line('fatal', "{$err['message']} in {$err['file']}:{$err['line']}");
            while (ob_get_level() > 0) { ob_end_clean(); }
            sh_render_error_page(500, 'Something went wrong', 'Something went wrong. Please try again later.');
        }
    });
}
