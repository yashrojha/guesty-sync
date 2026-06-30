<?php
if (!defined('ABSPATH')) exit;

/**
 * Collect the IDs of true "upsell-type" additional fees only.
 *
 * Auto-applied fees (isAutomated: true, e.g. Credit Surcharge, Booking Fee) are
 * applied by Guesty at quote-creation time when source/channel matches their
 * automationSources / automationPlatforms — those MUST NOT be sent to the
 * /additional-fees/inquiries/{id}/upsells endpoint or Guesty replies with:
 *   "Some additional fees are not upsell fees" (VALIDATION_ERROR).
 *
 * Steps:
 *   1. GET /additional-fees/listing/{id}  — listing-level overrides
 *   2. GET /additional-fees/account       — account-wide fees
 *   Merge (listing wins on same _id), keep only fees that are:
 *     - enabled on the bookingEngine channel, AND
 *     - NOT auto-applied (isAutomated falsy) — these are true opt-in upsells.
 *   Then exclude anything already on the invoice.
 */
function guesty_get_applicable_fee_ids(string $token, string $listing_id, array $existing_invoice_items): array {
    $headers = ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'];

    $normalise = function ($raw) {
        if (!is_array($raw)) return [];
        $list = isset($raw['results']) && is_array($raw['results']) ? $raw['results'] : $raw;
        $map  = [];
        foreach ($list as $f) {
            if (!empty($f['_id'])) $map[$f['_id']] = $f;
        }
        return $map;
    };

    $listing_fees = [];
    $r = wp_remote_get("https://open-api.guesty.com/v1/additional-fees/listing/{$listing_id}", ['headers' => $headers, 'timeout' => 12]);
    if (!is_wp_error($r) && wp_remote_retrieve_response_code($r) === 200) {
        $listing_fees = $normalise(json_decode(wp_remote_retrieve_body($r), true));
    }

    $account_fees = [];
    $r = wp_remote_get('https://open-api.guesty.com/v1/additional-fees/account', ['headers' => $headers, 'timeout' => 12]);
    if (!is_wp_error($r) && wp_remote_retrieve_response_code($r) === 200) {
        $account_fees = $normalise(json_decode(wp_remote_retrieve_body($r), true));
    }

    $all_fees = array_merge($account_fees, $listing_fees);

    $applicable = array_filter($all_fees, function ($fee) {
        if (!empty($fee['isAutomated'])) {
            return false;
        }
        foreach ($fee['channelConfigurations'] ?? [] as $ch) {
            if (($ch['channel'] ?? '') === 'bookingEngine' && !empty($ch['isEnabled'])) {
                return true;
            }
        }
        return false;
    });

    $existing_ids  = array_column($existing_invoice_items, '_id');
    $existing_refs = array_column($existing_invoice_items, 'refId');

    $ids = [];
    foreach ($applicable as $fee) {
        $fid = $fee['_id'];
        if (!in_array($fid, $existing_ids, true) && !in_array($fid, $existing_refs, true)) {
            $ids[] = $fid;
        }
    }

    return $ids;
}

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

add_action('wp_ajax_guesty_save_featured_properties', function () {
    if (!current_user_can('manage_options')) {
        wp_send_json_error();
    }

    $ids = isset($_POST['ids']) ? array_map('intval', $_POST['ids']) : [];
    update_option('guesty_featured_properties', $ids);

    wp_send_json_success();
});

add_action('wp_ajax_guesty_trigger_manual_sync', function () {
    check_ajax_referer('guesty_sync_nonce', 'nonce');

    if (get_transient('guesty_sync_lock')) {
		guesty_log('warning', 'Sync already running, skipped');
        wp_send_json_error(['message' => 'Sync already running!']);
    }

    wp_schedule_single_event(time(), 'guesty_all_sync_start', [false]);

    wp_send_json_success(['message' => 'Manual background sync queued.']);
});

add_action('wp_ajax_guesty_trigger_single_sync', function () {
    check_ajax_referer('guesty_sync_nonce', 'nonce');

    $pid = sanitize_text_field($_POST['pid'] ?? '');
    if (!$pid) wp_send_json_error(['message' => 'No Property ID provided']);

    if (get_transient('guesty_sync_lock')) {
        guesty_log('warning', 'Sync already running, skipped');
        wp_send_json_error(['message' => 'Sync already running!']);
    }

    wp_schedule_single_event(time(), 'guesty_single_sync_event', [$pid]);

    wp_send_json_success(['message' => "Background sync for $pid started!"]);
});

add_action('wp_ajax_guesty_get_sync_status', function () {
    check_ajax_referer('guesty_sync_nonce', 'nonce');
    wp_send_json_success(guesty_get_sync_ui());
});

add_filter('wp_image_editors', function () {
    return ['WP_Image_Editor_GD'];
});
function guesty_download_image_fast($url, $post_id) {
    if (!$url) return 0;

    $upload_dir = wp_upload_dir();
    $path_info = pathinfo(parse_url($url, PHP_URL_PATH));
    $filename = wp_unique_filename($upload_dir['path'], $path_info['basename']);
    $filepath = $upload_dir['path'] . '/' . $filename;

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

    $editor = wp_get_image_editor($filepath);
    if (!is_wp_error($editor)) {
        $editor->set_quality(80);
        $editor->resize(1800, null, false);
        $editor->save($filepath);
    }

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

        add_filter('intermediate_image_sizes_advanced', function ($sizes) {
            $allowed_sizes = ['thumbnail', 'guesty_medium', 'guesty_large'];
            return array_intersect_key($sizes, array_flip($allowed_sizes));
        });

        $attach_data = wp_generate_attachment_metadata($attach_id, $filepath);
        wp_update_attachment_metadata($attach_id, $attach_data);

        remove_all_filters('intermediate_image_sizes_advanced');

        update_post_meta($attach_id, 'guesty_hash', md5($url));
        return (int) $attach_id;
    }

    return 0;
}

add_action('pre_get_posts', 'guesty_archive_filter');
function guesty_archive_filter($query) {
    if (!is_admin() && $query->is_main_query() && (is_post_type_archive('properties') || is_tax() || is_search())) {
        if (isset($_GET['checkIn']) && !empty($_GET['checkIn']) && isset($_GET['checkOut']) && !empty($_GET['checkOut'])) {
            $check_in = sanitize_text_field($_GET['checkIn']);
            $check_out = sanitize_text_field($_GET['checkOut']);
			$guests = isset($_GET['minOccupancy']) ? intval($_GET['minOccupancy']) : 1;

            $available_ids = get_available_ids_from_be($check_in, $check_out, $guests);
			
            if (!empty($available_ids)) {
                $query->set('post__in', $available_ids);
            } else {
                $query->set('post__in', array(0));
            }
        }
		
		$meta_query = array('relation' => 'AND');

        if (isset($_GET['city']) && !empty($_GET['city'])) {
            $raw_cities = $_GET['city'];
            if (!is_array($raw_cities)) {
                $raw_cities = [$raw_cities];
            }
            $cities_clean = array_values(array_filter(array_map('sanitize_text_field', $raw_cities)));

            if (!empty($cities_clean)) {
                if (count($cities_clean) === 1) {
                    $meta_query[] = array(
                        'key'     => 'guesty_address_city',
                        'value'   => $cities_clean[0],
                        'compare' => 'LIKE',
                    );
                } else {
                    $meta_query[] = array(
                        'key'     => 'guesty_address_city',
                        'value'   => $cities_clean,
                        'compare' => 'IN',
                    );
                }
            }
        }

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

        if (is_post_type_archive('properties')) {
            $query->set('orderby', 'title');
            $query->set('order', 'ASC');
            $query->set('posts_per_page', 12);
        }
    }
}

function get_available_ids_from_be($check_in, $check_out, $guests = 1) {
    $token = guesty_be_get_token();
	if ( ! $token ) {
        guesty_log('error', 'Token missing');
        return array(0);
    }
    
    $url = 'https://booking.guesty.com/api/listings';

    $request_url = add_query_arg(array(
        'checkIn'      => $check_in,
        'checkOut'     => $check_out,
        'minOccupancy' => intval($guests),
        'limit'        => 100,
    ), $url);

    $response = wp_remote_get($request_url, array(
        'headers' => array(
            'Authorization' => 'Bearer ' . $token,
            'Accept'        => 'application/json',
        ),
        'timeout'   => 25,
        'sslverify' => false,
    ));

    if (is_wp_error($response)) {
        error_log('Guesty API Error: ' . $response->get_error_message());
        return array(0);
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);

    $listings = isset($body['results']) ? $body['results'] : $body;

    if (!empty($listings) && is_array($listings)) {
        $available_guesty_ids = array_column($listings, '_id');

        return get_wp_ids_from_guesty_ids($available_guesty_ids);
    }

    return array(0); 
}

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

        $from = date('Y-m-d');
        $to = date('Y-m-d', strtotime('+2 year'));

        $calendar_url = "https://open-api.guesty.com/v1/availability-pricing/api/calendar/listings/{$listing_id}?startDate={$from}&endDate={$to}";
        $cal_response = wp_remote_get($calendar_url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'accept' => 'application/json'
            ],
            'timeout' => 30
        ]);

        if (is_wp_error($cal_response)) {
            guesty_log('Guesty', 'Guesty API Connection Error: ' . $cal_response->get_error_message());
            return [];
        }

        $body = wp_remote_retrieve_body($cal_response);
        $response = json_decode($body);
		
        $blocked_dates = [];

        if (isset($response->status) && $response->status === 200 && isset($response->data->days) && is_array($response->data->days)) {
            foreach ($response->data->days as $day) {
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

    return $blocked_dates;
}

add_action('wp_ajax_guesty_check_availability', 'guesty_check_availability_handler');
add_action('wp_ajax_nopriv_guesty_check_availability', 'guesty_check_availability_handler');
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

    // Quote confirm: omit body `source` (Guesty rejects duplicate vs inquiry). No-quote path: include source direct.
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
            // Case-sensitive match for fee auto-apply (automationSources contains 'Direct').
            'source'                => 'Direct',
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

    $reservation_id    = $res_data['reservationId'] ?? $res_data['_id'] ?? '';
    $confirmation_code = $res_data['confirmationCode'] ?? '';

    guesty_log('reservation_success', 'ReservationId: ' . $reservation_id . ' | ConfirmationCode: ' . $confirmation_code);

    guesty_reservation_set_origin_note($token, $reservation_id);

    $provider_id = $frontend_provider ?: guesty_get_payment_provider_id($listing_id);

    if (!$provider_id) {
        wp_send_json_success([
            'reservationId'    => $reservation_id,
            'confirmationCode' => $confirmation_code,
            'paymentStatus'    => 'pending',
            'paymentNote'      => 'Payment provider not configured. Please attach payment manually in Guesty.',
        ]);
    }

    $pay_body = [
        '_id'               => $guesty_token,
        'paymentProviderId' => $provider_id,
        'reservationId'     => $reservation_id,
        'reuse'             => true,
    ];

    // Request body logged with the token redacted (PCI), but everything else
    // intact. Tagging Res:<id> on every payment line so the full chain can be
    // pulled per reservation regardless of how many rows are in the table.
    $pay_body_log = $pay_body;
    $pay_body_log['_id'] = substr($guesty_token, 0, 12) . '…';
    guesty_log('payment_method_request', 'Res: ' . $reservation_id . ' | GuestId: ' . $guest_id . ' | Provider: ' . $provider_id . ' | Body: ' . json_encode($pay_body_log));

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
    $pay_code     = 0;
    $pay_body_raw = '';

    if (is_wp_error($pay_response)) {
        $pay_err_msg  = 'Payment attachment failed (network error).';
        $pay_body_raw = 'WP_Error: ' . $pay_response->get_error_message();
        guesty_log('payment_method_error', 'Res: ' . $reservation_id . ' | ' . $pay_body_raw);
    } else {
        $pay_code     = wp_remote_retrieve_response_code($pay_response);
        $pay_body_raw = wp_remote_retrieve_body($pay_response);
        $pay_data     = json_decode($pay_body_raw, true);

        guesty_log('payment_method_response', 'Res: ' . $reservation_id . ' | HTTP ' . $pay_code . ' | Body: ' . $pay_body_raw);

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
            // Log full raw body too: Guesty 400s often hide the decline reason
            // (3DS / Merchant Warrior auth / card decline) outside the standard
            // error/message keys, so never truncate it away on failure.
            guesty_log('payment_method_error', 'Res: ' . $reservation_id . ' | HTTP ' . $pay_code . ' | Error: ' . $pay_err_msg . ' | Raw: ' . $pay_body_raw);
        }
    }

    // Single consolidated row that ALWAYS fires (success or failure), so the
    // complete attach outcome for a reservation is guaranteed to be captured
    // in one place even if other rows scroll out of the log viewer.
    guesty_log('payment_method_attempt', 'Res: ' . $reservation_id . ' | Status: ' . $pay_status . ' | HTTP: ' . $pay_code . ' | Provider: ' . $provider_id . ' | Raw: ' . $pay_body_raw);

    wp_send_json_success([
        'reservationId'    => $reservation_id,
        'confirmationCode' => $confirmation_code,
        'paymentStatus'    => $pay_status,
        'paymentNote'      => $pay_err_msg ?: null,
    ]);
}

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

    $url = "https://open-api.guesty.com/v1/quotes/$quote_id/coupons?" . http_build_query([
        'mergeAccommodationFarePriceComponents' => true,
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

    guesty_log('coupon_response', "HTTP {$code} | Coupon: {$coupon} | Body: " . substr($body, 0, 1000));

    if ($code === 200 && isset($data['_id']) && isset($data['rates']['ratePlans'][0]['money']['money'])) {
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

add_action('wp_ajax_get_guesty_quote', 'get_guesty_quote_handler');
add_action('wp_ajax_nopriv_get_guesty_quote', 'get_guesty_quote_handler');
function get_guesty_quote_handler() {
    $token = guesty_get_token();
    if (!$token) {
        guesty_log('Guesty Error', 'Token missing');
        wp_send_json_error('Authentication failed. Please try again later.');
    }

    $listing_id = sanitize_text_field($_POST['listing_id'] ?? '');
    $check_in   = sanitize_text_field($_POST['checkIn']     ?? '');
    $check_out  = sanitize_text_field($_POST['checkOut']    ?? '');
    $guests     = isset($_POST['guests']) ? intval($_POST['guests']) : 1;

    if (!$listing_id || !$check_in || !$check_out) {
        wp_send_json_error(['message' => 'Missing listing or dates.']);
    }

    $url = "https://open-api.guesty.com/v1/quotes";
    $body = [
        'listingId'             => $listing_id,
        'checkInDateLocalized'  => $check_in,
        'checkOutDateLocalized' => $check_out,
        'guestsCount'           => $guests,
        // Must match the literal strings in each fee's automationSources (case-sensitive).
        // 'Direct' triggers Guesty's auto-apply of fees configured for direct bookings.
        'source'                => 'Direct',
    ];

    $response = wp_remote_post($url, [
        'headers' => [
            'Authorization' => 'Bearer ' . $token,
            'Content-Type'  => 'application/json',
            'accept'        => 'application/json'
        ],
        'body' => json_encode($body),
        'timeout' => 20,
    ]);
    if (is_wp_error($response)) {
        wp_send_json_error('Guesty API connection failed');
    }

    $status_code = wp_remote_retrieve_response_code($response);
    $data = json_decode(wp_remote_retrieve_body($response), true);

    if (in_array($status_code, [200, 201], true)) {
        // Per Guesty docs, use rates.ratePlans[0].inquiryId for the upsell path
        $inquiry_id   = $data['rates']['ratePlans'][0]['inquiryId']
                      ?? $data['inquiryId']
                      ?? $data['_id']
                      ?? '';
        $rate_plan_id = $data['rates']['ratePlans'][0]['ratePlan']['_id']
                      ?? $data['rates']['ratePlans'][0]['money']['rateId']
                      ?? '';

        if ($inquiry_id) {
            $inner_items = $data['rates']['ratePlans'][0]['money']['money']['invoiceItems'] ?? [];
            $outer_items = $data['rates']['ratePlans'][0]['money']['invoiceItems']          ?? [];
            $current_items = count($outer_items) >= count($inner_items) && !empty($outer_items)
                ? $outer_items
                : (!empty($inner_items) ? $inner_items : []);

            $af_ids = guesty_get_applicable_fee_ids($token, $listing_id, $current_items);

            if (!empty($af_ids)) {
                $upsell_body = ['additionalFeeIds' => $af_ids];
                if ($rate_plan_id) {
                    $upsell_body['ratePlanIds'] = [$rate_plan_id];
                }

                $upsell_response = wp_remote_post(
                    "https://open-api.guesty.com/v1/additional-fees/inquiries/{$inquiry_id}/upsells",
                    [
                        'headers' => [
                            'Authorization' => 'Bearer ' . $token,
                            'Content-Type'  => 'application/json',
                            'Accept'        => 'application/json',
                        ],
                        'body'    => wp_json_encode($upsell_body),
                        'timeout' => 20,
                    ]
                );

                if (!is_wp_error($upsell_response) && wp_remote_retrieve_response_code($upsell_response) === 200) {
                    $upsell_data = json_decode(wp_remote_retrieve_body($upsell_response), true);
                    $upd_rp      = $upsell_data['rates']['ratePlans'][0]['money'] ?? [];
                    if (!empty($upd_rp)) {
                        $data['rates']['ratePlans'][0]['money'] = $upd_rp;
                    }
                }
            }
        }

        wp_send_json_success($data);
    } else {
        wp_send_json_error($data);
    }
}

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