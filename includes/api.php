<?php
if (!defined('ABSPATH')) exit;

/**
 * Get valid token (reuse or refresh)
 */
function guesty_get_token() {

    $token      = get_option('guesty_access_token');
    $expires_at = (int) get_option('guesty_token_expires');

    // Reuse token if valid (5 min buffer)
    if ($token && $expires_at > (time() + 300)) {
        return $token;
    }

    $client_id     = get_option('guesty_client_id');
    $client_secret = get_option('guesty_client_secret_key');

    if (!$client_id || !$client_secret) {
        guesty_log('error', 'Client ID or Secret missing');
        return false;
    }
	
    $response = wp_remote_post(
        'https://open-api.guesty.com/oauth2/token',
        [
            'headers' => [
                'Content-Type'  => 'application/x-www-form-urlencoded',
                'Accept' => 'application/json'
            ],
            'body' => http_build_query([
                "grant_type" => "client_credentials",
                "scope" => "open-api",
                "client_id" => $client_id,
                "client_secret" => $client_secret
            ]),
            'timeout' => 20,
        ]
    );
	
    if (is_wp_error($response)) {
        guesty_log('error', 'Token request failed: ' . $response->get_error_message());
        return false;
    }

    $code = wp_remote_retrieve_response_code($response);
    $body = json_decode(wp_remote_retrieve_body($response), true);

    if ($code === 200 && !empty($body['access_token'])) {

        update_option('guesty_access_token', $body['access_token']);
        update_option('guesty_token_expires', time() + (int) $body['expires_in']);

        guesty_log('success', 'New token generated');

        return $body['access_token'];
    }

    guesty_log('error', 'Token failed: ' . wp_remote_retrieve_body($response));
    return false;
}

/**
 * Get valid Booking Engine token (reuse or refresh)
 */
function guesty_be_get_token() {

    $token      = get_option('guesty_be_access_token');
    $expires_at = (int) get_option('guesty_be_token_expires');

    // Reuse token if valid (5 min buffer)
    if ($token && $expires_at > (time() + 300)) {
        return $token;
    }

    $client_id     = get_option('guesty_be_client_id');
    $client_secret = get_option('guesty_be_client_secret_key');

    if (!$client_id || !$client_secret) {
        guesty_log('error', 'Client ID or Secret missing');
        return false;
    }
	
    $response = wp_remote_post(
        'https://booking.guesty.com/oauth2/token',
        [
            'headers' => [
                'Content-Type'  => 'application/x-www-form-urlencoded',
                'Accept' => 'application/json'
            ],
            'body' => http_build_query([
                "grant_type" => "client_credentials",
                "scope" => "booking_engine:api",
                "client_id" => $client_id,
                "client_secret" => $client_secret
            ]),
            'timeout' => 20,
			'sslverify' => false
        ]
    );
	
    if (is_wp_error($response)) {
        guesty_log('error', 'Token request failed: ' . $response->get_error_message());
        return false;
    }

    $code = wp_remote_retrieve_response_code($response);
    $body = json_decode(wp_remote_retrieve_body($response), true);

    if ($code === 200 && !empty($body['access_token'])) {

        update_option('guesty_be_access_token', $body['access_token']);
        update_option('guesty_be_token_expires', time() + (int) $body['expires_in']);

        guesty_log('success', 'New token generated');

        return $body['access_token'];
    }

    guesty_log('error', 'Token failed: ' . wp_remote_retrieve_body($response));
    return false;
}

/**
 * Fetch Properties from Guesty API
 */
function guesty_get_properties() {
	$token = guesty_get_token();
    if ( ! $token ) {
        guesty_log('error', 'Token missing');
        return;
    }

    $url = add_query_arg([
        'limit'     => 50,
        'skip'      => 0,
        'active'    => 'true',
        'listed'    => 'true',
        'sort'      => 'title',
    ], 'https://open-api.guesty.com/v1/listings');

    $response = wp_remote_get($url, [
        'headers' => [
            'Authorization' => 'Bearer ' . $token,
            'Accept'        => 'application/json',
        ],
        'timeout' => 50,
    ]);

    if (is_wp_error($response)) return [];

    $data = json_decode(wp_remote_retrieve_body($response), true);

    return $data['results'] ?? [];
}

/**
 * Fetch Single Properties from Guesty API
 */
function guesty_get_property_by_id($id) {
    $token = guesty_get_token();
    if ( ! $token ) {
        guesty_log('error', 'Token missing');
        return;
    }

    $response = wp_remote_get(
        "https://open-api.guesty.com/v1/listings/{$id}",
        [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept'        => 'application/json',
            ],
            'timeout' => 30,
        ]
    );

    if (is_wp_error($response)) {
        return false;
    }

    $code = wp_remote_retrieve_response_code($response);
    if ($code !== 200) {
        return false;
    }

    $data = json_decode(wp_remote_retrieve_body($response), true);

    return [
        'property' => $data,
        'images'   => $data['pictures'] ?? [],
    ];
}

/**
 * Fetch Guesty Listings Average Reviews
 */
function fetch_guesty_review_average($listing_ids) {

    if (empty($listing_ids)) {
        return false;
    }

    // Ensure array
    if (!is_array($listing_ids)) {
        $listing_ids = [$listing_ids];
    }

    $token = guesty_get_token();
    if (!$token) {
        guesty_log('error', 'Guesty token missing');
        return false;
    }

    // Build query correctly as array
    $query_args = [];
    foreach ($listing_ids as $id) {
        $query_args['listingIds'][] = trim($id);
    }

    $url = add_query_arg(
        $query_args,
        'https://open-api.guesty.com/v1/reviews/listings-average'
    );

    $response = wp_remote_get($url, [
        'timeout' => 30,
        'headers' => [
            'Authorization' => 'Bearer ' . $token,
            'Accept'        => 'application/json',
        ],
    ]);

    if (is_wp_error($response)) {
        guesty_log('error', 'Guesty API WP Error', $response->get_error_message());
        return false;
    }

    $status = wp_remote_retrieve_response_code($response);
    $body   = wp_remote_retrieve_body($response);

    if ($status !== 200) {
        guesty_log('error', 'Guesty API Error', [
            'status' => $status,
            'body'   => $body,
        ]);
        return false;
    }

    return json_decode($body, true);
}

/**
 * Check if a listing is available for a date range using the Calendar API.
 *
 * Uses GET /v1/availability-pricing/api/calendar/listings/{id} which returns
 * per-day status and CTA/CTD flags. This is more authoritative than the quote
 * API — blocked/reserved nights will have status != "available" here even when
 * the quote endpoint occasionally accepts them.
 *
 * @param  string $listing_id  Guesty listing ID.
 * @param  string $check_in    Check-in date YYYY-MM-DD.
 * @param  string $check_out   Check-out date YYYY-MM-DD.
 * @return array               ['available' => bool, 'reason' => string]
 */
function guesty_check_availability( $listing_id, $check_in, $check_out ) {
    $token = guesty_get_token();
    if ( ! $token ) {
        return [ 'available' => false, 'reason' => 'Authentication error. Please try again shortly.' ];
    }

    $cal_url = "https://open-api.guesty.com/v1/availability-pricing/api/calendar/listings/{$listing_id}"
            . "?startDate={$check_in}&endDate={$check_out}";

    $response = wp_remote_get( $cal_url, [
        'headers' => [
            'Authorization' => 'Bearer ' . $token,
            'Accept'        => 'application/json',
        ],
        'timeout' => 20,
    ] );

    if ( is_wp_error( $response ) ) {
        guesty_log( 'availability_check', 'Calendar API error: ' . $response->get_error_message() );
        return [ 'available' => false, 'reason' => 'Unable to verify availability right now. Please try again.' ];
    }

    $http_code = wp_remote_retrieve_response_code( $response );
    $body      = wp_remote_retrieve_body( $response );
    $data      = json_decode( $body );

    if ( $http_code !== 200
        || ! isset( $data->status )
        || (int) $data->status !== 200
        || ! isset( $data->data->days )
        || ! is_array( $data->data->days )
    ) {
        guesty_log( 'availability_check', 'Calendar API unexpected response HTTP ' . $http_code . ': ' . substr( $body, 0, 400 ) );
        return [ 'available' => false, 'reason' => 'Unable to verify availability. Please try again.' ];
    }

    // Build a date → day-object lookup from the calendar response.
    $day_map = [];
    foreach ( $data->data->days as $day ) {
        if ( isset( $day->date ) ) {
            $day_map[ $day->date ] = $day;
        }
    }

    $ci_ts = strtotime( $check_in );
    $co_ts = strtotime( $check_out );

    // Validate every night from check-in up to (but not including) check-out.
    for ( $ts = $ci_ts; $ts < $co_ts; $ts = strtotime( '+1 day', $ts ) ) {
        $date_str = gmdate( 'Y-m-d', $ts );

        if ( ! isset( $day_map[ $date_str ] ) ) {
            return [ 'available' => false, 'reason' => 'The selected dates could not be verified. Please choose different dates.' ];
        }

        $day = $day_map[ $date_str ];

        if ( isset( $day->status ) && $day->status !== 'available' ) {
            return [ 'available' => false, 'reason' => 'We are booked for these dates. Please choose different dates.' ];
        }

        // Check-in day must not be closed to arrival.
        if ( $ts === $ci_ts && ! empty( $day->cta ) ) {
            return [ 'available' => false, 'reason' => 'Check-in is not permitted on the selected arrival date. Please choose a different check-in date.' ];
        }
    }

    // Check-out day must not be closed to departure.
    $co_date = gmdate( 'Y-m-d', $co_ts );
    if ( isset( $day_map[ $co_date ] ) && ! empty( $day_map[ $co_date ]->ctd ) ) {
        return [ 'available' => false, 'reason' => 'Check-out is not permitted on the selected departure date. Please choose a different check-out date.' ];
    }

    return [ 'available' => true, 'reason' => '' ];
}

/**
 * Fetch the Spaces (bedrooms/beds) data for a property from the Guesty Spaces API.
 *
 * The Spaces endpoint is the authoritative source for room names, types (BEDROOM,
 * SHARED_SPACE, FULL_BATHROOM, HALF_BATHROOM), and per-bed counts.
 * For simple listings the unitTypeId equals the listing _id.
 *
 * @param  string $listing_id  Guesty listing _id (used as unitTypeId).
 * @return array|false         The spaces array on success, false on failure.
 */
function guesty_get_property_spaces( $listing_id ) {
    $token = guesty_get_token();
    if ( ! $token ) {
        return false;
    }

    $url = "https://open-api.guesty.com/v1/properties/spaces/unit-type/{$listing_id}";

    $response = wp_remote_get( $url, [
        'headers' => [
            'Authorization' => 'Bearer ' . $token,
            'Accept'        => 'application/json',
        ],
        'timeout' => 20,
    ] );

    if ( is_wp_error( $response ) ) {
        guesty_log( 'spaces_api', 'Spaces API error for ' . $listing_id . ': ' . $response->get_error_message() );
        return false;
    }

    $code = wp_remote_retrieve_response_code( $response );
    $body = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( $code !== 200 || empty( $body['spaces'] ) ) {
        guesty_log( 'spaces_api', 'Spaces API HTTP ' . $code . ' for ' . $listing_id );
        return false;
    }

    return $body['spaces'];
}

/**
 * Resolve the payment provider ID used for a given listing.
 *
 * Resolution order:
 *   1. Admin manual override  (Guesty Sync > Settings > Booking Settings)
 *   2. paymentProviderId on the listing object in Guesty
 *   3. Account-level default  GET /v1/payment-providers/default
 *
 * @param  string $listing_id  Optional Guesty listing ID for tier-2 lookup.
 * @return string              Payment provider _id, or empty string if not found.
 */
function guesty_get_payment_provider_id( $listing_id = '' ) {
    // Tier 1: admin override always wins
    $saved = get_option('guesty_stripe_payment_provider_id', '');
    if ($saved) return $saved;

    $token = guesty_get_token();
    if (!$token) return '';

    // Tier 2: listing-level paymentProviderId
    if ($listing_id) {
        $resp = wp_remote_get(
            "https://open-api.guesty.com/v1/listings/{$listing_id}",
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Accept'        => 'application/json',
                ],
                'timeout' => 15,
            ]
        );

        if (!is_wp_error($resp) && wp_remote_retrieve_response_code($resp) === 200) {
            $data = json_decode(wp_remote_retrieve_body($resp), true);
            if (!empty($data['paymentProviderId'])) {
                return $data['paymentProviderId'];
            }
        }
    }

    // Tier 3: account default
    $resp = wp_remote_get(
        'https://open-api.guesty.com/v1/payment-providers/default',
        [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept'        => 'application/json',
            ],
            'timeout' => 12,
        ]
    );

    if (!is_wp_error($resp) && wp_remote_retrieve_response_code($resp) === 200) {
        $data = json_decode(wp_remote_retrieve_body($resp), true);
        // Response may be the provider object directly or wrapped under 'data'
        return $data['_id'] ?? ($data['data']['_id'] ?? '');
    }

    return '';
}

/**
 * Line written to the Guesty reservation (identifies this WordPress site / URL).
 * Filter: {@see 'guesty_reservation_website_origin_line'} to change format.
 *
 * @return string
 */
function guesty_reservation_website_origin_line() {
    $name = get_bloginfo( 'name' );
    $url  = esc_url( home_url( '/' ) );
    $line = sprintf( 'Reservation created by %s [%s]', $name, $url );

    return apply_filters( 'guesty_reservation_website_origin_line', $line );
}

/**
 * Set reservation notes via Open API (staff-visible “other” note by default).
 * Does not fail the booking if the request errors — logs and returns false.
 *
 * Filter {@see 'guesty_reservation_origin_note_payload'} to change keys (e.g. use only guestNote).
 * Return empty array to skip the request.
 *
 * @param string      $bearer_token   Open API access token.
 * @param string      $reservation_id Guesty reservation _id.
 * @param string|null $note_text      Optional; defaults to {@see guesty_reservation_website_origin_line()}.
 * @return bool True if HTTP 2xx or skipped intentionally, false on error.
 */
function guesty_reservation_set_origin_note( $bearer_token, $reservation_id, $note_text = null ) {
    if ( ! $reservation_id || ! $bearer_token ) {
        return false;
    }

    if ( $note_text === null || $note_text === '' ) {
        $note_text = guesty_reservation_website_origin_line();
    }

    $note_text = apply_filters( 'guesty_reservation_origin_note_text', $note_text, $reservation_id );
    if ( $note_text === '' || $note_text === false ) {
        return true;
    }

    $default_payload = [
        'notes' => [
            'otherNote' => $note_text,
        ],
    ];
    $payload = apply_filters( 'guesty_reservation_origin_note_payload', $default_payload, $reservation_id, $note_text );
    if ( ! is_array( $payload ) || empty( $payload ) ) {
        return true;
    }

    $url = 'https://open-api.guesty.com/v1/reservations-v3/' . rawurlencode( $reservation_id ) . '/notes';

    $response = wp_remote_request(
        $url,
        [
            'method'  => 'PUT',
            'headers' => [
                'Authorization' => 'Bearer ' . $bearer_token,
                'Content-Type'  => 'application/json',
                'Accept'          => 'application/json',
            ],
            'body'    => wp_json_encode( $payload ),
            'timeout' => 20,
        ]
    );

    if ( is_wp_error( $response ) ) {
        guesty_log( 'reservation_note_error', 'WP_Error: ' . $response->get_error_message() );

        return false;
    }

    $code = wp_remote_retrieve_response_code( $response );
    if ( $code < 200 || $code >= 300 ) {
        $raw = wp_remote_retrieve_body( $response );
        guesty_log( 'reservation_note_error', 'HTTP ' . $code . ' | ' . substr( (string) $raw, 0, 500 ) );

        return false;
    }

    guesty_log( 'reservation_note_ok', 'ReservationId: ' . $reservation_id );

    return true;
}