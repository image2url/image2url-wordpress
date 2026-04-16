<?php
/**
 * Plugin Name: Image2URL Clipboard Booster
 * Plugin URI: https://www.image2url.com/
 * Description: Upload pasted images from the block editor to a remote image host and insert the returned URL without storing the file in the local media library.
 * Version: 0.12.2
 * Author: image2url
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * License: MIT
 * License URI: https://opensource.org/licenses/MIT
 * Text Domain: image2url-clipboard-booster
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
    exit;
}

define('IMAGE2URL_VERSION', '0.12.2');
define('IMAGE2URL_PLUGIN_FILE', __FILE__);
define('IMAGE2URL_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('IMAGE2URL_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once IMAGE2URL_PLUGIN_DIR . 'includes/class-image2url-security.php';
require_once IMAGE2URL_PLUGIN_DIR . 'includes/class-image2url-migrations.php';
require_once IMAGE2URL_PLUGIN_DIR . 'includes/class-image2url-plugin.php';

register_activation_hook(IMAGE2URL_PLUGIN_FILE, ['Image2URL_Plugin', 'activate']);
register_deactivation_hook(IMAGE2URL_PLUGIN_FILE, ['Image2URL_Plugin', 'deactivate']);

add_action('plugins_loaded', static function () {
    $instance = new Image2URL_Plugin();
    $instance->init();
});
