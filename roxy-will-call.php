<?php
/**
 * Plugin Name: Roxy Will Call (WooCommerce)
 * Description: Will Call system for Newport Roxy. Includes showing mode, offline caching, and GitHub auto-updates.
 * Version: 0.3.5
 * Author: Roxy AI Team
 */

if (!defined('ABSPATH')) exit;

define('ROXY_WC_VERSION', '0.3.5');
define('ROXY_WC_PLUGIN_FILE', __FILE__);
define('ROXY_WC_PLUGIN_DIR', plugin_dir_path(__FILE__));

require_once ROXY_WC_PLUGIN_DIR . 'includes/class-roxy-wc-updater.php';

add_action('init', function () {

    if (!class_exists('\RoxyWC\Updater')) return;

    \RoxyWC\Updater::init([
        'version' => ROXY_WC_VERSION,
        'slug' => 'roxy-will-call',
        'plugin_file' => plugin_basename(__FILE__),
        'github_repo' => 'Tototex/roxy-will-call'
    ]);

});
