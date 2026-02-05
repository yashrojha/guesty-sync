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
 * Get valid token be (reuse or refresh)
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
