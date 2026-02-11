<?php
/*
Plugin Name: Guesty Property Sync
Description: Sync properties from Guesty API with token validation.
Version: 1.0.0
Author: Ifox
*/

if (!defined('ABSPATH')) exit;

define('GUESTY_SYNC_PATH', plugin_dir_path(__FILE__));
define('GUESTY_SYNC_URL', plugin_dir_url(__FILE__));

add_image_size('guesty_medium', 400, 0, false);
add_image_size('guesty_large', 900, 0, false);

require_once GUESTY_SYNC_PATH . 'includes/admin-menu.php';
require_once GUESTY_SYNC_PATH . 'includes/api.php';
require_once GUESTY_SYNC_PATH . 'includes/cron.php';
require_once GUESTY_SYNC_PATH . 'includes/ajax.php';
require_once GUESTY_SYNC_PATH . 'includes/cpt-property.php';
require_once GUESTY_SYNC_PATH . 'includes/sync-properties.php';
require_once GUESTY_SYNC_PATH . 'includes/forntend/search-bar-code.php';
require_once GUESTY_SYNC_PATH . 'includes/forntend/property-map-code.php';
require_once GUESTY_SYNC_PATH . 'includes/forntend/featured-properties-code.php';
require_once GUESTY_SYNC_PATH . 'includes/forntend/trending-regions-code.php';

/**
 * Enqueue Admin Assets in Plugin
 */
add_action('admin_enqueue_scripts', 'guesty_enqueue_admin_assets');
function guesty_enqueue_admin_assets() {
	// Enqueue CSS
    wp_enqueue_style(
        'guesty-admin-css',
        GUESTY_SYNC_URL . 'assets/admin.css',
        [],
        time()
    );
	// Enqueue JS
	wp_enqueue_script('jquery-ui-sortable');
	wp_enqueue_script(
        'guesty-admin',
        GUESTY_SYNC_URL . 'assets/admin.js',
        ['jquery'],
        time(),
        true
    );
	wp_localize_script('guesty-admin', 'guestySync', [
        'ajax' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('guesty_sync_nonce'),
    ]);
}

/**
 * Enqueue Frontend Assets in Plugin
 */
add_action('wp_enqueue_scripts', 'guesty_enqueue_frontend_css', 100);
function guesty_enqueue_frontend_css() {
	// Enqueue CSS
    wp_enqueue_style(
        'frontend-css',
        GUESTY_SYNC_URL . 'includes/forntend/css/frontend.css',
        [],
        filemtime(GUESTY_SYNC_PATH . 'includes/forntend/css/frontend.css')
    );
	// Enqueue JS (depends on swiper for card sliders)
    wp_enqueue_script(
        'frontend-js',
        GUESTY_SYNC_URL . 'includes/forntend/js/frontend.js',
        array('swiper-js'),
        time(),
        true // Load in footer
    );
}

/**
 * Enqueue Archive Properties CSS (only on properties archive)
 */
add_action('wp_enqueue_scripts', 'guesty_enqueue_archive_properties_css', 100);
function guesty_enqueue_archive_properties_css() {
	if (!is_post_type_archive('properties')) {
		return;
	}
	wp_enqueue_style(
		'guesty-archive-properties-css',
		GUESTY_SYNC_URL . 'includes/forntend/css/archive-properties.css',
		[],
		time()
	);
}

/**
 * Enqueue Swiper Assets in Plugin
 */
add_action('wp_enqueue_scripts', 'guesty_enqueue_swiper_assets');
function guesty_enqueue_swiper_assets() {
    // Enqueue CSS
    wp_enqueue_style(
        'swiper-css',
        GUESTY_SYNC_URL . 'includes/forntend/css/swiper-bundle.min.css',
        [],
        '11.0.0'
    );
    // Enqueue JS
    wp_enqueue_script(
        'swiper-js',
        GUESTY_SYNC_URL . 'includes/forntend/js/swiper-bundle.min.js',
        [],
        '11.0.0',
        true // Load in footer
    );
}

/**
 * Enqueue litepicker Assets in Plugin
 */
add_action('wp_enqueue_scripts', 'guesty_enqueue_litepicker_assets');
function guesty_enqueue_litepicker_assets() {
    // Enqueue CSS
    wp_enqueue_style(
        'litepicker-css',
        GUESTY_SYNC_URL . 'includes/forntend/css/litepicker.css',
        [],
        '2.0.12'
    );
    // Enqueue JS
    wp_enqueue_script(
        'litepicker-js',
        GUESTY_SYNC_URL . 'includes/forntend/js/litepicker.js',
        [],
        '4.6.13',
        true // Load in footer
    );
}

/**
 * Single Property Template File in Pluign
 */
add_filter('single_template', 'guesty_load_property_template');
function guesty_load_property_template($single_template) {
    global $post;

    // Check if we are viewing our specific custom post type
    if ($post->post_type === 'properties') {
        $file = plugin_dir_path(__FILE__) . 'templates/single-properties.php';
        
        // If the file exists in our plugin, use it
        if (file_exists($file)) {
            return $file;
        }
    }
    return $single_template;
}

/**
 * List Property Template File in Pluign
 */
add_filter('archive_template', 'guesty_load_property_archive_template');
function guesty_load_property_archive_template($archive_template) {
    if (is_post_type_archive('properties')) {
        $file = plugin_dir_path(__FILE__) . 'templates/archive-properties.php';
        if (file_exists($file)) {
            return $file;
        }
    }
    return $archive_template;
}

/**
 * Store LOGS PER PROPERTY (IMPORTANT)
 */
function guesty_logs($level, $message, $post_id = 0, $context = []) {
    if (!$post_id) return;
    $logs = get_post_meta($post_id, 'guesty_property_logs', true) ?: [];
    $logs[] = [
        'time'    => current_time('mysql'),
        'level'   => strtoupper($level),
        'message' => $message,
        'context' => $context,
    ];
    // Keep last 50 logs per property
    $logs = array_slice($logs, -50);
    update_post_meta($post_id, 'guesty_property_logs', $logs);
}

/**
 * Guesty Log file table create in DB
 */
register_activation_hook(__FILE__, 'guesty_create_log_table');
function guesty_create_log_table() {
    global $wpdb;

    $table = $wpdb->prefix . 'guesty_sync_logs';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        log_type VARCHAR(20) NOT NULL,
        message TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}
function guesty_log($type, $message) {
    global $wpdb;
    $table = $wpdb->prefix . 'guesty_sync_logs';

    // Ensure table exists (e.g. if activation hook never ran or DB was migrated)
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
        guesty_create_log_table();
    }

    $wpdb->insert(
        $table,
        [
            'log_type' => sanitize_text_field($type),
            'message'  => sanitize_textarea_field($message),
        ],
        ['%s', '%s']
    );
}

/**
 * Schedule cron code
 */
register_activation_hook(__FILE__, 'guesty_schedule_cron');
register_deactivation_hook(__FILE__, 'guesty_clear_cron');