<?php
declare(strict_types=1);
require_once __DIR__ . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/auth.php';

sh_session_start();
$redirect = sh_get('redirect');
if (sh_user() !== null) { sh_redirect('account.php'); }

$errors = [];
$form = ['name' => '', 'email' => '', 'phone' => ''];
foreach ($form as $k => $v) { if (isset($_POST[$k])) { $form[$k] = trim((string)$_POST[$k]); } }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sh_csrf_require();
    $redirect = sh_post('redirect', $redirect);
    $v = new ShValidator($_POST);
    $v->required('name', 'Full name')->minLen('name', 2, 'Full name')->maxLen('name', 110, 'Full name')
      ->required('email', 'Email address')->email('email', 'Email address')
      ->required('phone', 'Phone number')->phone('phone', 'Phone number')
      ->required('password', 'Password')->password('password')
      ->matches('password2', 'password', 'Password confirmation');
    if (empty($_POST['terms'])) { $v->custom('terms', false, 'You must accept the terms and conditions.'); }
    $errors = $v->errors();

    if (!$errors) {
        try {
            $exists = sh_one('SELECT id FROM users WHERE email = ? LIMIT 1', [$form['email']]);
            if ($exists) {
                $errors['email'] = 'An account with this email address already exists.';
            } else {
                $uid = sh_insert('users', [
                    'name'          => $form['name'],
                    'email'         => $form['email'],
                    'phone'         => $form['phone'],
                    'password_hash' => password_hash((string)$_POST['password'], PASSWORD_DEFAULT),
                    'status'        => 'active',
                ]);
                sh_login_user($uid);
                sh_flash('success', 'Your account has been created. Welcome to ' . sh_setting('site_name', 'ShopHaat') . '.');
                $target = $redirect !== '' && strpos($redirect, '//') === false ? $redirect : 'account.php';
                sh_redirect(ltrim($target, '/'));
            }
        } catch (Throwable $e) {
            sh_log_exception($e, 'register');
            $errors['general'] = 'Your account could not be created. Please try again.';
        }
    }
}

$pageTitle = 'Create Account';
$pageDescription = 'Create an account to order faster and track your purchases.';
require_once SH_ROOT . '/includes/header.php';
?>
<div class="sh-wrap">
  <div class="sh-auth">
    <h1 class="sh-auth__title">Create your account</h1>
    <p class="sh-auth__sub">It only takes a moment and makes checkout much faster.</p>

    <?php if ($errors): ?>
      <div class="sh-alert sh-alert--error">
        <?= sh_icon('x-circle', 16) ?>
        <div><strong>Please check the following:</strong>
          <ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div>
      </div>
    <?php endif; ?>

    <form method="post" novalidate>
      <?= sh_csrf_field() ?>
      <input type="hidden" name="redirect" value="<?= e($redirect) ?>">
      <div class="sh-field">
        <label class="sh-field__label" for="rg-name">Full name <span class="sh-field__req">*</span></label>
        <input class="sh-input <?= isset($errors['name']) ? 'sh-input--error' : '' ?>" id="rg-name" name="name"
               value="<?= e($form['name']) ?>" required maxlength="110" autocomplete="name">
      </div>
      <div class="sh-field">
        <label class="sh-field__label" for="rg-email">Email address <span class="sh-field__req">*</span></label>
        <input class="sh-input <?= isset($errors['email']) ? 'sh-input--error' : '' ?>" id="rg-email" type="email"
               name="email" value="<?= e($form['email']) ?>" required autocomplete="email">
      </div>
      <div class="sh-field">
        <label class="sh-field__label" for="rg-phone">Phone number <span class="sh-field__req">*</span></label>
        <input class="sh-input <?= isset($errors['phone']) ? 'sh-input--error' : '' ?>" id="rg-phone" name="phone"
               value="<?= e($form['phone']) ?>" required placeholder="01XXXXXXXXX" autocomplete="tel">
      </div>
      <div class="sh-field">
        <label class="sh-field__label" for="rg-pass">Password <span class="sh-field__req">*</span></label>
        <input class="sh-input <?= isset($errors['password']) ? 'sh-input--error' : '' ?>" id="rg-pass" type="password"
               name="password" required autocomplete="new-password">
        <p class="sh-field__hint">At least 8 characters, including a letter and a number.</p>
      </div>
      <div class="sh-field">
        <label class="sh-field__label" for="rg-pass2">Confirm password <span class="sh-field__req">*</span></label>
        <input class="sh-input <?= isset($errors['password2']) ? 'sh-input--error' : '' ?>" id="rg-pass2" type="password"
               name="password2" required autocomplete="new-password">
      </div>
      <label class="sh-check" style="margin-bottom:14px">
        <input type="checkbox" name="terms" value="1" <?= !empty($_POST['terms']) ? 'checked' : '' ?>>
        <span>I agree to the <a href="<?= e(sh_url('page.php?p=terms')) ?>" style="color:var(--sh-brand)">terms and conditions</a>
          and <a href="<?= e(sh_url('page.php?p=privacy')) ?>" style="color:var(--sh-brand)">privacy policy</a>.</span>
      </label>
      <button class="sh-btn sh-btn--lg sh-btn--block" type="submit"><?= sh_icon('user', 16) ?> Create account</button>
    </form>

    <p class="sh-auth__foot">Already registered?
      <a href="<?= e(sh_url('login.php' . ($redirect !== '' ? '?redirect=' . urlencode($redirect) : ''))) ?>">Sign in</a>
    </p>
  </div>
</div>
<?php require_once SH_ROOT . '/includes/footer.php'; ?>
