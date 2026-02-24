<?php
/**
 * Plugin Name: StreamTube Legacy Migration
 * Description: Migrate legacy StreamTube video post_content data into enhanced wizard meta fields.
 * Version: 1.0.0
 * Author: StreamTube Team
 */

if (! defined('ABSPATH')) {
    exit;
}

define('STLM_VERSION', '1.0.1');
define('STLM_PLUGIN_FILE', __FILE__);
define('STLM_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('STLM_PLUGIN_URL', plugin_dir_url(__FILE__));
define('STLM_OPTION_STATE', 'stlm_migration_state');
define('STLM_OPTION_STATUS_MAP', 'stlm_migration_status_map');
define('STLM_OPTION_LOGS', 'stlm_migration_logs');

require_once STLM_PLUGIN_DIR . 'includes/class-legacy-content-parser.php';
require_once STLM_PLUGIN_DIR . 'includes/class-stlm-image-renamer.php';
require_once STLM_PLUGIN_DIR . 'includes/class-migration-manager.php';
require_once STLM_PLUGIN_DIR . 'admin/class-admin-page.php';

/**
 * Activation defaults.
 */
function stlm_activate()
{
    if (get_option(STLM_OPTION_STATE, null) === null) {
        add_option(STLM_OPTION_STATE, array(
            'run_id' => '',
            'last_run_at' => '',
            'last_mode' => '',
            'last_processed_post_id' => 0,
        ), '', false);
    }

    if (get_option(STLM_OPTION_STATUS_MAP, null) === null) {
        add_option(STLM_OPTION_STATUS_MAP, array(), '', false);
    }

    if (get_option(STLM_OPTION_LOGS, null) === null) {
        add_option(STLM_OPTION_LOGS, array(), '', false);
    }
}
register_activation_hook(__FILE__, 'stlm_activate');

add_action('plugins_loaded', function () {
    STLM_Migration_Manager::instance();
    STLM_Admin_Page::instance();
});

