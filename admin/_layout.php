<?php
/**
 * Admin shell (sidebar + topbar). Include after sh_require_admin().
 * Expects $adminPage and $adminTitle.
 */
if (!defined('SH_BOOTSTRAPPED')) { require_once dirname(__DIR__) . '/config/config.php'; }
require_once SH_ROOT . '/includes/admin-auth.php';
require_once SH_ROOT . '/includes/admin-tools.php';

$admin = sh_admin();
sh_admin_schema_ensure();
$badgeInbox = sh_admin_notifications_unread_count();
$inboxItems = $badgeInbox > 0 ? sh_admin_notifications(6, true) : [];
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
        ['inbox',     'inbox.php',     'bell',   'Notifications', $badgeInbox],
        ['search',    'search.php',    'search', 'Global Search', 0],
    ],
    'Catalogue' => [
        ['products',   'products.php',      'box',  'Products', 0],
        ['stock',      'stock.php',         'alert', 'Inventory / Low Stock', 0],
        ['categories', 'categories.php',    'grid', 'Categories', 0],
        ['brands',     'brands.php',        'tag',  'Brands', 0],
        ['codes',      'digital-codes.php', 'key',  'Digital Codes', 0],
        ['coupons',    'coupons.php',       'tag',  'Coupons', 0],
    ],
    'Sales' => [
        ['orders',    'orders.php',    'package',     'Orders', $badgeOrders],
        ['payments',  'payments.php',  'credit-card', 'Payments', $badgePayments],
        ['customers', 'customers.php', 'users',       'Customers', 0],
        ['verification', 'verification.php', 'shield',  'Customer Verification', 0],
    ],
    'Storefront' => [
        ['homepage', 'homepage.php', 'image',    'Homepage Images', 0],
        ['theme',    'theme.php',    'settings', 'Theme Customization', 0],
    ],
    'Settings' => [
        ['settings',     'settings.php',         'settings',    'General Settings', 0],
        ['transition',   'transition.php',       'zap',         'Page Transition', 0],
        ['google_login', 'google-login.php',     'log-in',      'Google Login', 0],
        ['methods',      'payment-methods.php',  'dollar',      'Payment Methods', 0],
        ['gateways',     'payment-gateways.php', 'shield',      'Payment Gateways', 0],
        ['couriers',     'couriers.php',         'truck',       'Courier / Shipping', 0],
        ['parcels',      'parcels.php',          'package',     'Parcels', $badgeParcels],
        ['telegram',     'telegram.php',         'send',        'Telegram', 0],
        ['whatsapp',     'whatsapp.php',         'message',     'WhatsApp', 0],
        ['messenger',    'messenger.php',        'message',     'Messenger', 0],
        ['email',        'email.php',            'mail',        'SMTP / Email', 0],
        ['notifications','notifications.php',    'bell',        'Notifications', 0],
    ],
    'Security' => [
        ['security',      'security.php',      'smartphone', 'SMS / OTP', 0],
        ['firebase',      'firebase.php',      'mail',       'Email Verification', 0],
        ['otp_logs',      'otp-logs.php',      'message',    'OTP Logs', 0],
        ['security_logs', 'security-logs.php', 'lock',       'Security Logs', 0],
        ['audit',         'audit-log.php',     'file-text',  'Admin Audit Log', 0],
        ['backups',       'backups.php',       'database',   'Database Backup', 0],
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
        ['ai_providers','ai/providers.php', 'cpu',      'AI Providers', 0],
        ['ai_models',   'ai/models.php',    'list',     'AI Models', 0],
        ['ai_settings', 'ai/settings.php',  'settings', 'AI Settings', 0],
    ],
    'System' => [
        ['logs', 'logs.php', 'list', 'Error Logs', 0],
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
<?php require_once SH_ROOT . '/includes/transition.php'; $shTransition = sh_transition_config(); echo sh_transition_head($shTransition); ?>
</head>
<body<?= sh_transition_body_attrs($shTransition) ?>>
<?= sh_transition_overlay($shTransition) ?>
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
      <form class="sh-admin-search" method="get" action="<?= e(sh_url('admin/search.php')) ?>" role="search">
        <?= sh_icon('search', 15) ?>
        <input type="search" name="q" value="<?= e($adminPage === 'search' ? sh_get('q') : '') ?>" placeholder="Search orders, customers, products, TrxID…" aria-label="Global search" autocomplete="off">
      </form>
      <div class="sh-admin-bell" data-admin-bell>
        <a class="sh-admin-bell__btn" href="<?= e(sh_url('admin/inbox.php')) ?>" data-admin-bell-toggle aria-label="Notifications" aria-haspopup="true" aria-expanded="false">
          <?= sh_icon('bell', 19) ?><?php if ($badgeInbox > 0): ?><em><?= $badgeInbox > 99 ? '99+' : (int)$badgeInbox ?></em><?php endif; ?>
        </a>
        <div class="sh-admin-bell__menu" hidden>
          <div class="sh-admin-bell__head"><strong>Notifications</strong>
            <?php if ($badgeInbox > 0): ?><form method="post" action="<?= e(sh_url('admin/inbox.php')) ?>"><?= sh_csrf_field() ?><input type="hidden" name="form" value="read_all"><input type="hidden" name="back" value="1"><button type="submit">Mark all as read</button></form><?php endif; ?>
          </div>
          <?php if (!$inboxItems): ?><p class="sh-admin-bell__empty">You're all caught up.</p>
          <?php else: foreach ($inboxItems as $n): ?>
            <a class="sh-admin-bell__item" href="<?= e(sh_url('admin/inbox.php?open=' . (int)$n['id'])) ?>">
              <span class="sh-admin-bell__icon"><?= sh_icon(sh_admin_notification_icon((string)$n['type']), 15) ?></span>
              <span class="sh-admin-bell__text"><strong><?= e($n['title']) ?></strong><?php if ($n['body']): ?><small><?= e($n['body']) ?></small><?php endif; ?><small><?= e(sh_time_ago($n['created_at'])) ?></small></span>
            </a>
          <?php endforeach; endif; ?>
          <a class="sh-admin-bell__all" href="<?= e(sh_url('admin/inbox.php')) ?>">View all notifications</a>
        </div>
      </div>
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
