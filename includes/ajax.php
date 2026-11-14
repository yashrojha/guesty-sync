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

        $search = function_exists('guesty_search_request')
            ? guesty_search_request()
            : array('cities' => array(), 'property_ids' => array(), 'q' => '');
        $cities_clean = $search['cities'];
        $property_ids = $search['property_ids'];
        $search_term = $search['q'];
        $selection_count = count($property_ids) + (!empty($cities_clean) ? 1 : 0) + ($search_term !== '' ? 1 : 0);

        $restrict_ids = null;
        if ($selection_count > 1) {
            $restrict_ids = $property_ids;
            if (!empty($cities_clean) && function_exists('guesty_property_ids_for_cities')) {
                $restrict_ids = array_merge($restrict_ids, guesty_property_ids_for_cities($cities_clean));
            }
            if ($search_term !== '') {
                $restrict_ids = array_merge($restrict_ids, guesty_property_ids_for_term($search_term));
            }
            $restrict_ids = array_values(array_unique(array_map('intval', $restrict_ids)));
            if (empty($restrict_ids)) {
                $restrict_ids = array(0);
            }
        } elseif (count($property_ids) === 1) {
            $restrict_ids = $property_ids;
        } elseif ($search_term !== '') {
            $restrict_ids = guesty_property_ids_for_term($search_term);
            if (empty($restrict_ids)) {
                $restrict_ids = array(0);
            }
        } elseif (!empty($cities_clean)) {
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

        if (is_array($restrict_ids)) {
            $existing = $query->get('post__in');
            if (!empty($existing)) {
                $merged = array_values(array_intersect(array_map('intval', (array) $existing), $restrict_ids));
                $query->set('post__in', !empty($merged) ? $merged : array(0));
            } else {
                $query->set('post__in', $restrict_ids);
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

    $result = get_guesty_booking_blocked_dates($listing_id);
    wp_send_json_success($result);
}
function get_guesty_booking_blocked_dates($listing_id) {
    $cache_key = 'guesty_calendar_' . $listing_id;
    $empty_result = [
        'blocked_dates'     => [],
        'no_checkin_dates'  => [],
        'no_checkout_dates' => [],
        'min_nights_map' => [],
        'max_nights_map' => [],
    ];
    $result = get_transient($cache_key);

    if (false === $result) {
        $token = guesty_get_token();
        if (!$token) {
            guesty_log('Guesty Error', 'Token missing');
            return $empty_result;
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
            return $empty_result;
        }

        $body = wp_remote_retrieve_body($cal_response);
        $response = json_decode($body);
		
        $blocked_dates = [];
        $no_checkin_dates = [];
        $no_checkout_dates = [];
        $min_nights_map = [];
        $max_nights_map = [];

        if (isset($response->status) && $response->status === 200 && isset($response->data->days) && is_array($response->data->days)) {
            foreach ($response->data->days as $day) {
                $isUnavailable = (isset($day->status) && $day->status !== 'available');
                $isClosedToArrival   = (isset($day->cta) && $day->cta === true);
                $isClosedToDeparture = (isset($day->ctd) && $day->ctd === true);

                if ($isUnavailable) {
                    $blocked_dates[] = $day->date;
                }
                if ($isClosedToArrival) {
                    $no_checkin_dates[] = $day->date;
                }
                if ($isClosedToDeparture) {
                    $no_checkout_dates[] = $day->date;
                }
                if (isset($day->minNights)) {
                    $min_nights_map[$day->date] = (int) $day->minNights;
                }
                if (isset($day->maxNights)) {
                    $max_nights_map[$day->date] = (int) $day->maxNights;
                }
            }
        } else {
			guesty_log('Guesty Error', 'Guesty API Data Error: Received ' . $body);
        }
        
        $result = [
            'blocked_dates'     => $blocked_dates,
            'no_checkin_dates'  => $no_checkin_dates,
            'no_checkout_dates' => $no_checkout_dates,
            'min_nights_map'    => $min_nights_map,
            'max_nights_map'    => $max_nights_map,
        ];

        set_transient($cache_key, $result, HOUR_IN_SECONDS);
    }

    return $result;
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

    guesty_log('guest_request', 'Name: ' . $first_name . ' ' . $last_name . ' | Email: ' . ($email ?: '(none)'));

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
        guesty_log('guest_error', 'WP_Error: ' . $response->get_error_message());
        wp_send_json_error('Failed to connect. Please try again.');
    }

    $code   = wp_remote_retrieve_response_code($response);
    $raw    = wp_remote_retrieve_body($response);
    $data   = json_decode($raw, true);

    if ($code === 200 || $code === 201) {
        $guest_id = is_array($data) ? ($data['_id'] ?? '') : '';
        guesty_log('guest_ok', 'GuestId: ' . $guest_id . ' | Email: ' . ($email ?: '(none)'));
        wp_send_json_success(['guestId' => $guest_id]);
    } else {
        $msg = (is_array($data) ? ($data['error']['message'] ?? ($data['message'] ?? '')) : '') ?: 'Failed to create guest profile.';
        guesty_log('guest_error', 'HTTP ' . $code . ' | Error: ' . $msg . ' | Full body: ' . $raw);
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

    // Create as `reserved` (not `confirmed`): auto-payments generate at
    // confirmation, so the card must be attached first (steps below), else the
    // schedule has no card and never charges. reservedUntil -1 so a booking
    // stuck in reserved (confirm failed) is never auto-expired.
    if ($quote_id) {
        $res_url  = 'https://open-api.guesty.com/v1/reservations-v3/quote';
        $res_body = [
            'quoteId'       => $quote_id,
            'status'        => 'reserved',
            'reservedUntil' => -1,
            'guestId'       => $guest_id,
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
            'status'                => 'reserved',
            'reservedUntil'         => -1,
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

    guesty_log('reservation_response', 'HTTP ' . $res_code . ' | Body: ' . $res_body_raw);

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
            $err = 'Reservation failed (HTTP ' . $res_code . ').';
        }
        guesty_log('reservation_error', 'HTTP ' . $res_code . ' | Error: ' . $err . ' | Full body: ' . $res_body_raw);
        wp_send_json_error($err);
    }

    $reservation_id    = $res_data['reservationId'] ?? $res_data['_id'] ?? '';
    $confirmation_code = $res_data['confirmationCode'] ?? '';

    guesty_log('reservation_success', 'Res: ' . $reservation_id . ' | ConfirmationCode: ' . $confirmation_code . ' | GuestId: ' . $guest_id . ' | Status: reserved');

    if (!$reservation_id) {
        // Guesty returned 2xx but no id — cannot attach payment or confirm.
        guesty_log('reservation_error', 'Res: (none) | 2xx but no reservationId in body | Full body: ' . $res_body_raw);
        wp_send_json_error('Booking could not be created (no reservation id returned). Please try again.');
    }

    guesty_reservation_set_origin_note($token, $reservation_id);

    // The payment chain below deliberately waits on Guesty, so give PHP room and
    // cap the wait so the guest isn't left on a spinner until a gateway timeout.
    if (function_exists('set_time_limit')) {
        @set_time_limit(180);
    }
    $deadline = time() + max(20, (int) apply_filters('guesty_booking_payment_budget', 60));

    // Log where the provider came from — a wrong/missing provider is a known
    // cause of "reservation created without payment method".
    if ($frontend_provider) {
        $provider_id     = $frontend_provider;
        $provider_source = 'frontend';
    } else {
        $provider_id     = guesty_get_payment_provider_id($listing_id);
        $provider_source = 'lookup';
    }
    guesty_log('payment_provider_resolved', 'Res: ' . $reservation_id . ' | Provider: ' . ($provider_id ?: '(none)') . ' | Source: ' . $provider_source . ' | Listing: ' . $listing_id);

    // No provider → no card possible. Confirm anyway, flag for manual payment.
    if (!$provider_id) {
        guesty_log('payment_method_attempt', 'Res: ' . $reservation_id . ' | Status: skipped | Reason: no_provider | Provider: (none)');
        $confirm = guesty_reservation_confirm($token, $reservation_id);
        wp_send_json_success([
            'reservationId'    => $reservation_id,
            'confirmationCode' => $confirmation_code,
            'paymentStatus'    => 'pending',
            'paymentNote'      => $confirm['ok']
                ? 'Payment provider not configured. Please attach payment manually in Guesty.'
                : 'Booking created but confirmation failed — please review in Guesty.',
        ]);
    }

    // reservations-v3 writes replicate to the legacy /v1 store asynchronously,
    // and it's the legacy store the payment endpoints validate against. Attaching
    // before it catches up is what returned 400 "Reservation not found" and left
    // bookings with no card — Guesty then builds its payment schedule without one.
    $readable = guesty_wait_for_reservation_readable($token, $reservation_id, $deadline);

    // Guesty automations need the card on the *reservation* at confirm time.
    // Always attach with reservationId when readable; guest-level-only is a last
    // resort so the one-shot token isn't wasted if replication is still lagging.
    $attach = [
        'ok'        => false,
        'code'      => 0,
        'raw'       => '',
        'retryable' => false,
        'methodId'  => '',
        'method'    => '',
        'error'     => $readable ? '' : 'Reservation not yet readable for payment attach.',
    ];

    if ($readable) {
        $attach = guesty_attach_payment_method(
            $token,
            $guest_id,
            $provider_id,
            $guesty_token,
            $reservation_id,
            $deadline
        );
    }

    if (!$attach['ok']) {
        guesty_log('payment_method_fallback', 'Res: ' . $reservation_id . ' | Saving card on guest (no reservationId) so the token is not wasted');
        $attach = guesty_attach_payment_method($token, $guest_id, $provider_id, $guesty_token, '', $deadline);
    }

    $method_id   = $attach['methodId'];
    $method_name = $attach['method'];
    $pay_err_msg = $attach['ok'] ? '' : $attach['error'];

    // Guesty occasionally answers 2xx without echoing the record; resolve the
    // method ID off the guest when needed (logging / optional site charge).
    if ($attach['ok'] && !$method_id) {
        $found = guesty_find_recent_active_payment_method(guesty_get_guest_payment_methods($token, $guest_id));
        if ($found) {
            $method_id   = (string) ($found['_id'] ?? '');
            $method_name = strtoupper((string) ($found['method'] ?? ''));
            guesty_log('payment_method_lookup', 'Res: ' . $reservation_id . ' | Resolved MethodId: ' . $method_id);
        }
    }

    // The card must be on the reservation *before* confirmation: Guesty builds
    // its payment automation schedule at that moment, and a schedule created
    // without a card stays pending forever and has to be fixed by hand.
    $card_bound = false;
    if ($attach['ok'] && $readable && time() < $deadline) {
        $card_bound = guesty_reservation_wait_for_payment_method($token, $reservation_id, 6, 3);
    }

    if ($attach['ok'] && !$card_bound) {
        guesty_log(
            'payment_method_not_bound',
            'Res: ' . $reservation_id
                . ' | Card saved but not yet on the reservation before confirm.'
                . ' Guesty automations may need the card assigned manually.'
        );
        if (!$pay_err_msg) {
            $pay_err_msg = 'Card saved; Guesty may need the payment method assigned to scheduled payments.';
        }
    }

    // Confirm (always, per client policy — a failed card must never drop the
    // booking). Retried because a transient failure would leave it `reserved`.
    $confirm = ['ok' => false, 'code' => 0, 'body' => ''];
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        $confirm = guesty_reservation_confirm($token, $reservation_id);
        if ($confirm['ok']) {
            break;
        }
        if ($attempt < 3) {
            sleep(3);
        }
    }

    if (!$confirm['ok']) {
        // Don't tell the guest to rebook (avoids duplicates); flag for backend.
        guesty_log('reservation_confirm_failed_final', 'Res: ' . $reservation_id . ' | Confirm failed after 3 attempts | HTTP ' . $confirm['code'] . ' | Body: ' . $confirm['body']);
        $pay_err_msg = 'Booking received. Our team will finalize your confirmation shortly.';
    }

    // Site charge is off by default — Guesty payment automations collect.
    // Optional follow/always modes still go through guesty_collect_reservation_balance.
    $charge_mode = guesty_booking_auto_charge_mode();
    $charge      = ['ok' => true, 'charged' => 0.0, 'skipped' => true, 'error' => '', 'retryable' => false];

    if ('off' !== $charge_mode && $method_id && $confirm['ok']) {
        sleep(guesty_payment_settle_delay());
        $charge = guesty_collect_reservation_balance($token, $reservation_id, $method_id, $method_name, [
            'listingId'              => $listing_id,
            'cardBoundBeforeConfirm' => $card_bound,
        ]);
        if (!$charge['ok'] && !$pay_err_msg) {
            $pay_err_msg = $charge['error'];
        }
    } elseif ('off' === $charge_mode) {
        guesty_log('payment_charge_skipped', 'Res: ' . $reservation_id . ' | Guesty manages collection — site does not charge');
    }

    // success = card attached + reservation confirmed. When Guesty collects,
    // binding before confirm is ideal but not required for guest success UI.
    $pay_status = ($attach['ok'] && $confirm['ok'] && $charge['ok']) ? 'success' : 'pending';

    $needs_confirm = !$confirm['ok'];
    $needs_charge  = ('off' !== $charge_mode) && $method_id && !$charge['ok'] && ($charge['retryable'] || $needs_confirm);
    if ($needs_confirm || $needs_charge) {
        guesty_schedule_payment_retry([
            'reservationId'   => $reservation_id,
            'guestId'         => $guest_id,
            'listingId'       => $listing_id,
            'paymentMethodId' => $method_id,
            'method'          => $method_name,
            'cardBound'       => $card_bound,
            'needsConfirm'    => $needs_confirm,
            'needsCharge'     => $needs_charge,
        ]);
    }

    guesty_log(
        'booking_outcome',
        'Res: ' . $reservation_id
            . ' | Attach: ' . ($attach['ok'] ? 'ok' : 'failed')
            . ' | Bound: ' . ($card_bound ? 'yes' : 'no')
            . ' | Confirm: ' . ($confirm['ok'] ? 'ok' : 'failed')
            . ' | Charge: ' . ($charge['skipped'] ? 'guesty-managed' : ($charge['ok'] ? 'ok ' . $charge['charged'] : 'failed'))
            . ' | Mode: ' . $charge_mode
            . ' | Status: ' . $pay_status
    );

    wp_send_json_success([
        'reservationId'    => $reservation_id,
        'confirmationCode' => $confirmation_code,
        // 'success' = card attached and booking confirmed (Guesty schedules collect);
        // 'pending' = booking is in, payment needs manual attention.
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