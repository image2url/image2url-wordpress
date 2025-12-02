<?php
/**
 * Plugin Name: Image2URL Clipboard Booster
 * Plugin URI: https://www.image2url.com/
 * Description: 让 Gutenberg 粘贴图片即上云，自动返回可长期访问的外链，减少站点 inode 占用。支持自定义上传端点与体积限制。
 * Version: 0.1.0
 * Author: image2url
 * License: MIT
 * Text Domain: image2url
 */

if (!defined('ABSPATH')) {
    exit;
}

define('IMAGE2URL_VERSION', '0.1.0');
define('IMAGE2URL_PLUGIN_FILE', __FILE__);
define('IMAGE2URL_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('IMAGE2URL_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once IMAGE2URL_PLUGIN_DIR . 'includes/class-image2url-security.php';
require_once IMAGE2URL_PLUGIN_DIR . 'includes/class-image2url-plugin.php';

add_action('plugins_loaded', static function () {
    $instance = new Image2URL_Plugin();
    $instance->init();
});
