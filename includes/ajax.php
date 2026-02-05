<?php
if (!defined('ABSPATH')) exit;

/**
 * AJAX Test Connection
 */
add_action('wp_ajax_guesty_test_connection', function () {

    if (!current_user_can('manage_options')) {
        wp_send_json_error('Permission denied');
    }

    $result = guesty_get_token();

    if ($result) {
        wp_send_json_success('API Connected Successfully');
    } else {
        wp_send_json_error('Invalid Client ID or Secret');
    }
});

/**
 * AJAX Test Be Connection
 */
add_action('wp_ajax_guesty_be_test_connection', function () {

    if (!current_user_can('manage_options')) {
        wp_send_json_error('Permission denied');
    }

    $result = guesty_be_get_token();

    if ($result) {
        wp_send_json_success('API Connected Successfully');
    } else {
        wp_send_json_error('Invalid Client ID or Secret');
    }
});

/**
 * Trending Region auto save
 */
add_action('wp_ajax_guesty_save_trending_regions', function () {
  if (!current_user_can('manage_options')) {
    wp_die();
  }
  $regions = isset($_POST['regions'])
    ? array_map('sanitize_text_field', $_POST['regions'])
    : [];

  update_option('guesty_trending_regions', $regions);

  wp_die();
});

/**
 * Featured Properties auto save
 */
add_action('wp_ajax_guesty_save_featured_properties', function () {
  if (!current_user_can('manage_options')) {
    wp_send_json_error();
  }

  $ids = isset($_POST['ids']) ? array_map('intval', $_POST['ids']) : [];
  update_option('guesty_featured_properties', $ids);

  wp_send_json_success();
});

/**
 * Manual Sync All Property
 */
add_action('wp_ajax_guesty_trigger_manual_sync', function() {
    check_ajax_referer('guesty_sync_nonce', 'nonce');

    if (get_transient('guesty_sync_lock')) {
		guesty_log('warning', 'Sync already running, skipped');
        wp_send_json_error(['message' => 'Sync already running!']);
    }
	
    // ✅ This schedules the task to run 1 second from now in the background.
    // We pass 'false' as an argument to tell our function it's NOT a cron run.
    wp_schedule_single_event(time(), 'guesty_cron_sync', [false]); 

    wp_send_json_success(['message' => 'Manual Background sync started! You can close this page now.']);
});

/**
 * Manual Sync Single Property
 */
add_action('wp_ajax_guesty_trigger_single_sync', function() {
    check_ajax_referer('guesty_sync_nonce', 'nonce');

    $pid = sanitize_text_field($_POST['pid'] ?? '');
    if (!$pid) wp_send_json_error(['message' => 'No Property ID provided']);

    if (get_transient('guesty_sync_lock')) {
        guesty_log('warning', 'Sync already running, skipped');
        wp_send_json_error(['message' => 'Sync already running!']);
    }

    // ✅ Schedule the single sync in the background
    // We pass the Property ID as an argument to the cron hook
    wp_schedule_single_event(time(), 'guesty_single_sync_event', [$pid]);

    wp_send_json_success(['message' => "Background sync for $pid started!"]);
});

/**
 * AJAX handler to return current sync status
 */
add_action('wp_ajax_guesty_get_sync_status', function() {
    check_ajax_referer('guesty_sync_nonce', 'nonce');
    wp_send_json_success(guesty_get_sync_ui());
});

/* =========================
   IMAGE DOWNLOAD
========================= */
add_filter('wp_image_editors', function () {
    return ['WP_Image_Editor_GD'];
});
function guesty_download_image_fast($url, $post_id) {
    if (!$url) return 0;

    $upload_dir = wp_upload_dir();
    $path_info = pathinfo(parse_url($url, PHP_URL_PATH));
    $filename = wp_unique_filename($upload_dir['path'], $path_info['basename']);
    $filepath = $upload_dir['path'] . '/' . $filename;

    // 1. Fast Download via cURL
    $ch = curl_init($url);
    $fp = fopen($filepath, 'wb');
    curl_setopt_array($ch, [
        CURLOPT_FILE           => $fp,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    curl_exec($ch);
    curl_close($ch);
    fclose($fp);

    if (!file_exists($filepath) || filesize($filepath) < 100) return 0;

    // 2. RESIZE & OPTIMIZE (1800px Width, Auto Height, 80% Quality)
    $editor = wp_get_image_editor($filepath);
    if (!is_wp_error($editor)) {
        $editor->set_quality(80); // Reduce file size significantly
        // width: 1800, height: null (auto), crop: false
        $editor->resize(1800, null, false); 
        $editor->save($filepath);
    }

    // 3. Register in Media Library
    $wp_filetype = wp_check_filetype($filename, null);
    $attachment = [
        'post_mime_type' => $wp_filetype['type'],
        'post_title'     => sanitize_file_name($filename),
        'post_status'    => 'inherit',
		'post_author'    => 'guesty_api',
    ];

    $attach_id = wp_insert_attachment($attachment, $filepath, $post_id);

    if (!is_wp_error($attach_id)) {
        require_once ABSPATH . 'wp-admin/includes/image.php';
        
        // ✅ Change: Allow thumbnail, guesty_medium, and guesty_large size
		add_filter('intermediate_image_sizes_advanced', function($sizes) {
			$allowed_sizes = ['thumbnail', 'guesty_medium', 'guesty_large'];
			return array_intersect_key($sizes, array_flip($allowed_sizes));
		});
        
        $attach_data = wp_generate_attachment_metadata($attach_id, $filepath);
        wp_update_attachment_metadata($attach_id, $attach_data);
        
        // Important: Clean up the filter so it doesn't affect other uploads
		remove_all_filters('intermediate_image_sizes_advanced');

        update_post_meta($attach_id, 'guesty_hash', md5($url));
        return (int) $attach_id;
    }

    return 0;
}

/**
 * Only run on the frontend, main query, and for properties archive/search
 */
add_action('pre_get_posts', 'guesty_archive_filter');
function guesty_archive_filter($query) {
    if (!is_admin() && $query->is_main_query() && (is_post_type_archive('properties') || is_tax() || is_search())) {
        
		// 1. PROPERTY AVAILABILITY CHECK
        if (isset($_GET['checkIn']) && !empty($_GET['checkIn']) && isset($_GET['checkOut']) && !empty($_GET['checkOut'])) {
            $check_in = sanitize_text_field($_GET['checkIn']);
            $check_out = sanitize_text_field($_GET['checkOut']);
			$city = isset($_GET['city']) ? sanitize_text_field($_GET['city']) : '';
			$guests = isset($_GET['minOccupancy']) ? intval($_GET['minOccupancy']) : 1;

            // This replaces the "AJAX" call logic
            $available_ids = get_available_ids_from_be($check_in, $check_out, $guests);
			
            if (!empty($available_ids)) {
                $query->set('post__in', $available_ids);
            } else {
                $query->set('post__in', array(0));
            }
        }
		
		$meta_query = array('relation' => 'AND');

        // Check for 'city' in the URL (?city=Bilinga)
        if (isset($_GET['city']) && !empty($_GET['city'])) {
            $meta_query[] = array(
                'key'     => 'guesty_address_city', // The actual meta key in your DB
                'value'   => sanitize_text_field($_GET['city']),
                'compare' => 'LIKE' // Use LIKE if you want partial matches, or '=' for exact
            );
        }

        // Check for 'minOccupancy' from your form
        if (isset($_GET['minOccupancy']) && !empty($_GET['minOccupancy'])) {
            $meta_query[] = array(
                'key'     => 'guesty_accommodates',
                'value'   => intval($_GET['minOccupancy']),
                'type'    => 'NUMERIC',
                'compare' => '>='
            );
        }

        if (!empty($meta_query)) {
            $query->set('meta_query', $meta_query);
        }
    }
}

/**
 * AJAX handler to return availability property in dates
 */
function get_available_ids_from_be($check_in, $check_out, $guests = 1) {
    $token = guesty_be_get_token();
	if ( ! $token ) {
        guesty_log('error', 'Token missing');
        return array(0);
    }

    $url = 'https://booking.guesty.com/api/listings/availability';
    
    // Use the exact parameter names that worked in your Postman
    $request_url = add_query_arg(array(
        'checkIn'      => $check_in,
        'checkOut'     => $check_out,
        'minOccupancy' => intval($guests),
        'limit'        => 100 // Get all available in one go
    ), $url);

    $response = wp_remote_get($request_url, array(
        'headers' => array(
            'Authorization' => 'Bearer ' . $token,
            'Accept'        => 'application/json',
        ),
        'timeout'   => 25,
        'sslverify' => false // Keep false for localhost
    ));

    if (is_wp_error($response)) {
        error_log('Guesty API Error: ' . $response->get_error_message());
        return array(0);
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);

    // DEBUG: Uncomment the line below to see the actual API response in your error log
    // error_log('Guesty Response: ' . print_r($body, true));

    // Some versions of the API return the array directly, others use a 'results' key
    $listings = isset($body['results']) ? $body['results'] : $body;

    if (!empty($listings) && is_array($listings)) {
        // Extract the Guesty IDs (the 24-char strings)
        $available_guesty_ids = array_column($listings, '_id');
        
        // Pass these to your mapping function
        return get_wp_ids_from_guesty_ids($available_guesty_ids);
    }

    return array(0); 
}

/**
 * AJAX handler to return BlockedDates in Single property
 */
add_action('wp_ajax_get_blocked_dates', 'ajax_get_guesty_dates');
add_action('wp_ajax_nopriv_get_blocked_dates', 'ajax_get_guesty_dates');
function ajax_get_guesty_dates() {
    $listing_id = isset($_GET['listing_id']) ? sanitize_text_field($_GET['listing_id']) : '';
    
    if (empty($listing_id)) {
        wp_send_json_error('ID missing');
    }

    $blocked_days = get_guesty_booking_blocked_dates($listing_id);
    wp_send_json_success($blocked_days);
}
function get_guesty_booking_blocked_dates($listing_id) {
    $cache_key = 'guesty_calendar_' . $listing_id;
    $blocked_dates = get_transient($cache_key);

    if (false === $blocked_dates) {
        $token = guesty_get_token();
        if (!$token) {
			guesty_log('Guesty Error', 'Token missing');
            return [];
        }

        // 1. Fixed the assignment and character issue
        $from = date('Y-m-d');
        $to = date('Y-m-d', strtotime('+2 year'));

        // 2. Fetch Calendar
        $calendar_url = "https://open-api.guesty.com/v1/availability-pricing/api/calendar/listings/{$listing_id}?startDate={$from}&endDate={$to}";
        $cal_response = wp_remote_get($calendar_url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'accept' => 'application/json'
            ],
            'timeout' => 30 // Increase timeout for long ranges
        ]);

        // 3. Check for WordPress errors (like connection timeouts)
        if (is_wp_error($cal_response)) {
            guesty_log('Guesty', 'Guesty API Connection Error: ' . $cal_response->get_error_message());
            return [];
        }

        $body = wp_remote_retrieve_body($cal_response);
        $response = json_decode($body);
		
        $blocked_dates = [];

        // 4. Ensure we actually got an array back
		if (isset($response->status) && $response->status === 200 && isset($response->data->days) && is_array($response->data->days)) {
            foreach ($response->data->days as $day) {
                // Logic: Block if status is NOT available OR if CTA is true
                $isUnavailable = (isset($day->status) && $day->status !== 'available');
                $isClosedToArrival = (isset($day->cta) && $day->cta === true);

                if ($isUnavailable || $isClosedToArrival) {
                    $blocked_dates[] = $day->date;
                }
            }
        } else {
			guesty_log('Guesty Error', 'Guesty API Data Error: Received ' . $body);
        }
        set_transient($cache_key, $blocked_dates, HOUR_IN_SECONDS);
    }
	delete_transient('guesty_calendar_' . $listing_id);
    return $blocked_dates;
}

/**
 * AJAX handler to calculate Quote
 */
add_action('wp_ajax_get_guesty_quote', 'get_guesty_quote_handler');
add_action('wp_ajax_nopriv_get_guesty_quote', 'get_guesty_quote_handler');
function get_guesty_quote_handler() {
    // 1. Get the token safely
    $token = guesty_get_token();
    if (!$token) {
		guesty_log('Guesty Error', 'Token missing');
		return;
	}
    
    // 2. Collect and sanitize data from Javascript
    // Make sure these keys match your formData.append() names!
    $listing_id = sanitize_text_field($_POST['listing_id']); 
    $check_in   = sanitize_text_field($_POST['checkIn']);
    $check_out  = sanitize_text_field($_POST['checkOut']);
    $guests     = isset($_POST['guests']) ? intval($_POST['guests']) : 1;

    // 3. Prepare the Guesty Open API Body
    $url = "https://open-api.guesty.com/v1/quotes";
    $body = [
        'listingId'            => $listing_id,
        'checkInDateLocalized' => $check_in,
        'checkOutDateLocalized' => $check_out,
        'guestsCount'       => $guests,
		'source' => 'manual'
    ];

    // 4. Make the request
    $response = wp_remote_post($url, [
        'headers' => [
            'Authorization' => 'Bearer ' . $token,
            'Content-Type'  => 'application/json',
            'accept'        => 'application/json'
        ],
        'body' => json_encode($body),
        'timeout' => 20 // Localhost can be slow, give it time
    ]);

    // 5. Handle Errors or Success
    if (is_wp_error($response)) {
        wp_send_json_error('Guesty API connection failed');
    }

    $status_code = wp_remote_retrieve_response_code($response);
    $data = json_decode(wp_remote_retrieve_body($response), true);

    if ($status_code === 200) {
        wp_send_json_success($data); // This sends { "success": true, "data": { ...guesty_data... } }
    } else {
        wp_send_json_error($data);
    }
}