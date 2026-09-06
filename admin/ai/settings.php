<?php
require_once __DIR__ . '/_ai.php';

$errors = [];
$installReport = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sh_csrf_require();
    $form = sh_post('form');

    if ($form === 'install') {
        $installReport = sh_ai_migrate();
        $failed = array_filter($installReport, static fn($r) => empty($r['ok']));
        sh_flash($failed ? 'error' : 'success', $failed
            ? 'Some migration steps failed. Check the report below.'
            : 'AI Auto Work installed. Add your API key to start generating.');
        if (!$failed) { sh_redirect('admin/ai/settings.php'); }
        $aiInstalled = sh_ai_installed();
    }

    if ($form === 'save') {
        if (!$aiInstalled) {
            sh_flash('error', 'Run the installer first.');
            sh_redirect('admin/ai/settings.php');
        }
        // A blank key field means "keep the stored key" — it is never pre-filled.
        $key = trim((string)($_POST['api_key'] ?? ''));
        if ($key !== '') { sh_ai_setting_save('api_key', sh_ai_encrypt($key)); }
        if (!empty($_POST['clear_key'])) { sh_ai_setting_save('api_key', ''); }

        $provider = sh_post('provider');
        sh_ai_setting_save('provider', isset(sh_ai_providers()[$provider]) ? $provider : 'openai');

        $p = sh_ai_provider();
        $model = sh_post('model');
        sh_ai_setting_save('model', in_array($model, $p->textModels(), true) ? $model : $p->textModels()[0]);
        $imodel = sh_post('image_model');
        sh_ai_setting_save('image_model', in_array($imodel, $p->imageModels(), true) ? $imodel : ($p->imageModels()[0] ?? ''));

        $temp = (float)sh_post('temperature');
        if ($temp < 0 || $temp > 2) { $errors['temperature'] = 'Temperature must be between 0 and 2.'; }
        else { sh_ai_setting_save('temperature', (string)$temp); }

        $max = sh_int($_POST['max_tokens'] ?? 1200);
        if ($max < 100 || $max > 8000) { $errors['max_tokens'] = 'Max tokens must be between 100 and 8000.'; }
        else { sh_ai_setting_save('max_tokens', (string)$max); }

        $batch = sh_int($_POST['batch_size'] ?? 5);
        sh_ai_setting_save('batch_size', (string)max(1, min(10, $batch)));
        sh_ai_setting_save('max_retries', (string)max(0, min(5, sh_int($_POST['max_retries'] ?? 2))));

        sh_ai_setting_save('language', mb_substr(sh_post('language') ?: 'English', 0, 40));
        sh_ai_setting_save('tone', mb_substr(sh_post('tone') ?: 'Professional', 0, 40));
        sh_ai_setting_save('seo_mode', !empty($_POST['seo_mode']) ? '1' : '0');
        sh_ai_setting_save('auto_save', !empty($_POST['auto_save']) ? '1' : '0');
        sh_ai_setting_save('auto_publish', !empty($_POST['auto_publish']) ? '1' : '0');

        // Advanced: lets the pipeline be pointed at a compatible endpoint.
        $base = trim((string)($_POST['api_base'] ?? ''));
        if ($base !== '' && !preg_match('#^https?://#i', $base)) {
            $errors['api_base'] = 'The API base URL must start with http:// or https://';
        } else {
            sh_ai_setting_save('api_base', $base);
        }

        if (!$errors) {
            sh_log_line('admin', 'AI settings updated by ' . ($admin['email'] ?? ''));
            sh_flash('success', 'AI settings saved.');
            sh_redirect('admin/ai/settings.php');
        }
        $aiConfig = sh_ai_config();
    }
}

$provider = sh_ai_provider() ?? new ShOpenAIProvider();
$masked = $aiInstalled ? sh_ai_mask('api_key') : '';

$adminPage = 'ai_settings';
$adminTitle = 'AI Settings';
require dirname(__DIR__) . '/_layout.php';
?>
<div class="sh-aihead">
  <div>
    <h2 class="sh-aihead__title"><?= sh_icon('settings', 20) ?> AI Settings</h2>
    <p class="sh-aihead__sub">Provider credentials and generation defaults.</p>
  </div>
</div>

<?php sh_ai_subnav('settings'); ?>

<?php if ($errors): ?>
  <div class="sh-alert sh-alert--error"><?= sh_icon('x-circle', 17) ?>
    <div><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div></div>
<?php endif; ?>

<?php if (!$aiInstalled): ?>
  <div class="sh-panel">
    <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('box', 17) ?> Install AI Auto Work</h2></div>
    <div class="sh-panel__body">
      <p class="sh-panel__note" style="margin-bottom:12px">
        This creates the AI tables and adds SEO columns to categories. It never deletes data, never
        duplicates a column that already exists, and is safe to run more than once.
      </p>
      <form method="post"><?= sh_csrf_field() ?>
        <input type="hidden" name="form" value="install">
        <button class="sh-btn" type="submit"><?= sh_icon('check-circle', 15) ?> Run installer</button>
      </form>
      <?php if ($installReport): ?>
        <div class="sh-tablewrap" style="margin-top:14px">
          <table class="sh-table">
            <thead><tr><th>Item</th><th>Status</th><th>Detail</th></tr></thead>
            <tbody><?php foreach ($installReport as $r): ?>
              <tr><td><?= e((string)$r['item']) ?></td>
                <td><span class="sh-badge <?= $r['ok'] ? 'sh-badge--ok' : 'sh-badge--bad' ?>">
                  <?= $r['ok'] ? 'OK' : 'Failed' ?></span></td>
                <td class="sh-table__meta"><?= e((string)$r['note']) ?></td></tr>
            <?php endforeach; ?></tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>
<?php else: ?>

<div class="sh-aigrid">
  <div class="sh-panel">
    <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('cpu', 17) ?> Provider</h2></div>
    <div class="sh-panel__body">
      <form method="post" novalidate>
        <?= sh_csrf_field() ?>
        <input type="hidden" name="form" value="save">

        <div class="sh-field">
          <label class="sh-field__label" for="ai-provider">AI provider</label>
          <select class="sh-select" id="ai-provider" name="provider">
            <?php foreach (sh_ai_providers() as $k => $p): ?>
              <option value="<?= e($k) ?>" <?= $aiConfig['provider'] === $k ? 'selected' : '' ?>><?= e($p->label()) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="sh-field">
          <label class="sh-field__label" for="ai-key">API key</label>
          <input class="sh-input" id="ai-key" type="password" name="api_key" autocomplete="new-password"
                 placeholder="<?= $aiConfig['has_key'] ? 'A key is saved — leave blank to keep it' : 'sk-...' ?>">
          <span class="sh-field__hint">
            <?php if ($aiConfig['has_key']): ?>
              Stored encrypted. Currently: <code><?= e($masked) ?></code>. Never sent to the browser in full.
            <?php else: ?>
              Encrypted at rest and used only server-side.
            <?php endif; ?>
          </span>
        </div>
        <?php if ($aiConfig['has_key']): ?>
          <label class="sh-check" style="margin-bottom:12px">
            <input type="checkbox" name="clear_key" value="1"><span>Remove the stored API key</span></label>
        <?php endif; ?>

        <div class="sh-grid2">
          <div class="sh-field">
            <label class="sh-field__label" for="ai-model">Text model</label>
            <select class="sh-select" id="ai-model" name="model">
              <?php foreach ($provider->textModels() as $m): ?>
                <option value="<?= e($m) ?>" <?= $aiConfig['model'] === $m ? 'selected' : '' ?>><?= e($m) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="sh-field">
            <label class="sh-field__label" for="ai-imodel">Image model</label>
            <select class="sh-select" id="ai-imodel" name="image_model">
              <?php foreach ($provider->imageModels() as $m): ?>
                <option value="<?= e($m) ?>" <?= $aiConfig['image_model'] === $m ? 'selected' : '' ?>><?= e($m) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="sh-grid2">
          <div class="sh-field">
            <label class="sh-field__label" for="ai-temp">Temperature</label>
            <input class="sh-input" id="ai-temp" name="temperature" inputmode="decimal"
                   value="<?= e((string)$aiConfig['temperature']) ?>">
            <span class="sh-field__hint">0 = predictable, 1 = creative.</span>
          </div>
          <div class="sh-field">
            <label class="sh-field__label" for="ai-tokens">Max tokens</label>
            <input class="sh-input" id="ai-tokens" name="max_tokens" type="number" min="100" max="8000"
                   value="<?= e((string)$aiConfig['max_tokens']) ?>">
            <span class="sh-field__hint">Caps the cost of one generation.</span>
          </div>
        </div>

        <div class="sh-grid2">
          <div class="sh-field">
            <label class="sh-field__label" for="ai-lang">Default language</label>
            <input class="sh-input" id="ai-lang" name="language" value="<?= e($aiConfig['language']) ?>" placeholder="English">
          </div>
          <div class="sh-field">
            <label class="sh-field__label" for="ai-tone">Writing tone</label>
            <select class="sh-select" id="ai-tone" name="tone">
              <?php foreach (['Professional', 'Friendly', 'Persuasive', 'Technical', 'Casual', 'Luxury'] as $t): ?>
                <option value="<?= e($t) ?>" <?= $aiConfig['tone'] === $t ? 'selected' : '' ?>><?= e($t) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="sh-grid2">
          <div class="sh-field">
            <label class="sh-field__label" for="ai-batch">Bulk batch size</label>
            <input class="sh-input" id="ai-batch" name="batch_size" type="number" min="1" max="10"
                   value="<?= e((string)$aiConfig['batch_size']) ?>">
            <span class="sh-field__hint">Jobs processed per request (1–10).</span>
          </div>
          <div class="sh-field">
            <label class="sh-field__label" for="ai-retry">Retry limit</label>
            <input class="sh-input" id="ai-retry" name="max_retries" type="number" min="0" max="5"
                   value="<?= e((string)$aiConfig['max_retries']) ?>">
          </div>
        </div>

        <label class="sh-toggle" style="margin-bottom:10px">
          <input type="checkbox" name="seo_mode" value="1" <?= $aiConfig['seo_mode'] ? 'checked' : '' ?>>
          <span class="sh-toggle__track"></span><span>SEO optimisation</span></label>
        <label class="sh-toggle" style="margin-bottom:10px">
          <input type="checkbox" name="auto_save" value="1" <?= $aiConfig['auto_save'] ? 'checked' : '' ?>>
          <span class="sh-toggle__track"></span><span>Auto save generated text to the product</span></label>
        <label class="sh-toggle" style="margin-bottom:14px">
          <input type="checkbox" name="auto_publish" value="1" <?= $aiConfig['auto_publish'] ? 'checked' : '' ?>>
          <span class="sh-toggle__track"></span><span>Allow blog posts to be published directly (default is draft)</span></label>

        <div class="sh-field">
          <label class="sh-field__label" for="ai-base">API base URL <span class="sh-field__hint" style="font-weight:400">(advanced)</span></label>
          <input class="sh-input" id="ai-base" name="api_base" value="<?= e(sh_ai_setting('api_base', '')) ?>"
                 placeholder="https://api.openai.com">
          <span class="sh-field__hint">Leave blank for the official endpoint. Useful for an OpenAI-compatible gateway.</span>
        </div>

        <button class="sh-btn" type="submit"><?= sh_icon('check-circle', 15) ?> Save AI settings</button>
      </form>
    </div>
  </div>

  <div style="display:flex;flex-direction:column;gap:14px;min-width:0">
    <div class="sh-panel">
      <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('zap', 17) ?> Test configuration</h2></div>
      <div class="sh-panel__body">
        <?php if (!$aiConfig['has_key']): ?>
          <p class="sh-panel__note">Save an API key first, then you can verify it with one real request.</p>
        <?php else: ?>
          <p class="sh-panel__note" style="margin-bottom:10px">
            Sends one tiny request to the provider and reports the real answer.</p>
          <button class="sh-btn sh-btn--block" type="button" data-ai-test><?= sh_icon('zap', 15) ?> Test connection</button>
          <p class="sh-airesult" data-ai-test-result hidden></p>
        <?php endif; ?>
      </div>
    </div>

    <div class="sh-panel">
      <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('shield', 17) ?> Cost control</h2></div>
      <div class="sh-panel__body">
        <p class="sh-panel__note">
          Bulk jobs are queued and processed <strong><?= (int)$aiConfig['batch_size'] ?> at a time</strong>,
          never all at once. A single batch is capped at <?= (int)SH_AI_MAX_BATCH ?> jobs, each generation is
          capped at <?= (int)$aiConfig['max_tokens'] ?> tokens, and one admin is limited to 30 generations
          per minute. Every call is recorded with its token usage in
          <a href="<?= e(sh_url('admin/ai/history.php')) ?>">AI History</a>.
        </p>
      </div>
    </div>

    <div class="sh-panel">
      <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('box', 17) ?> Migration</h2></div>
      <div class="sh-panel__body">
        <p class="sh-panel__note" style="margin-bottom:10px">Safe to re-run after an update.</p>
        <form method="post"><?= sh_csrf_field() ?>
          <input type="hidden" name="form" value="install">
          <button class="sh-btn sh-btn--sm sh-btn--ghost" type="submit">Re-run migration</button>
        </form>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>
<?php require dirname(__DIR__) . '/_footer.php'; ?>
