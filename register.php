<?php
/**
 * Customer registration — full name + phone number + one-time code (OTP).
 */
declare(strict_types=1);
require_once __DIR__ . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/auth.php';

sh_session_start();
$redirect = sh_get('redirect');
if (sh_user() !== null) { sh_redirect(sh_safe_redirect($redirect, 'account.php')); }

$errors = [];
$form = ['name' => '', 'phone' => ''];
foreach ($form as $k => $v) { if (isset($_POST[$k])) { $form[$k] = trim((string)$_POST[$k]); } }

// OTP modal state (populated after a successful "Create account" request).
$otpSent = false;
$otpCanonical = '';
$otpMasked = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sh_csrf_require();
    $redirect = sh_post('redirect', $redirect);

    $v = new ShValidator($_POST);
    $v->required('name', 'Full name')->minLen('name', 2, 'Full name')->maxLen('name', 110, 'Full name')
      ->required('phone', 'Phone number')->phone('phone', 'Phone number');
    if (empty($_POST['terms'])) { $v->custom('terms', false, 'You must accept the terms and conditions.'); }
    $errors = $v->errors();

    if (!$errors) {
        $phone = sh_phone_normalize($form['phone']);
        if ($phone === '') {
            $errors['phone'] = 'Please enter a valid mobile number.';
        } else {
            try {
                $target = sh_safe_redirect($redirect, 'account.php');
                $exists = sh_find_user_by_phone($phone);

                if (!sh_otp_require_signup()) {
                    // Fallback: OTP is off, so create the account immediately.
                    if ($exists !== null) {
                        $errors['phone'] = 'An account already exists with this phone number. Please log in instead.';
                    } else {
                        $uid = sh_insert('users', [
                            'name'          => $form['name'],
                            'email'         => sh_synthetic_email($phone),
                            'phone'         => $phone,
                            'password_hash' => '',
                            'status'        => 'active',
                        ]);
                        sh_login_user($uid);
                        sh_security_log('account_created', $uid, ['phone' => sh_phone_mask($phone)]);
                        sh_flash('success', 'Your account has been created. Welcome to ' . sh_setting('site_name', 'ShopHaat') . '.');
                        sh_redirect($target);
                    }
                } elseif ($exists !== null) {
                    $errors['phone'] = 'An account already exists with this phone number. Please log in instead.';
                } else {
                    // Already mid-verification for this number? Re-open the modal
                    // without sending a duplicate SMS.
                    $pend = sh_pending_signup();
                    if ($pend !== null && sh_phone_normalize((string)($pend['phone'] ?? '')) === $phone) {
                        $otpSent = true;
                        $otpCanonical = $phone;
                        $otpMasked = sh_phone_mask_login($phone);
                    } else {
                        sh_pending_signup_save($form['name'], $phone, $target);
                        $issue = sh_otp_issue($phone, 'signup', null);
                        if ($issue['ok']) {
                            $otpSent = true;
                            $otpCanonical = $phone;
                            $otpMasked = sh_phone_mask_login($phone);
                        } else {
                            sh_pending_signup_clear();
                            $errors['general'] = $issue['error'] ?? 'We could not send a verification code. Please try again.';
                        }
                    }
                }
            } catch (Throwable $e) {
                sh_log_exception($e, 'register');
                $errors['general'] = 'Your account could not be created. Please try again.';
            }
        }
    }
}

$pageTitle = 'Create Account';
$pageDescription = 'Create an account with your mobile number to order faster and track your purchases.';
require_once SH_ROOT . '/includes/header.php';
?>
<div class="sh-wrap">
  <div class="sh-auth">
    <div class="sh-auth__mark"><?= sh_icon('user-plus', 26) ?></div>
    <h1 class="sh-auth__title">Create your account</h1>
    <p class="sh-auth__sub">Just your name and mobile number — we will verify it with a one-time code.</p>

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
        <label class="sh-field__label" for="rg-phone">Mobile number <span class="sh-field__req">*</span></label>
        <input class="sh-input sh-input--phone <?= isset($errors['phone']) ? 'sh-input--error' : '' ?>" id="rg-phone" name="phone"
               value="<?= e($form['phone'] !== '' ? sh_phone_display($form['phone']) : '') ?>" required
               placeholder="01XXXXXXXXX" autocomplete="tel">
      </div>
      <label class="sh-check" style="margin-bottom:14px">
        <input type="checkbox" name="terms" value="1" <?= !empty($_POST['terms']) ? 'checked' : '' ?>>
        <span>I agree to the <a href="<?= e(sh_url('page.php?p=terms')) ?>" style="color:var(--sh-brand)">terms and conditions</a>
          and <a href="<?= e(sh_url('page.php?p=privacy')) ?>" style="color:var(--sh-brand)">privacy policy</a>.</span>
      </label>
      <button class="sh-btn sh-btn--lg sh-btn--block" type="submit"><?= sh_icon('user-plus', 16) ?> Create account</button>
    </form>

    <p class="sh-auth__foot">Already have an account?
      <a href="<?= e(sh_url('login.php' . ($redirect !== '' ? '?redirect=' . urlencode($redirect) : ''))) ?>">Sign in</a>
    </p>
  </div>
</div>

<?php if ($otpSent): ?>
  <?php
  $otpPurpose = 'signup';
  $otpVerifyLabel = 'Verify & Create Account';
  ?>
  <noscript>
    <div class="sh-wrap"><div class="sh-auth" style="text-align:center">
      <p>A verification code was sent to <strong><?= e($otpMasked) ?></strong>.
        <a href="<?= e(sh_url('otp.php?purpose=signup')) ?>">Enter the code here</a>.</p>
    </div></div>
  </noscript>
  <?php require SH_ROOT . '/includes/otp-modal.php'; ?>
<?php endif; ?>
<?php require_once SH_ROOT . '/includes/footer.php'; ?>
