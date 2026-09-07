<?php
/**
 * Customer sign-in — phone number + one-time code (OTP).
 */
declare(strict_types=1);
require_once __DIR__ . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/auth.php';

sh_session_start();
$redirect = sh_get('redirect');
if (sh_user() !== null) { sh_redirect(sh_safe_redirect($redirect, 'account.php')); }

$error = '';
$phone = '';

// OTP modal state (populated after a successful "Continue" request).
$otpSent = false;
$otpCanonical = '';
$otpMasked = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sh_csrf_require();
    $phone = sh_post('phone');
    $redirect = sh_post('redirect', $redirect);

    if (sh_login_throttled('user_login')) {
        $error = 'Too many failed attempts. Please wait a few minutes and try again.';
    } elseif ($phone === '') {
        $error = 'Enter your mobile number to continue.';
    } else {
        $phone = sh_phone_normalize($phone);
        if ($phone === '') {
            $error = 'Please enter a valid mobile number (e.g. 017XXXXXXXX).';
        } else {
            try {
                $u = sh_find_user_by_phone($phone);
                if ($u === null) {
                    sh_login_fail('user_login');
                    sh_security_log('login_failed', null, ['phone' => sh_phone_mask($phone), 'reason' => 'unknown']);
                    $error = 'No account was found with this phone number. Please sign up instead.';
                } elseif ($u['status'] !== 'active') {
                    sh_security_log('login_failed', (int)$u['id'], ['phone' => sh_phone_mask($phone), 'reason' => 'blocked']);
                    $error = 'This account has been blocked. Please contact customer support.';
                } else {
                    $target = sh_safe_redirect($redirect, 'account.php');
                    $uid = (int)$u['id'];
                    $canonical = sh_phone_normalize((string)$u['phone']);

                    if (!sh_otp_require_login()) {
                        // Fallback: OTP is turned off, so sign in directly.
                        sh_login_reset('user_login');
                        sh_login_user($uid);
                        sh_security_log('login_success', $uid, ['phone' => sh_phone_mask($canonical)]);
                        sh_flash('success', 'Welcome back.');
                        sh_redirect($target);
                    }

                    // Already mid-verification for this account? Re-open the modal
                    // without sending a duplicate SMS.
                    $pend = sh_pending_login();
                    if ($pend !== null && sh_phone_normalize((string)($pend['phone'] ?? '')) === $canonical) {
                        sh_login_reset('user_login');
                        $otpSent = true;
                        $otpCanonical = $canonical;
                        $otpMasked = sh_phone_mask_login($canonical);
                    } else {
                        sh_pending_login_save($uid, $canonical, $target);
                        $issue = sh_otp_issue($canonical, 'login', $uid);
                        if ($issue['ok']) {
                            sh_login_reset('user_login');
                            $otpSent = true;
                            $otpCanonical = $canonical;
                            $otpMasked = sh_phone_mask_login($canonical);
                        } else {
                            sh_pending_login_clear();
                            $error = $issue['error'] ?? 'We could not send a verification code. Please try again.';
                        }
                    }
                }
            } catch (Throwable $e) {
                sh_log_exception($e, 'login');
                $error = 'Sign in is temporarily unavailable. Please try again shortly.';
            }
        }
    }
}

$pageTitle = 'Sign In';
$pageDescription = 'Sign in with your mobile number to track orders and manage your profile.';
require_once SH_ROOT . '/includes/header.php';
?>
<div class="sh-wrap">
  <div class="sh-auth">
    <div class="sh-auth__mark"><?= sh_icon('smartphone', 26) ?></div>
    <h1 class="sh-auth__title">Sign in</h1>
    <p class="sh-auth__sub">Enter your mobile number. We will send you a one-time code to verify it.</p>

    <?php if ($error): ?>
      <div class="sh-alert sh-alert--error"><?= sh_icon('x-circle', 16) ?><span><?= e($error) ?></span></div>
    <?php endif; ?>

    <form method="post" novalidate>
      <?= sh_csrf_field() ?>
      <input type="hidden" name="redirect" value="<?= e($redirect) ?>">
      <div class="sh-field">
        <label class="sh-field__label" for="lg-phone">Mobile number</label>
        <input class="sh-input sh-input--phone" id="lg-phone" type="tel" inputmode="tel" name="phone"
               value="<?= e($phone !== '' ? sh_phone_display($phone) : '') ?>" required
               placeholder="01XXXXXXXXX" autocomplete="tel" autofocus>
      </div>
      <button class="sh-btn sh-btn--lg sh-btn--block" type="submit"><?= sh_icon('arrow-right', 16) ?> Continue</button>
    </form>

    <p class="sh-auth__foot">New to <?= e(sh_setting('site_name', 'ShopHaat')) ?>?
      <a href="<?= e(sh_url('register.php' . ($redirect !== '' ? '?redirect=' . urlencode($redirect) : ''))) ?>">Sign up</a>
    </p>
  </div>
</div>

<?php if ($otpSent): ?>
  <?php
  $otpPurpose = 'login';
  $otpVerifyLabel = 'Verify & Login';
  ?>
  <noscript>
    <div class="sh-wrap"><div class="sh-auth" style="text-align:center">
      <p>A verification code was sent to <strong><?= e($otpMasked) ?></strong>.
        <a href="<?= e(sh_url('otp.php?purpose=login')) ?>">Enter the code here</a>.</p>
    </div></div>
  </noscript>
  <?php require SH_ROOT . '/includes/otp-modal.php'; ?>
<?php endif; ?>
<?php require_once SH_ROOT . '/includes/footer.php'; ?>
