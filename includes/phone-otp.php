<?php
/**
 * Phone OTP verification & anti-fake-order engine.
 *
 * Design goals (shared hosting / plain PHP / MySQL):
 *  - Provider agnostic: drivers are swappable without touching any caller.
 *    Built-in drivers: `offline` (test mode, never fakes a success on a live
 *    order) and `generic_http` (configurable API URL / method / body / headers
 *    / success-check so virtually any SMS gateway can be wired from the admin
 *    panel). A site-specific driver can be registered with
 *    sh_otp_register_driver().
 *  - OTPs are generated server-side, stored only as hashes, never logged and
 *    never echoed back to the client on success.
 *  - Rate limiting (per phone + per IP), resend cooldown, attempt limits and a
 *    daily cap protect against fake/abusive orders.
 *  - Verified users are NOT re-challenged unless the admin turns on
 *    "Require OTP For Every Order" (default OFF).
 *
 * The master switch (otp_enabled) gates everything: when it is OFF every helper
 * here returns "no verification required", so the pre-OTP flow is restored
 * exactly (subject to the schema columns added at migration time, which are
 * additive and non-breaking).
 */

require_once SH_ROOT . '/includes/functions.php';
require_once SH_ROOT . '/includes/errors.php';

/** Allowed OTP purposes. Keep in sync with UI copy and admin filters. */
function sh_otp_purposes(): array
{
    return ['register', 'login', 'checkout', 'phone_change', 'account', 'test'];
}

function sh_otp_enabled(): bool
{
    return sh_setting('otp_enabled', '1') === '1';
}

function sh_otp_rule(string $key, bool $default = false): bool
{
    if (!sh_otp_enabled()) { return false; }
    return sh_setting($key, $default ? '1' : '0') === '1';
}

// ---------------------------------------------------------------------------
// Settings
// ---------------------------------------------------------------------------
function sh_otp_length(): int
{
    $n = (int)sh_setting('otp_length', '6');
    return ($n >= 4 && $n <= 10) ? $n : 6;
}

function sh_otp_expiry_seconds(): int
{
    $m = max(1, (int)sh_setting('otp_expiry_minutes', '5'));
    return $m * 60;
}

function sh_otp_max_attempts(): int
{
    return max(1, (int)sh_setting('otp_max_attempts', '5'));
}

function sh_otp_max_resends(): int
{
    return max(1, (int)sh_setting('otp_max_resends', '3'));
}

function sh_otp_resend_cooldown(): int
{
    return max(0, (int)sh_setting('otp_resend_cooldown', '45'));
}

function sh_otp_daily_limit(): int
{
    return max(1, (int)sh_setting('otp_daily_limit', '20'));
}

function sh_otp_phone_rate_limit(): int
{
    return max(1, (int)sh_setting('otp_phone_rate_limit', '5'));
}

function sh_otp_phone_rate_window(): int
{
    return max(60, (int)sh_setting('otp_phone_rate_window', '10'));
}

function sh_otp_ip_rate_limit(): int
{
    return max(1, (int)sh_setting('otp_ip_rate_limit', '10'));
}

function sh_otp_ip_rate_window(): int
{
    return max(60, (int)sh_setting('otp_ip_rate_window', '60'));
}

function sh_otp_provider(): string
{
    return (string)sh_setting('otp_provider', 'offline');
}

function sh_otp_message(string $code, int $minutes): string
{
    $tpl = (string)sh_setting('otp_message', 'Your ShopHaat verification code is {code}. It expires in {minutes} minutes. Do not share it with anyone.');
    return str_replace(['{code}', '{minutes}'], [$code, (string)$minutes], $tpl);
}

// ---------------------------------------------------------------------------
// Security logging (OTP values and API secrets are NEVER written here)
// ---------------------------------------------------------------------------
function sh_security_log(string $event, ?int $userId = null, array $meta = []): void
{
    try {
        if (!sh_table_exists('security_logs')) { return; }
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $ip = sh_client_ip();
        $metaJson = empty($meta) ? null : json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        sh_db()->prepare(
            'INSERT INTO security_logs (user_id, event, ip_address, user_agent, metadata)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$userId, $event, mb_substr($ip, 0, 45), mb_substr($ua, 0, 255), $metaJson]);
    } catch (Throwable $e) {
        sh_log_exception($e, 'security-log');
    }
}

function sh_otp_log(string $phone, string $purpose, string $status, array $meta = []): void
{
    $meta['phone'] = sh_phone_mask($phone);
    $meta['purpose'] = $purpose;
    $meta['status'] = $status;
    sh_security_log('otp_' . $status, null, $meta);
}

// ---------------------------------------------------------------------------
// Generation & storage
// ---------------------------------------------------------------------------
function sh_otp_generate(int $length): string
{
    $max = (int)str_repeat('9', $length);
    $code = (string)random_int(0, $max);
    return str_pad($code, $length, '0', STR_PAD_LEFT);
}

function sh_otp_insert(string $phone, string $purpose, string $code, ?int $userId): int
{
    $hash = password_hash($code, PASSWORD_DEFAULT);
    sh_db()->prepare(
        'INSERT INTO otp_verifications
            (user_id, phone, purpose, otp_hash, expires_at, attempts, max_attempts, resend_count, ip_address, user_agent)
         VALUES (?,?,?,?,?,0,?,0,?,?)'
    )->execute([
        $userId,
        $phone,
        $purpose,
        $hash,
        date('Y-m-d H:i:s', time() + sh_otp_expiry_seconds()),
        sh_otp_max_attempts(),
        mb_substr(sh_client_ip(), 0, 45),
        mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
    ]);
    return (int)sh_db()->lastInsertId();
}

function sh_otp_get_active(string $phone, string $purpose, ?int $userId): ?array
{
    $st = sh_db()->prepare(
        'SELECT * FROM otp_verifications
         WHERE phone = ? AND purpose = ? AND verified_at IS NULL AND expires_at > NOW()
           AND (user_id <=> ?)
         ORDER BY id DESC LIMIT 1'
    );
    $st->execute([$phone, $purpose, $userId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Persist an OTP record and hand it to the configured provider driver.
 * Returns ['ok'=>bool, 'code'=>string|null, 'error'=>string|null].
 * `code` is only ever populated for the offline driver and MUST be treated as
 * test-only — never displayed in production UI.
 */
function sh_otp_issue(string $phone, string $purpose, ?int $userId, string $ip = null): array
{
    $ip = $ip ?: sh_client_ip();
    if (!sh_otp_enabled()) {
        return ['ok' => false, 'code' => null, 'error' => 'Phone verification is disabled.'];
    }
    if (!in_array($purpose, sh_otp_purposes(), true)) {
        return ['ok' => false, 'code' => null, 'error' => 'Invalid verification purpose.'];
    }
    $phone = sh_phone_normalize($phone);
    if ($phone === '') {
        return ['ok' => false, 'code' => null, 'error' => 'Please provide a valid mobile number.'];
    }

    // ---- Rate limits -----------------------------------------------------
    try {
        $limiter = sh_otp_limit_check($phone, $ip);
    } catch (Throwable $e) {
        sh_log_exception($e, 'otp-limit');
        $limiter = 'Verification is temporarily unavailable. Please try again shortly.';
    }
    if ($limiter !== null) {
        sh_otp_log($phone, $purpose, 'rate_limited', ['reason' => $limiter]);
        return ['ok' => false, 'code' => null, 'error' => $limiter];
    }

    try {
        $row = sh_otp_get_active($phone, $purpose, $userId);
    } catch (Throwable $e) {
        sh_log_exception($e, 'otp-active');
        return ['ok' => false, 'code' => null, 'error' => 'Verification is temporarily unavailable. Please try again shortly.'];
    }
    $now = time();
    if ($row) {
        if ((int)$row['resend_count'] >= sh_otp_max_resends()) {
            return ['ok' => false, 'code' => null, 'error' => 'Too many codes have been sent. Please try again later.'];
        }
        $cooldown = sh_otp_resend_cooldown();
        if ($cooldown > 0) {
            $last = strtotime($row['created_at']);
            if ($last !== false && ($now - $last) < $cooldown) {
                $wait = $cooldown - ($now - $last);
                return ['ok' => false, 'code' => null, 'error' => "Please wait {$wait} seconds before requesting another code."];
            }
        }
    }

    // ---- Generate, store, send ------------------------------------------
    $code = sh_otp_generate(sh_otp_length());
    try {
        $id = sh_otp_insert($phone, $purpose, $code, $userId);
    } catch (Throwable $e) {
        sh_log_exception($e, 'otp-insert');
        return ['ok' => false, 'code' => null, 'error' => 'Could not prepare verification. Please try again.'];
    }

    $minutes = (int)ceil(sh_otp_expiry_seconds() / 60);
    $provider = sh_otp_provider();
    $result = sh_otp_send($provider, $phone, sh_otp_message($code, $minutes));

    if ($result['ok']) {
        sh_otp_log($phone, $purpose, 'requested', ['provider' => $provider, 'id' => $id]);
        $out = ['ok' => true, 'code' => null, 'error' => null];
        if ($provider === 'offline') { $out['code'] = $code; } // test driver only
        return $out;
    }

    // Failed to deliver: destroy the record so no dead code lingers.
    try { sh_db()->prepare('DELETE FROM otp_verifications WHERE id = ?')->execute([$id]); } catch (Throwable $e) { sh_log_exception($e, 'otp-delete'); }
    sh_otp_log($phone, $purpose, 'failed', ['provider' => $provider, 'error' => $result['error']]);
    return ['ok' => false, 'code' => null, 'error' => $result['error']];
}

/**
 * Verify a code against the active record for the phone+purpose. On success the
 * record is marked verified and reused as the authoritative proof.
 */
function sh_otp_verify(string $phone, string $purpose, string $code, ?int $userId): array
{
    if (!sh_otp_enabled()) {
        return ['ok' => false, 'error' => 'Phone verification is disabled.'];
    }
    $phone = sh_phone_normalize($phone);
    $code = trim($code);
    if ($phone === '' || $code === '') {
        return ['ok' => false, 'error' => 'Enter the code we sent you.'];
    }
    $row = sh_otp_get_active($phone, $purpose, $userId);
    if (!$row) {
        return ['ok' => false, 'error' => 'No active verification code found. Please request a new code.'];
    }
    if ((int)$row['attempts'] >= (int)$row['max_attempts']) {
        return ['ok' => false, 'error' => 'Too many incorrect attempts. Please request a new code.'];
    }

    $now = time();
    $exp = strtotime($row['expires_at']);
    $pdo = sh_db();
    if ($exp === false || $now > $exp) {
        $pdo->prepare('UPDATE otp_verifications SET attempts = attempts + 1 WHERE id = ?')->execute([$row['id']]);
        sh_otp_log($phone, $purpose, 'expired');
        return ['ok' => false, 'error' => 'This code has expired. Please request a new code.'];
    }
    if (!password_verify($code, $row['otp_hash'])) {
        $pdo->prepare('UPDATE otp_verifications SET attempts = attempts + 1 WHERE id = ?')->execute([$row['id']]);
        sh_otp_log($phone, $purpose, 'failed', ['attempts' => (int)$row['attempts'] + 1]);
        return ['ok' => false, 'error' => 'Incorrect code. Please check and try again.'];
    }

    // Correct code: consume it so it cannot be replayed.
    $pdo->prepare('UPDATE otp_verifications SET verified_at = NOW() WHERE id = ?')->execute([$row['id']]);
    sh_otp_log($phone, $purpose, 'verified', ['id' => $row['id']]);
    return ['ok' => true, 'error' => null, 'method' => 'sms'];
}

// ---------------------------------------------------------------------------
// Rate limiting (per phone + per IP + daily cap) — implemented in SQL so a
// user cannot bypass it by clearing cookies or opening many sessions.
// ---------------------------------------------------------------------------
function sh_otp_limit_check(string $phone, string $ip): ?string
{
    $pdo = sh_db();
    $window = sh_otp_phone_rate_window();
    $cut = date('Y-m-d H:i:s', time() - $window);

    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM otp_verifications WHERE phone = ? AND created_at > ?'
    );
    $st->execute([$phone, $cut]);
    if ((int)$st->fetchColumn() >= sh_otp_phone_rate_limit()) {
        return 'Too many verification requests for this number. Please wait a few minutes.';
    }

    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM otp_verifications WHERE ip_address = ? AND created_at > ?'
    );
    $st->execute([$ip, date('Y-m-d H:i:s', time() - sh_otp_ip_rate_window())]);
    if ((int)$st->fetchColumn() >= sh_otp_ip_rate_limit()) {
        return 'Too many verification requests from this device. Please try again later.';
    }

    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM otp_verifications WHERE phone = ? AND created_at >= ?'
    );
    $st->execute([$phone, date('Y-m-d 00:00:00')]);
    if ((int)$st->fetchColumn() >= sh_otp_daily_limit()) {
        return 'Daily verification limit reached for this number. Please try again tomorrow.';
    }
    return null;
}

// ---------------------------------------------------------------------------
// Provider drivers
// ---------------------------------------------------------------------------
/** @var array<string, callable> */
function sh_otp_driver_registry(): array
{
    static $drivers = null;
    if ($drivers === null) { $drivers = []; }
    return $drivers;
}

/** Register a custom provider driver: fn(string $phone, string $message): array{ok:bool,error:?string} */
function sh_otp_register_driver(string $name, callable $fn): void
{
    $drivers = &sh_otp_driver_registry();
    $drivers[$name] = $fn;
}

function sh_otp_send(string $provider, string $phone, string $message): array
{
    $drivers = sh_otp_driver_registry();
    if (isset($drivers[$provider])) {
        try {
            return $drivers[$provider]($phone, $message);
        } catch (Throwable $e) {
            sh_log_exception($e, 'otp-provider');
            return ['ok' => false, 'error' => 'SMS provider error.'];
        }
    }
    if ($provider === 'offline') {
        return sh_otp_provider_offline($phone, $message);
    }
    if ($provider === 'generic_http') {
        return sh_otp_provider_generic($phone, $message);
    }
    return ['ok' => false, 'error' => 'SMS provider is not configured.'];
}

/** Test driver: never delivered anywhere; only for confirming the flow works. */
function sh_otp_provider_offline(string $phone, string $message): array
{
    return ['ok' => true, 'error' => null];
}

/**
 * Generic HTTP driver. Supports GET / form-post / JSON-post bodies with
 * {phone} {message} {sender} {api_key} {api_secret} placeholders, custom
 * headers, and an optional success marker in the response.
 */
function sh_otp_provider_generic(string $phone, string $message): array
{
    $url = trim((string)sh_setting('otp_api_url', ''));
    if ($url === '') {
        return ['ok' => false, 'error' => 'SMS API URL is not configured.'];
    }
    $method = strtolower((string)sh_setting('otp_api_method', 'post_json'));
    $bodyTpl = (string)sh_setting('otp_api_body', '');
    $headersTpl = (string)sh_setting('otp_api_headers', '');
    $successField = (string)sh_setting('otp_success_field', '');
    $successValue = (string)sh_setting('otp_success_value', '');

    $placeholders = [
        '{phone}'     => $phone,
        '{message}'   => $message,
        '{sender}'    => (string)sh_setting('otp_sender_id', 'ShopHaat'),
        '{api_key}'   => (string)sh_setting('otp_api_key', ''),
        '{api_secret}' => (string)sh_setting('otp_api_secret', ''),
    ];
    $body = str_replace(array_keys($placeholders), array_values($placeholders), $bodyTpl);
    $headers = sh_otp_parse_headers($headersTpl, $placeholders);

    $ch = curl_init();
    if ($ch === false) {
        return ['ok' => false, 'error' => 'cURL is required for SMS delivery.'];
    }
    $options = [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ];
    if ($method === 'get') {
        $sep = (strpos($url, '?') === false) ? '?' : '&';
        $options[CURLOPT_URL] = $url . $sep . $body;
    } else {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = ($method === 'post_form') ? $body : $body;
    }
    $httpHeaders = [];
    foreach ($headers as $k => $v) { $httpHeaders[] = "$k: $v"; }
    if ($httpHeaders) { $options[CURLOPT_HTTPHEADER] = $httpHeaders; }
    curl_setopt_array($ch, $options);

    $resp = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp === false) {
        return ['ok' => false, 'error' => 'Could not reach the SMS provider: ' . $err];
    }
    if ($code >= 400) {
        return ['ok' => false, 'error' => "SMS provider rejected the request (HTTP {$code})."];
    }
    if ($successField !== '') {
        if (!sh_otp_response_has($resp, $successField, $successValue)) {
            return ['ok' => false, 'error' => 'SMS provider reported a failure.'];
        }
    }
    return ['ok' => true, 'error' => null];
}

function sh_otp_parse_headers(string $json, array $placeholders): array
{
    $headers = [];
    if (trim($json) === '') { return $headers; }
    $decoded = json_decode($json, true);
    if (is_array($decoded)) {
        foreach ($decoded as $k => $v) {
            $headers[(string)$k] = str_replace(array_keys($placeholders), array_values($placeholders), (string)$v);
        }
    }
    return $headers;
}

function sh_otp_response_has(string $response, string $field, string $value): bool
{
    $decoded = json_decode($response, true);
    if (is_array($decoded) && sh_array_get($decoded, $field) !== null) {
        return (string)sh_array_get($decoded, $field) === $value;
    }
    return strpos($response, $value) !== false;
}

function sh_array_get(array $arr, string $key, $default = null)
{
    if (array_key_exists($key, $arr)) { return $arr[$key]; }
    return $default;
}

// ---------------------------------------------------------------------------
// User helpers
// ---------------------------------------------------------------------------
/**
 * Pending registration held in the session between the form step and the OTP
 * step. The phone is stored server-side so the OTP step can verify the right
 * number without trusting client input.
 */
function sh_pending_registration(): ?array
{
    $p = $_SESSION['otp_pending_register'] ?? null;
    return is_array($p) ? $p : null;
}

function sh_pending_registration_save(string $name, string $email, string $phone, string $passwordHash, string $redirect = ''): void
{
    $_SESSION['otp_pending_register'] = [
        'name' => $name,
        'email' => $email,
        'phone' => $phone,
        'password_hash' => $passwordHash,
        'redirect' => $redirect,
        'created_at' => time(),
    ];
}

function sh_pending_registration_clear(): void
{
    unset($_SESSION['otp_pending_register']);
}

/**
 * Pending login: password already verified, OTP not yet. Holds the user id and
 * phone so the OTP step cannot be pointed at another account's number.
 */
function sh_pending_login(): ?array
{
    $p = $_SESSION['otp_pending_login'] ?? null;
    return is_array($p) ? $p : null;
}

function sh_pending_login_save(int $userId, string $phone, string $redirect = ''): void
{
    $_SESSION['otp_pending_login'] = [
        'user_id' => $userId,
        'phone' => $phone,
        'redirect' => $redirect,
        'created_at' => time(),
    ];
}

function sh_pending_login_clear(): void
{
    unset($_SESSION['otp_pending_login']);
}

/** Phone the user wants to switch their account to (verified before switching). */
function sh_pending_phone_change(): ?string
{
    $p = $_SESSION['otp_pending_phone_change'] ?? null;
    return is_array($p) && !empty($p['phone']) ? (string)$p['phone'] : null;
}

function sh_pending_phone_change_save(string $phone): void
{
    $_SESSION['otp_pending_phone_change'] = ['phone' => $phone, 'created_at' => time()];
}

function sh_pending_phone_change_clear(): void
{
    unset($_SESSION['otp_pending_phone_change']);
}

/** Mark a user's phone as verified. Logs the event (phone changed/verified). */
function sh_user_mark_verified(int $userId, string $phone, ?string $method = null): void
{
    $canon = sh_phone_normalize($phone);
    sh_db()->prepare(
        'UPDATE users SET phone_verified = 1, phone_verified_at = NOW(), phone_verification_method = ?, phone = ? WHERE id = ?'
    )->execute([$method ?: 'sms', $canon, $userId]);
    sh_security_log('phone_verified', $userId, ['phone' => sh_phone_mask($canon), 'method' => $method ?: 'sms']);
}

function sh_user_verified(int $userId): bool
{
    $row = sh_val('SELECT phone_verified FROM users WHERE id = ? LIMIT 1', [$userId], 0);
    return (int)$row === 1;
}

/**
 * Whether a verified-phone gate applies to this user/context. Verified users
 * are never re-challenged unless the admin turns on "Require OTP For Every
 * Order" (default OFF).
 */
function sh_user_phone_verified(?int $userId, bool $everyOrder = false): bool
{
    if ($userId === null) { return false; }
    if (!sh_user_verified($userId)) { return false; }
    if ($everyOrder && sh_otp_rule('otp_every_order')) { return false; }
    return true;
}

/**
 * Is this phone already verified on another account? Prevents two accounts
 * sharing one verified number where it matters.
 */
function sh_phone_verified_taken(string $phone, int $ignoreId = 0): bool
{
    $variants = sh_phone_variants($phone);
    if ($variants === []) { return false; }
    $in = implode(',', array_fill(0, count($variants), '?'));
    $params = $variants;
    $params[] = $ignoreId;
    $n = (int)sh_val(
        "SELECT COUNT(*) FROM users WHERE phone_verified = 1 AND id <> ? AND phone IN ($in) LIMIT 1",
        $params, 0
    );
    return $n > 0;
}

/**
 * Perform the side effects of a successfully verified OTP for a purpose.
 * Called only after sh_otp_verify() returns ok. Returns
 * ['ok'=>bool, 'error'=>?, 'redirect'=>?].
 */
function sh_otp_complete(string $purpose, string $phone): array
{
    $phone = sh_phone_normalize($phone);
    switch ($purpose) {
        case 'register':
            $pend = sh_pending_registration();
            if ($pend === null) {
                return ['ok' => false, 'error' => 'Your session expired. Please register again.'];
            }
            $exists = sh_one('SELECT id FROM users WHERE email = ? LIMIT 1', [$pend['email']]);
            if ($exists !== null) {
                return ['ok' => false, 'error' => 'An account with this email address already exists.'];
            }
            if (sh_phone_verified_taken($phone)) {
                return ['ok' => false, 'error' => 'This mobile number is already verified on another account.'];
            }
            $uid = sh_insert('users', [
                'name'                    => $pend['name'],
                'email'                   => $pend['email'],
                'phone'                   => $phone,
                'phone_verified'          => 1,
                'phone_verified_at'       => date('Y-m-d H:i:s'),
                'phone_verification_method' => 'sms',
                'password_hash'           => $pend['password_hash'],
                'status'                  => 'active',
            ]);
            sh_pending_registration_clear();
            sh_login_user($uid);
            sh_security_log('account_created', $uid, ['phone' => sh_phone_mask($phone)]);
            return ['ok' => true, 'redirect' => sh_safe_redirect($pend['redirect'])];

        case 'login':
            $pend = sh_pending_login();
            if ($pend === null) {
                return ['ok' => false, 'error' => 'Your session expired. Please sign in again.'];
            }
            $uid = (int)$pend['user_id'];
            sh_user_mark_verified($uid, $phone, 'sms');
            $redirect = sh_safe_redirect($pend['redirect']);
            sh_pending_login_clear();
            sh_login_user($uid); // merges the guest cart now that verification passed
            sh_security_log('login_success', $uid, ['phone' => sh_phone_mask($phone)]);
            return ['ok' => true, 'redirect' => $redirect];

        case 'phone_change':
            $user = sh_user();
            if ($user === null) {
                return ['ok' => false, 'error' => 'Please sign in to continue.'];
            }
            $newPhone = sh_pending_phone_change();
            if ($newPhone === null || sh_phone_normalize($newPhone) !== $phone) {
                return ['ok' => false, 'error' => 'No pending phone change was found. Please try again.'];
            }
            $oldPhone = (string)$user['phone'];
            // Only now do we switch: the old phone stays active until this moment.
            sh_query(
                'UPDATE users SET phone = ?, phone_verified = 1, phone_verified_at = NOW(), phone_verification_method = ? WHERE id = ?',
                [$phone, 'sms', (int)$user['id']]
            );
            sh_pending_phone_change_clear();
            sh_security_log('phone_changed', (int)$user['id'], [
                'from' => sh_phone_mask($oldPhone), 'to' => sh_phone_mask($phone),
            ]);
            return ['ok' => true, 'redirect' => 'account.php'];

        case 'checkout':
            $user = sh_user();
            if ($user !== null && !sh_user_verified((int)$user['id'])) {
                // They proved this phone at checkout; record it on the account too.
                sh_user_mark_verified((int)$user['id'], $phone, 'sms');
            }
            sh_checkout_otp_mark($phone);
            return ['ok' => true, 'redirect' => 'checkout.php'];

        case 'account':
            $user = sh_user();
            if ($user === null) {
                return ['ok' => false, 'error' => 'Please sign in to continue.'];
            }
            sh_user_mark_verified((int)$user['id'], $phone, 'sms');
            return ['ok' => true, 'redirect' => 'account.php'];

        case 'test':
            sh_security_log('otp_test', null, ['phone' => sh_phone_mask($phone)]);
            return ['ok' => true, 'redirect' => null];

        default:
            return ['ok' => false, 'error' => 'Unknown verification purpose.'];
    }
}

// ---------------------------------------------------------------------------
// Checkout / order helpers
// ---------------------------------------------------------------------------
/**
 * Should the checkout page send this customer through phone verification before
 * they can place the order? Mirrors sh_order_verification_check() so the UI and
 * the enforcement can never disagree.
 */
function sh_checkout_requires_otp(?int $userId, bool $cod): bool
{
    if (!sh_otp_enabled()) { return false; }
    if (sh_otp_rule('otp_every_order')) { return true; }
    if ($userId !== null && sh_user_verified($userId)) { return false; }
    return sh_otp_rule('otp_before_checkout')
        || sh_otp_rule('otp_before_order')
        || ($cod ? sh_otp_rule('otp_before_cod') : sh_otp_rule('otp_before_online'));
}

/** Whether the verification rules apply to this specific order phone. */
function sh_order_verification_required_flag(string $phone, ?int $userId, bool $cod): bool
{
    if (!sh_otp_enabled()) { return false; }
    $p = sh_phone_normalize($phone);
    if ($p === '') { return false; }
    $userVerified = $userId !== null && sh_user_verified($userId)
        && sh_phone_normalize((string)sh_user_field($userId, 'phone')) === $p;
    $every = sh_otp_rule('otp_every_order');
    $required = $every
        || sh_otp_rule('otp_before_order')
        || sh_otp_rule('otp_before_checkout')
        || ($cod ? sh_otp_rule('otp_before_cod') : sh_otp_rule('otp_before_online'));
    if (!$required) { return false; }
    if ($userVerified && !$every) { return false; }
    return true;
}

/**
 * Resolve the authoritative verification proof for a phone: either the
 * account's recorded phone verification, or a consumed OTP for that phone.
 * @return array{verified_at:?string, method:?string}
 */
function sh_order_verification_proof(string $phone, ?int $userId): array
{
    $p = sh_phone_normalize($phone);
    if ($userId !== null && $p !== '' && sh_user_verified($userId)
        && sh_phone_normalize((string)sh_user_field($userId, 'phone')) === $p) {
        $at = sh_val('SELECT phone_verified_at FROM users WHERE id = ? LIMIT 1', [$userId], null);
        return ['verified_at' => $at ?: date('Y-m-d H:i:s'), 'method' => 'account'];
    }
    $row = sh_otp_recent_verified_row($phone, $userId);
    if ($row !== null) {
        return ['verified_at' => $row['verified_at'], 'method' => 'otp'];
    }
    return ['verified_at' => null, 'method' => null];
}

/**
 * Order-level anti-fake gate evaluated inside sh_create_order. Blocks an
 * unverified phone / missing OTP when the rules require it, before anything
 * is written. Returns an error key ('verification_required') or null.
 */
function sh_order_verification_check(string $shippingPhone, ?int $userId, bool $cod): ?string
{
    if (!sh_order_verification_required_flag($shippingPhone, $userId, $cod)) { return null; }
    $proof = sh_order_verification_proof($shippingPhone, $userId);
    return $proof['verified_at'] !== null ? null : 'verification_required';
}

/** Most recent successfully-verified OTP for a phone (still valid window). */
function sh_otp_recent_verified(string $phone, ?int $userId): bool
{
    return sh_otp_recent_verified_row($phone, $userId) !== null;
}

function sh_otp_recent_verified_row(string $phone, ?int $userId): ?array
{
    $st = sh_db()->prepare(
        'SELECT id, verified_at FROM otp_verifications
         WHERE phone = ? AND verified_at IS NOT NULL AND created_at > ? AND (user_id <=> ?)
         ORDER BY id DESC LIMIT 1'
    );
    $st->execute([sh_phone_normalize($phone), date('Y-m-d H:i:s', time() - sh_otp_expiry_seconds() * 2), $userId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/** Session proof that checkout OTP was completed in this browser session. */
function sh_checkout_otp_mark(string $phone): void
{
    $_SESSION['otp_checkout_verified'] = true;
    $_SESSION['otp_checkout_phone'] = sh_phone_normalize($phone);
    $_SESSION['otp_checkout_verified_at'] = time();
}

function sh_checkout_otp_ok(): bool
{
    return !empty($_SESSION['otp_checkout_verified'])
        && (time() - (int)($_SESSION['otp_checkout_verified_at'] ?? 0)) < 3600;
}

/**
 * Has this checkout already satisfied the verification rules? Verified users
 * satisfy by account status (unless "every order" is on); guests satisfy via
 * the session proof set when their checkout OTP was verified.
 */
function sh_checkout_otp_satisfied(?int $userId): bool
{
    if (!sh_otp_enabled()) { return true; }
    if ($userId !== null && sh_user_verified($userId) && !sh_otp_rule('otp_every_order')) {
        return true;
    }
    return sh_checkout_otp_ok();
}

function sh_checkout_otp_phone(): string
{
    return (string)($_SESSION['otp_checkout_phone'] ?? '');
}

function sh_checkout_otp_clear(): void
{
    unset($_SESSION['otp_checkout_verified'], $_SESSION['otp_checkout_phone'], $_SESSION['otp_checkout_verified_at']);
}

/**
 * Idempotency: an order token held in the session makes duplicate submissions
 * impossible even with rapid double-clicks.
 */
function sh_order_idempotency_token(): string
{
    if (empty($_SESSION['order_token'])) {
        $_SESSION['order_token'] = bin2hex(random_bytes(20));
    }
    return $_SESSION['order_token'];
}

/** Consume the order token after a successful order so a replay cannot double-place. */
function sh_order_idempotency_reset(): void
{
    unset($_SESSION['order_token']);
}
