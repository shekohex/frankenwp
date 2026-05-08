<?php
/**
 * Plugin Name: Auto Watermark
 * Plugin URI: https://github.com/StephenMiracle/frankenwp
 * Description: Adds configurable tiled text and logo watermarks to uploaded images and supported PDF documents.
 * Version: 0.1.0
 * Requires PHP: 8.3
 * Author: FrankenWP
 * License: MIT
 * Text Domain: auto-watermark
 * Domain Path: /languages
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/src/WatermarkPlugin.php';

add_action('plugins_loaded', static function (): void {
    $locale = function_exists('determine_locale') ? determine_locale() : get_locale();
    $phpTranslation = __DIR__ . '/languages/auto-watermark-' . $locale . '.l10n.php';

    if (is_readable($phpTranslation)) {
        load_textdomain('auto-watermark', $phpTranslation);
    }

    load_plugin_textdomain('auto-watermark', false, dirname(plugin_basename(__FILE__)) . '/languages');
    \FrankenWP\Watermark\WatermarkPlugin::boot(__FILE__);
});
