<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/admin-auth.php';
require_once SH_ROOT . '/includes/transition.php';

sh_session_start();
$admin = sh_require_admin();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sh_csrf_require();
    $cfg = sh_transition_config();
    $form = sh_post('form');

    if ($form === 'remove_media') {
        if ($cfg['media'] !== '') {
            $path = SH_UPLOAD_DIR . '/transitions/' . $cfg['media'];
            if (is_file($path)) { @unlink($path); }
        }
        sh_setting_save('transition_media', '');
        sh_setting_save('transition_type', 'fade');
        sh_flash('success', 'Transition media removed. The built-in fade is used.');
        sh_redirect('admin/transition.php');
    }

    $enabled = !empty($_POST['transition_enabled']) ? '1' : '0';
    $type = sh_post('transition_type');
    if (!in_array($type, ['fade', 'image', 'gif'], true)) { $type = 'fade'; }

    $preset = sh_post('transition_duration');
    if ($preset === 'custom') {
        $duration = sh_int($_POST['transition_custom'] ?? 0);
        if ($duration < 150 || $duration > 5000) { $errors['duration'] = 'Custom duration must be between 150 and 5000 milliseconds.'; }
    } else {
        $duration = sh_int($preset);
        if (!isset(sh_transition_presets()[$duration])) { $duration = 400; }
    }

    $media = $cfg['media'];
    if (!empty($_FILES['transition_media']['name'])) {
        $up = sh_upload_image($_FILES['transition_media'], 'transitions', 0, 4000);
        if (!empty($up['ok'])) {
            if ($media !== '' && $media !== $up['file']) {
                $old = SH_UPLOAD_DIR . '/transitions/' . $media;
                if (is_file($old)) { @unlink($old); }
            }
            $media = $up['file'];
            // Pick the matching type automatically from the real file.
            $type = str_ends_with(strtolower($media), '.gif') ? 'gif' : 'image';
        } else {
            $errors['media'] = $up['error'] ?? 'The file could not be uploaded.';
        }
    }
    if ($type !== 'fade' && $media === '') {
        $errors['type'] = 'Upload an image or GIF first, or choose "Default fade".';
    }

    if (!$errors) {
        sh_setting_save('transition_enabled', $enabled);
        sh_setting_save('transition_type', $type);
        sh_setting_save('transition_duration', (string)$duration);
        sh_setting_save('transition_media', $media);
        sh_log_line('admin', 'Page transition settings updated by ' . ($admin['email'] ?? ''));
        sh_flash('success', 'Page transition settings saved.');
        sh_redirect('admin/transition.php');
    }
}

$cfg = sh_transition_config();
$presets = sh_transition_presets();
$isPreset = isset($presets[$cfg['duration']]);
$storedType = (string)sh_setting('transition_type', 'fade');

$adminPage = 'transition';
$adminTitle = 'Page Transition';
require __DIR__ . '/_layout.php';
?>
<?php if ($errors): ?>
  <div class="sh-alert sh-alert--error"><?= sh_icon('x-circle', 17) ?>
    <div><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div></div>
<?php endif; ?>

<div class="sh-cards">
  <section class="sh-panel">
    <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('zap', 17) ?> Page Transition</h2></div>
    <div class="sh-panel__body">
      <form method="post" enctype="multipart/form-data" novalidate>
        <?= sh_csrf_field() ?>
        <input type="hidden" name="form" value="save">

        <label class="sh-toggle" style="margin-bottom:14px">
          <input type="checkbox" name="transition_enabled" value="1" <?= $cfg['enabled'] ? 'checked' : '' ?>>
          <span class="sh-toggle__track"></span><span>Enable page transition</span></label>

        <div class="sh-grid2">
          <div class="sh-field">
            <label class="sh-field__label" for="tr-type">Transition type</label>
            <select class="sh-select" id="tr-type" name="transition_type">
              <option value="fade" <?= $storedType === 'fade' ? 'selected' : '' ?>>Default fade</option>
              <option value="image" <?= $storedType === 'image' ? 'selected' : '' ?>>Image</option>
              <option value="gif" <?= $storedType === 'gif' ? 'selected' : '' ?>>GIF</option>
            </select>
            <span class="sh-field__hint">Image and GIF need an uploaded file; otherwise the fade is used automatically.</span>
          </div>
          <div class="sh-field">
            <label class="sh-field__label" for="tr-dur">Transition duration</label>
            <select class="sh-select" id="tr-dur" name="transition_duration" data-transition-duration>
              <?php foreach ($presets as $ms => $label): ?>
                <option value="<?= $ms ?>" <?= $isPreset && $cfg['duration'] === $ms ? 'selected' : '' ?>><?= e($label) ?></option>
              <?php endforeach; ?>
              <option value="custom" <?= !$isPreset ? 'selected' : '' ?>>Custom</option>
            </select>
            <div class="sh-field" style="margin:8px 0 0" data-transition-custom <?= $isPreset ? 'hidden' : '' ?>>
              <input class="sh-input" name="transition_custom" type="number" min="150" max="5000" step="50"
                     value="<?= !$isPreset ? (int)$cfg['duration'] : 750 ?>" placeholder="Milliseconds (150–5000)">
            </div>
            <span class="sh-field__hint">Controls how long the overlay is visible. Page loading is never delayed on purpose.</span>
          </div>
        </div>

        <div class="sh-field">
          <label class="sh-field__label" for="tr-media">Transition media (image or GIF)</label>
          <input class="sh-input" id="tr-media" name="transition_media" type="file" accept=".jpg,.jpeg,.png,.webp,.gif,image/jpeg,image/png,image/webp,image/gif">
          <span class="sh-field__hint">JPG, JPEG, PNG, WebP or GIF. Maximum <?= e(sh_bytes_label(sh_server_upload_limit())) ?>. GIF animation is preserved.</span>
        </div>

        <button class="sh-btn" type="submit"><?= sh_icon('check-circle', 15) ?> Save settings</button>
      </form>
    </div>
  </section>

  <section class="sh-panel">
    <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('eye', 17) ?> Preview</h2></div>
    <div class="sh-panel__body">
      <div class="sh-pt-preview">
        <?php if ($cfg['media_url'] !== ''): ?>
          <img src="<?= e($cfg['media_url']) ?>" alt="Current transition media">
        <?php else: ?>
          <div class="sh-pt__mark sh-pt__mark--static"><span></span><span></span><span></span></div>
        <?php endif; ?>
      </div>
      <p class="sh-panel__note" style="margin-top:10px">
        Currently: <strong><?= $cfg['enabled'] ? 'enabled' : 'disabled' ?></strong> ·
        <?= $cfg['type'] === 'fade' ? 'built-in fade' : e(strtoupper($cfg['type'])) . ' overlay' ?> ·
        <?= (int)$cfg['duration'] ?> ms<?= $cfg['media'] !== '' ? ' · ' . e($cfg['media']) : '' ?>
      </p>
      <div class="sh-actions" style="margin-top:10px">
        <button class="sh-btn sh-btn--sm sh-btn--ghost" type="button" data-transition-test <?= $cfg['enabled'] ? '' : 'disabled' ?>><?= sh_icon('zap', 13) ?> Play transition</button>
        <?php if ($cfg['media'] !== ''): ?>
          <form method="post" data-confirm="Remove the uploaded transition media?"><?= sh_csrf_field() ?>
            <input type="hidden" name="form" value="remove_media">
            <button class="sh-btn sh-btn--sm sh-btn--ghost" type="submit"><?= sh_icon('trash', 13) ?> Remove media</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </section>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
