<?php
if (!defined('ABSPATH')) exit;

/* =====================================================================
    1. Collect URL parameters
===================================================================== */
$check_in    = sanitize_text_field($_GET['check_in']   ?? '');
$check_out   = sanitize_text_field($_GET['check_out']  ?? '');
$guest_count = max(1, intval($_GET['guest']            ?? 1));
$listing_id  = sanitize_text_field($_GET['listing_id'] ?? '');

if (!$listing_id) {
    wp_redirect(get_post_type_archive_link('properties'));
    exit;
}

/* =====================================================================
    2. Date format & logic validation — before any API call
        Catches tampered / stale URLs immediately
===================================================================== */
$page_error = '';

if (!$check_in || !$check_out) {
    $page_error = 'Check-in and check-out dates are required. Please go back and select your dates.';
} else {
    $ci_obj = DateTime::createFromFormat('Y-m-d', $check_in);
    $co_obj = DateTime::createFromFormat('Y-m-d', $check_out);
    $today  = new DateTime('today');

    if (
        !$ci_obj || !$co_obj
        || $ci_obj->format('Y-m-d') !== $check_in
        || $co_obj->format('Y-m-d') !== $check_out
    ) {
        $page_error = 'The dates provided are not in a valid format. Please go back and select dates from the calendar.';
    } elseif ($ci_obj < $today) {
        $page_error = sprintf(
            'The check-in date <strong>%s</strong> is in the past. Please go back and choose a future date.',
            esc_html($check_in)
        );
    } elseif ($co_obj <= $ci_obj) {
        $page_error = 'Check-out must be after check-in. Please go back and select valid dates.';
    }
}

/* =====================================================================
    3. Look up the WordPress post by Guesty listing ID
===================================================================== */
$wp_posts = get_posts([
    'post_type'      => 'properties',
    'meta_key'       => 'guesty_id',
    'meta_value'     => $listing_id,
    'posts_per_page' => 1,
    'post_status'    => 'publish',
]);

$wp_post        = !empty($wp_posts) ? $wp_posts[0] : null;
$property_title = $wp_post ? get_the_title($wp_post->ID) : 'Property';
$property_type  = $wp_post ? get_post_meta($wp_post->ID, 'guesty_property_type', true) : '';
$property_image = $wp_post ? get_the_post_thumbnail_url($wp_post->ID, 'guesty_medium') : '';
$property_url   = $wp_post ? get_permalink($wp_post->ID) : get_post_type_archive_link('properties');

// Guesty stay rules (min/max nights) from synced post meta, used to humanise API errors.
$guesty_min_nights = $wp_post ? (int) get_post_meta($wp_post->ID, 'guesty_minNights', true) : 0;
$guesty_max_nights = $wp_post ? (int) get_post_meta($wp_post->ID, 'guesty_maxNights', true) : 0;

/* =====================================================================
    4. Fetch Guesty quote — the live availability & pricing check
        This is the canonical way to validate if the dates are truly
        available: if the quote fails the property is not bookable for
        those dates (blocked, min-night violation, unlisted, etc.)
===================================================================== */
$quote_data    = null;
$money         = null;
$invoice_items = [];
$currency      = 'GBP';
$total_price   = 0;
$accom_fare    = 0;
$total_fees    = 0;
$quote_valid   = false;
$quote_id      = '';

// Debug info — populated during the quote call, shown to admins
$debug_http_code  = null;
$debug_raw_body   = '';
$debug_token_used = '';

$token = guesty_get_token();

if (!$page_error) {
    if (!$token) {
        $page_error = 'The booking system is temporarily unavailable (authentication error). Please try again shortly.';
    } else {
        $debug_token_used = substr($token, 0, 12) . '…';

        // ── Calendar availability pre-check ──────────────────────────────────
        // Verify every night in the range is actually free before calling the
        // Quote API.  The quote endpoint is a pricing tool and can occasionally
        // return "valid" for dates that are blocked in the Guesty calendar.
        $avail_check = guesty_check_availability($listing_id, $check_in, $check_out);
        if (!$avail_check['available']) {
            $page_error = $avail_check['reason'];
        }
    }
}

if (!$page_error) {
    $q_response = wp_remote_post('https://open-api.guesty.com/v1/quotes', [
        'headers' => [
            'Authorization' => 'Bearer ' . $token,
            'Content-Type'  => 'application/json',
            'accept'        => 'application/json',
        ],
        'body'    => json_encode([
            'listingId'             => $listing_id,
            'checkInDateLocalized'  => $check_in,
            'checkOutDateLocalized' => $check_out,
            'guestsCount'           => $guest_count,
            'source'                => 'manual',
        ]),
        'timeout' => 25,
        'sslverify' => false,
    ]);

    if (is_wp_error($q_response)) {
        $debug_raw_body = 'WP_Error: ' . $q_response->get_error_message();
        $page_error     = 'Unable to verify availability right now (network error). Please refresh the page or try again in a moment.';
    } else {
        $debug_http_code = wp_remote_retrieve_response_code($q_response);
        $debug_raw_body  = wp_remote_retrieve_body($q_response);
        $quote_data      = json_decode($debug_raw_body, true);

        // Guesty returns 201 Created for POST /v1/quotes (not 200)
        if (
            in_array($debug_http_code, [200, 201], true)
            && is_array($quote_data)
            && !empty($quote_data['status'])
            && $quote_data['status'] === 'valid'
        ) {
            $money         = $quote_data['rates']['ratePlans'][0]['money']['money'] ?? null;
            $total_price   = $money['subTotalPrice']    ?? 0;
            $accom_fare    = $money['fareAccommodation'] ?? 0;
            $total_fees    = $money['totalFees']         ?? 0;
            $currency      = $money['currency']          ?? 'GBP';
            $invoice_items = $money['invoiceItems']      ?? [];
            $quote_id      = $quote_data['_id']          ?? '';
            $quote_valid   = true;
        } else {
            // guesty_log only accepts 2 args — serialize context into the message
            $log_msg = 'HTTP ' . $debug_http_code
                . ' | status=' . (is_array($quote_data) ? ($quote_data['status'] ?? 'n/a') : 'non-json')
                . ' | body=' . substr($debug_raw_body, 0, 500);
            guesty_log('quote_error', $log_msg);

            $api_msg = is_array($quote_data)
                ? ($quote_data['error']['message'] ?? $quote_data['message'] ?? '')
                : '';

            // Make low-level Guesty error strings more readable for guests.
            $page_error = gbk_humanize_quote_error($api_msg, $guesty_min_nights, $guesty_max_nights)
                ?: 'These dates are not available for this property. Please go back and choose different dates.';
        }
    }
}

/* =====================================================================
    5. Fetch listing details from Guesty
        — house rules, cancellation policy, and paymentProviderId
        We only make this call when dates/quote are valid so we don't
        waste an API call on an already-blocked request.
===================================================================== */
$property_api        = null;
$smoking_allowed     = false;
$pets_allowed        = false;
$parties_allowed     = false;
$children_ok         = true;
$cancel_code         = '';
$payment_provider_id = '';

if ($token && !$page_error) {
    $prop_response = wp_remote_get("https://open-api.guesty.com/v1/listings/{$listing_id}", [
        'headers' => [
            'Authorization' => 'Bearer ' . $token,
            'Accept'        => 'application/json',
        ],
        'timeout' => 20,
    ]);

    if (!is_wp_error($prop_response) && wp_remote_retrieve_response_code($prop_response) === 200) {
        $property_api = json_decode(wp_remote_retrieve_body($prop_response), true);
    }
}

if ($property_api) {
    $terms           = $property_api['terms'] ?? [];
    $smoking_allowed = !empty($terms['smokingAllowed']);
    $pets_allowed    = !empty($terms['petsAllowed']);
    $parties_allowed = !empty($terms['partiesEventsAllowed']);
    $children_ok     = empty($terms['childrenNotAllowed']);
    $cancel_code     = $terms['cancellationPolicy'] ?? '';
    // paymentProviderId lives directly on the listing object in Guesty
    $payment_provider_id = $property_api['paymentProviderId'] ?? '';
}

/* =====================================================================
    6. Auto-detect Stripe payment provider ID — 3-tier fallback
        Tier 1: Admin manual override (Guesty Sync > Settings > Booking)
        Tier 2: paymentProviderId on the listing (fetched above)
        Tier 3: Account-level default  GET /v1/payment-providers/default
===================================================================== */
$admin_provider = get_option('guesty_stripe_payment_provider_id', '');

if ($admin_provider) {
    $payment_provider_id = $admin_provider;   // Tier 1 always wins
} elseif (!$payment_provider_id && $token && !$page_error) {
    // Tier 3 – account default
    $pp_resp = wp_remote_get('https://open-api.guesty.com/v1/payment-providers/default', [
        'headers' => [
            'Authorization' => 'Bearer ' . $token,
            'Accept'        => 'application/json',
        ],
        'timeout' => 12,
    ]);

    if (!is_wp_error($pp_resp) && wp_remote_retrieve_response_code($pp_resp) === 200) {
        $pp_data             = json_decode(wp_remote_retrieve_body($pp_resp), true);
        // Response may be the object directly or nested under 'data'
        $payment_provider_id = $pp_data['_id'] ?? ($pp_data['data']['_id'] ?? '');
    }
}

/* =====================================================================
    7. Helper functions
===================================================================== */
function gbk_cancellation_text($code)
{
    $map = [
        'FLEXIBLE'                    => 'Full refund up to 24 hours before check-in. After that, no refund.',
        'MODERATE'                    => 'Full refund up to 5 days before check-in. After that, no refund.',
        'FIRM'                        => 'Full refund up to 30 days before check-in. 50% refund up to 7 days before. No refund thereafter.',
        'STRICT'                      => 'Full refund within 48 hours of booking if made 14+ days before check-in. Otherwise non-refundable.',
        'STRICT_14_WITH_GRACE_PERIOD' => 'Guests who cancel up to 14 days before check-in get a full refund; 7–14 days before get 50% back. After that, non-refundable.',
        'SUPER_STRICT_30'             => '50% refund up to 30 days before check-in. Non-refundable after that.',
        'SUPER_STRICT_60'             => '50% refund up to 60 days before check-in. Non-refundable after that.',
        'NO_REFUND'                   => 'This booking is non-refundable.',
        'NON_REFUNDABLE'              => 'This booking is non-refundable.',
        'MEDIUM'                      => 'Full refund up to 30 days before check-in; 50% refund up to 14 days before.',
    ];
    return $map[$code] ?? 'Our cancellation policy applies. Please contact us before booking for full details.';
}

/**
 * Turn low-level Guesty quote error messages into guest-friendly copy.
 *
 * Example raw errors:
 *   - "terms not applicable: minNights"
 *   - "terms not applicable: maxNights"
 */
function gbk_humanize_quote_error($api_msg, $min_nights = 0, $max_nights = 0)
{
    $msg = trim((string) $api_msg);
    if ($msg === '') {
        return '';
    }

    $lower = strtolower($msg);

    // Minimum stay violations
    if (strpos($lower, 'minnights') !== false || strpos($lower, 'minimum nights') !== false) {
        if ($min_nights > 0) {
            return sprintf(
                'This property requires a minimum stay of %d night%s for the selected dates. Please extend your stay or choose different dates.',
                $min_nights,
                $min_nights > 1 ? 's' : ''
            );
        }

        return 'The selected stay is shorter than the allowed minimum stay for this property. Please select a longer stay or different dates.';
    }

    // Maximum stay violations
    if (strpos($lower, 'maxnights') !== false || strpos($lower, 'maximum nights') !== false) {
        if ($max_nights > 0) {
            return sprintf(
                'This property allows a maximum stay of %d night%s for the selected dates. Please shorten your stay or choose different dates.',
                $max_nights,
                $max_nights > 1 ? 's' : ''
            );
        }

        return 'The selected stay is longer than the allowed maximum stay for this property. Please shorten your stay or choose different dates.';
    }

    // Generic "terms not applicable" style messages
    if (strpos($lower, 'terms not applicable') !== false || strpos($lower, 'terms are not applicable') !== false) {
        return 'The selected dates do not meet the booking rules for this property (for example, minimum/maximum stay or arrival/departure restrictions). Please try different dates or contact us for assistance.';
    }

    // Fallback: return the original message if it looks reasonable,
    // otherwise fall back to a generic text handled by the caller.
    return $msg;
}

function gbk_format_money($amount)
{
    return number_format((float) $amount, 2);
}

function gbk_currency_symbol($code)
{
    $symbols = ['GBP' => '£', 'USD' => '$', 'EUR' => '€', 'AUD' => 'A$', 'CAD' => 'CA$', 'NZD' => 'NZ$'];
    return $symbols[$code] ?? $code . ' ';
}

$currency_symbol = gbk_currency_symbol($currency);
$formatted_total = $quote_valid ? $currency_symbol . gbk_format_money($total_price) : '—';

$terms_url   = get_option('guesty_booking_terms_url',   get_privacy_policy_url());
$privacy_url = get_option('guesty_booking_privacy_url', get_privacy_policy_url());
$nonce       = wp_create_nonce('guesty_booking_nonce');

get_header();
?>

<div class="guesty-instant-booking-page">
    <div class="booking-page-inner">

        <h1 class="booking-page-title">Enjoy and book with confidence.</h1>

        <?php if ($page_error) : ?>
            <!-- =====================================================
            AVAILABILITY / DATE ERROR SCREEN
            Shown when dates are invalid OR listing is unavailable
        ====================================================== -->
            <div class="gbk-error-screen">
                <div class="gbk-error-icon">
                    <svg width="52" height="52" viewBox="0 0 52 52" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <circle cx="26" cy="26" r="24.5" stroke="#c0392b" stroke-width="1.5" />
                        <path d="M26 16v12" stroke="#c0392b" stroke-width="2" stroke-linecap="round" />
                        <circle cx="26" cy="35" r="1.5" fill="#c0392b" />
                    </svg>
                </div>
                <h2>Dates Unavailable. Try again with different dates.</h2>
                <p><?php echo wp_kses($page_error, ['strong' => []]); ?></p>
                <div class="gbk-error-meta">
                    <?php if ($check_in && $check_out) : ?>
                        <span>Requested: <strong><?php echo esc_html($check_in); ?></strong> → <strong><?php echo esc_html($check_out); ?></strong></span>
                        &nbsp;·&nbsp;
                    <?php endif; ?>
                    <span><?php echo esc_html($guest_count); ?> guest<?php echo $guest_count > 1 ? 's' : ''; ?></span>
                </div>
                <div class="gbk-error-actions">
                    <a href="<?php echo esc_url($property_url); ?>" class="gbk-btn gbk-btn-secondary" style="display:inline-block;width:auto;">
                        ← Change Dates
                    </a>
                    <a href="<?php echo esc_url(get_post_type_archive_link('properties')); ?>" class="gbk-btn gbk-btn-outline" style="display:inline-block;width:auto;">
                        Browse All Properties
                    </a>
                </div>
            </div>

        <?php else : ?>
            <!-- =====================================================
            BOOKING FORM (dates validated & available)
        ====================================================== -->

            <div class="booking-alert-banner">
                <span class="alert-icon">
                    <svg width="18" height="18" viewBox="0 0 18 18" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                        <circle cx="9" cy="9" r="8.25" stroke="#1a6fad" stroke-width="1.5" />
                        <path d="M9 5v4.5" stroke="#1a6fad" stroke-width="1.5" stroke-linecap="round" />
                        <circle cx="9" cy="13" r="0.75" fill="#1a6fad" />
                    </svg>
                </span>
                <span>Please act quickly! Rates and availability are subject to change.</span>
            </div>

            <div class="booking-content-layout">

                <!-- =====================================================
                LEFT COLUMN — 3-step booking form
            ====================================================== -->
                <div class="booking-steps-column">

                    <!-- ─── STEP 1: Guest details ─── -->
                    <div class="booking-step" id="gbk-step-1">
                        <div class="step-header">
                            <span class="step-number active" id="gbk-num-1"><span>1</span></span>
                            <h2 class="step-title active">Start booking</h2>
                            <button class="step-edit-btn" id="gbk-edit-1" style="display:none;">Edit</button>
                        </div>
                        <div class="step-body" id="gbk-body-1">
                            <form id="gbk-guest-form" novalidate>
                                <div class="gbk-form-grid">
                                    <div class="gbk-field">
                                        <label for="gbk_first_name">First name</label>
                                        <input type="text" id="gbk_first_name" name="firstName" autocomplete="given-name" required>
                                    </div>
                                    <div class="gbk-field">
                                        <label for="gbk_last_name">Last name</label>
                                        <input type="text" id="gbk_last_name" name="lastName" autocomplete="family-name" required>
                                    </div>
                                    <div class="gbk-field">
                                        <label for="gbk_phone">Phone</label>
                                        <input type="tel" id="gbk_phone" name="phone" minlength="7" maxlength="15" autocomplete="tel">
                                    </div>
                                    <div class="gbk-field">
                                        <label for="gbk_email">Email</label>
                                        <input type="email" id="gbk_email" name="email" autocomplete="email" required>
                                    </div>
                                </div>
                                <div class="gbk-terms">
                                    <input type="checkbox" id="gbk_terms_agree" required>
                                    <label for="gbk_terms_agree">
                                        By clicking Continue you agree to our
                                        <a href="<?php echo esc_url($terms_url ?: '#'); ?>" target="_blank">Terms &amp; Conditions</a>
                                        and
                                        <a href="<?php echo esc_url($privacy_url ?: '#'); ?>" target="_blank">Privacy Policy</a>
                                    </label>
                                </div>
                                <div class="gbk-form-error" id="gbk-error-1" style="display:none;"></div>
                                <button type="submit" class="gbk-btn gbk-btn-primary" id="gbk-step1-btn">Continue</button>
                            </form>
                        </div>
                    </div>

                    <!-- ─── STEP 2: Rules & Policies ─── -->
                    <div class="booking-step locked" id="gbk-step-2">
                        <div class="step-header">
                            <span class="step-number" id="gbk-num-2"><span>2</span></span>
                            <h2 class="step-title">Rules &amp; Policies</h2>
                            <button class="step-edit-btn" id="gbk-edit-2" style="display:none;">Edit</button>
                        </div>
                        <div class="step-body" id="gbk-body-2" style="display:none;">

                            <div class="policy-section">
                                <h3>Cancellation Policy</h3>
                                <p><?php echo esc_html(gbk_cancellation_text($cancel_code)); ?></p>
                            </div>

                            <div class="policy-section">
                                <h3>Terms &amp; Rules</h3>
                                <ul class="house-rules">
                                    <li><span class="rule-label">Smoking allowed:</span> <strong><?php echo $smoking_allowed ? 'Yes' : 'No'; ?></strong></li>
                                    <li><span class="rule-label">Pets allowed:</span> <strong><?php echo $pets_allowed ? 'Yes' : 'No'; ?></strong></li>
                                    <li><span class="rule-label">Party allowed:</span> <strong><?php echo $parties_allowed ? 'Yes' : 'No'; ?></strong></li>
                                    <li><span class="rule-label">Children allowed:</span> <strong><?php echo $children_ok ? 'Yes' : 'No'; ?></strong></li>
                                </ul>
                            </div>

                            <div class="gbk-terms">
                                <input type="checkbox" id="gbk_policies_agree">
                                <label for="gbk_policies_agree">I have read and agree with all rental policies and terms.</label>
                            </div>
                            <div class="gbk-form-error" id="gbk-error-2" style="display:none;"></div>
                            <button type="button" class="gbk-btn gbk-btn-primary" id="gbk-step2-btn">Continue</button>
                        </div>
                    </div>

                    <!-- ─── STEP 3: Payment ─── -->
                    <div class="booking-step locked" id="gbk-step-3">
                        <div class="step-header">
                            <span class="step-number" id="gbk-num-3"><span>3</span></span>
                            <h2 class="step-title">Payment</h2>
                        </div>
                        <div class="step-body" id="gbk-body-3" style="display:none;">
                            <?php if ($payment_provider_id) : ?>
                                <p class="gbk-stripe-info">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                                        <path d="M12 1L3 5v6c0 5.25 3.75 10.15 9 11.25C17.25 21.15 21 16.25 21 11V5L12 1Z" stroke="#767676" stroke-width="1.5" fill="none" />
                                    </svg>
                                    Your payment details are encrypted and processed securely by GuestyPay.
                                </p>
                                <div id="gbk-payment-container"></div>
                                <div class="gbk-form-error" id="gbk-error-3" style="display:none;"></div>
                                <button type="button" class="gbk-btn gbk-btn-pay" id="gbk-pay-btn" disabled>Process Payment</button>
                            <?php else : ?>
                                <div class="gbk-form-error" style="display:block;">
                                    Payment gateway is not configured. Please contact the host to complete your booking.
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- ─── SUCCESS ─── -->
                    <div id="gbk-booking-success" style="display:none;">
                        <div class="gbk-success-icon">✓</div>
                        <h2>Booking Confirmed!</h2>
                        <p>Your reservation has been created. A confirmation email will be sent to you shortly.</p>
                        <div class="gbk-confirmation-details" id="gbk-confirm-details"></div>
                        <a href="<?php echo esc_url(get_post_type_archive_link('properties')); ?>" class="gbk-browse-btn">
                            Browse More Properties
                        </a>
                    </div>

                </div><!-- /booking-steps-column -->

                <!-- =====================================================
                RIGHT COLUMN — Booking summary
            ====================================================== -->
                <aside class="booking-summary-column">
                    <div class="gbk-summary-card">

                        <h3 class="gbk-property-name"><?php echo esc_html($property_title); ?></h3>
                        <?php if ($property_type) : ?>
                            <p class="gbk-property-type"><?php echo esc_html($property_type); ?></p>
                        <?php endif; ?>

                        <?php if ($property_image) : ?>
                            <div class="gbk-property-image">
                                <img src="<?php echo esc_url($property_image); ?>" alt="<?php echo esc_attr($property_title); ?>">
                            </div>
                        <?php endif; ?>

                        <!-- Dates & Guests -->
                        <div class="gbk-booking-meta">
                            <table style="width:100%; border-collapse:collapse; border:1px solid #ddd;">
                                <tr>
                                    <td style="border:1px solid #ddd; padding:8px;">
                                        <div class="gbk-meta-label-wrap">
                                            <span class="gbk-meta-icon">
                                                <svg fill="#000000" height="20px" width="20px" version="1.1" id="Layer_1" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"
                                                    viewBox="0 0 512 512" stroke-width="2px" xml:space="preserve">
                                                    <g>
                                                        <path d="M256,0C114.88,0,0,114.88,0,256s114.88,256,256,256s256-114.88,256-256S397.12,0,256,0z M256,490.667
				c-129.387,0-234.667-105.28-234.667-234.667S126.613,21.333,256,21.333S490.667,126.613,490.667,256S385.387,490.667,256,490.667
				z" />
                                                        <path d="M365.76,280.533l-99.093,99.093V107.093c0-5.333-3.84-10.133-9.067-10.88c-6.613-0.96-12.267,4.16-12.267,10.56v272.96
				l-99.093-99.2c-4.267-4.053-10.987-3.947-15.04,0.213c-3.947,4.16-3.947,10.667,0,14.827l117.333,117.333
				c4.16,4.16,10.88,4.16,15.04,0l117.333-117.333c4.053-4.267,3.947-10.987-0.213-15.04
				C376.533,276.587,369.92,276.587,365.76,280.533z" />
                                                    </g>
                                                </svg>
                                            </span>
                                            <div class="gbk-meta-label">Arrive</div>
                                        </div>
                                        <div class="gbk-meta-value"><?php echo esc_html($check_in); ?></div>
                                    </td>
                                    <td style="border:1px solid #ddd; padding:8px;">
                                        <div class="gbk-meta-label-wrap">
                                            <span class="gbk-meta-icon">
                                                <svg fill="#000000" height="20px" width="20px" version="1.1" id="Layer_1" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"
                                                    viewBox="0 0 512 512" style="rotate: 180deg;" stroke-width="2px" xml:space="preserve">
                                                    <g>
                                                        <path d="M256,0C114.88,0,0,114.88,0,256s114.88,256,256,256s256-114.88,256-256S397.12,0,256,0z M256,490.667
				c-129.387,0-234.667-105.28-234.667-234.667S126.613,21.333,256,21.333S490.667,126.613,490.667,256S385.387,490.667,256,490.667
				z" />
                                                        <path d="M365.76,280.533l-99.093,99.093V107.093c0-5.333-3.84-10.133-9.067-10.88c-6.613-0.96-12.267,4.16-12.267,10.56v272.96
				l-99.093-99.2c-4.267-4.053-10.987-3.947-15.04,0.213c-3.947,4.16-3.947,10.667,0,14.827l117.333,117.333
				c4.16,4.16,10.88,4.16,15.04,0l117.333-117.333c4.053-4.267,3.947-10.987-0.213-15.04
				C376.533,276.587,369.92,276.587,365.76,280.533z" />
                                                    </g>
                                                </svg>
                                            </span>
                                            <div class="gbk-meta-label">Depart</div>
                                        </div>
                                        <div class="gbk-meta-value"><?php echo esc_html($check_out); ?></div>
                                    </td>
                                </tr>
                                <tr>
                                    <td colspan="2" style="border:1px solid #ddd; padding:8px;">
                                        <div class="gbk-meta-label-wrap">
                                            <span class="gbk-meta-icon">
                                                <svg width="20px" height="20px" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" stroke-width="2px">
                                                    <path d="M10.1992 12C12.9606 12 15.1992 9.76142 15.1992 7C15.1992 4.23858 12.9606 2 10.1992 2C7.43779 2 5.19922 4.23858 5.19922 7C5.19922 9.76142 7.43779 12 10.1992 12Z" stroke="#000000" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                                                    <path d="M1 22C1.57038 20.0332 2.74795 18.2971 4.36438 17.0399C5.98081 15.7827 7.95335 15.0687 10 15C14.12 15 17.63 17.91 19 22" stroke="#000000" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                                                    <path d="M17.8205 4.44006C18.5822 4.83059 19.1986 5.45518 19.579 6.22205C19.9594 6.98891 20.0838 7.85753 19.9338 8.70032C19.7838 9.5431 19.3674 10.3155 18.7458 10.9041C18.1243 11.4926 17.3302 11.8662 16.4805 11.97" stroke="#000000" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                                                    <path d="M17.3203 14.5701C18.6543 14.91 19.8779 15.5883 20.8729 16.5396C21.868 17.4908 22.6007 18.6827 23.0003 20" stroke="#000000" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                                                </svg>
                                            </span>
                                            <div class="gbk-meta-label">Guests</div>
                                        </div>
                                        <div class="gbk-meta-value">
                                            <?php echo esc_html($guest_count); ?> <?php echo $guest_count === 1 ? 'Guest' : 'Guests'; ?>
                                        </div>
                                    </td>
                                </tr>
                            </table>
                        </div>

                        <!-- Coupon -->
                        <div class="gbk-coupon-section">
                            <h4>Apply Coupon</h4>
                            <div class="gbk-coupon-form">
                                <input type="text" id="gbk-coupon-input" placeholder="Enter coupon code" autocomplete="off">
                                <button type="button" id="gbk-coupon-btn" class="gbk-btn gbk-btn-coupon">Apply</button>
                            </div>
                            <div id="gbk-coupon-msg" style="display:none;"></div>
                        </div>

                        <!-- Price breakdown -->
                        <div class="gbk-price-section">
                            <?php if ($quote_valid) : ?>
                                <div class="gbk-price-total-row">
                                    <span>Total</span>
                                    <strong id="gbk-total-price"><?php echo esc_html($formatted_total); ?></strong>
                                </div>
                                <div class="gbk-price-subtext">
                                    <span>Includes taxes and fees</span>
                                    <button type="button" id="gbk-view-details-btn" class="gbk-view-details-link">View details</button>
                                </div>
                                <div class="gbk-price-details-box" id="gbk-price-details">
                                    <?php foreach ($invoice_items as $item) : ?>
                                        <div class="gbk-fee-row">
                                            <span><?php echo esc_html($item['title'] ?? ''); ?></span>
                                            <span><?php echo esc_html($currency_symbol . gbk_format_money($item['amount'])); ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                    <div class="gbk-fee-row-total">
                                        <span>Total</span>
                                        <span><?php echo esc_html($formatted_total); ?></span>
                                    </div>
                                </div>
                            <?php else : ?>
                                <p class="gbk-no-price">Price information is unavailable. Please go back and try again.</p>
                            <?php endif; ?>
                        </div>

                        <div class="gbk-secure-badge">
                            <svg width="12" height="13" viewBox="0 0 12 13" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M6 1L1 3.5V7c0 2.76 2.14 5.34 5 6 2.86-.66 5-3.24 5-6V3.5L6 1Z" stroke="#767676" stroke-width="1" fill="none" />
                            </svg>
                            Secure booking
                        </div>

                    </div><!-- /gbk-summary-card -->
                </aside>

            </div><!-- /booking-content-layout -->

        <?php endif; // end $page_error check 
        ?>

    </div><!-- /booking-page-inner -->
</div><!-- /guesty-instant-booking-page -->

<!-- GuestyPay SDK — must be loaded directly from pay.guesty.com (PCI compliance) -->
<?php if (!$page_error && $payment_provider_id) : ?>
    <script src="https://pay.guesty.com/tokenization/v1/init.js"></script>
<?php endif; ?>

<?php if (!$page_error) : ?>
    <script>
        (function() {
            'use strict';

            /* ── Booking data from PHP ── */
            const GBK = {
                ajaxUrl: <?php echo json_encode(admin_url('admin-ajax.php')); ?>,
                nonce: <?php echo json_encode($nonce); ?>,
                listingId: <?php echo json_encode($listing_id); ?>,
                checkIn: <?php echo json_encode($check_in); ?>,
                checkOut: <?php echo json_encode($check_out); ?>,
                guests: <?php echo json_encode($guest_count); ?>,
                currency: <?php echo json_encode($currency); ?>,
                totalPrice: <?php echo json_encode((float) $total_price); ?>,
                invoiceItems: <?php echo json_encode(array_values($invoice_items)); ?>,
                quoteId: <?php echo json_encode($quote_id); ?>,
                paymentProviderId: <?php echo json_encode($payment_provider_id); ?>,
                propertyTitle: <?php echo json_encode($property_title); ?>,
                quoteValid: <?php echo $quote_valid ? 'true' : 'false'; ?>,
            };

            let guestId = null;
            let currentStep = 1;
            let guestyPayLoaded = false;

            /* ── Helpers ── */
            function formatMoney(amount, currency) {
                return new Intl.NumberFormat('en', {
                    style: 'currency',
                    currency: currency || GBK.currency || 'GBP',
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2,
                }).format(parseFloat(amount) || 0);
            }

            function showSpinner(btn) {
                btn.disabled = true;
                btn.dataset.originalText = btn.textContent;
                btn.innerHTML = '<span class="gbk-spinner"><div></div><div></div><div></div></span>';
            }

            function hideSpinner(btn, text) {
                btn.disabled = false;
                btn.textContent = text || btn.dataset.originalText || '';
            }

            function showError(id, msg) {
                const el = document.getElementById(id);
                if (el) {
                    el.textContent = msg;
                    el.style.display = 'block';
                }
            }

            function hideError(id) {
                const el = document.getElementById(id);
                if (el) el.style.display = 'none';
            }

            /* ── Step navigation ── */
            function activateStep(step) {
                const prevEl = document.getElementById('gbk-step-' + currentStep);
                if (prevEl && step !== currentStep) {
                    document.getElementById('gbk-body-' + currentStep).style.display = 'none';
                    prevEl.classList.remove('active');
                    prevEl.classList.add('completed');

                    const num = document.getElementById('gbk-num-' + currentStep);
                    if (num) {
                        num.textContent = '✓';
                        num.classList.remove('active');
                        num.classList.add('done');
                    }

                    const title = prevEl.querySelector('.step-title');
                    if (title) title.classList.remove('active');

                    const editBtn = document.getElementById('gbk-edit-' + currentStep);
                    if (editBtn && currentStep < step) editBtn.style.display = 'inline-block';
                }

                currentStep = step;

                const newEl = document.getElementById('gbk-step-' + step);
                if (!newEl) return;

                newEl.classList.remove('locked', 'completed');
                newEl.classList.add('active');

                const body = document.getElementById('gbk-body-' + step);
                if (body) body.style.display = 'block';

                const num = document.getElementById('gbk-num-' + step);
                if (num) {
                    num.classList.add('active');
                    num.classList.remove('done');
                    if (num.textContent === '✓') num.textContent = step;
                }

                const title = newEl.querySelector('.step-title');
                if (title) title.classList.add('active');

                newEl.scrollIntoView({
                    behavior: 'smooth',
                    block: 'start'
                });
            }

            /* ── STEP 1: Guest details form ── */
            document.getElementById('gbk-guest-form').addEventListener('submit', async function(e) {
                e.preventDefault();
                hideError('gbk-error-1');

                const firstName = document.getElementById('gbk_first_name').value.trim();
                const lastName = document.getElementById('gbk_last_name').value.trim();

                const emailInput = document.getElementById('gbk_email');
                const phoneInput = document.getElementById('gbk_phone');
                const email = (emailInput?.value || '').trim();
                const phone = (phoneInput?.value || '').trim();
                const termsOk = document.getElementById('gbk_terms_agree').checked;

                if (!firstName || !lastName) {
                    showError('gbk-error-1', 'Please enter your first and last name.');
                    return;
                }
                if (!email) {
                    showError('gbk-error-1', 'Please enter your email address.');
                    return;
                }
                // Form has `novalidate`, so we validate manually but reuse browser rules where possible.
                if (emailInput && typeof emailInput.checkValidity === 'function' && !emailInput.checkValidity()) {
                    showError('gbk-error-1', 'Please enter a valid email address.');
                    return;
                }
                // Fallback sanity check (covers edge cases and older browsers)
                if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
                    showError('gbk-error-1', 'Please enter a valid email address.');
                    return;
                }

                // Phone is optional, but if provided it must look valid.
                if (phone) {
                    if (/[^0-9+\-\s().]/.test(phone)) {
                        showError('gbk-error-1', 'Please enter a valid phone number.');
                        return;
                    }
                    const digits = phone.replace(/[^\d]/g, '');
                    if (digits.length < 7 || digits.length > 15) {
                        showError('gbk-error-1', 'Please enter a valid phone number.');
                        return;
                    }
                }
                if (!termsOk) {
                    showError('gbk-error-1', 'Please agree to the Terms & Conditions and Privacy Policy.');
                    return;
                }

                const btn = document.getElementById('gbk-step1-btn');
                showSpinner(btn);

                try {
                    const fd = new FormData();
                    fd.append('action', 'guesty_create_booking_guest');
                    fd.append('nonce', GBK.nonce);
                    fd.append('firstName', firstName);
                    fd.append('lastName', lastName);
                    fd.append('email', email);
                    fd.append('phone', phone);

                    const res = await fetch(GBK.ajaxUrl, {
                        method: 'POST',
                        body: fd
                    });
                    const data = await res.json();

                    if (data.success) {
                        guestId = data.data.guestId;
                        activateStep(2);
                    } else {
                        showError('gbk-error-1', data.data || 'Failed to process your details. Please try again.');
                    }
                } catch (err) {
                    showError('gbk-error-1', 'A network error occurred. Please check your connection and try again.');
                } finally {
                    hideSpinner(btn, 'Continue');
                }
            });

            /* Edit Step 1 */
            const edit1 = document.getElementById('gbk-edit-1');
            if (edit1) {
                edit1.addEventListener('click', function() {
                    edit1.style.display = 'none';
                    activateStep(1);
                    ['gbk-step-2', 'gbk-step-3'].forEach(function(id) {
                        const el = document.getElementById(id);
                        if (!el) return;
                        el.classList.remove('completed', 'active');
                        el.classList.add('locked');
                        const body = el.querySelector('.step-body');
                        if (body) body.style.display = 'none';
                        const num = el.querySelector('.step-number');
                        if (num) {
                            num.textContent = id.slice(-1);
                            num.classList.remove('done', 'active');
                        }
                    });
                });
            }

            /* Edit Step 2 (Rules & Policies) */
            const edit2 = document.getElementById('gbk-edit-2');
            if (edit2) {
                edit2.addEventListener('click', function() {
                    edit2.style.display = 'none';
                    activateStep(2);
                    ['gbk-step-3'].forEach(function(id) {
                        const el = document.getElementById(id);
                        if (!el) return;
                        el.classList.remove('completed', 'active');
                        el.classList.add('locked');
                        const body = el.querySelector('.step-body');
                        if (body) body.style.display = 'none';
                        const num = el.querySelector('.step-number');
                        if (num) {
                            num.textContent = id.slice(-1);
                            num.classList.remove('done', 'active');
                        }
                    });
                });
            }
            document.getElementById('gbk-step2-btn').addEventListener('click', function() {
                hideError('gbk-error-2');
                if (!document.getElementById('gbk_policies_agree').checked) {
                    showError('gbk-error-2', 'You must read and agree to all rental policies and terms to continue.');
                    return;
                }
                activateStep(3);
                initGuestyPayForm();
            });

            /* ── STEP 3: GuestyPay payment form ── */
            async function initGuestyPayForm() {
                if (!GBK.paymentProviderId || guestyPayLoaded) return;

                const btn = document.getElementById('gbk-pay-btn');

                try {
                    await window.guestyTokenization.render({
                        containerId: 'gbk-payment-container',
                        providerId: GBK.paymentProviderId,
                        amount: GBK.totalPrice,
                        currency: GBK.currency,
                        onStatusChange: function(isValid) {
                            if (btn) btn.disabled = !isValid;
                        },
                    });
                    guestyPayLoaded = true;
                } catch (err) {
                    showError('gbk-error-3', 'Payment form failed to load. Please refresh the page and try again.');
                }
            }

            const payBtn = document.getElementById('gbk-pay-btn');
            if (payBtn) {
                payBtn.addEventListener('click', async function() {
                    hideError('gbk-error-3');

                    if (!guestyPayLoaded || !window.guestyTokenization) {
                        showError('gbk-error-3', 'Payment form is not ready. Please refresh and try again.');
                        return;
                    }
                    if (!guestId) {
                        showError('gbk-error-3', 'Your guest session expired. Please go back to Step 1.');
                        return;
                    }

                    showSpinner(payBtn);

                    try {
                        /* 1. Tokenise the card via GuestyPay SDK */
                        let paymentMethod;
                        try {
                            paymentMethod = await window.guestyTokenization.submit();
                        } catch (tokenErr) {
                            showError('gbk-error-3', tokenErr.message || 'Payment validation failed. Please check your card details and try again.');
                            hideSpinner(payBtn, 'Process Payment');
                            return;
                        }

                        /* 2. Create reservation + attach payment via PHP/AJAX */
                        const fd = new FormData();
                        fd.append('action', 'guesty_create_booking_reservation');
                        fd.append('nonce', GBK.nonce);
                        fd.append('listingId', GBK.listingId);
                        fd.append('checkIn', GBK.checkIn);
                        fd.append('checkOut', GBK.checkOut);
                        fd.append('guestsCount', GBK.guests);
                        fd.append('guestId', guestId);
                        fd.append('guestyToken', paymentMethod._id);
                        fd.append('invoiceItems', JSON.stringify(GBK.invoiceItems));
                        fd.append('quoteId', GBK.quoteId);
                        fd.append('paymentProviderId', GBK.paymentProviderId);

                        const res = await fetch(GBK.ajaxUrl, {
                            method: 'POST',
                            body: fd
                        });
                        const data = await res.json();

                        if (data.success) {
                            /* Hide steps, show success panel */
                            ['gbk-step-1', 'gbk-step-2', 'gbk-step-3'].forEach(function(id) {
                                const el = document.getElementById(id);
                                if (el) el.style.display = 'none';
                            });

                            const successEl = document.getElementById('gbk-booking-success');
                            successEl.style.display = 'block';

                            document.getElementById('gbk-confirm-details').innerHTML =
                                (data.data.confirmationCode ?
                                    `<p><strong>Confirmation Code:</strong> ${data.data.confirmationCode}</p>` :
                                    '') +
                                (data.data.reservationId ?
                                    `<p><strong>Reservation ID:</strong> ${data.data.reservationId}</p>` :
                                    '') +
                                `<p><strong>Property:</strong> ${GBK.propertyTitle}</p>` +
                                `<p><strong>Dates:</strong> ${GBK.checkIn} &nbsp;&rarr;&nbsp; ${GBK.checkOut}</p>` +
                                `<p><strong>Guests:</strong> ${GBK.guests}</p>` +
                                (data.data.paymentStatus === 'success' ?
                                    `<p><strong>Payment:</strong> <span style="color:#059669;">Processed ✓</span></p>` :
                                    `<p><strong>Payment:</strong> Pending — you will be contacted to confirm.</p>`);

                            successEl.scrollIntoView({
                                behavior: 'smooth'
                            });
                        } else {
                            showError('gbk-error-3', data.data || 'Booking failed. Please try again or contact us directly.');
                            hideSpinner(payBtn, 'Process Payment');
                        }
                    } catch (err) {
                        showError('gbk-error-3', 'An unexpected error occurred. Please try again.');
                        hideSpinner(payBtn, 'Process Payment');
                    }
                });
            }

            /* ── Coupon ── */
            document.getElementById('gbk-coupon-btn').addEventListener('click', async function() {
                const coupon = document.getElementById('gbk-coupon-input').value.trim();
                const msgEl = document.getElementById('gbk-coupon-msg');
                msgEl.className = 'gbk-coupon-message';
                msgEl.style.display = 'none';

                if (!coupon) {
                    msgEl.textContent = 'Please enter a coupon code.';
                    msgEl.className += ' error';
                    msgEl.style.display = 'block';
                    return;
                }

                const btn = this;
                btn.disabled = true;
                btn.textContent = '…';

                try {
                    const fd = new FormData();
                    fd.append('action', 'guesty_apply_booking_coupon');
                    fd.append('nonce', GBK.nonce);
                    fd.append('listing_id', GBK.listingId);
                    fd.append('checkIn', GBK.checkIn);
                    fd.append('checkOut', GBK.checkOut);
                    fd.append('guests', GBK.guests);
                    fd.append('coupon', coupon);

                    const res = await fetch(GBK.ajaxUrl, {
                        method: 'POST',
                        body: fd
                    });
                    const data = await res.json();

                    if (data.success) {
                        const newMoney = data.data.rates.ratePlans[0].money.money;
                        const newTotal = newMoney.subTotalPrice;
                        const newItems = newMoney.invoiceItems || [];

                        GBK.totalPrice = newTotal;
                        GBK.invoiceItems = newItems;
                        GBK.quoteId = data.data._id || GBK.quoteId;

                        document.getElementById('gbk-total-price').textContent = formatMoney(newTotal, GBK.currency);

                        const box = document.getElementById('gbk-price-details');
                        if (box) {
                            box.innerHTML =
                                newItems.map(item =>
                                    `<div class="gbk-fee-row"><span>${item.title}</span><span>${formatMoney(item.amount, GBK.currency)}</span></div>`
                                ).join('') +
                                `<div class="gbk-fee-row-total"><span>Total</span><span>${formatMoney(newTotal, GBK.currency)}</span></div>`;
                        }

                        msgEl.textContent = `Coupon applied! New total: ${formatMoney(newTotal, GBK.currency)}`;
                        msgEl.className += ' success';
                    } else {
                        msgEl.textContent = data.data || 'Invalid coupon code.';
                        msgEl.className += ' error';
                    }
                } catch (err) {
                    msgEl.textContent = 'Failed to apply coupon. Please try again.';
                    msgEl.className += ' error';
                } finally {
                    msgEl.style.display = 'block';
                    btn.disabled = false;
                    btn.textContent = 'Apply';
                }
            });

            /* ── View / hide price details ── */
            const viewBtn = document.getElementById('gbk-view-details-btn');
            const detailsBox = document.getElementById('gbk-price-details');
            if (viewBtn && detailsBox) {
                viewBtn.addEventListener('click', function() {
                    const open = detailsBox.style.display === 'flex';
                    detailsBox.style.display = open ? 'none' : 'flex';
                    viewBtn.textContent = open ? 'View details' : 'Hide details';
                });
            }

        })();
    </script>
<?php endif; ?>

<?php get_footer(); ?>