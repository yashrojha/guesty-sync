<?php
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

// Delete log table
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}guesty_sync_logs");

// Delete options
//delete_option('guesty_client_id');
//delete_option('guesty_client_secret_key');
//delete_option('guesty_access_token');
//delete_option('guesty_token_expires');

// Optional: delete CPT posts
/*$posts = get_posts([
    'post_type'   => 'properties',
    'numberposts' => -1,
    'fields'      => 'ids',
]);

foreach ($posts as $post_id) {
    wp_delete_post($post_id, true);
}
*/
