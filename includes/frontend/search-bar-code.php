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
				<label for="guesty-where-input">Where</label>
				<?php
				$cities = get_guesty_property_cities();
				$properties = function_exists('get_guesty_search_properties') ? get_guesty_search_properties() : array();
				$raw_city = isset($_GET['city']) ? wp_unslash($_GET['city']) : array();
				if (!is_array($raw_city)) {
					$raw_city = $raw_city !== '' ? array($raw_city) : array();
				}
				$selected_cities = function_exists('guesty_canonical_region_names')
					? guesty_canonical_region_names($raw_city)
					: array_values(array_filter(array_map('sanitize_text_field', $raw_city)));
				$selected_property_id = isset($_GET['property_id']) ? absint(wp_unslash($_GET['property_id'])) : 0;
				$selected_property = null;
				if ($selected_property_id) {
					foreach ($properties as $property) {
						if ((int) $property['id'] === $selected_property_id) {
							$selected_property = $property;
							break;
						}
					}
				}
				$selected_query = isset($_GET['q']) ? sanitize_text_field(wp_unslash($_GET['q'])) : '';
				$summary_labels = $selected_cities;
				if ($selected_property) {
					$summary_labels[] = $selected_property['title'];
				}
				$where_value = $summary_labels ? implode(', ', $summary_labels) : $selected_query;
				?>
				<div class="guesty-where" id="guesty-where">
					<div class="guesty-where-control">
						<input type="text"
							id="guesty-where-input"
							class="guesty-where-input"
							placeholder="Somewhere to stay"
							value="<?php echo esc_attr($where_value); ?>"
							autocomplete="off"
							autocapitalize="off"
							spellcheck="false"
							role="combobox"
							aria-autocomplete="list"
							aria-expanded="false"
							aria-controls="guesty-where-list"
							aria-label="Search by region or property">
						<button type="button" class="guesty-where-clear" aria-label="Clear destination" <?php echo $where_value === '' ? 'hidden' : ''; ?>>
							<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 14 14" fill="none" aria-hidden="true">
								<path d="M3.2 11.2 2 10l3.6-3.6L2 2.8 3.2 1.6 6.8 5.2l3.6-3.6L11.6 2.8 8 6.4l3.6 3.6-1.2 1.2-3.6-3.6-3.6 3.6z" fill="currentColor"/>
							</svg>
						</button>
					</div>
					<div class="guesty-where-values">
						<?php foreach ($selected_cities as $city) : ?>
							<input type="hidden" name="city[]" value="<?php echo esc_attr($city); ?>">
						<?php endforeach; ?>
						<?php if ($selected_property) : ?>
							<input type="hidden" name="property_id" value="<?php echo esc_attr((string) $selected_property['id']); ?>">
						<?php endif; ?>
						<input type="hidden" name="q" value="<?php echo esc_attr($selected_query); ?>">
					</div>
					<div class="guesty-where-panel" hidden>
						<div class="guesty-where-selected" <?php echo ($selected_cities || $selected_property) ? '' : 'hidden'; ?>>
							<p class="guesty-where-selected-label">Selected</p>
							<div class="guesty-where-pills"></div>
						</div>
						<ul class="guesty-where-list" id="guesty-where-list" role="listbox" aria-label="Regions and properties">
							<li class="guesty-where-heading" data-heading="region" role="presentation">Regions</li>
							<?php if (!empty($cities)) : foreach ($cities as $city) :
								$city_selected = in_array($city, $selected_cities, true);
							?>
							<li class="guesty-where-option"
								id="guesty-where-region-<?php echo esc_attr(sanitize_title($city)); ?>"
								role="option"
								data-type="region"
								data-label="<?php echo esc_attr($city); ?>"
								aria-selected="<?php echo $city_selected ? 'true' : 'false'; ?>">
								<button type="button" class="guesty-where-row" tabindex="-1">
									<span class="guesty-where-check" aria-hidden="true"></span>
									<span class="guesty-where-label"><?php echo esc_html($city); ?></span>
								</button>
							</li>
							<?php endforeach; endif; ?>
							<li class="guesty-where-heading" data-heading="property" role="presentation" hidden>Properties</li>
							<?php if (!empty($properties)) : foreach ($properties as $property) : ?>
							<li class="guesty-where-option"
								id="guesty-where-property-<?php echo esc_attr((string) $property['id']); ?>"
								role="option"
								data-type="property"
								data-id="<?php echo esc_attr((string) $property['id']); ?>"
								data-label="<?php echo esc_attr($property['title']); ?>"
								data-city="<?php echo esc_attr($property['city']); ?>"
								data-url="<?php echo esc_url($property['url']); ?>"
								data-image="<?php echo esc_url($property['image']); ?>"
								hidden>
								<button type="button" class="guesty-where-row" tabindex="-1">
									<span class="guesty-where-thumb">
										<?php if ($property['image'] !== '') : ?>
											<img src="<?php echo esc_url($property['image']); ?>" alt="">
										<?php endif; ?>
									</span>
									<span class="guesty-where-copy">
										<span class="guesty-where-label"><?php echo esc_html($property['title']); ?></span>
										<?php if ($property['city'] !== '') : ?>
											<span class="guesty-where-meta"><?php echo esc_html($property['city']); ?></span>
										<?php endif; ?>
									</span>
								</button>
							</li>
							<?php endforeach; endif; ?>
							<li class="guesty-where-more" role="presentation" hidden>
								<a class="guesty-where-more-link" href="<?php echo esc_url(get_post_type_archive_link('properties')); ?>">View all properties</a>
							</li>
						</ul>
						<p class="guesty-where-empty" hidden role="status" aria-live="polite">No regions or properties match</p>
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