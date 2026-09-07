<?php
/**
 * Phone verification page (OTP entry). Used by registration, sign-in,
 * account phone verification, phone change and checkout.
 */
declare(strict_types=1);
require_once __DIR__ . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/auth.php';

sh_session_start();

if (!sh_otp_enabled()) {
    sh_redirect('index.php');
}

$purpose = sh_get('purpose');
$user = sh_user();

$info = [
    'register'     => ['title' => 'Verify your phone number', 'lead' => 'We sent a verification code to the number you provided. Enter it below to finish creating your account.', 'back' => 'register.php'],
    'login'        => ['title' => 'Verify your phone number', 'lead' => 'Enter the code we sent you to finish signing in.', 'back' => 'login.php'],
    'account'      => ['title' => 'Verify your phone number', 'lead' => 'We will send a code to your phone. Enter it below to mark your number as verified.', 'back' => 'account.php'],
    'phone_change' => ['title' => 'Verify your new number', 'lead' => 'Enter the code we sent to your new number. Your current number stays active until this is verified.', 'back' => 'account.php'],
    'checkout'     => ['title' => 'Verify to continue checkout', 'lead' => 'To help prevent fake orders, please verify your mobile number before placing your order.', 'back' => 'checkout.php'],
];
if (!isset($info[$purpose])) {
    sh_redirect('index.php');
}

// Resolve the phone and whether it may be edited on this page.
$phone = '';
$phoneEditable = false;
$alreadySent = false;
switch ($purpose) {
    case 'register':
        $pend = sh_pending_registration();
        if ($pend === null) { sh_redirect('register.php'); }
        $phone = $pend['phone'];
        $alreadySent = true;
        break;
    case 'login':
        $pend = sh_pending_login();
        if ($pend === null) { sh_redirect('login.php'); }
        $phone = $pend['phone'];
        $alreadySent = true;
        break;
    case 'account':
        if ($user === null) { sh_redirect('login.php?redirect=' . urlencode('account.php')); }
        $phone = (string)$user['phone'];
        break;
    case 'phone_change':
        if ($user === null) { sh_redirect('login.php?redirect=' . urlencode('account.php')); }
        $change = sh_pending_phone_change();
        if ($change === null) { sh_redirect('account.php'); }
        $phone = $change;
        $alreadySent = true;
        break;
    case 'checkout':
        if ($user !== null) {
            $phone = (string)$user['phone'];
        } else {
            $phone = sh_checkout_otp_phone();
            $phoneEditable = true; // guest enters / confirms their number here
        }
        break;
}

$display = sh_phone_display($phone);
$pageTitle = $info[$purpose]['title'];
$pageDescription = 'Enter the verification code sent to your phone.';
require_once SH_ROOT . '/includes/header.php';
?>
<div class="sh-wrap">
  <div class="sh-auth sh-otp" data-otp
       data-purpose="<?= e($purpose) ?>"
       data-phone="<?= e($phone) ?>"
       data-length="<?= (int)sh_otp_length() ?>"
       data-expires="<?= (int)sh_otp_expiry_seconds() ?>"
       data-cooldown="<?= (int)sh_otp_resend_cooldown() ?>"
       data-already-sent="<?= $alreadySent ? '1' : '0' ?>">
    <div class="sh-otp__icon"><?= sh_icon('shield', 24) ?></div>
    <h1 class="sh-auth__title"><?= e($pageTitle) ?></h1>
    <p class="sh-auth__sub"><?= e($info[$purpose]['lead']) ?></p>

    <?php if ($phoneEditable): ?>
      <div class="sh-field sh-otp__phone">
        <label class="sh-field__label" for="otp-phone">Mobile number</label>
        <input class="sh-input" id="otp-phone" type="tel" inputmode="tel" value="<?= e(sh_phone_display($phone)) ?>"
               placeholder="01XXXXXXXXX" autocomplete="tel">
      </div>
    <?php else: ?>
      <p class="sh-otp__phone-label">
        Code sent to <strong><?= e($display !== '' ? $display : 'your phone') ?></strong>
      </p>
    <?php endif; ?>

    <div class="sh-otp__code" data-otp-code-stage <?= $alreadySent ? '' : 'hidden' ?>>
      <div class="sh-field">
        <label class="sh-field__label" for="otp-code">Verification code</label>
        <input class="sh-input sh-otp__input" id="otp-code" type="text" inputmode="numeric" autocomplete="one-time-code"
               maxlength="<?= (int)sh_otp_length() ?>" placeholder="<?= str_repeat('•', (int)sh_otp_length()) ?>"
               pattern="\d*" autofocus>
        <p class="sh-field__hint" data-otp-expiry>Code expires after <?= (int)ceil(sh_otp_expiry_seconds() / 60) ?> minutes.</p>
      </div>
      <button class="sh-btn sh-btn--lg sh-btn--block" type="button" data-otp-verify>
        <?= sh_icon('check-circle', 16) ?> Verify code
      </button>
    </div>

    <button class="sh-btn sh-btn--lg sh-btn--block <?= $alreadySent ? 'sh-btn--ghost' : '' ?>" type="button" data-otp-send>
      <?= sh_icon('send', 16) ?><span data-otp-send-label><?= $alreadySent ? 'Resend code' : 'Send code' ?></span>
    </button>

    <div class="sh-alert sh-alert--error" data-otp-error hidden>
      <?= sh_icon('x-circle', 16) ?><span></span>
    </div>

    <p class="sh-auth__foot">
      <a href="<?= e(sh_url($info[$purpose]['back'])) ?>"><?= sh_icon('chevron-left', 14) ?> Go back</a>
    </p>
  </div>
</div>
<?php require_once SH_ROOT . '/includes/footer.php'; ?>
