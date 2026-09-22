<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/admin-auth.php';
require_once SH_ROOT . '/includes/catalog.php';

sh_session_start();
$admin = sh_require_admin();

/*
 * General site configuration only.
 * Payment methods, payment gateways, Telegram, WhatsApp, Messenger and SMTP each
 * have their own dedicated section and are deliberately NOT duplicated here.
 */
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sh_csrf_require();
    $form = sh_post('form');

    if ($form === 'general') {
        $v = new ShValidator($_POST);
        $v->required('site_name', 'Site name')->maxLen('site_name', 90, 'Site name');
        $errors = $v->errors();
        $contactEmail = trim((string)($_POST['contact_email'] ?? ''));
        if ($contactEmail !== '' && !sh_valid_email($contactEmail)) {
            $errors['contact_email'] = 'The contact email address is not valid.';
        }
        foreach (['delivery_fee_inside', 'delivery_fee_outside', 'free_delivery_over'] as $k) {
            if (!is_numeric($_POST[$k] ?? '0')) { $errors[$k] = 'Delivery values must be numbers.'; }
        }
        $perPage = sh_int($_POST['products_per_page'] ?? 24);
        if ($perPage < 4 || $perPage > 96) { $errors['products_per_page'] = 'Products per page must be between 4 and 96.'; }

        $orderPrefix = strtoupper(trim((string)($_POST['order_number_prefix'] ?? '')));
        if ($orderPrefix === '' || preg_match('/^[A-Z]{2}$/', $orderPrefix) !== 1) {
            $errors['order_number_prefix'] = 'Order number prefix must be exactly 2 English letters (A–Z).';
        }

        $logo = (string)sh_setting('site_logo', '');
        if (!empty($_FILES['site_logo']['name'])) {
            $up = sh_upload_image($_FILES['site_logo'], 'logos', 0, 2000);
            if (!empty($up['ok'])) { $logo = $up['file']; }
            else { $errors['site_logo'] = $up['error'] ?? 'The logo could not be uploaded.'; }
        }

        if (!$errors) {
            $text = ['site_name', 'site_tagline', 'site_description', 'contact_email', 'contact_phone',
                     'contact_address', 'footer_about', 'footer_copyright', 'currency_symbol'];
            foreach ($text as $k) {
                if (array_key_exists($k, $_POST)) { sh_setting_save($k, trim((string)$_POST[$k])); }
            }
            foreach (['delivery_fee_inside', 'delivery_fee_outside', 'free_delivery_over'] as $k) {
                sh_setting_save($k, (string)(float)$_POST[$k]);
            }
            sh_setting_save('products_per_page', (string)$perPage);
            sh_setting_save('order_number_prefix', $orderPrefix);
            sh_setting_save('site_logo', $logo);
            sh_setting_save('maintenance_mode', !empty($_POST['maintenance_mode']) ? '1' : '0');
            sh_log_line('admin', 'Site settings updated by ' . $admin['email']);
            sh_flash('success', 'Settings saved.');
            sh_redirect('admin/settings.php');
        }
    }

    if ($form === 'remove_logo') {
        sh_setting_save('site_logo', '');
        sh_flash('success', 'Logo removed. The text wordmark will be shown instead.');
        sh_redirect('admin/settings.php');
    }

    if ($form === 'password') {
        $current = (string)($_POST['current_password'] ?? '');
        $new = (string)($_POST['new_password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');
        $row = sh_one('SELECT password_hash FROM admins WHERE id = ? LIMIT 1', [(int)$admin['id']]);
        if ($row === null || !password_verify($current, $row['password_hash'])) {
            $errors['current_password'] = 'Your current password is not correct.';
        }
        $problem = sh_password_problem($new);
        if ($problem !== null) { $errors['new_password'] = $problem; }
        if ($new !== $confirm) { $errors['confirm_password'] = 'The new passwords do not match.'; }
        if (!$errors) {
            sh_query('UPDATE admins SET password_hash = ? WHERE id = ?',
                [password_hash($new, PASSWORD_DEFAULT), (int)$admin['id']]);
            sh_log_line('security', 'Admin password changed: ' . $admin['email']);
            sh_flash('success', 'Your admin password has been changed.');
            sh_redirect('admin/settings.php');
        }
    }

    if ($form === 'authentication') {
        if (!sh_admin_is_superadmin()) {
            sh_flash('error', 'Only the store owner can change the customer authentication method.');
            sh_redirect('admin/settings.php');
        }
        $mode = sh_post('authentication_mode');
        if (!in_array($mode, ['email_password', 'phone_otp'], true)) {
            sh_flash('error', 'Please choose a valid authentication method.');
            sh_redirect('admin/settings.php');
        }
        sh_setting_save('authentication_mode', $mode);
        sh_security_log('authentication_mode_changed', null, ['mode' => $mode]);
        sh_log_line('admin', 'Customer authentication mode set to ' . $mode . ' by ' . $admin['email']);
        sh_flash('success', $mode === 'phone_otp'
            ? 'Customer authentication is now Phone Number + OTP.'
            : 'Customer authentication is now Email + Password.');
        sh_redirect('admin/settings.php');
    }

    if ($form === 'google_login' || $form === 'google_test') {
        if (!sh_admin_is_superadmin()) {
            sh_flash('error', 'Only the store owner can change Google Login settings.');
            sh_redirect('admin/settings.php');
        }
        if ($form === 'google_login') {
            $cid = trim((string)($_POST['google_client_id'] ?? ''));
            $sec = trim((string)($_POST['google_client_secret'] ?? ''));
            $on  = !empty($_POST['google_login_enabled']);
            if ($cid !== '' && !preg_match('/^[A-Za-z0-9._-]+$/', $cid)) {
                $errors['google_client_id'] = 'The Google Client ID contains unexpected characters.';
            }
            if ($on && ($cid === '' || ($sec === '' && !sh_google_has_secret()))) {
                $errors['google_login_enabled'] = 'Enter the Google Client ID and Client Secret before turning Google Login on.';
            }
            if (!$errors) {
                sh_setting_save('google_client_id', $cid);
                // The secret is encrypted at rest and never rendered back; blank keeps the stored one.
                if ($sec !== '') { sh_setting_save('google_client_secret', sh_google_encrypt($sec)); }
                if (!empty($_POST['google_clear_secret'])) { sh_setting_save('google_client_secret', ''); $on = false; }
                sh_setting_save('google_login_enabled', $on ? '1' : '0');
                sh_security_log('google_login_settings_changed', null, ['enabled' => $on ? 1 : 0]);
                sh_log_line('admin', 'Google Login settings updated by ' . $admin['email'] . ' (enabled=' . ($on ? '1' : '0') . ')');
                sh_flash('success', $on ? 'Google Login settings saved. The button is now shown on the login page.' : 'Google Login settings saved. Google Login is OFF.');
                sh_redirect('admin/settings.php#google-login');
            }
        } else {
            $_SESSION['sh_google_test'] = sh_google_test_connection();
            sh_redirect('admin/settings.php#google-login');
        }
    }
}

$logo = (string)sh_setting('site_logo', '');
$s = static fn(string $k, string $fb = ''): string => (string)sh_setting($k, $fb);
// Repopulate the prefix from what was typed when validation failed, otherwise
// show the saved (always valid) prefix.
$orderPrefixInput = isset($errors['order_number_prefix'])
    ? strtoupper(trim((string)($_POST['order_number_prefix'] ?? '')))
    : sh_order_number_prefix();

$adminPage = 'settings';
$adminTitle = 'Settings';
require __DIR__ . '/_layout.php';
?>
<?php if ($errors): ?>
  <div class="sh-alert sh-alert--error"><?= sh_icon('x-circle', 17) ?>
    <div><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div></div>
<?php endif; ?>

<div class="sh-alert sh-alert--info"><?= sh_icon('info', 17) ?>
  <span>This page holds general site configuration only. Payment methods, payment gateways, Telegram, WhatsApp,
    Messenger and SMTP are managed in their own sections from the sidebar.</span></div>

<?php $authMode = sh_auth_mode(); ?>
<div class="sh-panel" style="margin-top:14px">
  <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('shield', 17) ?> Customer Authentication</h2></div>
  <div class="sh-panel__body">
    <p class="sh-panel__note">Choose how customers log in to your website. Only one method is active at a time.</p>
    <div class="sh-alert <?= $authMode === 'phone_otp' ? 'sh-alert--info' : 'sh-alert--success' ?>" style="margin:0 0 16px">
      <?= sh_icon($authMode === 'phone_otp' ? 'smartphone' : 'mail', 16) ?>
      <span>Active Authentication Method: <strong><?= $authMode === 'phone_otp' ? 'Phone Number + OTP' : 'Email + Password' ?></strong></span>
    </div>

    <?php if (sh_admin_is_superadmin()): ?>
      <form method="post" novalidate data-confirm="Change customer authentication method?">
        <?= sh_csrf_field() ?>
        <input type="hidden" name="form" value="authentication">
        <label class="sh-check" style="margin:10px 0">
          <input type="radio" name="authentication_mode" value="email_password"
                 <?= $authMode === 'email_password' ? 'checked' : '' ?>>
          <span><strong>Email + Password</strong> — customers sign in with their email address and password.</span>
        </label>
        <label class="sh-check" style="margin:10px 0">
          <input type="radio" name="authentication_mode" value="phone_otp"
                 <?= $authMode === 'phone_otp' ? 'checked' : '' ?>>
          <span><strong>Phone Number + OTP</strong> — customers sign in with a mobile number and a one-time code.</span>
        </label>
        <div style="display:flex;gap:9px;flex-wrap:wrap;margin-top:12px">
          <button class="sh-btn" type="submit"><?= sh_icon('check-circle', 15) ?> Save authentication method</button>
          <?php if ($authMode === 'phone_otp'): ?>
            <a class="sh-btn sh-btn--ghost" href="<?= e(sh_url('admin/security.php')) ?>"><?= sh_icon('send', 15) ?> SMS / OTP settings</a>
          <?php endif; ?>
        </div>
      </form>
    <?php else: ?>
      <p class="sh-panel__note">Only the store owner can change the customer authentication method.</p>
    <?php endif; ?>
  </div>
</div>


<?php
$gEnabled = (string)$s('google_login_enabled', '0') === '1';
$gLive    = sh_google_enabled();
$gTest    = $_SESSION['sh_google_test'] ?? null;
unset($_SESSION['sh_google_test']);
$gCallback = sh_google_callback_url();
$gOrigin   = sh_site_url();
$gOrigin   = preg_replace('#^(https?://[^/]+).*$#', '$1', $gOrigin);
?>
<div class="sh-panel" style="margin-top:14px" id="google-login">
  <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('log-in', 17) ?> Google Login / OAuth Settings</h2></div>
  <div class="sh-panel__body">
    <p class="sh-panel__note">Lets customers sign in with “Continue with Google” in addition to the normal login. Google accounts are matched to existing customers by their verified email address, so no duplicate accounts are created.</p>
    <div class="sh-alert <?= $gLive ? 'sh-alert--success' : 'sh-alert--info' ?>" style="margin:0 0 16px">
      <?= sh_icon($gLive ? 'check-circle' : 'info', 16) ?>
      <span>Google Login: <strong><?= $gLive ? 'ON — button visible on the login page' : ($gEnabled ? 'ON but incomplete — add Client ID and Secret' : 'OFF') ?></strong></span>
    </div>

    <?php if (is_array($gTest)): ?>
      <div class="sh-alert <?= $gTest['ok'] ? 'sh-alert--success' : 'sh-alert--error' ?>" style="margin:0 0 16px;align-items:flex-start">
        <?= sh_icon($gTest['ok'] ? 'check-circle' : 'x-circle', 16) ?>
        <div style="min-width:0">
          <strong><?= $gTest['ok'] ? 'Connection test passed.' : 'Connection test found problems.' ?></strong>
          <ul style="margin:6px 0 0 16px;padding:0;font-size:13px;line-height:1.8">
            <?php foreach ($gTest['checks'] as $c): ?>
              <li><?= e($c['label']) ?>: <strong style="color:<?= $c['ok'] ? '#1b7f3b' : '#c62828' ?>"><?= e($c['value']) ?></strong></li>
            <?php endforeach; ?>
          </ul>
        </div>
      </div>
    <?php endif; ?>

    <?php if (sh_admin_is_superadmin()): ?>
      <form method="post" novalidate autocomplete="off">
        <?= sh_csrf_field() ?>
        <input type="hidden" name="form" value="google_login">
        <label class="sh-toggle" style="margin:4px 0 16px">
          <input type="checkbox" name="google_login_enabled" value="1" <?= $gEnabled ? 'checked' : '' ?>>
          <span class="sh-toggle__track"></span>
          <span>Enable Google Login — show “Continue with Google” on the login page</span>
        </label>
        <div class="sh-grid2">
          <div class="sh-field"><label class="sh-field__label" for="gl-cid">Google Client ID</label>
            <input class="sh-input <?= isset($errors['google_client_id']) ? 'sh-input--error' : '' ?>" id="gl-cid" name="google_client_id"
                   value="<?= e($s('google_client_id')) ?>" placeholder="1234567890-abc123.apps.googleusercontent.com" spellcheck="false"></div>
          <div class="sh-field"><label class="sh-field__label" for="gl-sec">Google Client Secret</label>
            <input class="sh-input" id="gl-sec" type="password" name="google_client_secret" value="" autocomplete="new-password"
                   placeholder="<?= sh_google_has_secret() ? '•••••••••••• (stored encrypted — leave blank to keep)' : 'GOCSPX-…' ?>">
            <span class="sh-field__hint">Stored encrypted. Never shown again and never sent to the browser.</span>
            <?php if (sh_google_has_secret()): ?>
              <label class="sh-check" style="margin-top:6px;font-size:12.5px"><input type="checkbox" name="google_clear_secret" value="1"> <span>Remove the stored secret</span></label>
            <?php endif; ?>
          </div>
        </div>
        <div class="sh-field">
          <label class="sh-field__label" for="gl-cb">Authorized Redirect URI (copy this into Google Cloud Console)</label>
          <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
            <input class="sh-input" id="gl-cb" value="<?= e($gCallback) ?>" readonly onclick="this.select()" style="flex:1 1 260px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12.5px">
            <button class="sh-btn sh-btn--sm sh-btn--ghost" type="button" data-copy="<?= e($gCallback) ?>"><?= sh_icon('copy', 14) ?> Copy</button>
          </div>
          <span class="sh-field__hint">Detected automatically from this website's address. Authorized JavaScript origin: <code><?= e($gOrigin) ?></code></span>
          <?php if (!sh_google_is_https() && !sh_google_is_localhost()): ?>
            <span class="sh-field__hint" style="color:#c62828">Your site is not served over HTTPS. Google only accepts https:// redirect URIs (localhost excepted), so enable SSL first.</span>
          <?php endif; ?>
        </div>
        <div style="display:flex;gap:9px;flex-wrap:wrap;margin-top:6px">
          <button class="sh-btn" type="submit"><?= sh_icon('check-circle', 15) ?> Save Google settings</button>
          <button class="sh-btn sh-btn--ghost" type="submit" name="form" value="google_test" formnovalidate><?= sh_icon('shield', 15) ?> Test connection</button>
        </div>
      </form>
    <?php else: ?>
      <p class="sh-panel__note">Only the store owner can change Google Login settings.</p>
    <?php endif; ?>

    <details style="margin-top:18px">
      <summary style="cursor:pointer;font-weight:700;font-size:13.5px">Google Cloud Console setup guide</summary>
      <ol style="margin:10px 0 0 18px;padding:0;font-size:13.2px;line-height:1.9">
        <li>Go to <a href="https://console.cloud.google.com/" target="_blank" rel="noopener">console.cloud.google.com</a> and create or select a project.</li>
        <li>Open <strong>APIs &amp; Services → OAuth consent screen</strong>. Choose <strong>External</strong>, fill in the app name, support email and developer email, then save. Add the scopes <code>openid</code>, <code>email</code> and <code>profile</code>. Publish the app (or add test users while in Testing).</li>
        <li>Open <strong>APIs &amp; Services → Credentials → Create Credentials → OAuth client ID</strong>.</li>
        <li>Application type: <strong>Web application</strong>.</li>
        <li>Authorized JavaScript origins: <code><?= e($gOrigin) ?></code></li>
        <li>Authorized redirect URIs: <code><?= e($gCallback) ?></code> (must match exactly, including https and path).</li>
        <li>Copy the generated <strong>Client ID</strong> and <strong>Client Secret</strong> into the fields above, turn the toggle ON and save. Then press <strong>Test connection</strong>.</li>
      </ol>
    </details>
  </div>
</div>

<form method="post" enctype="multipart/form-data" novalidate>
  <?= sh_csrf_field() ?>
  <input type="hidden" name="form" value="general">

  <div class="sh-panel">
    <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('settings', 17) ?> Store identity</h2></div>
    <div class="sh-panel__body">
      <div class="sh-grid2">
        <div class="sh-field"><label class="sh-field__label" for="st-name">Site name <span class="sh-field__req">*</span></label>
          <input class="sh-input" id="st-name" name="site_name" required maxlength="90" value="<?= e($s('site_name', 'ShopHaat')) ?>"></div>
        <div class="sh-field"><label class="sh-field__label" for="st-tag">Tagline</label>
          <input class="sh-input" id="st-tag" name="site_tagline" maxlength="150" value="<?= e($s('site_tagline')) ?>"></div>
      </div>
      <div class="sh-field"><label class="sh-field__label" for="st-desc">Meta description</label>
        <textarea class="sh-textarea" id="st-desc" name="site_description" rows="2" maxlength="300"><?= e($s('site_description')) ?></textarea>
        <span class="sh-field__hint">Used as the default description in search results.</span></div>
      <div class="sh-grid2">
        <div class="sh-field"><label class="sh-field__label" for="st-logo">Site logo</label>
          <input class="sh-input" id="st-logo" type="file" name="site_logo" accept="image/*">
          <span class="sh-field__hint">PNG, JPG, WebP or GIF. Maximum <?= e(sh_bytes_label(sh_server_upload_limit())) ?>
            (your server's limit). Leave blank to keep the current logo.</span></div>
        <div class="sh-field"><label class="sh-field__label" for="st-cur">Currency prefix</label>
          <input class="sh-input" id="st-cur" name="currency_symbol" maxlength="8" value="<?= e($s('currency_symbol', 'BDT ')) ?>"></div>
      </div>
      <?php $siteLogoUrl = sh_logo_image($logo); ?>
      <?php if ($siteLogoUrl !== ''): ?>
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px">
          <img src="<?= e($siteLogoUrl) ?>" alt="Current logo" style="height:40px;max-width:180px;object-fit:contain;background:var(--sh-bg);border-radius:6px;padding:5px">
          <span class="sh-table__meta">Current logo</span>
        </div>
      <?php elseif ($logo !== ''): ?>
        <p class="sh-field__hint" style="margin-bottom:12px;color:#c62828">
          The saved logo file (<?= e($logo) ?>) is missing from the server. Upload it again to replace it.
        </p>
      <?php endif; ?>
    </div>
  </div>

  <div class="sh-panel" style="margin-top:14px">
    <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('phone', 17) ?> Contact details</h2></div>
    <div class="sh-panel__body">
      <div class="sh-grid2">
        <div class="sh-field"><label class="sh-field__label" for="st-ce">Contact email</label>
          <input class="sh-input" id="st-ce" type="email" name="contact_email" value="<?= e($s('contact_email')) ?>">
          <span class="sh-field__hint">Shown on the support page and in the footer.</span></div>
        <div class="sh-field"><label class="sh-field__label" for="st-cp">Contact phone</label>
          <input class="sh-input" id="st-cp" name="contact_phone" value="<?= e($s('contact_phone')) ?>"></div>
      </div>
      <div class="sh-field"><label class="sh-field__label" for="st-ca">Business address</label>
        <input class="sh-input" id="st-ca" name="contact_address" maxlength="240" value="<?= e($s('contact_address')) ?>"></div>
      <div class="sh-field"><label class="sh-field__label" for="st-fa">Footer about text</label>
        <textarea class="sh-textarea" id="st-fa" name="footer_about" rows="3" maxlength="400"><?= e($s('footer_about')) ?></textarea></div>
      <div class="sh-field"><label class="sh-field__label" for="st-fc">Footer copyright</label>
        <input class="sh-input" id="st-fc" name="footer_copyright" maxlength="180" value="<?= e($s('footer_copyright')) ?>"></div>
    </div>
  </div>

  <div class="sh-panel" style="margin-top:14px">
    <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('truck', 17) ?> Delivery and catalogue</h2></div>
    <div class="sh-panel__body">
      <div class="sh-grid3">
        <div class="sh-field"><label class="sh-field__label" for="st-di">Delivery fee — inside city</label>
          <input class="sh-input" id="st-di" name="delivery_fee_inside" inputmode="decimal" value="<?= e($s('delivery_fee_inside', '60')) ?>"></div>
        <div class="sh-field"><label class="sh-field__label" for="st-do">Delivery fee — outside city</label>
          <input class="sh-input" id="st-do" name="delivery_fee_outside" inputmode="decimal" value="<?= e($s('delivery_fee_outside', '120')) ?>"></div>
        <div class="sh-field"><label class="sh-field__label" for="st-fd">Free delivery over</label>
          <input class="sh-input" id="st-fd" name="free_delivery_over" inputmode="decimal" value="<?= e($s('free_delivery_over', '0')) ?>">
          <span class="sh-field__hint">Set 0 to disable free delivery.</span></div>
      </div>
      <div class="sh-field" style="max-width:250px">
        <label class="sh-field__label" for="st-pp">Products per page</label>
        <input class="sh-input" id="st-pp" name="products_per_page" type="number" min="4" max="96" value="<?= e($s('products_per_page', '24')) ?>"></div>
      <label class="sh-toggle" style="margin-top:6px">
        <input type="checkbox" name="maintenance_mode" value="1" <?= $s('maintenance_mode') === '1' ? 'checked' : '' ?>>
        <span class="sh-toggle__track"></span>
        <span>Maintenance mode — hide the storefront from visitors</span>
      </label>
    </div>
  </div>

  <div class="sh-panel" style="margin-top:14px">
    <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('package', 17) ?> Order settings</h2></div>
    <div class="sh-panel__body">
      <div class="sh-field" style="max-width:250px">
        <label class="sh-field__label" for="st-op">Order number prefix <span class="sh-field__req">*</span></label>
        <input class="sh-input <?= isset($errors['order_number_prefix']) ? 'sh-input--error' : '' ?>"
               id="st-op" name="order_number_prefix" value="<?= e($orderPrefixInput) ?>"
               maxlength="2" pattern="[A-Za-z]{2}" required autocomplete="off" spellcheck="false" data-order-prefix
               style="text-transform:uppercase;letter-spacing:2px;text-align:center;font-weight:700">
        <?php if (isset($errors['order_number_prefix'])): ?>
          <p class="sh-field__error"><?= e($errors['order_number_prefix']) ?></p>
        <?php endif; ?>
        <span class="sh-field__hint">Exactly 2 English letters (A–Z). New orders use this prefix — existing order numbers never change.</span>
        <span class="sh-field__hint">Example: <strong><?= e(sh_order_number_prefix()) ?>25010100001</strong></span>
      </div>
    </div>
  </div>

  <div style="display:flex;gap:9px;flex-wrap:wrap;margin-top:14px">
    <button class="sh-btn sh-btn--lg" type="submit"><?= sh_icon('check-circle', 16) ?> Save settings</button>
  </div>
</form>

<?php if ($logo !== ''): ?>
  <form method="post" style="margin-top:10px" data-confirm="Remove the site logo?">
    <?= sh_csrf_field() ?><input type="hidden" name="form" value="remove_logo">
    <button class="sh-btn sh-btn--sm sh-btn--ghost" type="submit"><?= sh_icon('trash', 14) ?> Remove logo</button>
  </form>
<?php endif; ?>

<div class="sh-panel" style="margin-top:14px">
  <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('lock', 17) ?> Change your admin password</h2></div>
  <div class="sh-panel__body">
    <form method="post" novalidate style="max-width:430px">
      <?= sh_csrf_field() ?>
      <input type="hidden" name="form" value="password">
      <div class="sh-field"><label class="sh-field__label" for="pw-cur">Current password</label>
        <input class="sh-input" id="pw-cur" type="password" name="current_password" required autocomplete="current-password"></div>
      <div class="sh-field"><label class="sh-field__label" for="pw-new">New password</label>
        <input class="sh-input" id="pw-new" type="password" name="new_password" required autocomplete="new-password">
        <span class="sh-field__hint">At least 8 characters, including a letter and a number.</span></div>
      <div class="sh-field"><label class="sh-field__label" for="pw-conf">Confirm new password</label>
        <input class="sh-input" id="pw-conf" type="password" name="confirm_password" required autocomplete="new-password"></div>
      <button class="sh-btn" type="submit"><?= sh_icon('lock', 15) ?> Change password</button>
    </form>
  </div>
</div>

<script>
(function () {
  var input = document.getElementById('st-op');
  var form = input && input.closest('form');
  if (!input || !form) { return; }
  input.addEventListener('input', function () {
    input.value = input.value.toUpperCase().replace(/[^A-Z]/g, '').slice(0, 2);
    input.classList.remove('sh-input--error');
    var err = input.parentElement.querySelector('.sh-field__error');
    if (err) { err.remove(); }
  });
  form.addEventListener('submit', function (ev) {
    var v = input.value.toUpperCase().replace(/[^A-Z]/g, '');
    if (/^[A-Z]{2}$/.test(v)) { input.value = v; return; }
    ev.preventDefault();
    ev.stopPropagation();
    input.value = v.slice(0, 2);
    input.classList.add('sh-input--error');
    input.focus();
    var err = input.parentElement.querySelector('.sh-field__error');
    if (!err) {
      err = document.createElement('p');
      err.className = 'sh-field__error';
      input.parentElement.appendChild(err);
    }
    err.textContent = 'Order number prefix must be exactly 2 English letters (A–Z).';
  });
})();
</script>
<?php require __DIR__ . '/_footer.php'; ?>
