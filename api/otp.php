<?php
/**
 * Phone OTP endpoint — send / verify. Always returns JSON.
 * The phone is never taken from the client for account-bound purposes; it is
 * resolved from the session (pending register/login/phone-change) or from the
 * signed-in account. Guest checkout is the one case a phone is accepted from
 * the client, and it is normalised + rate-limited server-side.
 */
declare(strict_types=1);
define('SH_JSON_CONTEXT', true);
require_once dirname(__DIR__) . '/config/config.php';

if (sh_db_config() === null || !sh_is_locked()) {
    sh_json(['success' => false, 'error' => 'The store is not installed yet.'], 503);
}
try { sh_db(); sh_otp_schema_ensure(); } catch (Throwable $e) {
    sh_log_exception($e, 'api-otp-db');
    sh_json(['success' => false, 'error' => 'Service temporarily unavailable.'], 503);
}

require_once SH_ROOT . '/includes/auth.php';
require_once SH_ROOT . '/includes/admin-auth.php';

sh_session_start();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sh_json(['success' => false, 'error' => 'POST required.'], 405);
}
sh_csrf_require();

if (!sh_otp_enabled()) {
    sh_json(['success' => false, 'error' => 'Phone verification is currently disabled.'], 403);
}

$action = sh_post('action');
$purpose = sh_post('purpose');
if (!in_array($purpose, sh_otp_purposes(), true)) {
    sh_json(['success' => false, 'error' => 'Invalid verification purpose.'], 400);
}

$user = sh_user();
$userId = $user !== null ? (int)$user['id'] : null;
$admin = null;
if ($purpose === 'test') {
    $admin = sh_admin();
    if ($admin === null || $admin['role'] !== 'superadmin') {
        sh_json(['success' => false, 'error' => 'Not authorized.'], 403);
    }
}

/** Resolve the phone this purpose is allowed to send to (server-side). */
function sh_otp_resolve_phone(string $purpose, ?array $user): ?string
{
    switch ($purpose) {
        case 'register':
            $p = sh_pending_registration();
            return $p !== null ? $p['phone'] : null;
        case 'login':
            $p = sh_pending_login();
            return $p !== null ? $p['phone'] : null;
        case 'phone_change':
            if ($user === null) { return null; }
            return sh_pending_phone_change();
        case 'checkout':
            if ($user !== null) { return (string)$user['phone']; }
            return sh_phone_normalize(sh_post('phone')); // guest: validated + rate-limited below
        case 'account':
            if ($user === null) { return null; }
            return (string)$user['phone'];
        case 'test':
            return sh_phone_normalize(sh_post('phone'));
    }
    return null;
}

try {
    if ($action === 'send') {
        $phone = sh_otp_resolve_phone($purpose, $user);
        if ($phone === '' || $phone === null) {
            sh_json(['success' => false, 'error' => 'We could not find the phone number to verify. Please start again.'], 400);
        }
        $res = sh_otp_issue($phone, $purpose, $userId);
        if (!$res['ok']) {
            sh_json(['success' => false, 'error' => $res['error'] ?? 'Could not send the code.'], 429);
        }
        $out = [
            'success' => true,
            'message' => 'Verification code sent.',
            'expires_in' => sh_otp_expiry_seconds(),
            'cooldown' => sh_otp_resend_cooldown(),
        ];
        // The offline/test driver returns a code so an admin can exercise the
        // flow without an SMS provider. It is ONLY ever surfaced for the admin
        // "test" purpose — never to customers.
        if ($purpose === 'test' && !empty($res['code'])) {
            $out['code'] = $res['code'];
        }
        sh_json($out);
    }

    if ($action === 'verify') {
        $phone = sh_otp_resolve_phone($purpose, $user);
        if ($phone === '' || $phone === null) {
            sh_json(['success' => false, 'error' => 'We could not find the phone number to verify. Please start again.'], 400);
        }
        $code = trim((string)$_POST['code'] ?? '');
        $res = sh_otp_verify($phone, $purpose, $code, $userId);
        if (!$res['ok']) {
            sh_json(['success' => false, 'error' => $res['error'] ?? 'Incorrect code.']);
        }
        $done = sh_otp_complete($purpose, $phone);
        if (!$done['ok']) {
            sh_json(['success' => false, 'error' => $done['error'] ?? 'Verification could not be completed.']);
        }
        sh_json(['success' => true, 'redirect' => $done['redirect'] ?? null]);
    }

    sh_json(['success' => false, 'error' => 'Unknown action.'], 400);
} catch (Throwable $e) {
    sh_log_exception($e, 'api-otp');
    sh_json(['success' => false, 'error' => 'The request could not be completed.'], 500);
}
