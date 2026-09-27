<?php
/**
 * Admin page-transition settings. Stored in the existing `settings` table as
 * transition_* rows; media lives in uploads/transitions/ via sh_upload_image().
 */

function sh_transition_config(): array
{
    $type = (string)sh_setting('transition_type', 'fade');
    if (!in_array($type, ['fade', 'image', 'gif'], true)) { $type = 'fade'; }
    $duration = (int)sh_setting('transition_duration', '400');
    $duration = max(150, min(5000, $duration));
    $media = basename((string)sh_setting('transition_media', ''));
    $mediaUrl = '';
    if ($media !== '' && is_file(SH_UPLOAD_DIR . '/transitions/' . $media)) {
        $mediaUrl = sh_url('uploads/transitions/' . rawurlencode($media));
    }
    // Without a usable file the visual type silently falls back to the built-in fade.
    if ($type !== 'fade' && $mediaUrl === '') { $type = 'fade'; }
    return [
        'enabled'   => sh_setting('transition_enabled', '1') === '1',
        'type'      => $type,
        'duration'  => $duration,
        'media'     => $media,
        'media_url' => $mediaUrl,
    ];
}

/** Duration presets for the settings form (ms => label). */
function sh_transition_presets(): array
{
    return [300 => '300 ms', 500 => '500 ms', 750 => '750 ms', 1000 => '1 second', 1500 => '1.5 seconds', 2000 => '2 seconds'];
}
