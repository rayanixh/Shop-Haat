<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/admin-auth.php';
require_once SH_ROOT . '/includes/payment.php';
require_once SH_ROOT . '/includes/notifications.php';

sh_session_start();
$admin = sh_require_admin();

$viewId = sh_int($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sh_csrf_require();
    $id = sh_int($_POST['id'] ?? 0);
    $order = sh_order_get($id);
    if ($order === null) {
        sh_flash('error', 'That order no longer exists.');
        sh_redirect('admin/orders.php');
    }
    $form = sh_post('form');

    if ($form === 'status') {
        $new = sh_post('status');
        $allowed = ['pending', 'awaiting_payment', 'payment_submitted', 'payment_verified', 'processing', 'completed', 'cancelled'];
        if (!in_array($new, $allowed, true)) {
            sh_flash('error', 'That status is not valid.');
        } else {
            try {
                sh_update('orders', ['status' => $new], 'id = ?', [$id]);
                sh_log_line('admin', 'Order ' . $order['order_number'] . ' status -> ' . $new . ' by ' . $admin['email']);

                // Deliver digital codes when an order reaches a fulfilled state.
                if (in_array($new, ['processing', 'completed'], true)
                    && (int)$order['has_digital'] === 1 && (int)$order['codes_delivered'] === 0
                    && $order['payment_status'] === 'verified') {
                    $d = sh_deliver_digital_codes($id);
                    if (!empty($d['ok'])) { sh_flash('success', 'Digital codes delivered (' . count($d['codes'] ?? []) . ').'); }
                    else { sh_flash('error', 'Codes could not be delivered: ' . ($d['error'] ?? 'unknown error')); }
                }
                $event = $new === 'completed' ? 'order_completed' : ($new === 'processing' ? 'order_processing' : null);
                if ($event !== null) { sh_notify($event, sh_order_notify_payload(sh_order_get($id))); }
                sh_flash('success', 'Order status updated to ' . sh_status_label($new) . '.');
            } catch (Throwable $e) {
                sh_log_exception($e, 'order-status');
                sh_flash('error', 'The status could not be updated. The error has been logged.');
            }
        }
        sh_redirect('admin/orders.php?id=' . $id);
    }

    if ($form === 'note') {
        sh_update('orders', ['order_note' => mb_substr(sh_post('order_note'), 0, 900)], 'id = ?', [$id]);
        sh_flash('success', 'Order note saved.');
        sh_redirect('admin/orders.php?id=' . $id);
    }
}

$adminPage = 'orders';

/* ---------------- Single order view ---------------- */
if ($viewId > 0) {
    $order = sh_order_get($viewId);
    if ($order === null) {
        http_response_code(404);
        $adminTitle = 'Order not found';
        require __DIR__ . '/_layout.php';
        echo '<div class="sh-panel"><div class="sh-panel__body"><p class="sh-panel__note">This order does not exist.</p>'
            . '<a class="sh-btn sh-btn--sm" style="margin-top:10px" href="' . e(sh_url('admin/orders.php')) . '">Back to orders</a></div></div>';
        require __DIR__ . '/_footer.php';
        exit;
    }
    $items = sh_order_items($viewId);
    $codes = sh_order_codes($viewId);
    $payments = sh_all('SELECT * FROM payments WHERE order_id = ? ORDER BY id DESC', [$viewId]);
    $logs = sh_all('SELECT * FROM notification_logs WHERE order_id = ? ORDER BY id DESC LIMIT 30', [$viewId]);

    $adminTitle = 'Order ' . $order['order_number'];
    require __DIR__ . '/_layout.php';
    ?>
    <div style="display:flex;gap:9px;flex-wrap:wrap;align-items:center">
      <a class="sh-btn sh-btn--sm sh-btn--ghost" href="<?= e(sh_url('admin/orders.php')) ?>"><?= sh_icon('chevron-left', 14) ?> All orders</a>
      <span class="sh-badge <?= e(sh_status_class($order['status'])) ?>"><?= e(sh_status_label($order['status'])) ?></span>
      <span class="sh-badge <?= e(sh_status_class($order['payment_status'])) ?>">Payment: <?= e(sh_status_label($order['payment_status'])) ?></span>
    </div>

    <div style="display:grid;grid-template-columns:minmax(0,1.4fr) minmax(0,1fr);gap:14px" class="sh-ordgrid">
      <div style="display:flex;flex-direction:column;gap:14px;min-width:0">
        <div class="sh-panel">
          <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('box', 17) ?> Items (<?= count($items) ?>)</h2></div>
          <div class="sh-tablewrap">
            <table class="sh-table">
              <thead><tr><th>Product</th><th>Unit price</th><th>Qty</th><th style="text-align:right">Line total</th></tr></thead>
              <tbody>
              <?php foreach ($items as $it): ?>
                <tr>
                  <td><div class="sh-table__cell">
                    <img class="sh-table__thumb" src="<?= e(sh_product_image($it['product_image'])) ?>" alt="" loading="lazy">
                    <div><div class="sh-table__name"><?= e($it['product_name']) ?></div>
                      <div class="sh-table__meta"><?= e(ucfirst((string)$it['product_type'])) ?></div></div></div></td>
                  <td><?= e(sh_money($it['unit_price'])) ?></td>
                  <td><?= (int)$it['quantity'] ?></td>
                  <td style="text-align:right;font-weight:700"><?= e(sh_money($it['line_total'])) ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <div class="sh-panel__body" style="border-top:1px solid var(--sh-line)">
            <div style="max-width:290px;margin-left:auto;font-size:13.2px;display:flex;flex-direction:column;gap:6px">
              <div style="display:flex;justify-content:space-between"><span>Subtotal</span><span><?= e(sh_money($order['subtotal'])) ?></span></div>
              <?php if ((float)$order['discount'] > 0): ?>
                <div style="display:flex;justify-content:space-between;color:#168c56"><span>Discount<?= $order['coupon_code'] ? ' (' . e($order['coupon_code']) . ')' : '' ?></span><span>−<?= e(sh_money($order['discount'])) ?></span></div>
              <?php endif; ?>
              <div style="display:flex;justify-content:space-between"><span>Delivery</span><span><?= e(sh_money($order['delivery_fee'])) ?></span></div>
              <div style="display:flex;justify-content:space-between;font-size:15.5px;font-weight:800;border-top:1px solid var(--sh-line);padding-top:7px">
                <span>Total</span><span style="color:var(--sh-brand)"><?= e(sh_money($order['total'])) ?></span></div>
            </div>
          </div>
        </div>

        <?php if ($codes): ?>
          <div class="sh-panel">
            <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('key', 17) ?> Delivered digital codes</h2></div>
            <div class="sh-panel__body" style="display:flex;flex-direction:column;gap:8px">
              <?php foreach ($codes as $c): ?>
                <div style="display:flex;gap:10px;align-items:center;background:var(--sh-bg);border-radius:7px;padding:9px 11px">
                  <div style="flex:1 1 auto;min-width:0">
                    <div class="sh-table__meta"><?= e((string)$c['product_name']) ?></div>
                    <code style="font-size:13.5px;font-weight:700;word-break:break-all"><?= e($c['code']) ?></code>
                  </div>
                  <span class="sh-table__meta"><?= $c['delivered_at'] ? e(date('d M, h:i A', strtotime($c['delivered_at']))) : '' ?></span>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>

        <div class="sh-panel">
          <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('credit-card', 17) ?> Payment records</h2></div>
          <div class="sh-tablewrap">
            <table class="sh-table">
              <thead><tr><th>Method</th><th>Transaction</th><th>Amount</th><th>Status</th><th style="text-align:right">Action</th></tr></thead>
              <tbody>
              <?php if (!$payments): ?><tr class="sh-table--empty"><td colspan="5">No payment submitted yet.</td></tr>
              <?php else: foreach ($payments as $p): ?>
                <tr>
                  <td><?= e((string)$p['method_name']) ?><div class="sh-table__meta"><?= e((string)$p['kind']) ?></div></td>
                  <td><?= $p['transaction_id'] ? '<code>' . e($p['transaction_id']) . '</code>' : '—' ?>
                    <?php if ($p['sender_phone']): ?><div class="sh-table__meta">From <?= e($p['sender_phone']) ?></div><?php endif; ?></td>
                  <td><?= e(sh_money($p['amount'])) ?></td>
                  <td><span class="sh-badge <?= e(sh_status_class($p['status'])) ?>"><?= e(sh_status_label($p['status'])) ?></span></td>
                  <td style="text-align:right">
                    <?php if ($p['status'] === 'pending' && $p['transaction_id']): ?>
                      <a class="sh-btn sh-btn--sm" href="<?= e(sh_url('admin/payments.php?id=' . (int)$p['id'])) ?>">Review</a>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
        </div>

        <div class="sh-panel">
          <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('bell', 17) ?> Notification log</h2></div>
          <div class="sh-tablewrap">
            <table class="sh-table">
              <thead><tr><th>Event</th><th>Channel</th><th>Status</th><th>Detail</th><th>Time</th></tr></thead>
              <tbody>
              <?php if (!$logs): ?><tr class="sh-table--empty"><td colspan="5">Nothing sent for this order yet.</td></tr>
              <?php else: foreach ($logs as $l): ?>
                <tr>
                  <td><?= e(str_replace('_', ' ', $l['event'])) ?></td>
                  <td><?= e(ucfirst($l['channel'])) ?></td>
                  <td><span class="sh-badge <?= $l['status'] === 'sent' ? 'sh-badge--ok' : ($l['status'] === 'failed' ? 'sh-badge--bad' : '') ?>"><?= e($l['status']) ?></span></td>
                  <td class="sh-table__meta" style="max-width:280px"><?= e((string)($l['error_message'] ?? '')) ?></td>
                  <td class="sh-table__meta"><?= e(date('d M, H:i', strtotime($l['created_at']))) ?></td>
                </tr>
              <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <div style="display:flex;flex-direction:column;gap:14px;min-width:0">
        <div class="sh-panel">
          <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('refresh', 17) ?> Update status</h2></div>
          <div class="sh-panel__body">
            <?php if ($order['payment_status'] !== 'verified' && (int)$order['has_digital'] === 1): ?>
              <p class="sh-panel__note" style="margin-bottom:10px">Digital codes are only released once the payment is verified.</p>
            <?php endif; ?>
            <form method="post">
              <?= sh_csrf_field() ?>
              <input type="hidden" name="form" value="status"><input type="hidden" name="id" value="<?= (int)$order['id'] ?>">
              <div class="sh-field">
                <label class="sh-field__label" for="o-status">Order status</label>
                <select class="sh-select" id="o-status" name="status">
                  <?php foreach (['pending', 'awaiting_payment', 'payment_submitted', 'payment_verified', 'processing', 'completed', 'cancelled'] as $s): ?>
                    <option value="<?= e($s) ?>" <?= $order['status'] === $s ? 'selected' : '' ?>><?= e(sh_status_label($s)) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <button class="sh-btn sh-btn--block" type="submit"><?= sh_icon('check-circle', 15) ?> Apply status</button>
            </form>
          </div>
        </div>

        <div class="sh-panel">
          <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('user', 17) ?> Customer</h2></div>
          <div class="sh-panel__body" style="font-size:13.2px;line-height:1.85">
            <strong><?= e($order['customer_name']) ?></strong><br>
            <?= sh_icon('mail', 13) ?> <?= e($order['customer_email']) ?><br>
            <?= sh_icon('phone', 13) ?> <?= e($order['customer_phone']) ?><br>
            <?php if ($order['user_id']): ?>
              <a href="<?= e(sh_url('admin/customers.php?id=' . (int)$order['user_id'])) ?>">View customer profile</a>
            <?php else: ?><span class="sh-table__meta">Guest checkout</span><?php endif; ?>
          </div>
        </div>

        <div class="sh-panel">
          <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('truck', 17) ?> Delivery</h2></div>
          <div class="sh-panel__body" style="font-size:13.2px;line-height:1.85">
            <?php if ((int)$order['has_digital'] === 1 && !$order['shipping_address']): ?>
              <span class="sh-table__meta">Digital order — no shipping required.</span>
            <?php else: ?>
              <?= e((string)$order['shipping_address']) ?><br>
              <?= e(trim((string)$order['shipping_area'] . ', ' . (string)$order['shipping_city'] . ' ' . (string)$order['shipping_postcode'], ', ')) ?>
            <?php endif; ?>
            <div style="margin-top:9px" class="sh-table__meta">
              Placed <?= e(date('d M Y, h:i A', strtotime($order['created_at']))) ?><br>
              Method: <?= e((string)$order['payment_method_name']) ?>
            </div>
          </div>
        </div>

        <div class="sh-panel">
          <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('pencil', 17) ?> Order note</h2></div>
          <div class="sh-panel__body">
            <form method="post">
              <?= sh_csrf_field() ?>
              <input type="hidden" name="form" value="note"><input type="hidden" name="id" value="<?= (int)$order['id'] ?>">
              <div class="sh-field"><label class="sh-field__label sh-sr-only" for="o-note">Note</label>
                <textarea class="sh-textarea" id="o-note" name="order_note" rows="4"><?= e((string)$order['order_note']) ?></textarea></div>
              <button class="sh-btn sh-btn--ghost sh-btn--block" type="submit">Save note</button>
            </form>
          </div>
        </div>
      </div>
    </div>
    <style>@media (max-width: 1050px){.sh-ordgrid{grid-template-columns:minmax(0,1fr)!important}}</style>
    <?php
    require __DIR__ . '/_footer.php';
    exit;
}

/* ---------------- Order list ---------------- */
$q = sh_get('q');
$fStatus = sh_get('status');
$fPay = sh_get('payment');
$page = max(1, sh_int($_GET['page'] ?? 1));
$per = 25;

$where = ['1=1']; $args = [];
if ($q !== '') { $where[] = '(o.order_number LIKE ? OR o.customer_name LIKE ? OR o.customer_phone LIKE ? OR o.customer_email LIKE ?)';
    array_push($args, "%$q%", "%$q%", "%$q%", "%$q%"); }
if ($fStatus !== '') { $where[] = 'o.status = ?'; $args[] = $fStatus; }
if ($fPay !== '') { $where[] = 'o.payment_status = ?'; $args[] = $fPay; }
$whereSql = implode(' AND ', $where);

$total = (int)sh_val("SELECT COUNT(*) FROM orders o WHERE $whereSql", $args, 0);
$pages = max(1, (int)ceil($total / $per));
$page = min($page, $pages);
$rows = sh_all("SELECT o.* FROM orders o WHERE $whereSql ORDER BY o.id DESC LIMIT $per OFFSET " . (($page - 1) * $per), $args);

$adminTitle = 'Orders';
require __DIR__ . '/_layout.php';
?>
<div class="sh-panel">
  <div class="sh-panel__head">
    <h2 class="sh-panel__title"><?= sh_icon('package', 17) ?> Orders (<?= number_format($total) ?>)</h2>
  </div>
  <div class="sh-panel__body" style="padding-bottom:0">
    <form class="sh-filterbar" method="get">
      <div class="sh-field"><label class="sh-field__label" for="f-q">Search</label>
        <input class="sh-input" id="f-q" name="q" value="<?= e($q) ?>" placeholder="Order no, name, phone"></div>
      <div class="sh-field"><label class="sh-field__label" for="f-st">Order status</label>
        <select class="sh-select" id="f-st" name="status">
          <option value="">All</option>
          <?php foreach (['pending', 'awaiting_payment', 'payment_submitted', 'payment_verified', 'processing', 'completed', 'cancelled'] as $s): ?>
            <option value="<?= e($s) ?>" <?= $fStatus === $s ? 'selected' : '' ?>><?= e(sh_status_label($s)) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="sh-field"><label class="sh-field__label" for="f-pay">Payment</label>
        <select class="sh-select" id="f-pay" name="payment">
          <option value="">All</option>
          <?php foreach (['unpaid', 'submitted', 'verified', 'rejected', 'refunded'] as $s): ?>
            <option value="<?= e($s) ?>" <?= $fPay === $s ? 'selected' : '' ?>><?= e(sh_status_label($s)) ?></option>
          <?php endforeach; ?>
        </select></div>
      <button class="sh-btn sh-btn--sm" type="submit"><?= sh_icon('filter', 14) ?> Filter</button>
      <?php if ($q !== '' || $fStatus !== '' || $fPay !== ''): ?>
        <a class="sh-btn sh-btn--sm sh-btn--ghost" href="<?= e(sh_url('admin/orders.php')) ?>">Reset</a><?php endif; ?>
    </form>
  </div>
  <div class="sh-tablewrap">
    <table class="sh-table">
      <thead><tr><th>Order</th><th>Customer</th><th>Method</th><th>Total</th><th>Payment</th><th>Status</th><th style="text-align:right">Action</th></tr></thead>
      <tbody>
      <?php if (!$rows): ?><tr class="sh-table--empty"><td colspan="7">No orders match these filters.</td></tr>
      <?php else: foreach ($rows as $o): ?>
        <tr>
          <td><a href="<?= e(sh_url('admin/orders.php?id=' . (int)$o['id'])) ?>" style="font-weight:600"><?= e($o['order_number']) ?></a>
            <div class="sh-table__meta"><?= e(date('d M Y, h:i A', strtotime($o['created_at']))) ?></div></td>
          <td><?= e($o['customer_name']) ?><div class="sh-table__meta"><?= e($o['customer_phone']) ?></div></td>
          <td><?= e((string)$o['payment_method_name']) ?></td>
          <td style="font-weight:700"><?= e(sh_money($o['total'])) ?></td>
          <td><span class="sh-badge <?= e(sh_status_class($o['payment_status'])) ?>"><?= e(sh_status_label($o['payment_status'])) ?></span></td>
          <td><span class="sh-badge <?= e(sh_status_class($o['status'])) ?>"><?= e(sh_status_label($o['status'])) ?></span></td>
          <td style="text-align:right"><a class="sh-btn sh-btn--sm sh-btn--ghost" href="<?= e(sh_url('admin/orders.php?id=' . (int)$o['id'])) ?>">Open</a></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <?php if ($pages > 1): ?>
    <div class="sh-panel__body"><?= sh_paginate($total, $per, $page, sh_url('admin/orders.php') . '?' . http_build_query(array_diff_key($_GET, ['page' => 1]))) ?></div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
