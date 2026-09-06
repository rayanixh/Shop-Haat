<?php
declare(strict_types=1);
require_once __DIR__ . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/auth.php';

sh_session_start();
$redirect = sh_get('redirect');
if (sh_user() !== null) { sh_redirect($redirect !== '' ? $redirect : 'account.php'); }

$error = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sh_csrf_require();
    $email = sh_post('email');
    $password = (string)($_POST['password'] ?? '');
    $redirect = sh_post('redirect', $redirect);

    if (sh_login_throttled('user_login')) {
        $error = 'Too many failed attempts. Please wait a few minutes and try again.';
    } elseif ($email === '' || $password === '') {
        $error = 'Enter your email address and password.';
    } else {
        try {
            $u = sh_one('SELECT id, password_hash, status FROM users WHERE email = ? LIMIT 1', [$email]);
            // Constant-ish work factor whether or not the user exists.
            $hash = $u['password_hash'] ?? '$2y$10$usesomesillystringforsalt0000000000000000000000000000000';
            if ($u !== null && password_verify($password, $hash) && $u['status'] === 'active') {
                if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
                    sh_query('UPDATE users SET password_hash = ? WHERE id = ?',
                        [password_hash($password, PASSWORD_DEFAULT), (int)$u['id']]);
                }
                sh_login_reset('user_login');
                sh_login_user((int)$u['id']);
                sh_flash('success', 'Welcome back.');
                $target = $redirect !== '' && strpos($redirect, '//') === false ? $redirect : 'account.php';
                sh_redirect(ltrim($target, '/'));
            }
            if ($u !== null && $u['status'] !== 'active') {
                $error = 'This account has been blocked. Please contact customer support.';
            } else {
                sh_login_fail('user_login');
                $error = 'The email address or password is incorrect.';
            }
        } catch (Throwable $e) {
            sh_log_exception($e, 'login');
            $error = 'Sign in is temporarily unavailable. Please try again shortly.';
        }
    }
}

$pageTitle = 'Sign In';
$pageDescription = 'Sign in to your account to track orders and manage your profile.';
require_once SH_ROOT . '/includes/header.php';
?>
<div class="sh-wrap">
  <div class="sh-auth">
    <h1 class="sh-auth__title">Sign in</h1>
    <p class="sh-auth__sub">Access your orders, wishlist and digital purchases.</p>

    <?php if ($error): ?>
      <div class="sh-alert sh-alert--error"><?= sh_icon('x-circle', 16) ?><span><?= e($error) ?></span></div>
    <?php endif; ?>

    <form method="post" novalidate>
      <?= sh_csrf_field() ?>
      <input type="hidden" name="redirect" value="<?= e($redirect) ?>">
      <div class="sh-field">
        <label class="sh-field__label" for="lg-email">Email address</label>
        <input class="sh-input" id="lg-email" type="email" name="email" value="<?= e($email) ?>" required autocomplete="email">
      </div>
      <div class="sh-field">
        <label class="sh-field__label" for="lg-pass">Password</label>
        <input class="sh-input" id="lg-pass" type="password" name="password" required autocomplete="current-password">
      </div>
      <button class="sh-btn sh-btn--lg sh-btn--block" type="submit"><?= sh_icon('user', 16) ?> Sign in</button>
    </form>

    <p class="sh-auth__foot">New to <?= e(sh_setting('site_name', 'ShopHaat')) ?>?
      <a href="<?= e(sh_url('register.php' . ($redirect !== '' ? '?redirect=' . urlencode($redirect) : ''))) ?>">Create an account</a>
    </p>
  </div>
</div>
<?php require_once SH_ROOT . '/includes/footer.php'; ?>
