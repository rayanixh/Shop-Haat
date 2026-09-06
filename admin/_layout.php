<?php
/**
 * Admin shell (sidebar + topbar). Include after sh_require_admin().
 * Expects $adminPage and $adminTitle.
 */
if (!defined('SH_BOOTSTRAPPED')) { require_once dirname(__DIR__) . '/config/config.php'; }
require_once SH_ROOT . '/includes/admin-auth.php';

$admin = sh_admin();
$adminPage = $adminPage ?? '';
$adminTitle = $adminTitle ?? 'Dashboard';

// Live counters for the sidebar badges
$badgePayments = 0; $badgeOrders = 0; $badgeParcels = 0;
try {
    $badgePayments = (int)sh_val('SELECT COUNT(*) FROM payments WHERE status = \'pending\' AND transaction_id IS NOT NULL', [], 0);
    $badgeOrders = (int)sh_val('SELECT COUNT(*) FROM orders WHERE status IN (\'payment_submitted\',\'processing\')', [], 0);
    // Parcels currently out with the courier (table appears once the courier
    // section is first opened on an existing install).
    if ((int)sh_val("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'shipments'", [], 0) > 0) {
        $badgeParcels = (int)sh_val('SELECT COUNT(*) FROM shipments WHERE status IN (\'booked\',\'picked_up\',\'in_transit\',\'out_for_delivery\',\'failed_attempt\')', [], 0);
    }
} catch (Throwable $e) { sh_log_exception($e, 'admin-badges'); }

$nav = [
    'Overview' => [
        ['dashboard', 'dashboard.php', 'layout', 'Dashboard', 0],
    ],
    'Storefront' => [
        ['homepage', 'homepage.php', 'image', 'Homepage Images', 0],
    ],
    'AI Auto Work' => [
        ['ai',          'ai/index.php',     'cpu',      'AI Dashboard', 0],
        ['ai_product',  'ai/products.php',  'box',      'Product AI', 0],
        ['ai_bulk',     'ai/bulk.php',      'list',     'Bulk AI Generator', 0],
        ['ai_blog',     'ai/blog.php',      'file',     'Blog AI', 0],
        ['ai_category', 'ai/category.php',  'grid',     'Category AI', 0],
        ['ai_seo',      'ai/seo.php',       'search',   'SEO AI', 0],
        ['ai_image',    'ai/image.php',     'image',    'Image AI', 0],
        ['ai_history',  'ai/history.php',   'clock',    'AI History', 0],
        ['ai_settings', 'ai/settings.php',  'settings', 'AI Settings', 0],
    ],
    'Catalogue' => [
        ['products',   'products.php',   'box',   'Products', 0],
        ['categories', 'categories.php', 'grid',  'Categories', 0],
        ['brands',     'brands.php',     'tag',   'Brands', 0],
        ['codes',      'digital-codes.php', 'key', 'Digital Codes', 0],
        ['coupons',    'coupons.php',    'tag',   'Coupons', 0],
    ],
    'Sales' => [
        ['orders',    'orders.php',    'package',     'Orders', $badgeOrders],
        ['payments',  'payments.php',  'credit-card', 'Payments', $badgePayments],
        ['gateways',  'payment-gateways.php', 'shield', 'Payment Gateways', 0],
        ['methods',   'payment-methods.php',  'dollar', 'Payment Methods', 0],
        ['customers', 'customers.php', 'users',       'Customers', 0],
    ],
    'Shipping' => [
        ['parcels',   'parcels.php',   'package', 'Parcels', $badgeParcels],
        ['couriers',  'couriers.php',  'truck',   'Couriers', 0],
    ],
    'Messaging' => [
        ['telegram',  'telegram.php',  'send',    'Telegram', 0],
        ['whatsapp',  'whatsapp.php',  'message', 'WhatsApp', 0],
        ['messenger', 'messenger.php', 'message', 'Messenger', 0],
        ['email',     'email.php',     'mail',    'Email / SMTP', 0],
        ['notifications', 'notifications.php', 'bell', 'Notifications', 0],
    ],
    'System' => [
        ['settings', 'settings.php', 'settings', 'Settings', 0],
        ['logs',     'logs.php',     'list',     'Error Logs', 0],
    ],
];
$adminFlash = sh_flash_pull();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= e($adminTitle) ?> — Admin | <?= e(sh_setting('site_name', 'ShopHaat')) ?></title>
<link rel="icon" href="data:image/svg+xml,<?= rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32"><rect width="32" height="32" rx="7" fill="#151b2b"/><text x="16" y="22" font-family="Arial" font-size="14" font-weight="bold" fill="#e8501b" text-anchor="middle">A</text></svg>') ?>">
<link rel="stylesheet" href="<?= e(sh_asset('assets/css/app.css')) ?>">
<script>window.SH_BASE = <?= json_encode(sh_base_url() . '/') ?>; window.SH_CSRF = <?= json_encode(sh_csrf_token()) ?>;</script>
</head>
<body>
<div class="sh-admin">
  <aside class="sh-admin-sidebar">
    <a class="sh-admin-sidebar__brand" href="<?= e(sh_url('admin/dashboard.php')) ?>">
      <span class="sh-brand__mark sh-brand__mark--sm">SH</span> <?= e(sh_setting('site_name', 'ShopHaat')) ?>
    </a>
    <?php foreach ($nav as $group => $links): ?>
      <p class="sh-admin-sidebar__group"><?= e($group) ?></p>
      <?php foreach ($links as [$key, $href, $icon, $label, $badge]): ?>
        <a class="sh-admin-sidebar__link <?= $adminPage === $key ? 'sh-admin-sidebar__link--on' : '' ?>"
           href="<?= e(sh_url('admin/' . $href)) ?>">
          <?= sh_icon($icon, 17) ?><span><?= e($label) ?></span>
          <?php if ($badge > 0): ?><em><?= (int)$badge ?></em><?php endif; ?>
        </a>
      <?php endforeach; ?>
    <?php endforeach; ?>
    <p class="sh-admin-sidebar__group">Account</p>
    <a class="sh-admin-sidebar__link" href="<?= e(sh_url('index.php')) ?>" target="_blank" rel="noopener">
      <?= sh_icon('external', 17) ?><span>View store</span></a>
    <a class="sh-admin-sidebar__link" href="<?= e(sh_url('admin/logout.php')) ?>">
      <?= sh_icon('log-out', 17) ?><span>Sign out</span></a>
    <div style="height:24px"></div>
  </aside>
  <button class="sh-admin-scrim" type="button" hidden aria-label="Close menu"></button>

  <div class="sh-admin-main">
    <header class="sh-admin-top">
      <button class="sh-admin-burger" type="button" data-admin-burger aria-label="Toggle menu"><?= sh_icon('menu', 21) ?></button>
      <h1 class="sh-admin-top__title"><?= e($adminTitle) ?></h1>
      <div class="sh-admin-top__user">
        <?= sh_icon('user', 16) ?>
        <span><?= e($admin['name'] ?? 'Admin') ?></span>
      </div>
    </header>
    <div class="sh-admin-body">
      <?php foreach ($adminFlash as $f): ?>
        <div class="sh-alert sh-alert--<?= e($f['type']) ?>">
          <?= sh_icon($f['type'] === 'success' ? 'check-circle' : ($f['type'] === 'error' ? 'x-circle' : 'info'), 17) ?>
          <span><?= e($f['message']) ?></span>
        </div>
      <?php endforeach; ?>
