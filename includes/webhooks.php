<?php
if (!defined('ABSPATH')) exit;

/**
 * Guesty payment webhooks receiver. Charges run async in Guesty, so this is the
 * only authoritative signal of whether money was actually collected.
 *
 * Endpoint: POST /wp-json/guesty/v1/payment-webhook?token=<secret>
 * Auth: shared secret in the ?token= param (Guesty sends no signing header).
 */

add_action('rest_api_init', 'guesty_register_payment_webhook_route');
function guesty_register_payment_webhook_route() {
    register_rest_route(
        'guesty/v1',
        '/payment-webhook',
        [
            'methods'             => 'POST',
            'callback'            => 'guesty_handle_payment_webhook',
            'permission_callback' => 'guesty_verify_payment_webhook',
        ]
    );
}

function guesty_get_webhook_secret() {
    $secret = get_option('guesty_webhook_secret', '');
    if (!$secret) {
        $secret = wp_generate_password(40, false, false);
        update_option('guesty_webhook_secret', $secret);
    }
    return $secret;
}

function guesty_get_webhook_url() {
    return add_query_arg(
        'token',
        guesty_get_webhook_secret(),
        rest_url('guesty/v1/payment-webhook')
    );
}

function guesty_verify_payment_webhook($request) {
    $provided = (string) $request->get_param('token');
    $expected = guesty_get_webhook_secret();

    if (!$provided || !hash_equals($expected, $provided)) {
        guesty_log('webhook_rejected', 'Bad or missing token from ' . guesty_webhook_client_ip());
        return false;
    }
    return true;
}

function guesty_webhook_client_ip() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    return sanitize_text_field((string) $ip);
}

// Always returns 200 so Guesty doesn't retry; the outcome is captured in logs.
function guesty_handle_payment_webhook($request) {
    $raw  = $request->get_body();
    $data = json_decode($raw, true);

    if (!is_array($data)) {
        guesty_log('webhook_error', 'Unparseable body: ' . substr((string) $raw, 0, 1000));
        return new WP_REST_Response(['ok' => false], 200);
    }

    $event   = (string) ($data['event'] ?? 'unknown');
    $payment = is_array($data['payment'] ?? null) ? $data['payment'] : [];

    $reservation_id = (string) ($payment['reservationId'] ?? '');
    $payment_id     = (string) ($payment['paymentId'] ?? '');

    $summary  = guesty_summarize_payment_webhook($event, $payment);
    $log_type = 'webhook_' . str_replace('.', '_', $event);

    guesty_log(
        $log_type,
        'Res: ' . ($reservation_id ?: '(none)')
            . ' | PaymentId: ' . ($payment_id ?: '(none)')
            . ' | ' . $summary
            . ' | Raw: ' . $raw
    );

    return new WP_REST_Response(['ok' => true], 200);
}

function guesty_summarize_payment_webhook($event, array $p) {
    switch ($event) {
        case 'payments.received':
            return 'RECEIVED ' . guesty_money($p) . ' | ' . guesty_card($p)
                . ' | confirmationCode: ' . ($p['confirmationCode'] ?? '');

        case 'payments.failed':
            return 'FAILED | reason: ' . ($p['failureReason'] ?? '(none)');

        case 'payments.refunded':
            return 'REFUNDED amount: ' . ($p['refundAmount'] ?? $p['refundedAmount'] ?? '?')
                . ' totalRefunded: ' . ($p['totalRefunded'] ?? '?')
                . ' | ' . guesty_card($p);

        case 'payments.overdue':
            return 'OVERDUE ' . guesty_money($p)
                . (empty($p['isAuthorizationHold']) ? '' : ' | authHold')
                . (empty($p['isSecurityDeposit']) ? '' : ' | securityDeposit');

        case 'payments.overcharged':
            return 'OVERCHARGED balanceDue: ' . ($p['balanceDue'] ?? '?');

        case 'payments.overcharge.expected':
            return 'OVERCHARGE_EXPECTED status: ' . ($p['creditCardPaymentsStatus'] ?? '?')
                . ' totalPaid: ' . ($p['totalPaid'] ?? '?')
                . ' expectedCharge: ' . ($p['totalExpectedCharge'] ?? '?');

        case 'payments.authenticationRequired':
            return 'AUTHENTICATION_REQUIRED (3DS/SCA challenge pending)';

        case 'payments.authenticationFailed':
            return 'AUTHENTICATION_FAILED (3DS/SCA failed)';

        case 'payments.authorizationHoldFailed':
            return 'AUTH_HOLD_FAILED';

        case 'payments.method.received':
            return 'PAYMENT_METHOD_RECEIVED (card valid & on reservation)';

        case 'payments.invalidPaymentMethod':
        case 'payments.invalidBcomCard':
        case 'payments.invalidSecondBcomCard':
            $cc  = is_array($p['invalidCreditCard'] ?? null) ? $p['invalidCreditCard'] : [];
            $pe  = is_array($cc['processorError'] ?? null) ? $cc['processorError'] : [];
            return 'INVALID_METHOD | ' . ($cc['brand'] ?? '') . ' ****' . ($cc['last4'] ?? '')
                . ' | error: ' . ($cc['error'] ?? '')
                . ' | processor: ' . ($pe['code'] ?? '') . ' ' . ($pe['message'] ?? '');

        case 'payments.disputes':
            return 'DISPUTE ' . ($p['type'] ?? '') . ' amount: ' . ($p['amount'] ?? '?')
                . ' | reason: ' . ($p['reason'] ?? '') . ' | caseId: ' . ($p['caseId'] ?? '');

        default:
            return 'EVENT: ' . $event;
    }
}

function guesty_money(array $p) {
    $amount   = $p['amount']   ?? null;
    $currency = $p['currency'] ?? '';
    if (null === $amount) {
        return '(no amount)';
    }
    return trim($currency . ' ' . $amount);
}

function guesty_card(array $p) {
    $brand = $p['brand'] ?? '';
    $last4 = $p['last4'] ?? '';
    if (!$brand && !$last4) {
        return 'card: (n/a)';
    }
    return trim($brand . ' ****' . $last4);
}

// Payment events we subscribe to. Guesty webhook subscriptions are API-only
// (the dashboard can't create them), so we register via POST /v1/webhooks.
function guesty_payment_webhook_events() {
    return [
        'payments.received',
        'payments.failed',
        'payments.refunded',
        'payments.overdue',
        'payments.overcharged',
        'payments.overcharge.expected',
        'payments.authenticationRequired',
        'payments.authorizationHoldFailed',
        'payments.disputes',
    ];
}

/**
 * Look up our webhook subscription on Guesty by matching URL.
 * Returns the matching row (incl. '_id') or null if not found. On API error
 * returns a WP_Error so callers can distinguish "not registered" from "couldn't check".
 *
 * @return array|WP_Error|null
 */
function guesty_find_registered_webhook() {
    $token = guesty_get_token();
    if (!$token) {
        return new WP_Error('guesty_auth', 'Could not authenticate with Guesty.');
    }

    $our_url = guesty_get_webhook_url();

    $list = wp_remote_get('https://open-api.guesty.com/v1/webhooks', [
        'headers' => ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'],
        'timeout' => 20,
    ]);
    if (is_wp_error($list)) {
        return $list;
    }

    $items = json_decode(wp_remote_retrieve_body($list), true);
    $rows  = is_array($items['results'] ?? null) ? $items['results'] : (is_array($items) ? $items : []);
    foreach ($rows as $row) {
        if (is_array($row) && !empty($row['url']) && $row['url'] === $our_url) {
            return $row;
        }
    }

    return null;
}

/**
 * Register our payment webhook with Guesty via the API.
 * Skips creation if a webhook for the same URL already exists.
 *
 * @return array{ok:bool,message:string}
 */
function guesty_register_webhook_with_guesty() {
    $token = guesty_get_token();
    if (!$token) {
        return ['ok' => false, 'message' => 'Could not authenticate with Guesty.'];
    }

    $our_url = guesty_get_webhook_url();

    // Avoid duplicates: check existing subscriptions first.
    $existing = guesty_find_registered_webhook();
    if (is_array($existing)) {
        guesty_log('webhook_register', 'Already registered: ' . $our_url);
        return ['ok' => true, 'message' => 'Webhook already registered with Guesty.'];
    }

    $response = wp_remote_post('https://open-api.guesty.com/v1/webhooks', [
        'headers' => [
            'Authorization' => 'Bearer ' . $token,
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
        ],
        'body'    => wp_json_encode([
            'url'    => $our_url,
            'events' => guesty_payment_webhook_events(),
        ]),
        'timeout' => 30,
    ]);

    if (is_wp_error($response)) {
        guesty_log('webhook_register_error', 'WP_Error: ' . $response->get_error_message());
        return ['ok' => false, 'message' => 'Network error contacting Guesty.'];
    }

    $code = (int) wp_remote_retrieve_response_code($response);
    $raw  = (string) wp_remote_retrieve_body($response);

    if ($code >= 200 && $code < 300) {
        guesty_log('webhook_register', 'HTTP ' . $code . ' | URL: ' . $our_url . ' | Body: ' . $raw);
        return ['ok' => true, 'message' => 'Webhook registered with Guesty successfully.'];
    }

    guesty_log('webhook_register_error', 'HTTP ' . $code . ' | Body: ' . $raw);
    return ['ok' => false, 'message' => 'Guesty rejected the request (HTTP ' . $code . '). See Guesty Sync Logs.'];
}

/**
 * Delete our payment webhook subscription from Guesty.
 *
 * @return array{ok:bool,message:string}
 */
function guesty_delete_webhook_with_guesty() {
    $existing = guesty_find_registered_webhook();
    if (is_wp_error($existing)) {
        return ['ok' => false, 'message' => 'Could not reach Guesty to look up the webhook.'];
    }
    if (!is_array($existing)) {
        return ['ok' => true, 'message' => 'No webhook was registered (nothing to remove).'];
    }

    $id = $existing['_id'] ?? ($existing['id'] ?? '');
    if (!$id) {
        return ['ok' => false, 'message' => 'Found the webhook but Guesty returned no ID.'];
    }

    $token = guesty_get_token();
    if (!$token) {
        return ['ok' => false, 'message' => 'Could not authenticate with Guesty.'];
    }

    $response = wp_remote_request('https://open-api.guesty.com/v1/webhooks/' . rawurlencode($id), [
        'method'  => 'DELETE',
        'headers' => ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'],
        'timeout' => 30,
    ]);

    if (is_wp_error($response)) {
        guesty_log('webhook_delete_error', 'WP_Error: ' . $response->get_error_message());
        return ['ok' => false, 'message' => 'Network error contacting Guesty.'];
    }

    $code = (int) wp_remote_retrieve_response_code($response);
    $raw  = (string) wp_remote_retrieve_body($response);

    if ($code >= 200 && $code < 300) {
        guesty_log('webhook_delete', 'HTTP ' . $code . ' | ID: ' . $id);
        return ['ok' => true, 'message' => 'Webhook removed from Guesty.'];
    }

    guesty_log('webhook_delete_error', 'HTTP ' . $code . ' | Body: ' . $raw);
    return ['ok' => false, 'message' => 'Guesty rejected the delete (HTTP ' . $code . '). See Guesty Sync Logs.'];
}

function guesty_webhook_action_redirect(array $result) {
    $redirect = wp_get_referer() ?: admin_url('admin.php?page=guesty-sync-settings&tab=booking_settings');
    $redirect = add_query_arg([
        'guesty_wh'     => $result['ok'] ? 'ok' : 'err',
        'guesty_wh_msg' => rawurlencode($result['message']),
    ], $redirect);
    wp_safe_redirect($redirect);
    exit;
}

// admin-post handler for the "Register webhook with Guesty" button.
add_action('admin_post_guesty_register_webhook', 'guesty_handle_register_webhook_action');
function guesty_handle_register_webhook_action() {
    if (!current_user_can('manage_options')) {
        wp_die('Insufficient permissions.');
    }
    check_admin_referer('guesty_register_webhook');
    guesty_webhook_action_redirect(guesty_register_webhook_with_guesty());
}

// admin-post handler for the "Remove webhook" button.
add_action('admin_post_guesty_delete_webhook', 'guesty_handle_delete_webhook_action');
function guesty_handle_delete_webhook_action() {
    if (!current_user_can('manage_options')) {
        wp_die('Insufficient permissions.');
    }
    check_admin_referer('guesty_delete_webhook');
    guesty_webhook_action_redirect(guesty_delete_webhook_with_guesty());
}