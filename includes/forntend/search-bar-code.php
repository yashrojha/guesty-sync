<?php
if (!defined('ABSPATH')) exit;

/**
 * Register the Shortcode [guesty_search_bar]
 */
function guesty_render_search_bar() {
    ob_start(); ?>
	<div class="guesty-search-bar-box" >
		<form class="guesty-search-bar" method="get" action="<?php echo esc_url(get_post_type_archive_link('properties')); ?>">
			<div class="guesty-field-wrap">
				<div class="guesty-field option-where">
					<label>Where</label>
					<select name="city">
						<option value="">Select your destination</option>
						<?php 
						$cities = get_guesty_property_cities();
						$selected_city = isset($_GET['city']) ? sanitize_text_field($_GET['city']) : '';
						if ( !empty($cities) ) :
							foreach ( $cities as $city ) : ?>
								<option value="<?php echo esc_attr($city); ?>" <?php selected($selected_city, $city); ?>>
									<?php echo esc_html($city); ?>
								</option>
							<?php endforeach; 
						endif; ?>
					</select>
				</div>
				<div class="guesty-field option-date">
					<label>Check In</label>
					<?php
						$selected_checkIn = isset($_GET['checkIn']) ? sanitize_text_field($_GET['checkIn']) : '';
						if ($selected_checkIn) {
							$display_checkIn = wp_date('d M, Y',strtotime($selected_checkIn));
						} else {
						    $display_checkIn = "";
						}
					?>
					<input type="text" id="checkin_date_display" placeholder="Select Date" value="<?php echo esc_attr($display_checkIn); ?>" readonly>
					<input type="hidden" id="checkin_date" name="checkIn" value="<?php echo esc_attr($selected_checkIn); ?>"  readonly>
				</div>
				<div class="guesty-field option-date">
					<label>Check Out</label>
					<?php
						$selected_checkOut = isset($_GET['checkOut']) ? sanitize_text_field($_GET['checkOut']) : '';
						if ($selected_checkOut) {
							$display_checkOut = wp_date('d M, Y',strtotime($selected_checkOut));
						} else {
						    $display_checkOut = "";
						}
					?>
					<input type="text" id="checkout_date_display" placeholder="Select Date" value="<?php echo esc_attr($display_checkOut); ?>" readonly>
					<input type="hidden" id="checkout_date" name="checkOut" value="<?php echo esc_attr($selected_checkOut); ?>" readonly>
				</div>
				<div class="guesty-field option-guests">
					<label>Guests</label>
					<select name="minOccupancy">
						<?php 
						$max_allowed = get_max_property_occupancy();
						$selected_minOccupancy = isset($_GET['minOccupancy']) ? sanitize_text_field($_GET['minOccupancy']) : '';
						for ( $i = 1; $i <= $max_allowed; $i++ ) : ?>
							<option value="<?php echo $i; ?>" <?php selected($selected_minOccupancy, $i); ?>>
								<?php echo $i; ?> <?php echo ($i > 1) ? 'Adults' : 'Adult'; ?>
							</option>
						<?php endfor; ?>
					</select>
				</div>
			</div>
			<div class="guesty-action">
				<button type="submit">Search</button>
			</div>
		</form>
	</div>
    <?php
    return ob_get_clean();
}
add_shortcode('guesty_search_bar', 'guesty_render_search_bar');
