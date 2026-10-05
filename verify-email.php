<?php
/**
 * Landing page for Firebase email verification.
 *  - Default Firebase flow: the hosted action page verifies the code, then
 *    "Continue" brings the customer here → we confirm the state with Firebase.
 *  - Custom action URL flow: Firebase links straight here with
 *    ?mode=verifyEmail&oobCode=… → we hand the code to Firebase for validation.
 */
declare(strict_types=1);
require_once __DIR__ . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/auth.php';
require_once SH_ROOT . '/includes/firebase.php';

sh_session_start();
$user = sh_user();
$state = 'info'; $title = 'Email verification'; $msg = '';

$mode = (string)($_GET['mode'] ?? '');
$code = trim((string)($_GET['oobCode'] ?? ''));

if (!sh_fb_enabled()) {
    $msg = 'Email verification is not available right now.';
} elseif ($mode === 'verifyEmail' && $code !== '') {
    $r = sh_fb_apply_oob_code($code);
    if ($r['ok']) { $state = 'ok'; $title = 'Email verified'; $msg = 'Thank you — ' . $r['email'] . ' is now verified.'; }
    else { $state = 'error'; $title = 'Verification failed'; $msg = (string)$r['error']; }
} elseif ($user !== null && sh_fb_applies($user)) {
    $r = sh_fb_sync_verified($user);
    if (!empty($r['verified'])) { $state = 'ok'; $title = 'Email verified'; $msg = 'Thank you — your email address is now verified.'; }
    elseif ($r['ok']) { $state = 'warn'; $title = 'Not verified yet'; $msg = 'We could not see a verified status yet. Please open the link in the verification email, then try again.'; }
    else { $state = 'error'; $title = 'Verification check failed'; $msg = (string)($r['error'] ?? 'Please try again shortly.'); }
} elseif ($mode !== '' && $code === '') {
    $state = 'error'; $title = 'Invalid link'; $msg = 'This verification link is incomplete. Please request a new one from your profile.';
} else {
    $msg = 'Sign in to confirm your email verification status.';
}

$pageTitle = $title;
require_once SH_ROOT . '/includes/header.php';
$icon = $state === 'ok' ? 'check-circle' : ($state === 'error' ? 'x-circle' : ($state === 'warn' ? 'alert' : 'mail'));
?>
<div class="sh-wrap">
  <div class="sh-section" style="max-width:520px;margin:28px auto;text-align:center">
    <span class="sh-stat__icon" style="margin:0 auto 12px;width:52px;height:52px;border-radius:50%;display:grid;place-items:center;background:<?= $state === 'ok' ? 'var(--sh-ok-soft);color:var(--sh-ok)' : ($state === 'error' ? 'var(--sh-bad-soft);color:var(--sh-bad)' : 'var(--sh-warn-soft);color:var(--sh-warn)') ?>"><?= sh_icon($icon, 26) ?></span>
    <h1 class="sh-section__title" style="justify-content:center"><?= e($title) ?></h1>
    <p style="color:var(--sh-ink-2);margin:10px 0 18px;line-height:1.7"><?= e($msg) ?></p>
    <div style="display:flex;gap:9px;justify-content:center;flex-wrap:wrap">
      <?php if ($user !== null): ?>
        <a class="sh-btn" href="<?= e(sh_url('account.php')) ?>#account-verification"><?= sh_icon('user', 15) ?> Go to my profile</a>
        <?php if ($state === 'warn'): ?><a class="sh-btn sh-btn--ghost" href="<?= e(sh_url('verify-email.php')) ?>"><?= sh_icon('refresh', 15) ?> Check again</a><?php endif; ?>
      <?php else: ?>
        <a class="sh-btn" href="<?= e(sh_url('login.php?redirect=' . rawurlencode('account.php'))) ?>"><?= sh_icon('log-in', 15) ?> Sign in</a>
        <a class="sh-btn sh-btn--ghost" href="<?= e(sh_url('index.php')) ?>">Continue shopping</a>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php require_once SH_ROOT . '/includes/footer.php'; ?>
