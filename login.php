<?php
declare(strict_types=1);
require_once __DIR__ . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/auth.php';

sh_session_start();
$redirect = sh_get('redirect');
if (sh_user() !== null) { sh_redirect(sh_safe_redirect($redirect, 'account.php')); }

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
                $uid = (int)$u['id'];
                $target = sh_safe_redirect($redirect, 'account.php');

                // OTP before sign-in: the code is only ever sent after a correct
                // password, and no session is established until it is verified.
                if (sh_otp_rule('otp_before_login')) {
                    $ru = sh_one('SELECT phone, phone_verified FROM users WHERE id = ? LIMIT 1', [$uid]);
                    $phone = sh_phone_normalize((string)($ru['phone'] ?? ''));
                    if ($phone !== '' && (int)($ru['phone_verified'] ?? 0) !== 1) {
                        sh_pending_login_save($uid, $phone, $target);
                        $issue = sh_otp_issue($phone, 'login', $uid);
                        if ($issue['ok']) {
                            sh_security_log('login_success', $uid, ['phone' => sh_phone_mask($phone), 'otp_pending' => 1]);
                            sh_redirect('otp.php?purpose=login');
                        }
                        $error = $issue['error'] ?? 'We could not send a verification code. Please try again.';
                        sh_security_log('login_success', $uid, ['phone' => sh_phone_mask($phone), 'otp_failed' => 1]);
                    } else {
                        sh_login_user($uid);
                        sh_security_log('login_success', $uid);
                        sh_flash('success', 'Welcome back.');
                        sh_redirect($target);
                    }
                } else {
                    sh_login_user($uid);
                    sh_security_log('login_success', $uid);
                    sh_flash('success', 'Welcome back.');
                    sh_redirect($target);
                }
            }
            if ($u !== null && $u['status'] !== 'active') {
                sh_security_log('login_failed', (int)$u['id'], ['reason' => 'blocked']);
                $error = 'This account has been blocked. Please contact customer support.';
            } else {
                sh_login_fail('user_login');
                sh_security_log('login_failed', null, ['email' => mb_substr($email, 0, 3) . '***']);
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
