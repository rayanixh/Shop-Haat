<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/admin-auth.php';
require_once SH_ROOT . '/includes/courier.php';

sh_session_start();
$admin = sh_require_admin();
sh_courier_schema_ensure();

$errors = [];
$editId = sh_int($_GET['edit'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sh_csrf_require();
    $form = sh_post('form');

    if ($form === 'toggle') {
        $id = sh_int($_POST['id'] ?? 0);
        $c = sh_courier_by_id($id);
        if ($c === null) {
            sh_flash('error', 'That courier does not exist.');
        } else {
            sh_query('UPDATE couriers SET status = 1 - status WHERE id = ?', [$id]);
            sh_flash('success', 'Courier updated.');
        }
        sh_redirect('admin/couriers.php');
    }

    if ($form === 'delete') {
        $id = sh_int($_POST['id'] ?? 0);
        if ((int)sh_val('SELECT COUNT(*) FROM shipments WHERE courier_id = ?', [$id], 0) > 0) {
            sh_query('UPDATE couriers SET status = 0 WHERE id = ?', [$id]);
            sh_flash('info', 'This courier is used by existing parcels, so it was disabled instead of deleted.');
        } else {
            sh_query('DELETE FROM couriers WHERE id = ?', [$id]);
            sh_flash('success', 'Courier deleted.');
        }
        sh_redirect('admin/couriers.php');
    }

    if ($form === 'save') {
        $id = sh_int($_POST['id'] ?? 0);
        $existing = $id > 0 ? sh_courier_by_id($id) : null;
        $drivers = sh_courier_drivers();
        $driver = sh_post('driver');
        if (!isset($drivers[$driver])) { $driver = 'manual'; }

        $v = new ShValidator($_POST);
        $v->required('name', 'Courier name')->maxLen('name', 100, 'Courier name');
        $errors = $v->errors();

        $code = strtolower(preg_replace('/[^a-z0-9_]/i', '', sh_post('code')) ?? '');
        if ($code === '') { $code = str_replace('-', '_', sh_slug(sh_post('name'))); }
        if ($code === '') { $errors['code'] = 'Enter a short machine code.'; }
        if (sh_one('SELECT id FROM couriers WHERE code = ? AND id <> ? LIMIT 1', [$code, $id])) {
            $errors['code'] = 'That courier code is already in use.';
        }

        // Merge credentials: a blank field keeps the stored secret.
        $creds = $existing !== null ? sh_courier_credentials($existing) : [];
        foreach (array_merge(sh_courier_fields($driver), sh_courier_optional_fields($driver)) as $field => $label) {
            $posted = trim((string)($_POST['cred_' . $field] ?? ''));
            if ($posted !== '') { $creds[$field] = $posted; }
            if (!empty($_POST['clear_credentials'])) { unset($creds[$field]); }
        }

        $logo = sh_post('current_logo');
        if (!empty($_FILES['logo']['name'])) {
            $up = sh_upload_image($_FILES['logo'], 'logos', 0, 2000);
            if (!empty($up['ok'])) { $logo = $up['file']; }
            else { $errors['logo'] = $up['error'] ?? 'The logo could not be uploaded.'; }
        }

        if (!$errors) {
            $data = [
                'code'         => $code,
                'name'         => sh_post('name'),
                'driver'       => $driver,
                'logo'         => $logo ?: null,
                'tracking_url' => sh_post('tracking_url') ?: null,
                'description'  => sh_post('description') ?: null,
                'credentials'  => $creds ? json_encode($creds, JSON_UNESCAPED_SLASHES) : null,
                'sort_order'   => sh_int($_POST['sort_order'] ?? 0),
                'status'       => !empty($_POST['status']) ? 1 : 0,
            ];
            try {
                if ($id > 0) { sh_update('couriers', $data, 'id = ?', [$id]); sh_flash('success', 'Courier updated.'); }
                else { sh_insert('couriers', $data); sh_flash('success', 'Courier created.'); }
                sh_log_line('admin', 'Courier "' . $data['name'] . '" saved by ' . $admin['email']);
                sh_redirect('admin/couriers.php');
            } catch (Throwable $e) {
                sh_log_exception($e, 'courier-save');
                $errors['general'] = 'The courier could not be saved.';
            }
        }
        $editId = $id;
    }
}

$editing = $editId > 0 ? sh_courier_by_id($editId) : null;
$val = static function (string $k, $fb = '') use ($editing, $errors) {
    if ($errors && isset($_POST[$k])) { return (string)$_POST[$k]; }
    return (string)($editing[$k] ?? $fb);
};
$editDriver = $val('driver', 'manual');
$editCreds = $editing !== null ? sh_courier_credentials($editing) : [];
$rows = sh_couriers();

$adminPage = 'couriers';
$adminTitle = 'Couriers';
require __DIR__ . '/_layout.php';
?>
<?php if ($errors): ?>
  <div class="sh-alert sh-alert--error"><?= sh_icon('x-circle', 17) ?>
    <div><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div></div>
<?php endif; ?>

<div class="sh-alert sh-alert--info"><?= sh_icon('truck', 17) ?>
  <div>
    <strong>How couriers behave here.</strong>
    Every courier works for manual parcels: assign it and type the consignment / tracking number yourself.
    Credentials are stored server-side and never sent to the browser. Only <strong>Steadfast</strong> currently ships
    with a real API client (book &amp; fetch status). Other drivers are registered so you can store their credentials,
    but they refuse to send requests until their official API is wired in — the site never fakes a booking or a status.
  </div>
</div>

<div style="display:grid;grid-template-columns:minmax(0,1fr) 380px;gap:14px" class="sh-crgrid">
  <div class="sh-panel">
    <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('truck', 17) ?> Couriers (<?= count($rows) ?>)</h2></div>
    <div class="sh-tablewrap">
      <table class="sh-table">
        <thead><tr><th>Courier</th><th>Driver</th><th>API</th><th>Tracking link</th><th>Status</th><th style="text-align:right">Actions</th></tr></thead>
        <tbody>
        <?php if (!$rows): ?><tr class="sh-table--empty"><td colspan="6">No couriers configured yet.</td></tr>
        <?php else: foreach ($rows as $c): ?>
          <tr>
            <td><div class="sh-table__cell">
              <?php $cLogo = sh_logo_image($c['logo']); ?>
              <?php if ($cLogo !== ''): ?>
                <img class="sh-table__thumb" style="object-fit:contain;background:#fff" src="<?= e($cLogo) ?>" alt="" loading="lazy">
              <?php else: ?>
                <span class="sh-table__thumb" style="display:grid;place-items:center;color:var(--sh-muted)"><?= sh_icon('truck', 17) ?></span>
              <?php endif; ?>
              <div><div class="sh-table__name"><?= e($c['name']) ?></div>
                <div class="sh-table__meta"><?= e($c['code']) ?></div></div></div></td>
            <td><?= e(sh_courier_drivers()[$c['driver']] ?? $c['driver']) ?></td>
            <td class="sh-table__meta" style="max-width:230px"><?= e(sh_courier_status_text($c)) ?></td>
            <td><?= $c['tracking_url'] ? '<span class="sh-badge sh-badge--muted">template set</span>' : '<span class="sh-table__meta">—</span>' ?></td>
            <td><span class="sh-statuspill <?= (int)$c['status'] === 1 ? 'sh-statuspill--on' : 'sh-statuspill--off' ?>">
              <?= (int)$c['status'] === 1 ? 'Enabled' : 'Disabled' ?></span></td>
            <td><div class="sh-table__actions">
              <a class="sh-btn sh-btn--sm sh-btn--ghost" href="<?= e(sh_url('admin/couriers.php?edit=' . (int)$c['id'])) ?>"><?= sh_icon('pencil', 13) ?></a>
              <form method="post"><?= sh_csrf_field() ?>
                <input type="hidden" name="form" value="toggle"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                <button class="sh-btn sh-btn--sm sh-btn--ghost" type="submit" title="Enable / disable"><?= sh_icon('eye', 13) ?></button></form>
              <form method="post" data-confirm="Delete <?= e($c['name']) ?>?"><?= sh_csrf_field() ?>
                <input type="hidden" name="form" value="delete"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                <button class="sh-btn sh-btn--sm sh-btn--bad" type="submit"><?= sh_icon('trash', 13) ?></button></form>
            </div></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="sh-panel" style="align-self:start">
    <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon($editing ? 'pencil' : 'plus', 17) ?>
      <?= $editing ? 'Edit courier' : 'New courier' ?></h2></div>
    <div class="sh-panel__body">
      <form method="post" enctype="multipart/form-data" novalidate>
        <?= sh_csrf_field() ?>
        <input type="hidden" name="form" value="save">
        <input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">
        <input type="hidden" name="current_logo" value="<?= e($val('logo')) ?>">

        <div class="sh-field"><label class="sh-field__label" for="cr-name">Courier name <span class="sh-field__req">*</span></label>
          <input class="sh-input" id="cr-name" name="name" required maxlength="100" value="<?= e($val('name')) ?>" placeholder="Steadfast"></div>
        <div class="sh-field"><label class="sh-field__label" for="cr-code">Machine code</label>
          <input class="sh-input" id="cr-code" name="code" maxlength="40" value="<?= e($val('code')) ?>" placeholder="auto">
          <span class="sh-field__hint">Lowercase letters, numbers and underscores. Auto-generated if blank.</span></div>
        <div class="sh-field"><label class="sh-field__label" for="cr-driver">Driver</label>
          <select class="sh-select" id="cr-driver" name="driver">
            <?php foreach (sh_courier_drivers() as $k => $label): ?>
              <option value="<?= e($k) ?>" <?= $editDriver === $k ? 'selected' : '' ?>>
                <?= e($label) ?><?= in_array($k, sh_courier_api_drivers(), true) ? ' — API ready' : '' ?></option>
            <?php endforeach; ?>
          </select>
          <span class="sh-field__hint">Save and reopen after changing the driver to see its credential fields.</span></div>

        <div class="sh-field"><label class="sh-field__label" for="cr-track">Public tracking URL template</label>
          <input class="sh-input" id="cr-track" name="tracking_url" maxlength="255" value="<?= e($val('tracking_url')) ?>"
                 placeholder="https://example.com/track/{tracking}">
          <span class="sh-field__hint">Use <code>{tracking}</code> where the tracking number goes. Shown as a link on parcels.</span></div>

        <?php $showCreds = array_merge(sh_courier_fields($editDriver), sh_courier_optional_fields($editDriver)); ?>
        <?php if ($showCreds): ?>
          <p style="font-size:11.5px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--sh-muted);margin:14px 0 8px">
            Merchant credentials</p>
          <?php foreach ($showCreds as $field => $label):
            $has = trim((string)($editCreds[$field] ?? '')) !== ''; ?>
            <div class="sh-field">
              <label class="sh-field__label" for="cr-<?= e($field) ?>"><?= e($label) ?></label>
              <input class="sh-input" id="cr-<?= e($field) ?>" type="password" name="cred_<?= e($field) ?>" autocomplete="new-password"
                     placeholder="<?= $has ? 'Saved — leave blank to keep' : 'Issued by the courier' ?>">
            </div>
          <?php endforeach; ?>
          <?php if ($editCreds): ?>
            <label class="sh-check" style="margin-bottom:12px">
              <input type="checkbox" name="clear_credentials" value="1"><span>Remove all stored credentials</span></label>
          <?php endif; ?>
        <?php endif; ?>

        <div class="sh-field"><label class="sh-field__label" for="cr-desc">Description</label>
          <input class="sh-input" id="cr-desc" name="description" maxlength="190" value="<?= e($val('description')) ?>"></div>
        <div class="sh-field"><label class="sh-field__label" for="cr-logo">Logo</label>
          <input class="sh-input" id="cr-logo" type="file" name="logo" accept="image/*">
          <span class="sh-field__hint">Optional. JPG, PNG, WebP or GIF — max <?= e(sh_bytes_label(sh_server_upload_limit())) ?>.</span></div>
        <?php $curLogo = sh_logo_image($val('logo')); ?>
        <?php if ($curLogo !== ''): ?>
          <p style="margin:-4px 0 12px;display:flex;align-items:center;gap:9px">
            <img src="<?= e($curLogo) ?>" alt="Current logo" style="height:34px;object-fit:contain">
            <span class="sh-field__hint">Current logo</span>
          </p>
        <?php elseif ($val('logo') !== ''): ?>
          <p class="sh-field__hint" style="margin:-4px 0 12px;color:#c62828">
            The saved logo file (<?= e($val('logo')) ?>) is missing from the server. Upload it again to replace it.</p>
        <?php endif; ?>
        <div class="sh-field"><label class="sh-field__label" for="cr-sort">Sort order</label>
          <input class="sh-input" id="cr-sort" name="sort_order" type="number" value="<?= e($val('sort_order', '0')) ?>"></div>
        <label class="sh-check" style="margin-bottom:12px">
          <input type="checkbox" name="status" value="1" <?= ($errors ? !empty($_POST['status']) : (int)($editing['status'] ?? 0) === 1) ? 'checked' : '' ?>>
          <span>Available when creating parcels</span></label>

        <button class="sh-btn sh-btn--block" type="submit"><?= sh_icon('check-circle', 15) ?> <?= $editing ? 'Save courier' : 'Create courier' ?></button>
        <?php if ($editing): ?>
          <a class="sh-btn sh-btn--ghost sh-btn--block" style="margin-top:8px" href="<?= e(sh_url('admin/couriers.php')) ?>">Cancel edit</a>
        <?php endif; ?>
      </form>
    </div>
  </div>
</div>
<style>@media (max-width: 1100px){.sh-crgrid{grid-template-columns:minmax(0,1fr)!important}}</style>
<?php require __DIR__ . '/_footer.php'; ?>
