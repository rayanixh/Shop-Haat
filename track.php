<?php
declare(strict_types=1);
require_once __DIR__ . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/payment.php';

sh_session_start();
$result = null;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sh_csrf_require();
    $number = strtoupper(sh_post('order_number'));
    $phone = sh_post('phone');
    if ($number === '' || $phone === '') {
        $error = 'Enter both your order number and the phone number used at checkout.';
    } else {
        // Both values required — prevents order-number enumeration.
        $result = sh_one('SELECT * FROM orders WHERE order_number = ? AND customer_phone = ? LIMIT 1', [$number, $phone]);
        if ($result === null) { $error = 'No order matched those details. Please check and try again.'; }
    }
}

$pageTitle = 'Track Your Order';
$pageDescription = 'Check the current status of your order.';
require_once SH_ROOT . '/includes/header.php';
?>
<div class="sh-wrap">
  <nav class="sh-breadcrumb"><a href="<?= e(sh_url('index.php')) ?>">Home</a> <?= sh_icon('chevron-right', 13) ?> <span aria-current="page">Track Order</span></nav>
  <div class="sh-auth" style="max-width:560px">
    <h1 class="sh-auth__title">Track your order</h1>
    <p class="sh-auth__sub">Enter your order number and phone number to see live status.</p>
    <?php if ($error): ?>
      <div class="sh-alert sh-alert--error"><?= sh_icon('x-circle', 16) ?><span><?= e($error) ?></span></div>
    <?php endif; ?>
    <form method="post" novalidate>
      <?= sh_csrf_field() ?>
      <div class="sh-field">
        <label class="sh-field__label" for="tr-num">Order number</label>
        <input class="sh-input" id="tr-num" name="order_number" required placeholder="SH25010100001"
               value="<?= e(sh_post('order_number')) ?>" style="text-transform:uppercase">
      </div>
      <div class="sh-field">
        <label class="sh-field__label" for="tr-phone">Phone number used at checkout</label>
        <input class="sh-input" id="tr-phone" name="phone" required placeholder="01XXXXXXXXX" value="<?= e(sh_post('phone')) ?>">
      </div>
      <button class="sh-btn sh-btn--lg sh-btn--block" type="submit"><?= sh_icon('search', 16) ?> Track order</button>
    </form>
  </div>

  <?php if ($result):
    $digital = (int)$result['has_digital'] === 1;
    $steps = $digital
        ? ['Order Placed', 'Payment Submitted', 'Payment Verified', 'Code Delivered', 'Completed']
        : ['Order Placed', 'Payment Submitted', 'Payment Verified', 'Processing', 'Completed'];
    $idx = match ($result['status']) {
        'pending', 'awaiting_payment' => 0, 'payment_submitted' => 1, 'payment_verified' => 2,
        'processing' => $digital ? 2 : 3, 'completed' => 4, default => 0,
    }; ?>
    <div class="sh-section" style="max-width:760px;margin:14px auto 0">
      <div class="sh-section__head">
        <h2 class="sh-section__title"><?= sh_icon('package', 18) ?> <?= e($result['order_number']) ?></h2>
        <span class="sh-badge <?= e(sh_status_class($result['status'])) ?>" style="margin-left:auto"><?= e(sh_status_label($result['status'])) ?></span>
      </div>
      <p style="font-size:13px;color:var(--sh-muted);margin-bottom:6px">
        Placed <?= e(date('d M Y, h:i A', strtotime($result['created_at']))) ?> · Total <?= e(sh_money($result['total'])) ?>
      </p>
      <?php if (!in_array($result['status'], ['payment_rejected', 'cancelled'], true)): ?>
        <div class="sh-track">
          <?php foreach ($steps as $i => $label): ?>
            <div class="sh-track__step <?= $i < $idx ? 'sh-track__step--done' : ($i === $idx ? 'sh-track__step--on' : '') ?>">
              <span class="sh-track__dot"><?= $i <= $idx ? sh_icon('check-circle', 11) : '' ?></span>
              <span class="sh-track__label"><?= e($label) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <div class="sh-alert sh-alert--error" style="margin:0"><?= sh_icon('x-circle', 16) ?>
          <span>This order is marked as <?= e(sh_status_label($result['status'])) ?>. Please contact support for assistance.</span></div>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>
<?php require_once SH_ROOT . '/includes/footer.php'; ?>
