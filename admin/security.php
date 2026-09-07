<?php
/**
 * SMS / OTP settings — master switch, signup/login gates, OTP behaviour,
 * rate limiting, provider configuration (dynamic per-provider fields) and a
 * server-side test send. Restricted to the superadmin.
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sh_csrf_require();
    $form = sh_post('form');

    if ($form === 'general') {
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
            sh_security_log('otp_settings_changed', null, ['section' => 'general']);
            sh_flash('success', 'OTP settings saved.');
            sh_redirect('admin/security.php');
        }
    }

    if ($form === 'sms') {
        $keys = ['otp_provider', 'otp_sender_id', 'otp_message',
                 'otp_api_url', 'otp_api_method', 'otp_api_body', 'otp_api_headers',
                 'otp_api_auth', 'otp_api_key', 'otp_api_secret', 'otp_api_token',
                 'otp_api_username', 'otp_api_password', 'otp_phone_param', 'otp_message_param',
                 'otp_success_field', 'otp_success_value',
                 'otp_textbee_api_key', 'otp_textbee_device_id',
                 'otp_firebase_api_key', 'otp_firebase_sender_id'];
        foreach ($keys as $k) {
            if (array_key_exists($k, $_POST)) {
                sh_setting_save($k, trim((string)$_POST[$k]));
            }
        }
        sh_security_log('otp_settings_changed', null, ['section' => 'sms', 'provider' => sh_post('otp_provider')]);
        sh_flash('success', 'SMS provider configuration saved.');
        sh_redirect('admin/security.php');
    }

    if ($form === 'test') {
        $phone = sh_phone_normalize(sh_post('test_phone'));
        if ($phone === '') {
            $errors['test_phone'] = 'Enter a valid mobile number to send the test to.';
        } else {
            $res = sh_otp_issue($phone, 'test', null);
            if ($res['ok']) {
                $notice = 'Test message sent to ' . sh_phone_display($phone) . ' via ' . sh_otp_provider() . '.';
                if (sh_otp_provider() === 'offline' && !empty($res['code'])) {
                    $testCode = $res['code']; // test driver only — never a real SMS
                }
            } else {
                $errors['test_phone'] = $res['error'] ?? 'The test message could not be sent.';
            }
        }
    }
}

$s = static fn(string $k, string $fb = ''): string => (string)sh_setting($k, $fb);
$provider = $s('otp_provider', 'offline');

// Quick stats for the top of the page.
$stats = ['today' => 0, 'login' => 0, 'signup' => 0, 'verified' => 0, 'failed' => 0, 'expired' => 0, 'rate_limited' => 0];
try {
    $stats['today']   = (int)sh_val("SELECT COUNT(*) FROM otp_verifications WHERE created_at >= CURDATE()", [], 0);
    $stats['login']   = (int)sh_val("SELECT COUNT(*) FROM otp_verifications WHERE purpose = 'login' AND created_at >= CURDATE()", [], 0);
    $stats['signup']  = (int)sh_val("SELECT COUNT(*) FROM otp_verifications WHERE purpose = 'signup' AND created_at >= CURDATE()", [], 0);
    $stats['verified']= (int)sh_val("SELECT COUNT(*) FROM otp_verifications WHERE verified_at IS NOT NULL AND verified_at >= CURDATE()", [], 0);
    $stats['failed']  = (int)sh_val("SELECT COUNT(*) FROM security_logs WHERE event = 'otp_failed' AND created_at >= CURDATE()", [], 0);
    $stats['expired'] = (int)sh_val("SELECT COUNT(*) FROM security_logs WHERE event = 'otp_expired' AND created_at >= CURDATE()", [], 0);
    $stats['rate_limited'] = (int)sh_val("SELECT COUNT(*) FROM security_logs WHERE event = 'otp_rate_limited' AND created_at >= CURDATE()", [], 0);
} catch (Throwable $e) { sh_log_exception($e, 'security-stats'); }

$adminPage = 'security';
$adminTitle = 'SMS / OTP Settings';
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

<?php if (sh_otp_enabled()): ?>
  <div class="sh-alert sh-alert--info"><?= sh_icon('smartphone', 17) ?>
    <span>Active Authentication Method: <strong>Phone Number + OTP</strong>. These settings apply to customer signup and login.</span></div>
  <?php if ($provider === 'offline'): ?>
    <div class="sh-alert sh-alert--warning"><?= sh_icon('alert', 17) ?>
      <span><strong>No real SMS provider is configured.</strong> Customers cannot receive verification codes while the provider is “offline”.
        Configure TextBee or a custom HTTP gateway below.</span></div>
  <?php endif; ?>
<?php else: ?>
  <div class="sh-alert sh-alert--warning"><?= sh_icon('mail', 17) ?>
    <span>Active Authentication Method: <strong>Email + Password</strong>. SMS / OTP is inactive — these settings only apply when
      Phone Number + OTP is selected under <a href="<?= e(sh_url('admin/settings.php')) ?>">Settings → Authentication</a>.</span></div>
<?php endif; ?>

<div class="sh-stats">
  <div class="sh-stat"><span class="sh-stat__icon"><?= sh_icon('message', 18) ?></span>
    <div><p class="sh-stat__value"><?= $stats['today'] ?></p><p class="sh-stat__label">OTPs sent today</p></div></div>
  <div class="sh-stat"><span class="sh-stat__icon sh-stat__icon--blue"><?= sh_icon('log-in', 18) ?></span>
    <div><p class="sh-stat__value"><?= $stats['login'] ?></p><p class="sh-stat__label">Login OTPs</p></div></div>
  <div class="sh-stat"><span class="sh-stat__icon sh-stat__icon--blue"><?= sh_icon('user-plus', 18) ?></span>
    <div><p class="sh-stat__value"><?= $stats['signup'] ?></p><p class="sh-stat__label">Signup OTPs</p></div></div>
  <div class="sh-stat"><span class="sh-stat__icon sh-stat__icon--green"><?= sh_icon('check-circle', 18) ?></span>
    <div><p class="sh-stat__value"><?= $stats['verified'] ?></p><p class="sh-stat__label">Verified today</p></div></div>
  <div class="sh-stat"><span class="sh-stat__icon sh-stat__icon--amber"><?= sh_icon('x-circle', 18) ?></span>
    <div><p class="sh-stat__value"><?= $stats['failed'] ?></p><p class="sh-stat__label">Failed attempts</p></div></div>
  <div class="sh-stat"><span class="sh-stat__icon sh-stat__icon--amber"><?= sh_icon('clock', 18) ?></span>
    <div><p class="sh-stat__value"><?= $stats['expired'] ?></p><p class="sh-stat__label">Expired</p></div></div>
  <div class="sh-stat"><span class="sh-stat__icon sh-stat__icon--amber"><?= sh_icon('shield', 18) ?></span>
    <div><p class="sh-stat__value"><?= $stats['rate_limited'] ?></p><p class="sh-stat__label">Rate-limited</p></div></div>
</div>

<!-- OTP behaviour -->
<form method="post" novalidate>
  <?= sh_csrf_field() ?>
  <input type="hidden" name="form" value="general">
  <div class="sh-panel">
    <div class="sh-panel__head">
      <h2 class="sh-panel__title"><?= sh_icon('sliders', 17) ?> OTP Settings</h2>
    </div>
    <div class="sh-panel__body">
      <p class="sh-panel__note" style="font-weight:700;color:var(--sh-ink)">OTP behaviour</p>
      <div class="sh-grid3">
        <div class="sh-field">
          <label class="sh-field__label" for="sc-len">Code length</label>
          <input class="sh-input" id="sc-len" type="number" min="4" max="10" name="otp_length" value="<?= e($s('otp_length', '6')) ?>">
        </div>
        <div class="sh-field">
          <label class="sh-field__label" for="sc-exp">Validity (minutes)</label>
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
          <input class="sh-input" id="sc-cd" type="number" min="0" max="600" name="otp_resend_cooldown" value="<?= e($s('otp_resend_cooldown', '60')) ?>">
        </div>
      </div>

      <p class="sh-panel__note" style="font-weight:700;color:var(--sh-ink);margin-top:16px">Abuse prevention (rate limits)</p>
      <div class="sh-grid3">
        <div class="sh-field">
          <label class="sh-field__label" for="sc-dl">Daily limit per phone</label>
          <input class="sh-input" id="sc-dl" type="number" min="1" max="1000" name="otp_daily_limit" value="<?= e($s('otp_daily_limit', '20')) ?>">
        </div>
        <div class="sh-field">
          <label class="sh-field__label" for="sc-pl">Per-phone requests</label>
          <input class="sh-input" id="sc-pl" type="number" min="1" max="100" name="otp_phone_rate_limit" value="<?= e($s('otp_phone_rate_limit', '5')) ?>">
          <span class="sh-field__hint">Requests allowed per window.</span>
        </div>
        <div class="sh-field">
          <label class="sh-field__label" for="sc-pw">Per-phone window (seconds)</label>
          <input class="sh-input" id="sc-pw" type="number" min="60" max="3600" name="otp_phone_rate_window" value="<?= e($s('otp_phone_rate_window', '60')) ?>">
        </div>
        <div class="sh-field">
          <label class="sh-field__label" for="sc-ip">Per-IP requests</label>
          <input class="sh-input" id="sc-ip" type="number" min="1" max="1000" name="otp_ip_rate_limit" value="<?= e($s('otp_ip_rate_limit', '10')) ?>">
        </div>
        <div class="sh-field">
          <label class="sh-field__label" for="sc-ipw">Per-IP window (seconds)</label>
          <input class="sh-input" id="sc-ipw" type="number" min="60" max="86400" name="otp_ip_rate_window" value="<?= e($s('otp_ip_rate_window', '60')) ?>">
        </div>
      </div>
      <button class="sh-btn" type="submit"><?= sh_icon('check-circle', 15) ?> Save settings</button>
    </div>
  </div>
</form>

<!-- SMS provider -->
<form method="post" novalidate>
  <?= sh_csrf_field() ?>
  <input type="hidden" name="form" value="sms">
  <div class="sh-panel" style="margin-top:14px">
    <div class="sh-panel__head">
      <h2 class="sh-panel__title"><?= sh_icon('send', 17) ?> SMS Provider</h2>
    </div>
    <div class="sh-panel__body">
      <div class="sh-grid2">
        <div class="sh-field">
          <label class="sh-field__label" for="sc-provider">Provider</label>
          <select class="sh-select" id="sc-provider" name="otp_provider" data-provider-select>
            <option value="offline" <?= $provider === 'offline' ? 'selected' : '' ?>>Offline / test mode (no real SMS)</option>
            <option value="textbee" <?= $provider === 'textbee' ? 'selected' : '' ?>>TextBee (Android SMS gateway)</option>
            <option value="firebase" <?= $provider === 'firebase' ? 'selected' : '' ?>>Firebase (optional)</option>
            <option value="generic_http" <?= $provider === 'generic_http' ? 'selected' : '' ?>>Custom HTTP SMS API</option>
          </select>
          <span class="sh-field__hint">Credentials are stored server-side and never reach the browser output or logs.</span>
        </div>
        <div class="sh-field">
          <label class="sh-field__label" for="sc-sender">Sender ID</label>
          <input class="sh-input" id="sc-sender" name="otp_sender_id" maxlength="11" value="<?= e($s('otp_sender_id', 'ShopHaat')) ?>">
        </div>
      </div>

      <div class="sh-field">
        <label class="sh-field__label" for="sc-msg">Message template</label>
        <textarea class="sh-textarea" id="sc-msg" name="otp_message" rows="2" maxlength="320"><?= e($s('otp_message', 'Your ShopHaat verification code is {code}. It expires in {minutes} minutes. Do not share it with anyone.')) ?></textarea>
        <span class="sh-field__hint">Placeholders: {code}, {minutes}.</span>
      </div>

      <!-- TextBee -->
      <div data-provider-fields="textbee" class="sh-grid2" style="margin-top:10px">
        <div class="sh-field">
          <label class="sh-field__label" for="sc-tb-key">TextBee API key</label>
          <input class="sh-input" id="sc-tb-key" name="otp_textbee_api_key" autocomplete="off" value="<?= e($s('otp_textbee_api_key')) ?>">
        </div>
        <div class="sh-field">
          <label class="sh-field__label" for="sc-tb-dev">TextBee Device ID</label>
          <input class="sh-input" id="sc-tb-dev" name="otp_textbee_device_id" autocomplete="off" value="<?= e($s('otp_textbee_device_id')) ?>">
        </div>
      </div>

      <!-- Firebase -->
      <div data-provider-fields="firebase" class="sh-grid2" style="margin-top:10px">
        <div class="sh-field">
          <label class="sh-field__label" for="sc-fb-key">Firebase web API key</label>
          <input class="sh-input" id="sc-fb-key" name="otp_firebase_api_key" autocomplete="off" value="<?= e($s('otp_firebase_api_key')) ?>">
        </div>
        <div class="sh-field">
          <label class="sh-field__label" for="sc-fb-sender">Firebase sender ID (optional)</label>
          <input class="sh-input" id="sc-fb-sender" name="otp_firebase_sender_id" autocomplete="off" value="<?= e($s('otp_firebase_sender_id')) ?>">
        </div>
        <p class="sh-panel__note">Firebase Auth issues its own phone code and cannot carry this store's server-generated OTP — for app-level phone auth only. Use TextBee or a custom HTTP gateway to deliver your codes.</p>
      </div>

      <!-- Custom HTTP -->
      <div data-provider-fields="generic_http" style="margin-top:10px">
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
            <label class="sh-field__label" for="sc-auth">Auth type</label>
            <select class="sh-select" id="sc-auth" name="otp_api_auth">
              <option value="" <?= $s('otp_api_auth') === '' ? 'selected' : '' ?>>None (via body/headers)</option>
              <option value="bearer" <?= $s('otp_api_auth') === 'bearer' ? 'selected' : '' ?>>Bearer token</option>
              <option value="basic" <?= $s('otp_api_auth') === 'basic' ? 'selected' : '' ?>>HTTP Basic</option>
            </select>
          </div>
        </div>
        <div class="sh-grid2">
          <div class="sh-field">
            <label class="sh-field__label" for="sc-key">API key</label>
            <input class="sh-input" id="sc-key" name="otp_api_key" autocomplete="off" value="<?= e($s('otp_api_key')) ?>">
          </div>
          <div class="sh-field">
            <label class="sh-field__label" for="sc-secret">API secret</label>
            <input class="sh-input" id="sc-secret" name="otp_api_secret" type="password" autocomplete="new-password" value="<?= e($s('otp_api_secret')) ?>">
            <span class="sh-field__hint">Optional — only when your gateway uses one. Stored server-side only.</span>
          </div>
          <div class="sh-field">
            <label class="sh-field__label" for="sc-token">Bearer token</label>
            <input class="sh-input" id="sc-token" name="otp_api_token" type="password" autocomplete="new-password" value="<?= e($s('otp_api_token')) ?>">
          </div>
          <div class="sh-field">
            <label class="sh-field__label" for="sc-user">Username</label>
            <input class="sh-input" id="sc-user" name="otp_api_username" autocomplete="off" value="<?= e($s('otp_api_username')) ?>">
          </div>
          <div class="sh-field">
            <label class="sh-field__label" for="sc-pass">Password</label>
            <input class="sh-input" id="sc-pass" name="otp_api_password" type="password" autocomplete="new-password" value="<?= e($s('otp_api_password')) ?>">
          </div>
        </div>
        <div class="sh-grid2">
          <div class="sh-field">
            <label class="sh-field__label" for="sc-pp">Phone param name</label>
            <input class="sh-input" id="sc-pp" name="otp_phone_param" value="<?= e($s('otp_phone_param', 'phone')) ?>">
          </div>
          <div class="sh-field">
            <label class="sh-field__label" for="sc-mp">Message param name</label>
            <input class="sh-input" id="sc-mp" name="otp_message_param" value="<?= e($s('otp_message_param', 'message')) ?>">
          </div>
        </div>
        <div class="sh-field">
          <label class="sh-field__label" for="sc-body">Request body template (JSON / form / query)</label>
          <textarea class="sh-textarea" id="sc-body" name="otp_api_body" rows="4"
            placeholder='{"phone":"{phone}","message":"{message}","sender":"{sender}","api_key":"{api_key}"}'><?= e($s('otp_api_body')) ?></textarea>
          <span class="sh-field__hint">Optional. If empty, the phone/message param names above are used. Placeholders: {phone}, {message}, {sender}, {api_key}, {api_secret}, {token}, {username}, {password}.</span>
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

<script>
(function () {
  var sel = document.querySelector('[data-provider-select]');
  if (!sel) return;
  var fields = document.querySelectorAll('[data-provider-fields]');
  function sync() {
    var v = sel.value;
    fields.forEach(function (el) { el.hidden = (el.getAttribute('data-provider-fields') !== v); });
  }
  sel.addEventListener('change', sync);
  sync();
})();
</script>
<?php require __DIR__ . '/_footer.php'; ?>
