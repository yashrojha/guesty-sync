<?php
if (!defined('ABSPATH')) exit;

/**
 * Card attachment for direct bookings (Guesty owns collection by default).
 *
 * A reservation created through /v1/reservations-v3 is only eventually
 * consistent with the legacy /v1 store that /v1/guests/{id}/payment-methods
 * validates against. Calling that endpoint immediately returns 400
 * "Reservation not found", the card never lands on the booking, and Guesty's
 * auto-payment schedule is built with no card — so nothing is ever charged.
 *
 * Default mode is `off`: wait until the reservation is readable, attach the
 * card to the reservation (with retries), wait until it is bound, then confirm.
 * Guesty's payment automations collect from there. Optional `always` / `follow`
 * modes can still post a charge from the site if an operator turns them on.
 */

if (!defined('GUESTY_PAYMENT_RETRY_HOOK')) {
    define('GUESTY_PAYMENT_RETRY_HOOK', 'guesty_payment_retry_event');
}
if (!defined('GUESTY_PAYMENT_MAX_RETRIES')) {
    define('GUESTY_PAYMENT_MAX_RETRIES', 5);
}

/**
 * Payment statuses that mean "Guesty already intends to take this money".
 * Guesty rejects a new payment when balance due minus these would go negative,
 * so they must be subtracted before we post our own charge.
 */
function guesty_payment_pending_statuses() {
    return ['PENDING', 'PROCESSING', 'SCHEDULED', 'AUTHORIZED', 'IN_PROGRESS', 'WAITING_FOR_AUTHENTICATION'];
}

/**
 * 'off'    — attach the card only; Guesty payment automations collect (default).
 * 'follow' — charge from the site only when no Guesty automation applies.
 * 'always' — always collect the outstanding balance at booking.
 */
function guesty_booking_auto_charge_mode() {
    $mode = (string) get_option('guesty_booking_auto_charge', 'off');

    // 'balance' was the pre-workflow name for the always-collect behaviour.
    if ('balance' === $mode) {
        return 'always';
    }

    return in_array($mode, ['follow', 'always', 'off'], true) ? $mode : 'off';
}

/**
 * One-time: switch existing installs to Guesty-managed collection (client policy).
 */
add_action('init', 'guesty_ensure_guesty_managed_payments', 5);
function guesty_ensure_guesty_managed_payments() {
    if (get_option('guesty_payments_managed_by_guesty_v1')) {
        return;
    }
    update_option('guesty_booking_auto_charge', 'off', false);
    update_option('guesty_payments_managed_by_guesty_v1', 1, false);
}

/**
 * Seconds to let Guesty's auto-payment policy settle after confirmation before
 * reading the balance. Without it we race the policy and both sides try to
 * collect the same money.
 */
function guesty_payment_settle_delay() {
    return max(0, (int) apply_filters('guesty_payment_settle_delay', 6));
}

function guesty_api_headers($token, $with_content_type = false) {
    $headers = [
        'Authorization' => 'Bearer ' . $token,
        'Accept'        => 'application/json',
    ];
    if ($with_content_type) {
        $headers['Content-Type'] = 'application/json';
    }

    return $headers;
}

/**
 * Errors worth retrying: transport failures, throttling, server faults, and the
 * replication lag that shows up as a 400/404 "not found".
 */
function guesty_payment_error_is_retryable($code, $raw) {
    $code = (int) $code;

    if (0 === $code || 429 === $code || $code >= 500) {
        return true;
    }
    if (404 === $code) {
        return true;
    }

    return 400 === $code && stripos((string) $raw, 'not found') !== false;
}

/**
 * True once the reservation exists in the legacy /v1 store — the same store the
 * payment endpoints read.
 */
function guesty_reservation_is_readable($token, $reservation_id) {
    if (!$token || !$reservation_id) {
        return false;
    }

    $url = add_query_arg(
        ['fields' => '_id status'],
        'https://open-api.guesty.com/v1/reservations/' . rawurlencode($reservation_id)
    );

    $response = wp_remote_get($url, ['headers' => guesty_api_headers($token), 'timeout' => 15]);

    if (is_wp_error($response)) {
        return false;
    }

    return 200 === (int) wp_remote_retrieve_response_code($response);
}

/**
 * Poll until the reservation is readable or the request's wall-clock budget runs out.
 */
function guesty_wait_for_reservation_readable($token, $reservation_id, $deadline_ts, $delay = 2) {
    $attempt = 0;

    while (true) {
        $attempt++;

        if (guesty_reservation_is_readable($token, $reservation_id)) {
            guesty_log('reservation_readable', 'Res: ' . $reservation_id . ' | Readable on /v1/reservations after attempt ' . $attempt);

            return true;
        }

        if (time() + $delay >= $deadline_ts) {
            break;
        }

        sleep($delay);
    }

    guesty_log('reservation_not_readable', 'Res: ' . $reservation_id . ' | Not readable on /v1/reservations after ' . $attempt . ' attempts');

    return false;
}

/**
 * Guest's saved payment methods. Pass a reservation ID when the method was
 * attached to a specific booking.
 *
 * @return array List of payment method objects.
 */
function guesty_get_guest_payment_methods($token, $guest_id, $reservation_id = '') {
    if (!$token || !$guest_id) {
        return [];
    }

    $url = 'https://open-api.guesty.com/v1/guests/' . rawurlencode($guest_id) . '/payment-methods';
    if ($reservation_id) {
        $url = add_query_arg('reservationId', $reservation_id, $url);
    }

    $response = wp_remote_get($url, ['headers' => guesty_api_headers($token), 'timeout' => 20]);

    if (is_wp_error($response) || 200 !== (int) wp_remote_retrieve_response_code($response)) {
        return [];
    }

    $data = json_decode((string) wp_remote_retrieve_body($response), true);
    if (!is_array($data)) {
        return [];
    }

    return isset($data['results']) && is_array($data['results']) ? $data['results'] : $data;
}

/**
 * Newest ACTIVE method created within $within_seconds.
 *
 * The recency window matters: a returning guest can have older reused cards on
 * file, and picking one of those would charge a card the guest didn't just enter.
 *
 * @return array|null
 */
function guesty_find_recent_active_payment_method(array $methods, $within_seconds = 300) {
    $cutoff = time() - (int) $within_seconds;
    $best   = null;
    $best_t = 0;

    foreach ($methods as $method) {
        if (!is_array($method) || 'ACTIVE' !== strtoupper((string) ($method['status'] ?? ''))) {
            continue;
        }

        $created = strtotime((string) ($method['createdAt'] ?? ''));
        if (!$created || $created < $cutoff) {
            continue;
        }

        if ($created >= $best_t) {
            $best   = $method;
            $best_t = $created;
        }
    }

    return $best;
}

/**
 * Normalised result shape shared by every attach path.
 */
function guesty_attach_result($ok, $code, $raw, $retryable, $method_id = '', $method = '', $error = '') {
    return [
        'ok'        => (bool) $ok,
        'code'      => (int) $code,
        'raw'       => (string) $raw,
        'retryable' => (bool) $retryable,
        'methodId'  => (string) $method_id,
        'method'    => (string) $method,
        'error'     => (string) $error,
    ];
}

/**
 * One POST to /v1/guests/{id}/payment-methods.
 *
 * Omit $reservation_id to save the card against the guest only — useful when the
 * reservation hasn't replicated yet, since the token would otherwise be wasted.
 */
function guesty_attach_payment_method_once($token, $guest_id, $provider_id, $card_token, $reservation_id = '') {
    $scope = $reservation_id ?: '(guest-level)';

    $body = [
        '_id'               => $card_token,
        'paymentProviderId' => $provider_id,
        'reuse'             => true,
    ];
    if ($reservation_id) {
        $body['reservationId'] = $reservation_id;
    }

    // Token redacted (PCI); the Res: tag lets the whole chain be pulled per booking.
    $log_body        = $body;
    $log_body['_id'] = substr((string) $card_token, 0, 12) . '…';
    guesty_log(
        'payment_method_request',
        'Res: ' . $scope . ' | GuestId: ' . $guest_id . ' | Provider: ' . $provider_id . ' | Body: ' . wp_json_encode($log_body)
    );

    $response = wp_remote_post(
        'https://open-api.guesty.com/v1/guests/' . rawurlencode($guest_id) . '/payment-methods',
        [
            'headers' => guesty_api_headers($token, true),
            'body'    => wp_json_encode($body),
            'timeout' => 30,
        ]
    );

    if (is_wp_error($response)) {
        $raw = 'WP_Error: ' . $response->get_error_message();
        guesty_log('payment_method_error', 'Res: ' . $scope . ' | ' . $raw);

        return guesty_attach_result(false, 0, $raw, true, '', '', 'Payment attachment failed (network error).');
    }

    $code = (int) wp_remote_retrieve_response_code($response);
    $raw  = (string) wp_remote_retrieve_body($response);
    $data = json_decode($raw, true);

    guesty_log('payment_method_response', 'Res: ' . $scope . ' | HTTP ' . $code . ' | Body: ' . $raw);

    if (200 === $code || 201 === $code) {
        $status    = is_array($data) ? strtoupper((string) ($data['status'] ?? '')) : '';
        $method_id = is_array($data) ? (string) ($data['_id'] ?? '') : '';
        $method    = is_array($data) ? strtoupper((string) ($data['method'] ?? '')) : '';

        // 2xx alone isn't proof — the processor can reject the card and still
        // return a record, so require ACTIVE when Guesty reports a status.
        $ok = ('ACTIVE' === $status || '' === $status);

        if (!$ok) {
            guesty_log('payment_method_error', 'Res: ' . $scope . ' | Card attached but status is ' . $status . ' | Raw: ' . $raw);
        }

        return guesty_attach_result(
            $ok,
            $code,
            $raw,
            false,
            $method_id,
            $method,
            $ok ? '' : 'Card attached but not ACTIVE (status: ' . $status . ').'
        );
    }

    $error = '';
    if (is_array($data)) {
        $error = $data['error']['message']
            ?? $data['errors'][0]['message']
            ?? $data['message']
            ?? (is_string($data['error'] ?? null) ? $data['error'] : '');
    }
    if (!$error) {
        $error = 'Payment could not be attached (HTTP ' . $code . ').';
    }

    // Keep the raw body — decline and 3DS reasons rarely sit in the standard keys.
    guesty_log('payment_method_error', 'Res: ' . $scope . ' | HTTP ' . $code . ' | Error: ' . $error . ' | Raw: ' . $raw);

    return guesty_attach_result(false, $code, $raw, guesty_payment_error_is_retryable($code, $raw), '', '', $error);
}

/**
 * Attach the card, retrying transient failures until the deadline.
 */
function guesty_attach_payment_method($token, $guest_id, $provider_id, $card_token, $reservation_id, $deadline_ts, $delay = 3) {
    $scope   = $reservation_id ?: '(guest-level)';
    $attempt = 0;

    while (true) {
        $attempt++;
        $result = guesty_attach_payment_method_once($token, $guest_id, $provider_id, $card_token, $reservation_id);

        if ($result['ok'] || !$result['retryable']) {
            break;
        }

        // A transport failure may have succeeded server-side; re-posting the same
        // token would then either duplicate the card or fail as already used.
        if (0 === $result['code']) {
            $existing = guesty_find_recent_active_payment_method(
                guesty_get_guest_payment_methods($token, $guest_id, $reservation_id)
            );
            if ($existing) {
                guesty_log('payment_method_recovered', 'Res: ' . $scope . ' | Found method ' . ($existing['_id'] ?? '') . ' after a network failure');
                $result = guesty_attach_result(
                    true,
                    200,
                    wp_json_encode($existing),
                    false,
                    (string) ($existing['_id'] ?? ''),
                    strtoupper((string) ($existing['method'] ?? ''))
                );
                break;
            }
        }

        if (time() + $delay >= $deadline_ts) {
            break;
        }

        sleep($delay);
    }

    // Single consolidated row so the outcome survives even if the detail rows
    // scroll out of the log viewer.
    guesty_log(
        'payment_method_attempt',
        'Res: ' . $scope
            . ' | Status: ' . ($result['ok'] ? 'success' : 'failed')
            . ' | Attempts: ' . $attempt
            . ' | HTTP: ' . $result['code']
            . ' | Provider: ' . $provider_id
            . ' | MethodId: ' . ($result['methodId'] ?: '(none)')
            . ' | Raw: ' . $result['raw']
    );

    return $result;
}

/**
 * The payment automation Guesty will run for this booking, if any.
 *
 * `paymentWorkflow` is the current automation engine: `actionTemplates` is the
 * rule configured against the listing/channel, `actions` is the schedule Guesty
 * materialised once the booking was confirmed.
 *
 * @return array|null The workflow object, or null when none applies.
 */
function guesty_get_reservation_payment_workflow($token, $reservation_id) {
    if (!$token || !$reservation_id) {
        return null;
    }

    $url = add_query_arg(
        [
            'reservationIds[]'       => $reservation_id,
            'includePaymentsTemplate' => 'true',
        ],
        'https://open-api.guesty.com/v1/reservations-v3'
    );

    $response = wp_remote_get($url, ['headers' => guesty_api_headers($token), 'timeout' => 20]);

    if (is_wp_error($response) || 200 !== (int) wp_remote_retrieve_response_code($response)) {
        return null;
    }

    $data = json_decode((string) wp_remote_retrieve_body($response), true);
    if (!is_array($data)) {
        return null;
    }

    $reservations = isset($data['results']) && is_array($data['results']) ? $data['results'] : $data;

    foreach ($reservations as $reservation) {
        if (!is_array($reservation)) {
            continue;
        }
        $workflow = $reservation['paymentWorkflow'] ?? ($reservation['money']['paymentWorkflow']['workflow'] ?? null);
        if (is_array($workflow) && !empty($workflow)) {
            return $workflow;
        }
    }

    return null;
}

/**
 * Legacy auto-payment rules, still in use on older Guesty accounts. Checked as a
 * fallback so an account that hasn't migrated to payment automations isn't
 * mistaken for one with no rule at all.
 */
function guesty_listing_has_legacy_auto_payments($token, $listing_id) {
    if (!$token || !$listing_id) {
        return false;
    }

    $url = add_query_arg(
        ['fields' => 'pms.autoPayments'],
        'https://open-api.guesty.com/v1/listings/' . rawurlencode($listing_id)
    );

    $response = wp_remote_get($url, ['headers' => guesty_api_headers($token), 'timeout' => 15]);

    if (is_wp_error($response) || 200 !== (int) wp_remote_retrieve_response_code($response)) {
        return false;
    }

    $data   = json_decode((string) wp_remote_retrieve_body($response), true);
    $policy = $data['pms']['autoPayments']['policy'] ?? null;

    return is_array($policy) && !empty($policy);
}

/**
 * Whether Guesty itself will collect for this booking.
 *
 * Guesty requires a payment automation to cover 100% of the reservation total,
 * so when one applies the site must not charge anything — doing so would
 * front-load money the property deliberately scheduled for later.
 *
 * @return array{governed:bool,source:string,scheduled:int}
 */
function guesty_reservation_payment_plan($token, $reservation_id, $listing_id = '') {
    $workflow = guesty_get_reservation_payment_workflow($token, $reservation_id);

    if (is_array($workflow) && !isset($workflow['enabled'])) {
        $workflow['enabled'] = true;
    }

    if (is_array($workflow) && !empty($workflow['enabled'])) {
        $actions = is_array($workflow['actions'] ?? null) ? $workflow['actions'] : [];

        guesty_log(
            'payment_plan',
            'Res: ' . $reservation_id
                . ' | Governed by Guesty automation "' . (string) ($workflow['name'] ?? 'unnamed') . '"'
                . ' | Scheduled actions: ' . count($actions)
        );

        return ['governed' => true, 'source' => 'workflow', 'scheduled' => count($actions)];
    }

    if ($listing_id && guesty_listing_has_legacy_auto_payments($token, $listing_id)) {
        guesty_log('payment_plan', 'Res: ' . $reservation_id . ' | Governed by legacy auto-payment rules on listing ' . $listing_id);

        return ['governed' => true, 'source' => 'legacy', 'scheduled' => 0];
    }

    guesty_log('payment_plan', 'Res: ' . $reservation_id . ' | No Guesty payment automation applies — the site will collect');

    return ['governed' => false, 'source' => 'none', 'scheduled' => 0];
}

/**
 * Reservation `money` object from the legacy store (balance due, payments, currency).
 *
 * @return array|null
 */
function guesty_get_reservation_money($token, $reservation_id) {
    if (!$token || !$reservation_id) {
        return null;
    }

    $url = add_query_arg(
        ['fields' => 'money status'],
        'https://open-api.guesty.com/v1/reservations/' . rawurlencode($reservation_id)
    );

    $response = wp_remote_get($url, ['headers' => guesty_api_headers($token), 'timeout' => 20]);

    if (is_wp_error($response)) {
        guesty_log('payment_balance_error', 'Res: ' . $reservation_id . ' | WP_Error: ' . $response->get_error_message());

        return null;
    }

    $code = (int) wp_remote_retrieve_response_code($response);
    $raw  = (string) wp_remote_retrieve_body($response);

    if (200 !== $code) {
        guesty_log('payment_balance_error', 'Res: ' . $reservation_id . ' | HTTP ' . $code . ' | Body: ' . $raw);

        return null;
    }

    $data = json_decode($raw, true);

    return is_array($data) && is_array($data['money'] ?? null) ? $data['money'] : null;
}

/**
 * Money Guesty is already going to collect (scheduled or in-flight payments).
 * Authorization holds are excluded — they don't reduce the balance due.
 */
function guesty_reservation_pending_payments_total(array $money) {
    $pending  = guesty_payment_pending_statuses();
    $payments = is_array($money['payments'] ?? null) ? $money['payments'] : [];
    $total    = 0.0;

    foreach ($payments as $payment) {
        if (!is_array($payment) || !empty($payment['isAuthorizationHold'])) {
            continue;
        }
        if (in_array(strtoupper((string) ($payment['status'] ?? '')), $pending, true)) {
            $total += (float) ($payment['amount'] ?? 0);
        }
    }

    return $total;
}

/**
 * What we may safely charge now. Guesty rejects any payment that would push the
 * total expected collection past the balance due, so scheduled payments are
 * subtracted rather than duplicated.
 */
function guesty_reservation_chargeable_amount(array $money) {
    $balance = (float) ($money['balanceDue'] ?? 0);

    return round(max(0, $balance - guesty_reservation_pending_payments_total($money)), 2);
}

/**
 * Charge the card. Omitting shouldBePaidAt makes Guesty process it immediately.
 *
 * @return array{ok:bool,code:int,raw:string,error:string,retryable:bool}
 */
function guesty_post_reservation_payment($token, $reservation_id, $method_name, $payment_method_id, $amount, $note = '') {
    $body = [
        'paymentMethod' => [
            'method'           => $method_name,
            'saveForFutureUse' => true,
            'id'               => $payment_method_id,
        ],
        'amount' => (float) $amount,
    ];
    if ($note) {
        $body['note'] = $note;
    }

    guesty_log(
        'payment_charge_request',
        'Res: ' . $reservation_id . ' | Amount: ' . $amount . ' | Method: ' . $method_name . ' | MethodId: ' . $payment_method_id
    );

    $response = wp_remote_post(
        'https://open-api.guesty.com/v1/reservations/' . rawurlencode($reservation_id) . '/payments',
        [
            'headers' => guesty_api_headers($token, true),
            'body'    => wp_json_encode($body),
            'timeout' => 45,
        ]
    );

    if (is_wp_error($response)) {
        $raw = 'WP_Error: ' . $response->get_error_message();
        guesty_log('payment_charge_error', 'Res: ' . $reservation_id . ' | ' . $raw);

        return ['ok' => false, 'code' => 0, 'raw' => $raw, 'error' => 'Charge failed (network error).', 'retryable' => true];
    }

    $code = (int) wp_remote_retrieve_response_code($response);
    $raw  = (string) wp_remote_retrieve_body($response);
    $data = json_decode($raw, true);

    if ($code >= 200 && $code < 300) {
        guesty_log('payment_charge_ok', 'Res: ' . $reservation_id . ' | HTTP ' . $code . ' | Amount: ' . $amount . ' | Body: ' . $raw);

        return ['ok' => true, 'code' => $code, 'raw' => $raw, 'error' => '', 'retryable' => false];
    }

    $error = '';
    if (is_array($data)) {
        $error = $data['error']['message']
            ?? $data['errors'][0]['message']
            ?? $data['message']
            ?? (is_string($data['error'] ?? null) ? $data['error'] : '');
    }
    if (!$error) {
        $error = 'Charge could not be posted (HTTP ' . $code . ').';
    }

    guesty_log('payment_charge_error', 'Res: ' . $reservation_id . ' | HTTP ' . $code . ' | Error: ' . $error . ' | Raw: ' . $raw);

    return [
        'ok'        => false,
        'code'      => $code,
        'raw'       => $raw,
        'error'     => $error,
        'retryable' => guesty_payment_error_is_retryable($code, $raw),
    ];
}

/**
 * Collect the outstanding balance with the guest's card.
 *
 * In the default 'follow' mode this is a safety net, not a policy: it only fires
 * when the property has no Guesty payment automation, because an automation
 * covers 100% of the total and charging alongside it would collect money the
 * property deliberately scheduled for later.
 *
 * @param array $context {listingId, cardBoundBeforeConfirm}
 * @return array{ok:bool,charged:float,skipped:bool,error:string,retryable:bool}
 */
function guesty_collect_reservation_balance($token, $reservation_id, $payment_method_id, $method_name = '', array $context = []) {
    $mode = guesty_booking_auto_charge_mode();

    if ('off' === $mode) {
        guesty_log('payment_charge_skipped', 'Res: ' . $reservation_id . ' | Site collection disabled in Booking Settings');

        return ['ok' => true, 'charged' => 0.0, 'skipped' => true, 'error' => '', 'retryable' => false];
    }

    if (!$payment_method_id) {
        return ['ok' => false, 'charged' => 0.0, 'skipped' => false, 'error' => 'No payment method available to charge.', 'retryable' => false];
    }

    if ('follow' === $mode) {
        $plan = guesty_reservation_payment_plan($token, $reservation_id, (string) ($context['listingId'] ?? ''));

        if ($plan['governed']) {
            // Guesty only runs its schedule against a card that was on the
            // reservation at confirmation. If ours landed late the automation
            // sits pending with no card and needs a human — charging on top of
            // it would double up once staff assign the card.
            if (empty($context['cardBoundBeforeConfirm'])) {
                guesty_log(
                    'payment_automation_needs_card',
                    'Res: ' . $reservation_id
                        . ' | The ' . $plan['source'] . ' automation was created without a card on the reservation.'
                        . ' Assign the guest card to the scheduled payments in Guesty, or it will never collect.'
                );

                return [
                    'ok'        => false,
                    'charged'   => 0.0,
                    'skipped'   => false,
                    'error'     => 'Scheduled payments need the card assigned in Guesty.',
                    'retryable' => false,
                ];
            }

            guesty_log(
                'payment_charge_skipped',
                'Res: ' . $reservation_id . ' | Guesty ' . $plan['source'] . ' automation owns collection (' . $plan['scheduled'] . ' scheduled) — site charges nothing'
            );

            return ['ok' => true, 'charged' => 0.0, 'skipped' => true, 'error' => '', 'retryable' => false];
        }
    }

    $money = guesty_get_reservation_money($token, $reservation_id);
    if (null === $money) {
        return ['ok' => false, 'charged' => 0.0, 'skipped' => false, 'error' => 'Could not read the reservation balance.', 'retryable' => true];
    }

    $amount = guesty_reservation_chargeable_amount($money);

    if ($amount <= 0) {
        guesty_log(
            'payment_charge_skipped',
            'Res: ' . $reservation_id
                . ' | balanceDue: ' . (float) ($money['balanceDue'] ?? 0)
                . ' | pending: ' . guesty_reservation_pending_payments_total($money)
                . ' | Nothing left to charge'
        );

        return ['ok' => true, 'charged' => 0.0, 'skipped' => true, 'error' => '', 'retryable' => false];
    }

    // GuestyPay, Merchant Warrior and Hyp all report as AMARYLLIS.
    if (!$method_name) {
        $method_name = 'AMARYLLIS';
    }

    $result = guesty_post_reservation_payment(
        $token,
        $reservation_id,
        $method_name,
        $payment_method_id,
        $amount,
        apply_filters('guesty_payment_charge_note', 'Website booking payment — ' . get_bloginfo('name'), $reservation_id)
    );

    return [
        'ok'        => $result['ok'],
        'charged'   => $result['ok'] ? (float) $amount : 0.0,
        'skipped'   => false,
        'error'     => $result['error'],
        'retryable' => $result['retryable'],
    ];
}

/**
 * Queue a background retry for a booking whose confirmation or charge didn't
 * complete in the request. Only IDs are persisted — never the card token.
 *
 * @param array $job {reservationId, guestId, paymentMethodId, method, needsConfirm, needsCharge}
 */
function guesty_schedule_payment_retry(array $job, $attempt = 1) {
    $attempt = (int) $attempt;

    if ($attempt > GUESTY_PAYMENT_MAX_RETRIES) {
        guesty_log('payment_retry_exhausted', 'Res: ' . ($job['reservationId'] ?? '(none)') . ' | Giving up after ' . GUESTY_PAYMENT_MAX_RETRIES . ' retries — needs manual handling in Guesty');

        return;
    }

    $delay = min(15 * pow(2, $attempt - 1), 600);

    wp_schedule_single_event(time() + (int) $delay, GUESTY_PAYMENT_RETRY_HOOK, [$job, $attempt]);

    guesty_log(
        'payment_retry_scheduled',
        'Res: ' . ($job['reservationId'] ?? '(none)') . ' | Attempt ' . $attempt . ' in ' . (int) $delay . 's'
            . ' | Confirm: ' . (empty($job['needsConfirm']) ? 'no' : 'yes')
            . ' | Charge: ' . (empty($job['needsCharge']) ? 'no' : 'yes')
    );
}

add_action(GUESTY_PAYMENT_RETRY_HOOK, 'guesty_run_payment_retry', 10, 2);
function guesty_run_payment_retry($job, $attempt = 1) {
    if (!is_array($job) || empty($job['reservationId'])) {
        return;
    }

    $reservation_id = (string) $job['reservationId'];
    $attempt        = (int) $attempt;

    $token = guesty_get_token();
    if (!$token) {
        guesty_log('payment_retry_error', 'Res: ' . $reservation_id . ' | Could not authenticate with Guesty');
        guesty_schedule_payment_retry($job, $attempt + 1);

        return;
    }

    guesty_log('payment_retry_run', 'Res: ' . $reservation_id . ' | Attempt ' . $attempt);

    if (!empty($job['needsConfirm'])) {
        $confirm = guesty_reservation_confirm($token, $reservation_id);
        if ($confirm['ok']) {
            $job['needsConfirm'] = false;
            sleep(guesty_payment_settle_delay());
        }
    }

    // Charging an unconfirmed booking just fails; wait for the next pass.
    // Also skip charge retries when the site is set to Guesty-managed collection.
    if (!empty($job['needsCharge']) && empty($job['needsConfirm'])) {
        if ('off' === guesty_booking_auto_charge_mode()) {
            $job['needsCharge'] = false;
            guesty_log('payment_charge_skipped', 'Res: ' . $reservation_id . ' | Retry skipped charge — Guesty manages collection');
        } else {
            $charge = guesty_collect_reservation_balance(
                $token,
                $reservation_id,
                (string) ($job['paymentMethodId'] ?? ''),
                (string) ($job['method'] ?? ''),
                [
                    'listingId'              => (string) ($job['listingId'] ?? ''),
                    'cardBoundBeforeConfirm' => !empty($job['cardBound']),
                ]
            );
            if ($charge['ok']) {
                $job['needsCharge'] = false;
            } elseif (!$charge['retryable']) {
                // A hard decline won't fix itself; stop burning retries on it.
                guesty_log('payment_retry_abandoned', 'Res: ' . $reservation_id . ' | Non-retryable charge error: ' . $charge['error']);
                $job['needsCharge'] = false;
            }
        }
    }

    if (empty($job['needsConfirm']) && empty($job['needsCharge'])) {
        guesty_log('payment_retry_complete', 'Res: ' . $reservation_id . ' | Resolved on attempt ' . $attempt);

        return;
    }

    guesty_schedule_payment_retry($job, $attempt + 1);
}
