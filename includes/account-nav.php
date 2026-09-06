<?php
/** Shared customer account sidebar. Expects $accountPage. */
require_once __DIR__ . '/auth.php';
$accountPage = $accountPage ?? '';
$u = sh_user();
$links = [
    'account'   => ['account.php',   'user',    'My Profile'],
    'orders'    => ['orders.php',    'package', 'My Orders'],
    'digital'   => ['digital.php',   'key',     'Digital Purchases'],
    'wishlist'  => ['wishlist.php',  'heart',   'Wishlist'],
    'addresses' => ['addresses.php', 'map-pin', 'Addresses'],
    'password'  => ['password.php',  'lock',    'Change Password'],
];
?>
<nav class="sh-account__nav" aria-label="Account">
  <?php if ($u): ?>
    <div class="sh-account__user">
      <span class="sh-account__avatar"><?= e(mb_strtoupper(mb_substr($u['name'], 0, 1))) ?></span>
      <div style="min-width:0">
        <p class="sh-account__name"><?= e($u['name']) ?></p>
        <p class="sh-account__email"><?= e($u['email']) ?></p>
      </div>
    </div>
  <?php endif; ?>
  <?php foreach ($links as $key => [$href, $icon, $label]): ?>
    <a class="sh-account__link <?= $accountPage === $key ? 'sh-account__link--on' : '' ?>" href="<?= e(sh_url($href)) ?>">
      <?= sh_icon($icon, 17) ?><span><?= e($label) ?></span>
    </a>
  <?php endforeach; ?>
  <a class="sh-account__link" href="<?= e(sh_url('logout.php')) ?>"><?= sh_icon('log-out', 17) ?><span>Sign out</span></a>
</nav>
