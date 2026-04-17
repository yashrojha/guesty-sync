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
 * Manual Sync All Properties
 */
add_action('wp_ajax_guesty_trigger_manual_sync', function() {
    check_ajax_referer('guesty_sync_nonce', 'nonce');

    if (get_transient('guesty_sync_lock')) {
		guesty_log('warning', 'Sync already running, skipped');
        wp_send_json_error(['message' => 'Sync already running!']);
    }
	
    // Start queue in background
    wp_schedule_single_event(time(), 'guesty_all_sync_start', [false]);

    wp_send_json_success(['message' => 'Manual background sync queued.']);
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

        // Order & pagination for properties archive
        if (is_post_type_archive('properties')) {
            $query->set('orderby', 'title');
            $query->set('order', 'ASC');
            $query->set('posts_per_page', 12);
        }
    }
}

/**
 * Map Booking Engine availability to WordPress post IDs for the given dates
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

// AVAILABILITY CHECK

/**
 * AJAX handler: check if a listing is available for a date range.
 * Uses the Calendar API — more accurate than the quote endpoint.
 * Returns wp_send_json_success(['available'=>true]) or wp_send_json_error('reason').
 */
add_action( 'wp_ajax_guesty_check_availability',        'guesty_check_availability_handler' );
add_action( 'wp_ajax_nopriv_guesty_check_availability', 'guesty_check_availability_handler' );
function guesty_check_availability_handler() {
    $listing_id = sanitize_text_field( $_POST['listing_id'] ?? '' );
    $check_in   = sanitize_text_field( $_POST['check_in']   ?? '' );
    $check_out  = sanitize_text_field( $_POST['check_out']  ?? '' );

    if ( ! $listing_id || ! $check_in || ! $check_out ) {
        wp_send_json_error( 'Missing required parameters.' );
    }

    $ci_obj = DateTime::createFromFormat( 'Y-m-d', $check_in );
    $co_obj = DateTime::createFromFormat( 'Y-m-d', $check_out );
    if (
        ! $ci_obj || ! $co_obj
        || $ci_obj->format( 'Y-m-d' ) !== $check_in
        || $co_obj->format( 'Y-m-d' ) !== $check_out
        || $co_obj <= $ci_obj
    ) {
        wp_send_json_error( 'Invalid date format or range.' );
    }

    $result = guesty_check_availability( $listing_id, $check_in, $check_out );

    if ( $result['available'] ) {
        wp_send_json_success( [ 'available' => true ] );
    } else {
        wp_send_json_error( $result['reason'] );
    }
}

// INSTANT BOOKING HANDLERS

/**
 * Create or update a Guesty guest profile
 */
add_action('wp_ajax_guesty_create_booking_guest', 'guesty_create_booking_guest_handler');
add_action('wp_ajax_nopriv_guesty_create_booking_guest', 'guesty_create_booking_guest_handler');
function guesty_create_booking_guest_handler() {
    check_ajax_referer('guesty_booking_nonce', 'nonce');

    $first_name = sanitize_text_field($_POST['firstName'] ?? '');
    $last_name  = sanitize_text_field($_POST['lastName'] ?? '');
    $email      = sanitize_email($_POST['email'] ?? '');
    $phone      = sanitize_text_field($_POST['phone'] ?? '');

    if (!$first_name || !$last_name) {
        wp_send_json_error('First and last name are required.');
    }

    $token = guesty_get_token();
    if (!$token) {
        wp_send_json_error('Authentication failed. Please try again.');
    }

    $body = ['firstName' => $first_name, 'lastName' => $last_name];
    if ($email) $body['email'] = $email;
    if ($phone) $body['phone'] = $phone;

    $response = wp_remote_post('https://open-api.guesty.com/v1/guests-crud', [
        'headers' => [
            'Authorization' => 'Bearer ' . $token,
            'Content-Type'  => 'application/json',
            'accept'        => 'application/json',
        ],
        'body'    => json_encode($body),
        'timeout' => 20,
    ]);

    if (is_wp_error($response)) {
        wp_send_json_error('Failed to connect. Please try again.');
    }

    $code = wp_remote_retrieve_response_code($response);
    $data = json_decode(wp_remote_retrieve_body($response), true);

    if ($code === 200 || $code === 201) {
        wp_send_json_success(['guestId' => $data['_id']]);
    } else {
        $msg = $data['error']['message'] ?? ($data['message'] ?? 'Failed to create guest profile.');
        wp_send_json_error($msg);
    }
}

/**
 * Create a Guesty reservation and attach a GuestyPay payment method.
 *
 * Flow:
 *   1. Accept quoteId from the frontend (locked-in pricing from page load)
 *   2. Create reservation — include quoteId in body so Guesty locks the price
 *   3. Resolve payment provider (frontend → admin setting → listing → account default)
 *   4. Attach GuestyPay token (_id) to the guest via POST /v1/guests/{id}/payment-methods
 */
add_action('wp_ajax_guesty_create_booking_reservation', 'guesty_create_booking_reservation_handler');
add_action('wp_ajax_nopriv_guesty_create_booking_reservation', 'guesty_create_booking_reservation_handler');
function guesty_create_booking_reservation_handler() {
    check_ajax_referer('guesty_booking_nonce', 'nonce');

    $listing_id          = sanitize_text_field($_POST['listingId']         ?? '');
    $check_in            = sanitize_text_field($_POST['checkIn']           ?? '');
    $check_out           = sanitize_text_field($_POST['checkOut']          ?? '');
    $guests              = max(1, intval($_POST['guestsCount']             ?? 1));
    $guest_id            = sanitize_text_field($_POST['guestId']           ?? '');
    $guesty_token        = sanitize_text_field($_POST['guestyToken']       ?? '');
    $quote_id            = sanitize_text_field($_POST['quoteId']           ?? '');
    $rate_plan_id        = sanitize_text_field($_POST['ratePlanId']        ?? '');
    $frontend_provider   = sanitize_text_field($_POST['paymentProviderId'] ?? '');

    $raw_items     = stripslashes($_POST['invoiceItems'] ?? '[]');
    $invoice_items = json_decode($raw_items, true);
    if (!is_array($invoice_items)) $invoice_items = [];

    if (!$listing_id || !$check_in || !$check_out || !$guest_id || !$guesty_token) {
        wp_send_json_error('Missing required booking fields.');
    }

    $token = guesty_get_token();
    if (!$token) {
        wp_send_json_error('Authentication failed. Please try again.');
    }

    // ── Step 1: Create reservation ────────────────────────────────────────
    // Two endpoints exist:
    //   POST /v1/reservations-v3/quote  — when we have a quoteId (locks price + coupon)
    //   POST /v1/reservations-v3        — quick booking without a quote
    // The legacy POST /v1/reservations does NOT accept quoteId.
    // See: https://open-api-docs.guesty.com/docs/reservations-v3-booking-flow
    if ($quote_id) {
        $res_url  = 'https://open-api.guesty.com/v1/reservations-v3/quote';
        $res_body = [
            'quoteId'  => $quote_id,
            'status'   => 'confirmed',
            'guestId'  => $guest_id,
        ];
        if ($rate_plan_id) {
            $res_body['ratePlanId'] = $rate_plan_id;
        }
    } else {
        $res_url  = 'https://open-api.guesty.com/v1/reservations-v3';
        $res_body = [
            'listingId'             => $listing_id,
            'checkInDateLocalized'  => $check_in,
            'checkOutDateLocalized' => $check_out,
            'guestsCount'           => $guests,
            'guestId'               => $guest_id,
            'status'                => 'confirmed',
            'source'                => 'manual',
        ];
    }

    guesty_log('reservation_request', 'URL: ' . $res_url . ' | Body: ' . json_encode($res_body));

    $res_response = wp_remote_post($res_url, [
        'headers' => [
            'Authorization' => 'Bearer ' . $token,
            'Content-Type'  => 'application/json',
            'accept'        => 'application/json',
        ],
        'body'    => json_encode($res_body),
        'timeout' => 30,
    ]);

    if (is_wp_error($res_response)) {
        guesty_log('reservation_error', 'WP_Error: ' . $res_response->get_error_message());
        wp_send_json_error('Network error creating reservation. Please try again.');
    }

    $res_code = wp_remote_retrieve_response_code($res_response);
    $res_body_raw = wp_remote_retrieve_body($res_response);
    $res_data = json_decode($res_body_raw, true);

    // Log the full Guesty response — visible in wp-content/debug.log
    guesty_log('reservation_response', 'HTTP ' . $res_code . ' | Body: ' . substr($res_body_raw, 0, 1000));

    if ($res_code !== 200 && $res_code !== 201) {
        $err = '';
        if (is_array($res_data)) {
            $err = $res_data['error']['message']
                ?? $res_data['errors'][0]['message']
                ?? $res_data['message']
                ?? $res_data['error']
                ?? '';
        }
        if (!$err) {
            $err = 'Reservation failed (HTTP ' . $res_code . '). Raw: ' . substr($res_body_raw, 0, 300);
        }
        guesty_log('reservation_error', 'HTTP ' . $res_code . ' | Error: ' . $err . ' | Full body: ' . substr($res_body_raw, 0, 1000));
        wp_send_json_error($err);
    }

    // V3 endpoints return "reservationId"; legacy returns "_id"
    $reservation_id    = $res_data['reservationId'] ?? $res_data['_id'] ?? '';
    $confirmation_code = $res_data['confirmationCode'] ?? '';

    guesty_log('reservation_success', 'ReservationId: ' . $reservation_id . ' | ConfirmationCode: ' . $confirmation_code);

    // ── Step 2: Resolve payment provider ID ──────────────────────────────
    // Priority: frontend-passed → admin override → listing → account default
    $provider_id = $frontend_provider ?: guesty_get_payment_provider_id($listing_id);

    if (!$provider_id) {
        // No provider configured — reservation created but payment not attached
        wp_send_json_success([
            'reservationId'    => $reservation_id,
            'confirmationCode' => $confirmation_code,
            'paymentStatus'    => 'pending',
            'paymentNote'      => 'Payment provider not configured. Please attach payment manually in Guesty.',
        ]);
    }

    // ── Step 3: Attach GuestyPay token to the guest ───────────────────────
    // _id   = GuestyPay tokenization ID returned by guestyTokenization.submit()
    // reuse = true so the method can be reused for future reservations
    $pay_body = [
        '_id'               => $guesty_token,
        'paymentProviderId' => $provider_id,
        'reservationId'     => $reservation_id,
        'reuse'             => true,
    ];

    guesty_log('payment_method_request', 'GuestId: ' . $guest_id . ' | Provider: ' . $provider_id . ' | Token: ' . substr($guesty_token, 0, 12) . '…');

    $pay_response = wp_remote_post(
        "https://open-api.guesty.com/v1/guests/{$guest_id}/payment-methods",
        [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
                'accept'        => 'application/json',
            ],
            'body'    => json_encode($pay_body),
            'timeout' => 30,
        ]
    );

    $pay_status   = 'pending';
    $pay_err_msg  = '';

    if (is_wp_error($pay_response)) {
        $pay_err_msg = 'Payment attachment failed (network error).';
        guesty_log('payment_method_error', 'WP_Error: ' . $pay_response->get_error_message());
    } else {
        $pay_code     = wp_remote_retrieve_response_code($pay_response);
        $pay_body_raw = wp_remote_retrieve_body($pay_response);
        $pay_data     = json_decode($pay_body_raw, true);

        guesty_log('payment_method_response', 'HTTP ' . $pay_code . ' | Body: ' . substr($pay_body_raw, 0, 500));

        if ($pay_code === 200 || $pay_code === 201) {
            $pay_status = 'success';
        } else {
            $pay_err_msg = '';
            if (is_array($pay_data)) {
                $pay_err_msg = $pay_data['error']['message']
                    ?? $pay_data['errors'][0]['message']
                    ?? $pay_data['message']
                    ?? '';
            }
            if (!$pay_err_msg) {
                $pay_err_msg = 'Payment could not be attached (HTTP ' . $pay_code . ').';
            }
            guesty_log('payment_method_error', 'HTTP ' . $pay_code . ' | Error: ' . $pay_err_msg);
        }
    }

    wp_send_json_success([
        'reservationId'    => $reservation_id,
        'confirmationCode' => $confirmation_code,
        'paymentStatus'    => $pay_status,
        'paymentNote'      => $pay_err_msg ?: null,
    ]);
}

/**
 * Add coupons to an existing quote using the correct Guesty API endpoint
 */
add_action('wp_ajax_guesty_apply_booking_coupon', 'guesty_apply_booking_coupon_handler');
add_action('wp_ajax_nopriv_guesty_apply_booking_coupon', 'guesty_apply_booking_coupon_handler');
function guesty_apply_booking_coupon_handler() {
    check_ajax_referer('guesty_booking_nonce', 'nonce');

    $quote_id = sanitize_text_field($_POST['quoteId'] ?? '');
    $coupon   = sanitize_text_field($_POST['coupon'] ?? '');

    if (!$quote_id) {
        wp_send_json_error('Missing quote ID. Please refresh the page and try again.');
    }

    if (!$coupon) {
        wp_send_json_error('Please enter a coupon code.');
    }

    $token = guesty_get_token();
    if (!$token) {
        wp_send_json_error('Authentication failed.');
    }

    // Use the correct Guesty API endpoint for adding coupons to quotes
    // POST /quotes/{quoteId}/coupons with coupons array in body
    // The query parameter should be a boolean true, not string "true"
    $url = "https://open-api.guesty.com/v1/quotes/$quote_id/coupons?" . http_build_query([
        'mergeAccommodationFarePriceComponents' => true
    ]);

    $response = wp_remote_post($url, [
        'headers' => [
            'Authorization' => 'Bearer ' . $token,
            'Content-Type'  => 'application/json',
            'accept'        => 'application/json',
        ],
        'body'    => json_encode([
            'coupons' => [$coupon]
        ]),
        'timeout' => 20,
    ]);

    if (is_wp_error($response)) {
        guesty_log('coupon_error', 'WP_Error: ' . $response->get_error_message());
        wp_send_json_error('Request failed. Please try again.');
    }

    $code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    // Log the response for debugging
    guesty_log('coupon_response', "HTTP {$code} | Coupon: {$coupon} | Body: " . substr($body, 0, 1000));

    // Check if the coupon was successfully applied
    // Success = HTTP 200 AND the response has _id and rates structure
    // The Guesty API doesn't always return "status": "valid" for coupon responses
    if ($code === 200 && isset($data['_id']) && isset($data['rates']['ratePlans'][0]['money']['money'])) {
        // Check if coupon was actually applied by looking for it in the coupons array
        $applied_coupons = $data['coupons'] ?? [];
        $coupon_found = false;
        
        foreach ($applied_coupons as $applied) {
            if (isset($applied['couponCode']) && strtoupper($applied['couponCode']) === strtoupper($coupon)) {
                $coupon_found = true;
                if (isset($applied['isValid']) && $applied['isValid'] === false) {
                    wp_send_json_error('Coupon code "' . $coupon . '" is not valid for this booking.');
                }
                break;
            }
        }
        
        if (!$coupon_found) {
            wp_send_json_error('Coupon was not applied. Please check the code and try again.');
        }
        
        wp_send_json_success($data);
    } else {
        $msg = $data['error']['message'] ?? ($data['message'] ?? 'Invalid coupon code or coupon cannot be applied to this booking.');
        guesty_log('coupon_error', "Failed - HTTP {$code} | Message: {$msg}");
        wp_send_json_error($msg);
    }
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
    
    // 2. Collect and sanitize data from JavaScript
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

    if (in_array($status_code, [200, 201], true)) {
        wp_send_json_success($data);
    } else {
        wp_send_json_error($data);
    }
}

/**
 * AJAX: Reset custom bedroom data so the next Guesty sync re-seeds it
 */
add_action('wp_ajax_guesty_reset_custom_bedrooms', function () {
    if (!current_user_can('edit_posts')) {
        wp_send_json_error('Permission denied.');
    }

    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'guesty_sync_nonce')) {
        wp_send_json_error('Invalid nonce.');
    }

    $post_id = absint($_POST['post_id'] ?? 0);
    if (!$post_id || get_post_type($post_id) !== 'properties') {
        wp_send_json_error('Invalid property.');
    }

    delete_post_meta($post_id, 'guesty_custom_bedrooms');

    wp_send_json_success('Custom bedroom data cleared. Re-sync this property to repopulate from Guesty.');
});