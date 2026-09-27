<?php
/**
 * Page-transition settings, shared by the admin panel and the public website.
 * Stored in the existing `settings` table:
 *   admin scope → transition_*        (admin panel navigation)
 *   user  scope → user_transition_*   (customer-facing website navigation)
 * Media lives in uploads/transitions/ via sh_upload_image().
 */

/** Settings-key prefix for a scope. */
function sh_transition_prefix(string $scope): string
{
    return $scope === 'user' ? 'user_transition_' : 'transition_';
}

/** Render the shared overlay markup + return body attributes for a scope. */
function sh_transition_config(string $scope = 'admin'): array
{
    $p = sh_transition_prefix($scope);
    $type = (string)sh_setting($p . 'type', 'fade');
    if (!in_array($type, ['fade', 'image', 'gif'], true)) { $type = 'fade'; }
    $duration = (int)sh_setting($p . 'duration', '400');
    $duration = max(150, min(5000, $duration));
    $media = basename((string)sh_setting($p . 'media', ''));
    $mediaUrl = '';
    if ($media !== '' && is_file(SH_UPLOAD_DIR . '/transitions/' . $media)) {
        $mediaUrl = sh_url('uploads/transitions/' . rawurlencode($media));
    }
    // Without a usable file the visual type silently falls back to the built-in fade.
    if ($type !== 'fade' && $mediaUrl === '') { $type = 'fade'; }
    return [
        'scope'     => $scope,
        'enabled'   => sh_setting($p . 'enabled', '1') === '1',
        'type'      => $type,
        'duration'  => $duration,
        'media'     => $media,
        'media_url' => $mediaUrl,
    ];
}

/** HTML attributes for <body> that the shared JS reads. */
function sh_transition_body_attrs(array $cfg): string
{
    return ' data-transition="' . ($cfg['enabled'] ? '1' : '0') . '"'
        . ' data-transition-scope="' . e($cfg['scope']) . '"'
        . ' data-transition-type="' . e($cfg['type']) . '"'
        . ' data-transition-duration="' . (int)$cfg['duration'] . '"'
        . ' data-transition-media="' . e($cfg['media_url']) . '"';
}

/** Full-screen overlay markup (only when enabled). */
function sh_transition_overlay(array $cfg): string
{
    if (!$cfg['enabled']) { return ''; }
    $html = '<div class="sh-pt" id="sh-pt" aria-hidden="true" style="--sh-pt-ms:' . (int)$cfg['duration'] . 'ms">';
    if ($cfg['media_url'] !== '') {
        $html .= '<div class="sh-pt__media"><img src="' . e($cfg['media_url']) . '" alt="" decoding="async"></div>';
    } else {
        $html .= '<div class="sh-pt__mark"><span></span><span></span><span></span></div>';
    }
    return $html . '</div>';
}

/** Duration presets for the settings form (ms => label). */
function sh_transition_presets(): array
{
    return [300 => '300 ms', 500 => '500 ms', 750 => '750 ms', 1000 => '1 second', 1500 => '1.5 seconds', 2000 => '2 seconds'];
}
