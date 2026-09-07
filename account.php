<?php
declare(strict_types=1);
require_once __DIR__ . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/auth.php';

sh_session_start();
$user = sh_require_login();
$errors = [];

// Phone-only accounts carry a synthetic email; never surface it in the form.
$form = [
    'name'  => $user['name'],
    'email' => sh_is_synthetic_email((string)$user['email']) ? '' : (string)$user['email'],
    'phone' => (string)$user['phone'],
];
foreach ($form as $k => $v) { if (isset($_POST[$k])) { $form[$k] = trim((string)$_POST[$k]); } }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sh_csrf_require();
    $v = new ShValidator($_POST);
    $v->required('name', 'Full name')->maxLen('name', 110, 'Full name')
      ->email('email', 'Email address')
      ->required('phone', 'Phone number')->phone('phone', 'Phone number');
    $errors = $v->errors();

    if (!$errors) {
        try {
            $email = trim($form['email']);
            if ($email !== '') {
                $dupeEmail = sh_one('SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1', [$email, (int)$user['id']]);
                if ($dupeEmail) {
                    $errors['email'] = 'That email address is already used by another account.';
                }
            }

            $newPhone = sh_phone_normalize($form['phone']);
            $oldPhone = sh_phone_normalize((string)$user['phone']);
            $phoneChanged = $newPhone !== '' && $newPhone !== $oldPhone;
            if ($newPhone === '') {
                $errors['phone'] = 'Please enter a valid mobile number.';
            } elseif ($phoneChanged) {
                $dupe = sh_find_user_by_phone($newPhone);
                if ($dupe !== null && (int)$dupe['id'] !== (int)$user['id']) {
                    $errors['phone'] = 'This mobile number is already used by another account.';
                }
            }

            if (!$errors) {
                $upd = [
                    'name'  => $form['name'],
                    'phone' => $newPhone,
                ];
                if ($email !== '' || !sh_is_synthetic_email((string)$user['email'])) {
                    // Keep a real email; only overwrite when the customer typed one.
                    if ($email !== '') { $upd['email'] = $email; }
                }
                if ($phoneChanged) {
                    // The new number is unverified until they sign in with it.
                    $upd['phone_verified'] = 0;
                    $upd['phone_verified_at'] = null;
                    $upd['phone_verification_method'] = null;
                }
                sh_update('users', $upd, 'id = ?', [(int)$user['id']]);
                sh_security_log('profile_updated', (int)$user['id']);
                sh_flash('success', 'Your profile has been updated.');
                sh_redirect('account.php');
            }
        } catch (Throwable $e) {
            sh_log_exception($e, 'profile');
            $errors['general'] = 'Your profile could not be saved.';
        }
    }
}

$stats = ['orders' => 0, 'pending' => 0, 'completed' => 0, 'spent' => 0];
try {
    $row = sh_one('SELECT COUNT(*) AS c,
        SUM(status IN (\'pending\',\'awaiting_payment\',\'payment_submitted\',\'processing\')) AS p,
        SUM(status = \'completed\') AS d,
        COALESCE(SUM(CASE WHEN payment_status = \'verified\' OR status = \'completed\' THEN total ELSE 0 END),0) AS s
        FROM orders WHERE user_id = ?', [(int)$user['id']]);
    $stats = ['orders' => (int)$row['c'], 'pending' => (int)$row['p'], 'completed' => (int)$row['d'], 'spent' => (float)$row['s']];
} catch (Throwable $e) { sh_log_exception($e, 'account-stats'); }

$accountPage = 'account';
$pageTitle = 'My Profile';
require_once SH_ROOT . '/includes/header.php';
?>
<div class="sh-wrap">
  <nav class="sh-breadcrumb"><a href="<?= e(sh_url('index.php')) ?>">Home</a> <?= sh_icon('chevron-right', 13) ?> <span aria-current="page">My Account</span></nav>
  <div class="sh-account">
    <?php require SH_ROOT . '/includes/account-nav.php'; ?>
    <div>
      <div class="sh-stats">
        <div class="sh-stat sh-stat--brand"><span class="sh-stat__icon"><?= sh_icon('package', 19) ?></span>
          <div><p class="sh-stat__value"><?= $stats['orders'] ?></p><p class="sh-stat__label">Total orders</p></div></div>
        <div class="sh-stat sh-stat--warn"><span class="sh-stat__icon"><?= sh_icon('clock', 19) ?></span>
          <div><p class="sh-stat__value"><?= $stats['pending'] ?></p><p class="sh-stat__label">In progress</p></div></div>
        <div class="sh-stat sh-stat--ok"><span class="sh-stat__icon"><?= sh_icon('check-circle', 19) ?></span>
          <div><p class="sh-stat__value"><?= $stats['completed'] ?></p><p class="sh-stat__label">Completed</p></div></div>
        <div class="sh-stat sh-stat--info"><span class="sh-stat__icon"><?= sh_icon('dollar', 19) ?></span>
          <div><p class="sh-stat__value" style="font-size:16px"><?= e(sh_money($stats['spent'])) ?></p><p class="sh-stat__label">Total spent</p></div></div>
      </div>

      <div class="sh-section">
        <div class="sh-section__head"><h1 class="sh-section__title"><?= sh_icon('user', 18) ?> Profile Information</h1></div>
        <?php if ($errors): ?>
          <div class="sh-alert sh-alert--error"><?= sh_icon('x-circle', 16) ?>
            <div><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div></div>
        <?php endif; ?>
        <form method="post" novalidate>
          <?= sh_csrf_field() ?>
          <div class="sh-grid2">
            <div class="sh-field">
              <label class="sh-field__label" for="ac-name">Full name</label>
              <input class="sh-input" id="ac-name" name="name" value="<?= e($form['name']) ?>" required maxlength="110">
            </div>
            <div class="sh-field">
              <label class="sh-field__label" for="ac-phone">Mobile number</label>
              <input class="sh-input" id="ac-phone" name="phone" value="<?= e($form['phone'] !== '' ? sh_phone_display($form['phone']) : '') ?>" required>
              <p class="sh-field__hint" style="display:flex;align-items:center;gap:8px">
                <?php if (!empty($user['phone_verified'])): ?>
                  <span class="sh-verify-badge sh-verify-badge--ok"><?= sh_icon('check-circle', 13) ?> ✓ Verified</span>
                <?php else: ?>
                  <span class="sh-verify-badge sh-verify-badge--no"><?= sh_icon('alert', 13) ?> Not verified — sign in to verify</span>
                <?php endif; ?>
              </p>
            </div>
          </div>
          <div class="sh-field">
            <label class="sh-field__label" for="ac-email">Email address <span style="color:var(--sh-muted);font-weight:400">(optional)</span></label>
            <input class="sh-input" id="ac-email" type="email" name="email" value="<?= e($form['email']) ?>">
            <p class="sh-field__hint">Only used for order updates and digital delivery receipts.</p>
          </div>
          <button class="sh-btn" type="submit"><?= sh_icon('check-circle', 15) ?> Save changes</button>
        </form>
        <p style="font-size:12.5px;color:var(--sh-muted);margin-top:12px">
          Member since <?= e(date('d M Y', strtotime($user['created_at']))) ?>.
        </p>
      </div>
    </div>
  </div>
</div>
<?php require_once SH_ROOT . '/includes/footer.php'; ?>
