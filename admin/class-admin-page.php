<?php

if (! defined('ABSPATH')) {
    exit;
}

class STLM_Admin_Page
{
    /**
     * @var STLM_Admin_Page|null
     */
    private static $instance = null;

    /**
     * @return STLM_Admin_Page
     */
    public static function instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        add_action('admin_menu', array($this, 'register_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));

        add_action('wp_ajax_stlm_get_dashboard_data', array($this, 'ajax_get_dashboard_data'));
        add_action('wp_ajax_stlm_run_bulk_migration', array($this, 'ajax_run_bulk'));
        add_action('wp_ajax_stlm_run_chunk_migration', array($this, 'ajax_run_chunk'));
        add_action('wp_ajax_stlm_run_single_migration', array($this, 'ajax_run_single'));
    }

    public function register_menu()
    {
        add_menu_page(
            __('StreamTube Migration', 'streamtube-legacy-migrator'),
            __('StreamTube Migration', 'streamtube-legacy-migrator'),
            'manage_options',
            'stlm-migration',
            array($this, 'render_page'),
            'dashicons-database-import',
            59
        );
    }

    public function enqueue_assets($hook_suffix)
    {
        if ($hook_suffix !== 'toplevel_page_stlm-migration') {
            return;
        }

        wp_enqueue_style(
            'stlm-admin',
            STLM_PLUGIN_URL . 'assets/admin.css',
            array(),
            STLM_VERSION
        );

        wp_enqueue_script(
            'stlm-admin',
            STLM_PLUGIN_URL . 'assets/admin.js',
            array('jquery'),
            STLM_VERSION,
            true
        );

        wp_localize_script('stlm-admin', 'STLM_DATA', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('stlm_admin_nonce'),
            'per_page' => 20,
            'i18n' => array(
                'running' => __('Migration running...', 'streamtube-legacy-migrator'),
                'done' => __('Migration completed.', 'streamtube-legacy-migrator'),
                'error' => __('Migration request failed.', 'streamtube-legacy-migrator'),
                'confirm_bulk' => __('Run BULK migration for all legacy videos?', 'streamtube-legacy-migrator'),
                'confirm_bulk_dry_run' => __('Run BULK DRY RUN for all legacy videos? No data will be saved.', 'streamtube-legacy-migrator'),
            ),
        ));
    }

    public function render_page()
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Access denied.', 'streamtube-legacy-migrator'));
        }

        $payload = STLM_Migration_Manager::instance()->get_dashboard_payload();
        include STLM_PLUGIN_DIR . 'admin/views/migration-dashboard.php';
    }

    public function ajax_get_dashboard_data()
    {
        $this->validate_ajax_request();
        $filter = isset($_POST['filter']) ? sanitize_text_field(wp_unslash($_POST['filter'])) : 'all';
        $page = isset($_POST['page']) ? (int) $_POST['page'] : 1;
        $per_page = isset($_POST['per_page']) ? (int) $_POST['per_page'] : 20;

        $payload = STLM_Migration_Manager::instance()->get_dashboard_payload($filter, $page, $per_page);
        wp_send_json_success($payload);
    }

    public function ajax_run_bulk()
    {
        $this->validate_ajax_request();
        $force = ! empty($_POST['force']) && sanitize_text_field(wp_unslash($_POST['force'])) === '1';
        $dry_run = ! empty($_POST['dry_run']) && sanitize_text_field(wp_unslash($_POST['dry_run'])) === '1';
        $result = STLM_Migration_Manager::instance()->run_bulk($force, $dry_run);
        wp_send_json_success($result);
    }

    public function ajax_run_chunk()
    {
        $this->validate_ajax_request();
        $force = ! empty($_POST['force']) && sanitize_text_field(wp_unslash($_POST['force'])) === '1';
        $dry_run = ! empty($_POST['dry_run']) && sanitize_text_field(wp_unslash($_POST['dry_run'])) === '1';
        $limit = isset($_POST['limit']) ? (int) $_POST['limit'] : 20;
        $result = STLM_Migration_Manager::instance()->run_chunk($limit, $force, $dry_run);
        wp_send_json_success($result);
    }

    public function ajax_run_single()
    {
        $this->validate_ajax_request();
        $post_id = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;
        $force = ! empty($_POST['force']) && sanitize_text_field(wp_unslash($_POST['force'])) === '1';
        $dry_run = ! empty($_POST['dry_run']) && sanitize_text_field(wp_unslash($_POST['dry_run'])) === '1';
        if ($post_id <= 0) {
            wp_send_json_error(array('message' => 'Invalid post ID.'));
        }

        $result = STLM_Migration_Manager::instance()->run_single($post_id, $force, $dry_run);
        wp_send_json_success($result);
    }

    private function validate_ajax_request()
    {
        if (! current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Access denied.'), 403);
        }
        check_ajax_referer('stlm_admin_nonce', 'nonce');
    }
}

