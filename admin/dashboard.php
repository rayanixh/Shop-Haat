<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/admin-auth.php';
require_once SH_ROOT . '/includes/payment.php';

sh_session_start();
sh_require_admin();

$revenue      = (float)sh_val("SELECT COALESCE(SUM(total),0) FROM orders WHERE payment_status = 'verified'", [], 0);
$revenueMonth = (float)sh_val("SELECT COALESCE(SUM(total),0) FROM orders WHERE payment_status = 'verified' AND created_at >= ?", [date('Y-m-01 00:00:00')], 0);
$ordersTotal  = (int)sh_val('SELECT COUNT(*) FROM orders', [], 0);
$ordersToday  = (int)sh_val('SELECT COUNT(*) FROM orders WHERE created_at >= ?', [date('Y-m-d 00:00:00')], 0);
$customers    = (int)sh_val('SELECT COUNT(*) FROM users', [], 0);
$pendingPay   = (int)sh_val("SELECT COUNT(*) FROM payments WHERE status = 'pending' AND transaction_id IS NOT NULL", [], 0);
$productCount = (int)sh_val('SELECT COUNT(*) FROM products WHERE status = 1', [], 0);
$lowStock     = sh_all("SELECT id, name, slug, stock FROM products WHERE product_type = 'physical' AND status = 1 AND stock <= 5 ORDER BY stock ASC LIMIT 8");
$codesLeft    = (int)sh_val("SELECT COUNT(*) FROM product_codes WHERE status = 'available'", [], 0);

$recentOrders = sh_all(
    'SELECT o.id, o.order_number, o.customer_name, o.total, o.status, o.payment_status, o.created_at
     FROM orders o ORDER BY o.id DESC LIMIT 8'
);
$awaiting = sh_all(
    "SELECT p.id, p.order_id, p.amount, p.transaction_id, p.sender_phone, p.created_at,
            o.order_number, pm.name AS method_name
     FROM payments p
     JOIN orders o ON o.id = p.order_id
     LEFT JOIN payment_methods pm ON pm.id = p.payment_method_id
     WHERE p.status = 'pending' AND p.transaction_id IS NOT NULL
     ORDER BY p.id ASC LIMIT 6"
);
$failedNotices = (int)sh_val("SELECT COUNT(*) FROM notification_logs WHERE status = 'failed' AND created_at >= ?", [date('Y-m-d H:i:s', time() - 86400)], 0);

// SMS / OTP summary (schema is ensured by sh_require_installed()).
$otpEnabled = sh_otp_enabled();
$otpStats = ['today' => 0, 'login' => 0, 'signup' => 0, 'verified' => 0, 'failed' => 0, 'expired' => 0, 'rate_limited' => 0, 'unverified' => 0];
try {
    $d = date('Y-m-d 00:00:00');
    $otpStats['today']    = (int)sh_val("SELECT COUNT(*) FROM otp_verifications WHERE created_at >= ?", [$d], 0);
    $otpStats['login']    = (int)sh_val("SELECT COUNT(*) FROM otp_verifications WHERE purpose = 'login' AND created_at >= ?", [$d], 0);
    $otpStats['signup']   = (int)sh_val("SELECT COUNT(*) FROM otp_verifications WHERE purpose = 'signup' AND created_at >= ?", [$d], 0);
    $otpStats['verified'] = (int)sh_val("SELECT COUNT(*) FROM otp_verifications WHERE verified_at IS NOT NULL AND verified_at >= ?", [$d], 0);
    $otpStats['failed']   = (int)sh_val("SELECT COUNT(*) FROM security_logs WHERE event = 'otp_failed' AND created_at >= ?", [$d], 0);
    $otpStats['expired']  = (int)sh_val("SELECT COUNT(*) FROM security_logs WHERE event = 'otp_expired' AND created_at >= ?", [$d], 0);
    $otpStats['rate_limited'] = (int)sh_val("SELECT COUNT(*) FROM security_logs WHERE event = 'otp_rate_limited' AND created_at >= ?", [$d], 0);
    $otpStats['unverified'] = (int)sh_val("SELECT COUNT(*) FROM users WHERE phone_verified = 0 AND phone IS NOT NULL AND phone <> ''", [], 0);
} catch (Throwable $e) { sh_log_exception($e, 'dashboard-otp'); }

// 14-day sales sparkline data
$series = sh_all(
    "SELECT DATE(created_at) AS d, COUNT(*) AS c, COALESCE(SUM(total),0) AS amt
     FROM orders WHERE created_at >= ? GROUP BY DATE(created_at) ORDER BY d ASC",
    [date('Y-m-d 00:00:00', time() - 13 * 86400)]
);
$byDay = [];
foreach ($series as $r) { $byDay[$r['d']] = $r; }
$days = [];
for ($i = 13; $i >= 0; $i--) {
    $d = date('Y-m-d', time() - $i * 86400);
    $days[] = ['d' => $d, 'c' => (int)($byDay[$d]['c'] ?? 0), 'amt' => (float)($byDay[$d]['amt'] ?? 0)];
}
$maxAmt = max(1.0, max(array_column($days, 'amt')));

$adminPage = 'dashboard';
$adminTitle = 'Dashboard';
require __DIR__ . '/_layout.php';
?>
<div class="sh-stats">
  <div class="sh-stat">
    <span class="sh-stat__icon sh-stat__icon--green"><?= sh_icon('dollar', 20) ?></span>
    <div><div class="sh-stat__value"><?= e(sh_money($revenue)) ?></div>
      <div class="sh-stat__label">Verified revenue · <?= e(sh_money($revenueMonth)) ?> this month</div></div>
  </div>
  <div class="sh-stat">
    <span class="sh-stat__icon sh-stat__icon--blue"><?= sh_icon('package', 20) ?></span>
    <div><div class="sh-stat__value"><?= number_format($ordersTotal) ?></div>
      <div class="sh-stat__label">Total orders · <?= number_format($ordersToday) ?> today</div></div>
  </div>
  <div class="sh-stat">
    <span class="sh-stat__icon sh-stat__icon--amber"><?= sh_icon('clock', 20) ?></span>
    <div><div class="sh-stat__value"><?= number_format($pendingPay) ?></div>
      <div class="sh-stat__label">Payments awaiting verification</div></div>
  </div>
  <div class="sh-stat">
    <span class="sh-stat__icon"><?= sh_icon('users', 20) ?></span>
    <div><div class="sh-stat__value"><?= number_format($customers) ?></div>
      <div class="sh-stat__label">Registered customers</div></div>
  </div>
</div>

<?php if ($pendingPay > 0): ?>
  <div class="sh-alert sh-alert--warning"><?= sh_icon('alert', 17) ?>
    <span><strong><?= (int)$pendingPay ?></strong> manual payment<?= $pendingPay === 1 ? '' : 's' ?> need review.
      Orders stay on hold until you approve them.
      <a href="<?= e(sh_url('admin/payments.php?status=pending')) ?>">Review now</a>.</span></div>
<?php endif; ?>

<div class="sh-panel">
  <div class="sh-panel__head">
    <h2 class="sh-panel__title"><?= sh_icon('trending-up', 17) ?> Orders — last 14 days</h2>
    <div class="sh-panel__actions"><span class="sh-panel__note"><?= array_sum(array_column($days, 'c')) ?> orders</span></div>
  </div>
  <div class="sh-panel__body">
    <div style="display:flex;align-items:flex-end;gap:5px;height:120px">
      <?php foreach ($days as $d):
        $h = max(3, (int)round(($d['amt'] / $maxAmt) * 108)); ?>
        <div style="flex:1 1 0;display:flex;flex-direction:column;align-items:center;gap:5px;min-width:0"
             title="<?= e(date('d M', strtotime($d['d'])) . ' — ' . $d['c'] . ' orders, ' . sh_money($d['amt'])) ?>">
          <div style="width:100%;height:<?= $h ?>px;border-radius:4px 4px 0 0;background:<?= $d['amt'] > 0 ? 'var(--sh-brand)' : '#e3e6ec' ?>"></div>
          <span style="font-size:9.5px;color:var(--sh-muted);white-space:nowrap"><?= e(date('j/n', strtotime($d['d']))) ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<div style="display:grid;grid-template-columns:minmax(0,1.35fr) minmax(0,1fr);gap:14px" class="sh-dashgrid">
  <div class="sh-panel">
    <div class="sh-panel__head">
      <h2 class="sh-panel__title"><?= sh_icon('package', 17) ?> Recent orders</h2>
      <div class="sh-panel__actions"><a class="sh-btn sh-btn--sm sh-btn--ghost" href="<?= e(sh_url('admin/orders.php')) ?>">View all</a></div>
    </div>
    <div class="sh-tablewrap">
      <table class="sh-table">
        <thead><tr><th>Order</th><th>Customer</th><th>Total</th><th>Status</th></tr></thead>
        <tbody>
        <?php if (!$recentOrders): ?>
          <tr class="sh-table--empty"><td colspan="4">No orders yet.</td></tr>
        <?php else: foreach ($recentOrders as $o): ?>
          <tr>
            <td><a href="<?= e(sh_url('admin/orders.php?id=' . (int)$o['id'])) ?>" style="font-weight:600"><?= e($o['order_number']) ?></a>
              <div class="sh-table__meta"><?= e(date('d M, h:i A', strtotime($o['created_at']))) ?></div></td>
            <td><?= e($o['customer_name']) ?></td>
            <td style="font-weight:700"><?= e(sh_money($o['total'])) ?></td>
            <td><span class="sh-badge <?= e(sh_status_class($o['status'])) ?>"><?= e(sh_status_label($o['status'])) ?></span></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div style="display:flex;flex-direction:column;gap:14px;min-width:0">
    <div class="sh-panel">
      <div class="sh-panel__head">
        <h2 class="sh-panel__title"><?= sh_icon('send', 17) ?> SMS / OTP</h2>
        <div class="sh-panel__actions">
          <span class="sh-statuspill <?= $otpEnabled ? 'sh-statuspill--on' : 'sh-statuspill--off' ?>"><?= $otpEnabled ? 'Phone + OTP' : 'Email + Password' ?></span>
        </div>
      </div>
      <div class="sh-panel__body" style="display:flex;flex-direction:column;gap:9px">
        <div style="display:flex;justify-content:space-between;font-size:13px">
          <span>OTPs sent today</span><strong><?= number_format($otpStats['today']) ?></strong></div>
        <div style="display:flex;justify-content:space-between;font-size:13px">
          <span>Login / Signup OTPs</span>
          <strong><?= number_format($otpStats['login']) ?> / <?= number_format($otpStats['signup']) ?></strong></div>
        <div style="display:flex;justify-content:space-between;font-size:13px">
          <span>Verified today</span>
          <strong style="color:#1a7f37"><?= number_format($otpStats['verified']) ?></strong></div>
        <div style="display:flex;justify-content:space-between;font-size:13px">
          <span>Failed / Expired</span>
          <strong style="color:<?= ($otpStats['failed'] + $otpStats['expired']) > 0 ? '#c33' : 'inherit' ?>"><?= number_format($otpStats['failed']) ?> / <?= number_format($otpStats['expired']) ?></strong></div>
        <div style="display:flex;justify-content:space-between;font-size:13px">
          <span>Rate-limited requests</span>
          <strong><?= number_format($otpStats['rate_limited']) ?></strong></div>
        <div style="display:flex;justify-content:space-between;font-size:13px">
          <span>Unverified phone accounts</span>
          <strong style="color:<?= $otpStats['unverified'] > 0 ? '#b8760a' : 'inherit' ?>"><?= number_format($otpStats['unverified']) ?></strong></div>
        <?php if (sh_admin_is_superadmin()): ?>
          <a class="sh-btn sh-btn--sm sh-btn--ghost" href="<?= e(sh_url('admin/security.php')) ?>"><?= sh_icon('settings', 14) ?> Manage settings</a>
        <?php endif; ?>
      </div>
    </div>

    <div class="sh-panel">
      <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('credit-card', 17) ?> Awaiting verification</h2></div>
      <div class="sh-panel__body" style="padding:0">
        <?php if (!$awaiting): ?>
          <p class="sh-panel__note" style="padding:15px">Nothing waiting. All submitted payments have been reviewed.</p>
        <?php else: foreach ($awaiting as $p): ?>
          <div style="padding:11px 15px;border-bottom:1px solid var(--sh-line-2);display:flex;gap:10px;align-items:center">
            <div style="min-width:0;flex:1 1 auto">
              <div style="font-weight:600;font-size:13px"><?= e($p['order_number']) ?> · <?= e(sh_money($p['amount'])) ?></div>
              <div class="sh-table__meta"><?= e((string)$p['method_name']) ?> · TrxID <?= e((string)$p['transaction_id']) ?></div>
            </div>
            <a class="sh-btn sh-btn--sm" href="<?= e(sh_url('admin/payments.php?id=' . (int)$p['id'])) ?>">Review</a>
          </div>
        <?php endforeach; endif; ?>
      </div>
    </div>

    <div class="sh-panel">
      <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('alert', 17) ?> Needs attention</h2></div>
      <div class="sh-panel__body" style="display:flex;flex-direction:column;gap:9px">
        <div style="display:flex;justify-content:space-between;font-size:13px">
          <span>Active products</span><strong><?= number_format($productCount) ?></strong></div>
        <div style="display:flex;justify-content:space-between;font-size:13px">
          <span>Unused digital codes</span><strong><?= number_format($codesLeft) ?></strong></div>
        <div style="display:flex;justify-content:space-between;font-size:13px">
          <span>Failed notifications (24h)</span>
          <strong style="color:<?= $failedNotices > 0 ? '#c33' : 'inherit' ?>"><?= number_format($failedNotices) ?></strong></div>
        <?php if ($lowStock): ?>
          <p style="font-size:11.5px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--sh-muted);margin-top:5px">Low stock</p>
          <?php foreach ($lowStock as $ls): ?>
            <div style="display:flex;justify-content:space-between;gap:10px;font-size:12.7px">
              <a href="<?= e(sh_url('admin/products.php?edit=' . (int)$ls['id'])) ?>" style="min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($ls['name']) ?></a>
              <strong style="color:<?= (int)$ls['stock'] === 0 ? '#c33' : '#b8760a' ?>"><?= (int)$ls['stock'] ?> left</strong>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<style>@media (max-width: 1000px) { .sh-dashgrid { grid-template-columns: minmax(0, 1fr) !important; } }</style>
<?php require __DIR__ . '/_footer.php'; ?>
