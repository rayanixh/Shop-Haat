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
}

$logo = (string)sh_setting('site_logo', '');
$s = static fn(string $k, string $fb = ''): string => (string)sh_setting($k, $fb);

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
<?php require __DIR__ . '/_footer.php'; ?>
