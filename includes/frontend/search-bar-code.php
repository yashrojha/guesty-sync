<?php
if (!defined('ABSPATH')) exit;

/**
 * Register the Shortcode [guesty_search_bar]
 */
function guesty_render_search_bar()
{
	ob_start(); ?>
	<div class="guesty-search-bar-box">
		<button
			type="button"
			class="guesty-search-bar-mobile-trigger"
			aria-haspopup="dialog"
			aria-expanded="false">
			<span class="guesty-search-bar-mobile-label">Search</span>
			<span class="guesty-search-bar-mobile-placeholder">Start your search</span>
		</button>

		<div class="guesty-search-modal-header" aria-hidden="true">
			<h2 class="guesty-search-modal-title">Find your next stay</h2>
			<button type="button" class="guesty-search-modal-close" aria-label="Close search">
				<svg xmlns="http://www.w3.org/2000/svg" width="30" height="30" viewBox="0 0 30 30" fill="none">
					<path d="M10.2 21L9 19.8L13.8 15L9 10.2L10.2 9L15 13.8L19.8 9L21 10.2L16.2 15L21 19.8L19.8 21L15 16.2L10.2 21Z" fill="black" />
				</svg>
			</button>
		</div>

		<form id="guesty-search-form" class="guesty-search-bar" method="get" action="<?php echo esc_url(get_post_type_archive_link('properties')); ?>">
			<div class="guesty-field-wrap">
			<div class="guesty-field option-where">
				<label>Where</label>
				<?php
				$cities = get_guesty_property_cities();
				$raw_city = isset($_GET['city']) ? $_GET['city'] : [];
				if (!is_array($raw_city)) {
					$raw_city = $raw_city !== '' ? [$raw_city] : [];
				}
				$selected_cities = array_map('sanitize_text_field', $raw_city);
				$selected_cities = array_filter($selected_cities);
				$multiselect_display = !empty($selected_cities) ? implode(', ', $selected_cities) : '';
				?>
				<div class="guesty-multiselect"
					id="city-multiselect"
					data-placeholder="Select your destination"
					role="combobox"
					aria-haspopup="listbox"
					aria-expanded="false">
					<button type="button" class="guesty-multiselect-trigger" aria-label="Select regions">
						<span class="guesty-multiselect-value<?php echo !empty($selected_cities) ? ' has-value' : ''; ?>">
							<?php echo !empty($multiselect_display) ? esc_html($multiselect_display) : 'Select your destination'; ?>
						</span>
					</button>
					<div class="guesty-multiselect-dropdown" hidden>
						<div class="guesty-multiselect-search-wrap">
							<input type="text"
								class="guesty-multiselect-search"
								placeholder="Search regions…"
								autocomplete="off"
								aria-label="Search regions">
						</div>
						<ul class="guesty-multiselect-list" role="listbox" aria-multiselectable="true">
							<?php if (!empty($cities)) : foreach ($cities as $city) : ?>
							<li class="guesty-multiselect-option" role="option" aria-selected="<?php echo in_array($city, $selected_cities, true) ? 'true' : 'false'; ?>">
								<input
									type="checkbox"
									class="guesty-multiselect-cb"
									name="city[]"
									value="<?php echo esc_attr($city); ?>"
									tabindex="-1"
									aria-hidden="true"
									<?php checked(in_array($city, $selected_cities)); ?>>
								<button type="button" class="guesty-multiselect-row">
									<span class="guesty-multiselect-check-icon" aria-hidden="true"></span>
									<span class="guesty-multiselect-option-label"><?php echo esc_html($city); ?></span>
								</button>
							</li>
							<?php endforeach; endif; ?>
						</ul>
						<p class="guesty-multiselect-no-results" hidden role="status" aria-live="polite"><?php esc_html_e( 'No regions match your search.', 'guesty-sync' ); ?></p>
					</div>
				</div>
			</div>
				<div class="guesty-field option-date">
					<label for="checkin_date">Check In</label>
					<?php
					$selected_checkIn = isset($_GET['checkIn']) ? sanitize_text_field($_GET['checkIn']) : '';
					if ($selected_checkIn) {
						$display_checkIn = wp_date('d M, Y', strtotime($selected_checkIn));
					} else {
						$display_checkIn = "";
					}
					?>
					<input type="text" id="checkin_date_display" placeholder="Enter date" value="<?php echo esc_attr($display_checkIn); ?>" readonly>
					<input type="hidden" id="checkin_date" name="checkIn" value="<?php echo esc_attr($selected_checkIn); ?>" readonly>
				</div>
				<div class="guesty-field option-date">
					<label for="checkout_date">Check Out</label>
					<?php
					$selected_checkOut = isset($_GET['checkOut']) ? sanitize_text_field($_GET['checkOut']) : '';
					if ($selected_checkOut) {
						$display_checkOut = wp_date('d M, Y', strtotime($selected_checkOut));
					} else {
						$display_checkOut = "";
					}
					?>
					<input type="text" id="checkout_date_display" placeholder="Enter date" value="<?php echo esc_attr($display_checkOut); ?>" readonly>
					<input type="hidden" id="checkout_date" name="checkOut" value="<?php echo esc_attr($selected_checkOut); ?>" readonly>
				</div>
				<div class="guesty-field option-guests">
					<label for="minOccupancy">Guests</label>
					<select name="minOccupancy" id="minOccupancy">
						<?php
						$max_allowed = get_max_property_occupancy();
						$selected_minOccupancy = isset($_GET['minOccupancy']) ? sanitize_text_field($_GET['minOccupancy']) : '';
						for ($i = 1; $i <= $max_allowed; $i++) : ?>
							<option value="<?php echo $i; ?>" <?php selected($selected_minOccupancy, $i); ?>>
								<?php echo $i; ?> <?php echo ($i > 1) ? 'Adults' : 'Adult'; ?>
							</option>
						<?php endfor; ?>
					</select>
				</div>
			</div>
			<div class="guesty-search-modal-extra" aria-hidden="true">
				<h3 class="guesty-search-modal-subtitle">Trending regions</h3>
				<?php echo do_shortcode('[guesty_trending_regions]'); ?>
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