<?php
/**
 * Plugin Name: Nara Webhook Handler
 * Description: This plugins handle custom webhooks like Tally form submission that nara website receives
 * Version: 1.0.0
 * Author: Kamal Ahmed
 */

if (!defined('ABSPATH')) exit;
defined('ABSPATH') || die('Direct Access is not allowed!');

define('NWH_VERSION', '1.0.0');
define('NWH_PLUGIN_DIR_PATH', plugin_dir_path(__FILE__));
define('NWH_PLUGIN_DIR_URI', plugin_dir_url(__FILE__));

require_once(NWH_PLUGIN_DIR_PATH . 'includes/class-tally-webhook.php');