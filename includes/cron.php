<?php
if ( ! defined('ABSPATH') ) exit;

/**
 * Register custom cron schedules
 */
add_filter('cron_schedules', function ($schedules) {
    // 2. Custom Fortnightly (14 days)
    $schedules['fortnightly'] = [
        'interval' => 1209600,
        'display'  => __('Every 14 Days', 'guesty-sync')
    ];
    // 3. Custom Monthly (30 days)
    $schedules['monthly'] = [
        'interval' => 2592000,
        'display'  => __('Monthly (30 Days)', 'guesty-sync')
    ];
    // Note: 'daily', 'twicedaily', and 'weekly' are already built-in to WordPress.
    return $schedules;
});

/**
 * Schedule cron
 */
function guesty_schedule_cron() {
    guesty_clear_cron();

    if (get_option('guesty_cron_enabled') !== 'yes') return;

    $interval = get_option('guesty_sync_interval', 'daily'); // default daily
	
	// 1. Get the site's timezone (e.g., Asia/Kolkata)
    $timezone_string = wp_timezone_string();
    $timezone = new DateTimeZone($timezone_string);
    $date = new DateTime('now', $timezone);
    
    // 2. Target 1:00 AM in that timezone
    $date->setTime(1, 0, 0); 

    // 3. If 1:00 AM has already passed today, schedule for tomorrow
    if ($date->getTimestamp() < time()) {
        $date->modify('+1 day');
    }

    // 4. Final UTC timestamp
    $utc_timestamp = $date->getTimestamp();
	
    if (! wp_next_scheduled('guesty_cron_sync')) {
        wp_schedule_event($utc_timestamp, $interval, 'guesty_cron_sync');
    }
}

/**
 * Clear cron
 */
function guesty_clear_cron() {
    $timestamp = wp_next_scheduled('guesty_cron_sync');
    if ( $timestamp ) {
        wp_unschedule_event($timestamp, 'guesty_cron_sync');
    }
}

/**
 * Automated recurring event -> queue starter
 */
add_action('guesty_cron_sync', function() {
    // true = automated run
    guesty_start_all_sync_queue(true);
});

/**
 * Manual/auto starter event
 */
add_action('guesty_all_sync_start', 'guesty_start_all_sync_queue', 10, 1);


/**
 * Build queue and schedule first tick.
 */
function guesty_start_all_sync_queue($is_cron = true) {
	// 1. Check if a sync is already running to avoid overlaps
    if (get_transient('guesty_sync_lock')) {
        guesty_log('warning', ($is_cron ? 'Cron' : 'Manual') . ': Sync already running, skipped');
        return;
    }
	
	// 🔒 Start global lock (All mode)
    guesty_start_sync_lock('all', null, null);
    guesty_set_sync_ui([
        'running'          => true,
        'mode'             => 'all',
        'property_total'   => 0,
        'property_current' => 0,
        'image_current'    => 0,
        'image_total'      => 0,
        'status'           => $is_cron ? 'Cron: Automated Syncing...' : 'Manual: All Syncing...',
        'updated'          => time(),
    ]);
	
	// ✅ ONLY mark last run time if it's the automated schedule
    if ($is_cron) {
        update_option('guesty_cron_last_run', time(), false);
        guesty_log('info', 'Cron: Auto sync started at ' . wp_date('F j, Y g:i A'));
    } else {
        update_option('guesty_cron_last_run_manual', time(), false);
        guesty_log('info', 'Manual: All sync started by user at ' . wp_date('F j, Y g:i A'));
    }
	
	// 2. Get all properties from API
    $properties = guesty_get_properties();
    $ids = array_values(array_filter(array_column($properties, '_id')));
	// 3. CLEANUP: Now that we know who is active, draft the rest
    guesty_cleanup_orphaned_properties($ids);
	
	
    if (empty($ids)) {
        guesty_log('info', ($is_cron ? 'Cron' : 'Manual') . ': No properties found');
        guesty_finish_all_sync_queue($is_cron, true);
        return;
    }
	
    update_option('guesty_any_pending', array_fill_keys($ids, true), false);
    update_option('guesty_all_sync_queue', $ids, false);
    update_option('guesty_all_sync_total', count($ids), false);
    update_option('guesty_all_sync_is_cron', $is_cron ? 1 : 0, false);
    set_transient('guesty_sync_lock', 'cron_running', 2 * HOUR_IN_SECONDS);
    wp_schedule_single_event(time() + 1, 'guesty_all_sync_tick');
}

/**
 * Process one property per tick.
 */
add_action('guesty_all_sync_tick', function() {
    if (!get_transient('guesty_sync_lock')) {
        return;
    }
    $queue   = get_option('guesty_all_sync_queue', []);
    $total   = (int) get_option('guesty_all_sync_total', 0);
    $is_cron = (int) get_option('guesty_all_sync_is_cron', 1) === 1;
    if (empty($queue)) {
        guesty_finish_all_sync_queue($is_cron, false);
        return;
    }
    $pid = array_shift($queue);
    update_option('guesty_all_sync_queue', $queue, false);
    $current = $total - count($queue);
    guesty_set_sync_ui([
        'running'          => true,
        'mode'             => 'all',
        'property'         => $pid,
        'property_total'   => $total,
        'property_current' => $current,
        'image_current'    => 0,
        'image_total'      => 0,
        'status'           => 'Downloading optimized images',
        'updated'          => time(),
    ]);
    // Heavy work for ONE property only
    guesty_sync_single_property_background($pid, $is_cron);
    // keep lock alive for long queues
    set_transient('guesty_sync_lock', 'cron_running', 2 * HOUR_IN_SECONDS);
    // schedule next property
    wp_schedule_single_event(time() + 1, 'guesty_all_sync_tick');
});

/**
 * Final cleanup for all-sync queue
 */
function guesty_finish_all_sync_queue($is_cron = true, $no_properties = false) {
    delete_transient('guesty_sync_lock');
    delete_option('guesty_all_sync_queue');
    delete_option('guesty_all_sync_total');
    delete_option('guesty_all_sync_is_cron');
    $start = $is_cron
        ? get_option('guesty_cron_last_run')
        : get_option('guesty_cron_last_run_manual');
    $duration = $start ? (time() - $start) : 0;
    if ($no_properties) {
        // nothing else
    } else {
        guesty_log(
            'info',
            ($is_cron ? 'Cron: Auto sync finished at ' : 'Manual: All sync finished at ')
            . wp_date('F j, Y g:i A')
            . ' (Duration: ' . human_time_diff(0, $duration) . ')'
        );
    }
    guesty_end_sync_lock();
    delete_option('guesty_cron_last_run_manual');
    guesty_set_sync_ui([
        'running' => false,
        'status'  => 'Completed',
        'updated' => time(),
    ]);
}

/**
 * Hook the background single property to your processing function
 */
add_action('guesty_single_sync_event', 'guesty_run_single_background_worker', 10, 1);
function guesty_run_single_background_worker($pid) {
	// 🔒 Start global lock (All mode)
	guesty_start_sync_lock('single', null, $pid);
	
    // 1. Start the UI state
    guesty_set_sync_ui([
        'running' => true,
        'mode'    => 'single',
        'property' => $pid,
        'status'  => 'Starting single sync...',
        'image_current' => 0,
        'image_total' => 0
    ]);
	update_option('guesty_cron_last_run_manual_single', time(), false);
	guesty_log('info', 'Manual: Single Sync started by user at ' . wp_date('F j, Y g:i A'));

    // 2. Set the lock so other syncs don't start
    set_transient('guesty_sync_lock', 'single_running', 1 * HOUR_IN_SECONDS);

    // 3. Run the actual heavy syncing (Image downloads/resizing)
    guesty_sync_single_property_background($pid, false);

    // 4. Cleanup
    delete_transient('guesty_sync_lock');
	$start = get_option('guesty_cron_last_run_manual_single');
	$duration = $start ? (time() - $start) : 0;
	guesty_log('info', 'Manual: Single sync finished at ' . wp_date('F j, Y g:i A') .' (Duration: ' . human_time_diff(0, $duration) . ')');
	guesty_end_sync_lock();
	delete_option('guesty_cron_last_run_manual_single');
    guesty_set_sync_ui([
        'running' => false,
        'status'  => 'Completed-Single',
		'updated' => time()
    ]);
}
/**
 * Processes a single property in the background (Cron/CLI)
 */
function guesty_sync_single_property_background($pid, $is_cron = true) {
    // 1. Fetch Property Data
    $data = guesty_get_property_by_id($pid);
    if (empty($data['property'])) {
        guesty_log('error', "Cron: Property $pid not found in API.");
        return;
    }

    // 2. Initial Setup (Upsert and Map)
    $post_id = guesty_upsert_property($data['property']);
    $url_map = get_post_meta($post_id, 'guesty_image_url_map', true) ?: [];
    $gallery = get_post_meta($post_id, 'guesty_gallery_ids', true) ?: [];
    $api_images = $data['images'] ?? [];
    
    // 3. Cleanup old images
    $api_hashes = [];
    foreach ($api_images as $img) {
        if (!empty($img['original'])) $api_hashes[md5($img['original'])] = true;
    }
    foreach ($url_map as $hash => $attachment_id) {
        if (!isset($api_hashes[$hash])) {
            wp_delete_attachment($attachment_id, true);
            unset($url_map[$hash]);
            $gallery = array_diff($gallery, [$attachment_id]);
        }
    }

    // 4. Image Loop
	$image_total_count = count($api_images);
	$new_gallery = [];
	
    foreach ($api_images as $index => $img_data) {
        $url = $img_data['original'] ?? '';
        if (!$url) continue;

        $hash = md5($url);
        $attachment_id = null;
		
		// Check if we already have this image
        if (isset($url_map[$hash])) {
            $attachment_id = $url_map[$hash];
            
            // Double check the attachment still exists in the media library
            if (!get_post($attachment_id)) {
                $attachment_id = null; 
            }
        }

        // If we don't have it (or it was deleted), download it
        if (!$attachment_id) {
            $attachment_id = guesty_download_image_fast($url, $post_id);
            if ($attachment_id) {
                $url_map[$hash] = $attachment_id;
            }
        }

        // If we have a valid ID now, add it to our NEW gallery order
        if ($attachment_id) {
            $new_gallery[] = $attachment_id;
            
            // Set the first image as the featured image
            if ($index === 0) {
                set_post_thumbnail($post_id, $attachment_id);
            }
        }
		
		// ✅ UPDATE UI: After each image is processed (downloaded or skipped)
		guesty_set_sync_ui([
			'status'        => 'Downloading optimized images',
			'image_current' => $index + 1,
			'image_total'   => $image_total_count,
			'updated'       => time()
		]);
    }
	
    // 5. Final Meta Update
    update_post_meta($post_id, 'guesty_gallery_ids', $new_gallery);
    update_post_meta($post_id, 'guesty_image_url_map', $url_map);
    update_post_meta($post_id, 'guesty_sync_status', 'Completed');
	update_option('guesty_last_sync', time());
    
	if ($is_cron) {
		guesty_log('info', "Cron: Property $post_id synchronized successfully.");
	} else {
		guesty_log('info', "Manual: Property $post_id synchronized successfully.");
	}
}

/**
 * Drafts properties that were not part of the most recent sync.
 */
function guesty_cleanup_orphaned_properties($active_api_ids) {
    // 1. Get all currently published properties in WP
    $wp_properties = get_posts([
        'post_type'      => 'properties',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'fields'         => 'ids',
    ]);

    foreach ($wp_properties as $post_id) {
        $api_id = get_post_meta($post_id, 'guesty_id', true);

        // 2. If the WP property is NOT in the list of active IDs from the API
        if (!in_array($api_id, $active_api_ids)) {
            wp_update_post([
                'ID'          => $post_id,
                'post_status' => 'draft' // Hide it!
            ]);
            guesty_log('info', "Cleanup: Property $post_id drafted because it's no longer in the API.");
        }
    }
}

/**
 * HELPER FUNCTIONS (NEXT RUN + REMAINING TIME)
 */
function guesty_get_next_cron_time() {
    return wp_next_scheduled('guesty_cron_sync');
}
function guesty_get_remaining_time() {
    $next = guesty_get_next_cron_time();
    return $next ? max(0, $next - time()) : false;
}
function guesty_human_time($seconds) {
    if ($seconds === false) return 'Not scheduled';
    if ($seconds <= 0) return 'Running now';
    $units = [
        'day'    => 86400,
        'hour'   => 3600,
        'minute' => 60,
        'second' => 1,
    ];
    foreach ($units as $label => $size) {
        $value = floor($seconds / $size);
        if ($value > 0) {
            return $value . ' ' . $label . ($value > 1 ? 's' : '');
        }
    }
}
function guesty_get_cron_status_data() {
    $last   = get_option('guesty_cron_last_run');
    $next   = wp_next_scheduled('guesty_cron_sync');
    $remain = $next ? max(0, $next - time()) : false;
    return [
        'last'   => $last,
        'next'   => $next,
        'remain' => $remain,
    ];
}