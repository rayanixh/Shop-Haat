<?php
/**
 * Phone Verification — master switch, verification rules, SMS provider config
 * and a test-send button. Restricted to the superadmin.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/admin-auth.php';

sh_session_start();
$admin = sh_require_superadmin();

$errors = [];
$notice = '';
$testCode = null;

$boolKeys = [
    'otp_enabled', 'otp_before_register', 'otp_before_login', 'otp_before_checkout',
    'otp_before_order', 'otp_before_cod', 'otp_before_online', 'otp_every_order',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sh_csrf_require();
    $form = sh_post('form');

    if ($form === 'verification') {
        foreach ($boolKeys as $k) {
            sh_setting_save($k, isset($_POST[$k]) ? '1' : '0');
        }
        sh_security_log('otp_settings_changed', null, ['section' => 'verification']);
        sh_flash('success', 'Phone verification rules saved.');
        sh_redirect('admin/security.php');
    }

    if ($form === 'sms') {
        // Provider + behaviour
        foreach (['otp_provider', 'otp_api_method', 'otp_api_body', 'otp_api_headers',
                  'otp_api_key', 'otp_api_secret', 'otp_sender_id', 'otp_message',
                  'otp_success_field', 'otp_success_value'] as $k) {
            if (array_key_exists($k, $_POST)) {
                sh_setting_save($k, trim((string)$_POST[$k]));
            }
        }
        if (array_key_exists('otp_api_url', $_POST)) {
            sh_setting_save('otp_api_url', trim((string)$_POST['otp_api_url']));
        }
        // Numeric settings with sane bounds
        $num = [
            'otp_length'           => [4, 10],
            'otp_expiry_minutes'   => [1, 60],
            'otp_max_attempts'     => [1, 20],
            'otp_max_resends'      => [1, 10],
            'otp_resend_cooldown'  => [0, 600],
            'otp_daily_limit'      => [1, 1000],
            'otp_phone_rate_limit' => [1, 100],
            'otp_phone_rate_window'=> [60, 3600],
            'otp_ip_rate_limit'    => [1, 1000],
            'otp_ip_rate_window'   => [60, 86400],
        ];
        foreach ($num as $k => [$min, $max]) {
            $v = (int)($_POST[$k] ?? 0);
            if ($v < $min || $v > $max) { $errors[$k] = 'Value must be between ' . $min . ' and ' . $max . '.'; }
        }
        if (!$errors) {
            foreach ($num as $k => $unused) { sh_setting_save($k, (string)(int)$_POST[$k]); }
            sh_security_log('otp_settings_changed', null, ['section' => 'sms']);
            sh_flash('success', 'SMS / OTP configuration saved.');
            sh_redirect('admin/security.php');
        }
    }

    if ($form === 'test') {
        $phone = sh_phone_normalize(sh_post('test_phone'));
        if ($phone === '') {
            $errors['test_phone'] = 'Enter a valid mobile number to send the test to.';
        } else {
            $code = sh_otp_generate(sh_otp_length());
            $minutes = (int)ceil(sh_otp_expiry_seconds() / 60);
            $res = sh_otp_send(sh_otp_provider(), $phone, sh_otp_message($code, $minutes));
            if ($res['ok']) {
                $notice = 'Test message sent to ' . sh_phone_display($phone) . '.';
                if (sh_otp_provider() === 'offline') {
                    $testCode = $code; // test driver only — never a real SMS
                }
                sh_security_log('otp_test', null, ['phone' => sh_phone_mask($phone)]);
            } else {
                $errors['test_phone'] = $res['error'] ?? 'The test message could not be sent.';
            }
        }
    }
}

$s = static fn(string $k, string $fb = ''): string => (string)sh_setting($k, $fb);
$provider = $s('otp_provider', 'offline');

// Quick stats for the top of the page.
$stats = ['today' => 0, 'verified' => 0, 'failed' => 0, 'unverified_users' => 0];
try {
    $stats['today'] = (int)sh_val("SELECT COUNT(*) FROM otp_verifications WHERE created_at >= CURDATE()", [], 0);
    $stats['verified'] = (int)sh_val("SELECT COUNT(*) FROM otp_verifications WHERE verified_at IS NOT NULL AND verified_at >= CURDATE()", [], 0);
    $stats['failed'] = (int)sh_val("SELECT COUNT(*) FROM security_logs WHERE event = 'otp_failed' AND created_at >= CURDATE()", [], 0);
    $stats['unverified_users'] = (int)sh_val("SELECT COUNT(*) FROM users WHERE phone_verified = 0 AND phone IS NOT NULL AND phone <> ''", [], 0);
} catch (Throwable $e) { sh_log_exception($e, 'security-stats'); }

$adminPage = 'security';
$adminTitle = 'Phone Verification';
require __DIR__ . '/_layout.php';
?>
<?php if ($errors): ?>
  <div class="sh-alert sh-alert--error"><?= sh_icon('x-circle', 17) ?>
    <div><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div></div>
<?php endif; ?>
<?php if ($notice !== ''): ?>
  <div class="sh-alert sh-alert--success"><?= sh_icon('check-circle', 17) ?><span><?= e($notice) ?></span></div>
<?php endif; ?>
<?php if ($testCode !== null): ?>
  <div class="sh-alert sh-alert--info"><?= sh_icon('key', 17) ?>
    <span>Test mode code (shown because the provider is “offline”): <strong style="letter-spacing:2px"><?= e($testCode) ?></strong></span></div>
<?php endif; ?>

<?php if ($provider === 'offline' && $s('otp_enabled', '1') === '1'): ?>
  <div class="sh-alert sh-alert--warning"><?= sh_icon('alert', 17) ?>
    <span><strong>No real SMS provider is configured.</strong> Customers cannot receive verification codes while the provider is “offline”.
      Configure a gateway below, or turn the <em>Phone Verification System</em> switch off to restore the original checkout flow.</span></div>
<?php endif; ?>

<div class="sh-stats">
  <div class="sh-stat"><span class="sh-stat__icon"><?= sh_icon('message', 18) ?></span>
    <div><p class="sh-stat__value"><?= $stats['today'] ?></p><p class="sh-stat__label">OTPs requested today</p></div></div>
  <div class="sh-stat"><span class="sh-stat__icon sh-stat__icon--green"><?= sh_icon('check-circle', 18) ?></span>
    <div><p class="sh-stat__value"><?= $stats['verified'] ?></p><p class="sh-stat__label">Verified today</p></div></div>
  <div class="sh-stat"><span class="sh-stat__icon sh-stat__icon--amber"><?= sh_icon('x-circle', 18) ?></span>
    <div><p class="sh-stat__value"><?= $stats['failed'] ?></p><p class="sh-stat__label">Failed attempts today</p></div></div>
  <div class="sh-stat"><span class="sh-stat__icon sh-stat__icon--blue"><?= sh_icon('users', 18) ?></span>
    <div><p class="sh-stat__value"><?= $stats['unverified_users'] ?></p><p class="sh-stat__label">Unverified phone accounts</p></div></div>
</div>

<!-- Verification rules -->
<form method="post" novalidate>
  <?= sh_csrf_field() ?>
  <input type="hidden" name="form" value="verification">
  <div class="sh-panel">
    <div class="sh-panel__head">
      <h2 class="sh-panel__title"><?= sh_icon('shield', 17) ?> Security &amp; Verification</h2>
    </div>
    <div class="sh-panel__body">
      <label class="sh-toggle">
        <input type="checkbox" name="otp_enabled" value="1" <?= $s('otp_enabled', '1') === '1' ? 'checked' : '' ?>>
        <span class="sh-toggle__track"></span>
        <span><strong>Phone Verification System</strong> — master switch. When OFF, the original login / register / checkout flow is restored unchanged.</span>
      </label>

      <div class="sh-grid2" style="margin-top:16px">
        <div>
          <p class="sh-panel__note" style="font-weight:700;color:var(--sh-ink)">Verification rules</p>
          <?php
          $rules = [
              'otp_before_register' => 'Require OTP before account creation',
              'otp_before_login'    => 'Require OTP before sign-in (unverified phones)',
              'otp_before_checkout' => 'Require OTP before checkout',
              'otp_before_order'    => 'Require OTP before order creation',
          ];
          foreach ($rules as $k => $label): ?>
            <label class="sh-toggle" style="margin:10px 0">
              <input type="checkbox" name="<?= e($k) ?>" value="1" <?= $s($k) === '1' ? 'checked' : '' ?>>
              <span class="sh-toggle__track"></span><span><?= e($label) ?></span>
            </label>
          <?php endforeach; ?>
        </div>
        <div>
          <p class="sh-panel__note" style="font-weight:700;color:var(--sh-ink)">Payment-type phone requirements</p>
          <?php
          $payRules = [
              'otp_before_cod'    => 'COD orders require a verified phone',
              'otp_before_online' => 'Online-payment orders require a verified phone',
          ];
          foreach ($payRules as $k => $label): ?>
            <label class="sh-toggle" style="margin:10px 0">
              <input type="checkbox" name="<?= e($k) ?>" value="1" <?= $s($k) === '1' ? 'checked' : '' ?>>
              <span class="sh-toggle__track"></span><span><?= e($label) ?></span>
            </label>
          <?php endforeach; ?>
          <label class="sh-toggle" style="margin:10px 0">
            <input type="checkbox" name="otp_every_order" value="1" <?= $s('otp_every_order') === '1' ? 'checked' : '' ?>>
            <span class="sh-toggle__track"></span>
            <span><strong>Require OTP for every order</strong> — even for already-verified users (off by default).</span>
          </label>
        </div>
      </div>
      <button class="sh-btn" type="submit"><?= sh_icon('check-circle', 15) ?> Save rules</button>
    </div>
  </div>
</form>

<!-- SMS / OTP configuration -->
<form method="post" novalidate>
  <?= sh_csrf_field() ?>
  <input type="hidden" name="form" value="sms">
  <div class="sh-panel" style="margin-top:14px">
    <div class="sh-panel__head">
      <h2 class="sh-panel__title"><?= sh_icon('send', 17) ?> OTP / SMS Configuration</h2>
    </div>
    <div class="sh-panel__body">
      <div class="sh-grid2">
        <div class="sh-field">
          <label class="sh-field__label" for="sc-provider">SMS provider</label>
          <select class="sh-select" id="sc-provider" name="otp_provider">
            <option value="offline" <?= $provider === 'offline' ? 'selected' : '' ?>>Offline / test mode (no real SMS)</option>
            <option value="generic_http" <?= $provider === 'generic_http' ? 'selected' : '' ?>>Generic HTTP API (any SMS gateway)</option>
          </select>
          <span class="sh-field__hint">Providers are swappable without code changes. Credentials never reach the browser or logs.</span>
        </div>
        <div class="sh-field">
          <label class="sh-field__label" for="sc-sender">Sender ID</label>
          <input class="sh-input" id="sc-sender" name="otp_sender_id" maxlength="11" value="<?= e($s('otp_sender_id', 'ShopHaat')) ?>">
        </div>
      </div>

      <div class="sh-field">
        <label class="sh-field__label" for="sc-url">API URL</label>
        <input class="sh-input" id="sc-url" name="otp_api_url" value="<?= e($s('otp_api_url')) ?>" placeholder="https://api.example.com/send-sms">
      </div>
      <div class="sh-grid2">
        <div class="sh-field">
          <label class="sh-field__label" for="sc-method">Request method</label>
          <select class="sh-select" id="sc-method" name="otp_api_method">
            <option value="post_json" <?= $s('otp_api_method') === 'post_json' ? 'selected' : '' ?>>POST (JSON)</option>
            <option value="post_form" <?= $s('otp_api_method') === 'post_form' ? 'selected' : '' ?>>POST (form fields)</option>
            <option value="get" <?= $s('otp_api_method') === 'get' ? 'selected' : '' ?>>GET (query string)</option>
          </select>
        </div>
        <div class="sh-field">
          <label class="sh-field__label" for="sc-key">API key</label>
          <input class="sh-input" id="sc-key" name="otp_api_key" autocomplete="off" value="<?= e($s('otp_api_key')) ?>">
        </div>
      </div>
      <div class="sh-grid2">
        <div class="sh-field">
          <label class="sh-field__label" for="sc-secret">API secret</label>
          <input class="sh-input" id="sc-secret" name="otp_api_secret" type="password" autocomplete="new-password" value="<?= e($s('otp_api_secret')) ?>">
          <span class="sh-field__hint">Stored server-side only; never written to logs.</span>
        </div>
        <div class="sh-field">
          <label class="sh-field__label" for="sc-msg">Message template</label>
          <input class="sh-input" id="sc-msg" name="otp_message" maxlength="320" value="<?= e($s('otp_message', 'Your ShopHaat verification code is {code}. It expires in {minutes} minutes. Do not share it with anyone.')) ?>">
          <span class="sh-field__hint">Placeholders: {code}, {minutes}.</span>
        </div>
      </div>

      <div class="sh-grid3">
        <div class="sh-field">
          <label class="sh-field__label" for="sc-len">OTP length</label>
          <input class="sh-input" id="sc-len" type="number" min="4" max="10" name="otp_length" value="<?= e($s('otp_length', '6')) ?>">
        </div>
        <div class="sh-field">
          <label class="sh-field__label" for="sc-exp">Expiry (minutes)</label>
          <input class="sh-input" id="sc-exp" type="number" min="1" max="60" name="otp_expiry_minutes" value="<?= e($s('otp_expiry_minutes', '5')) ?>">
        </div>
        <div class="sh-field">
          <label class="sh-field__label" for="sc-att">Max attempts</label>
          <input class="sh-input" id="sc-att" type="number" min="1" max="20" name="otp_max_attempts" value="<?= e($s('otp_max_attempts', '5')) ?>">
        </div>
        <div class="sh-field">
          <label class="sh-field__label" for="sc-res">Max resends</label>
          <input class="sh-input" id="sc-res" type="number" min="1" max="10" name="otp_max_resends" value="<?= e($s('otp_max_resends', '3')) ?>">
        </div>
        <div class="sh-field">
          <label class="sh-field__label" for="sc-cd">Resend cooldown (seconds)</label>
          <input class="sh-input" id="sc-cd" type="number" min="0" max="600" name="otp_resend_cooldown" value="<?= e($s('otp_resend_cooldown', '45')) ?>">
        </div>
        <div class="sh-field">
          <label class="sh-field__label" for="sc-dl">Daily limit per phone</label>
          <input class="sh-input" id="sc-dl" type="number" min="1" max="1000" name="otp_daily_limit" value="<?= e($s('otp_daily_limit', '20')) ?>">
        </div>
        <div class="sh-field">
          <label class="sh-field__label" for="sc-pl">Per-phone rate limit</label>
          <input class="sh-input" id="sc-pl" type="number" min="1" max="100" name="otp_phone_rate_limit" value="<?= e($s('otp_phone_rate_limit', '5')) ?>">
          <span class="sh-field__hint">Requests allowed per window.</span>
        </div>
        <div class="sh-field">
          <label class="sh-field__label" for="sc-pw">Per-phone window (seconds)</label>
          <input class="sh-input" id="sc-pw" type="number" min="60" max="3600" name="otp_phone_rate_window" value="<?= e($s('otp_phone_rate_window', '10')) ?>">
        </div>
        <div class="sh-field">
          <label class="sh-field__label" for="sc-ip">Per-IP rate limit</label>
          <input class="sh-input" id="sc-ip" type="number" min="1" max="1000" name="otp_ip_rate_limit" value="<?= e($s('otp_ip_rate_limit', '10')) ?>">
        </div>
      </div>

      <div class="sh-field">
        <label class="sh-field__label" for="sc-body">Request body template (JSON)</label>
        <textarea class="sh-textarea" id="sc-body" name="otp_api_body" rows="4"
          placeholder='{"phone":"{phone}","message":"{message}","sender":"{sender}","api_key":"{api_key}","api_secret":"{api_secret}"}'><?= e($s('otp_api_body')) ?></textarea>
        <span class="sh-field__hint">For GET, this string is appended as the query string. Placeholders: {phone}, {message}, {sender}, {api_key}, {api_secret}.</span>
      </div>
      <div class="sh-grid2">
        <div class="sh-field">
          <label class="sh-field__label" for="sc-hdr">Headers (JSON, optional)</label>
          <textarea class="sh-textarea" id="sc-hdr" name="otp_api_headers" rows="2"
            placeholder='{"Content-Type":"application/json"}'><?= e($s('otp_api_headers')) ?></textarea>
        </div>
        <div class="sh-field">
          <label class="sh-field__label" for="sc-sf">Success check (optional)</label>
          <div class="sh-grid2" style="gap:8px">
            <input class="sh-input" id="sc-sf" name="otp_success_field" placeholder="Field, e.g. status" value="<?= e($s('otp_success_field')) ?>">
            <input class="sh-input" name="otp_success_value" placeholder="Value, e.g. OK" value="<?= e($s('otp_success_value')) ?>">
          </div>
          <span class="sh-field__hint">If set, the provider response must contain this field/value or the code is treated as not sent (no fake successes).</span>
        </div>
      </div>

      <button class="sh-btn" type="submit"><?= sh_icon('check-circle', 15) ?> Save SMS configuration</button>
    </div>
  </div>
</form>

<!-- Test send -->
<div class="sh-panel" style="margin-top:14px">
  <div class="sh-panel__head">
    <h2 class="sh-panel__title"><?= sh_icon('send', 17) ?> Send a test code</h2>
  </div>
  <div class="sh-panel__body">
    <form method="post" novalidate style="max-width:460px">
      <?= sh_csrf_field() ?>
      <input type="hidden" name="form" value="test">
      <div class="sh-field">
        <label class="sh-field__label" for="sc-test">Mobile number</label>
        <input class="sh-input <?= isset($errors['test_phone']) ? 'sh-input--error' : '' ?>" id="sc-test"
               name="test_phone" value="<?= e(sh_post('test_phone')) ?>" placeholder="01XXXXXXXXX">
        <?php if (isset($errors['test_phone'])): ?><p class="sh-field__error"><?= e($errors['test_phone']) ?></p><?php endif; ?>
      </div>
      <button class="sh-btn" type="submit"><?= sh_icon('send', 15) ?> Send test code</button>
    </form>
  </div>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
