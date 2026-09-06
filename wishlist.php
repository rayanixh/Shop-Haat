<?php
declare(strict_types=1);
require_once __DIR__ . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/auth.php';
require_once SH_ROOT . '/includes/catalog.php';

sh_session_start();
$user = sh_require_login();
$items = sh_all('SELECT ' . SH_PRODUCT_SELECT . ' FROM wishlist w
    JOIN products p ON p.id = w.product_id
    WHERE w.user_id = ? AND p.status = 1 ORDER BY w.id DESC', [(int)$user['id']]);
$shWishlist = array_map(static fn($r) => (int)$r['id'], $items);

$accountPage = 'wishlist';
$pageTitle = 'My Wishlist';
require_once SH_ROOT . '/includes/header.php';
?>
<div class="sh-wrap">
  <nav class="sh-breadcrumb"><a href="<?= e(sh_url('index.php')) ?>">Home</a> <?= sh_icon('chevron-right', 13) ?> <span aria-current="page">Wishlist</span></nav>
  <div class="sh-account">
    <?php require SH_ROOT . '/includes/account-nav.php'; ?>
    <div>
      <div class="sh-section">
        <div class="sh-section__head"><h1 class="sh-section__title"><?= sh_icon('heart', 18) ?> My Wishlist (<?= count($items) ?>)</h1></div>
        <?php if (!$items): ?>
          <div style="text-align:center;padding:30px 10px">
            <span class="sh-empty__icon"><?= sh_icon('heart', 24) ?></span>
            <p class="sh-empty__title" style="font-size:16px">Your wishlist is empty</p>
            <p class="sh-empty__text">Tap the heart icon on any product to save it for later.</p>
            <a class="sh-btn" href="<?= e(sh_url('products.php')) ?>">Browse products</a>
          </div>
        <?php else: ?>
          <div class="sh-product-grid sh-product-grid--4">
            <?php foreach ($items as $p) { include SH_ROOT . '/includes/product-card.php'; } ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php require_once SH_ROOT . '/includes/footer.php'; ?>
