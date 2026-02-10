<?php get_header(); ?>
<?php
if (did_action('elementor/loaded')) {
	add_action('wp_enqueue_scripts', function () {
		if (is_singular('properties')) {
			if (class_exists('\Elementor\Plugin')) {
				wp_enqueue_style('elementor-frontend');
			}
		}
	}, 20);
}
?>
<?php if (did_action('elementor/loaded')) { ?>
	<div data-elementor-type="wp-post" data-elementor-id="<?php the_ID(); ?>" class="elementor single-property elementor-<?php the_ID(); ?>">
		<div class="elementor-element e-flex e-con-boxed e-con e-parent" data-element_type="container" style="display: flex;">
			<div class="e-con-inner">
			<?php } else { ?>
				<main id="content" <?php post_class('site-main'); ?>>
				<?php } ?>
				<?php while (have_posts()) : the_post(); ?>
					<div class="guesty-single-property-wrapper">
						<div class="property-back-button">
							<a href="<?php echo esc_url(get_post_type_archive_link('properties')); ?>" class="back-link">← See all Properties</a>
						</div>
						<?php
						$post_id = get_the_ID();

						// 1. Get the ordered list of IDs we saved during sync
						$gallery_data = get_post_meta($post_id, 'guesty_gallery_ids', true);

						// Handle both string ("1,2,3") and array formats
						$attachment_ids = is_array($gallery_data) ? $gallery_data : explode(',', $gallery_data);
						$attachment_ids = array_filter($attachment_ids); // Remove empty values

						if (!empty($attachment_ids)) :
							// 2. Fetch the actual image objects in the SPECIFIC order of the IDs
							$attached_media = [];
							foreach ($attachment_ids as $id) {
								$attachment = get_post($id);
								if ($attachment && $attachment->post_type === 'attachment') {
									$attached_media[] = $attachment;
								}
							}

							$total_count = count($attached_media);

							// Take the first 5 for the grid display
							$display_images = array_slice($attached_media, 0, 5);
							// Keep the rest for the hidden Fancybox gallery
							$hidden_images = array_slice($attached_media, 5);

							$i = 0;
						?>
							<div class="property-grid-container">
								<div class="gallery-grid">
									<div class="property-back-button mobile" style="display: none;">
													<a href="<?php echo esc_url(get_post_type_archive_link('properties')); ?>" class="back-link"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M4.871 7.22859L14 7.22859L14 8.77141L4.871 8.77141L8.894 12.9092L7.8335 14L2 8L7.8335 2L8.894 3.09077L4.871 7.22859Z" fill="black"/></svg></a>
									</div>
									<?php foreach ($display_images as $attachment) :
										$full_url = wp_get_attachment_image_url($attachment->ID, 'full');
										$thumb_url = wp_get_attachment_image_url($attachment->ID, 'guesty_large');
										$i++;
									?>
										<a href="<?php echo $full_url; ?>"
											class="grid-item item-<?php echo $i; ?>"
											data-fancybox="property-gallery">

											<img src="<?php echo $thumb_url; ?>" alt="Property Image">

											<?php if ($i === 1) : ?>
												<div class="view-gallery-btn mobile" style="display: none;" onclick="openFancyboxFirst(event)">
													(1/<?php echo $total_count; ?>)
												</div>
											<?php endif; ?>
											<?php if ($i === 5) : ?>
												<div class="view-gallery-btn" onclick="openFancyboxFirst(event)">
													VIEW GALLERY (<?php echo $total_count; ?>)
												</div>
											<?php endif; ?>
										</a>
									<?php endforeach; ?>

									<?php foreach ($hidden_images as $attachment) : ?>
										<a href="<?php echo wp_get_attachment_image_url($attachment->ID, 'full'); ?>"
											data-fancybox="property-gallery"
											style="display:none;"></a>
									<?php endforeach; ?>
								</div>
							</div>
						<?php endif; ?>
						<div class="property-main-content elementor-container">
							<div class="content-left">
								<?php
								// 1. Fetch Specs
								$specs_array = [];
								if ($property_type = get_post_meta($post_id, 'guesty_property_type', true)) $specs_array[] = $property_type;
								if ($guests = get_post_meta($post_id, 'guesty_accommodates', true)) $specs_array[] = $guests . ' GUESTS';
								if ($bedrooms = get_post_meta($post_id, 'guesty_bedrooms', true)) $specs_array[] = $bedrooms . ' BEDROOMS';
								if ($bathrooms = get_post_meta($post_id, 'guesty_bathrooms', true)) $specs_array[] = $bathrooms . ' BATHROOMS';
								$combined_specs = implode(' • ', $specs_array);

								// 1. Get existing meta
								$reviews_count = get_post_meta($post_id, 'guesty_property_reviews_count', true);
								$reviews_avg_score   = (float) get_post_meta($post_id, 'guesty_property_reviews_avg', true);

								// 2. Check if we need to update (if empty, fetch from API)
								if (empty($reviews_count) || empty($reviews_avg_score)) {
									$listing_ids = get_post_meta($post_id, 'guesty_id', true);
									$api_data = fetch_guesty_review_average($listing_ids);
									if (! empty($api_data) && isset($api_data['data'][0])) {

										$avg_score = $api_data['data'][0]['avg'];
										$rev_count = $api_data['data'][0]['total'];

										// 4. SET/UPDATE the meta data in the database
										update_post_meta($post_id, 'guesty_property_reviews_avg', $avg_score);
										update_post_meta($post_id, 'guesty_property_reviews_count', $rev_count);

										// Update local variables so the display works immediately
										$reviews_avg_score   = $avg_score;
										$reviews_count = $rev_count;
									}
								}

								function display_guesty_custom_stars($avg_score, $count)
								{
									$percentage = ($avg_score / 10) * 100;
									ob_start(); ?>
									<div class="star-rating-wrapper" style="position: relative; width: 92px; height: 19px; display: inline-block; vertical-align: middle;">
										<div style="display: grid;position: absolute; top: 0; left: 0;">
											<?php echo get_guesty_star_svg('none', 'black'); ?>
										</div>
										<div style="display: grid;position: absolute; top: 0; left: 0; width: <?php echo $percentage; ?>%; overflow: hidden; white-space: nowrap;">
											<?php echo get_guesty_star_svg('black', 'black'); ?>
										</div>
									</div>
									<span>(<?php echo $count; ?> reviews)</span>
								<?php
									return ob_get_clean();
								}
								// Helper to keep code clean
								function get_guesty_star_svg($fill_color, $border_color = 'black')
								{
									return '
									<svg width="92" height="19" viewBox="0 0 92 19" xmlns="http://www.w3.org/2000/svg">
										<g stroke="' . $border_color . '" stroke-width="1">
											<path d="M5.06 17L6.36 11.4539L2 7.72368L7.76 7.23026L10 2L12.24 7.23026L18 7.72368L13.64 11.4539L14.94 17L10 14.0592L5.06 17Z" fill="' . $fill_color . '"/>
											<path d="M23.06 17L24.36 11.4539L20 7.72368L25.76 7.23026L28 2L30.24 7.23026L36 7.72368L31.64 11.4539L32.94 17L28 14.0592L23.06 17Z" fill="' . $fill_color . '"/>
											<path d="M41.06 17L42.36 11.4539L38 7.72368L43.76 7.23026L46 2L48.24 7.23026L54 7.72368L49.64 11.4539L50.94 17L46 14.0592L41.06 17Z" fill="' . $fill_color . '"/>
											<path d="M59.06 17L60.36 11.4539L56 7.72368L61.76 7.23026L64 2L66.24 7.23026L72 7.72368L67.64 11.4539L68.94 17L64 14.0592L59.06 17Z" fill="' . $fill_color . '"/>
											<path d="M77.06 17L78.36 11.4539L74 7.72368L79.76 7.23026L82 2L84.24 7.23026L90 7.72368L85.64 11.4539L86.94 17L82 14.0592L77.06 17Z" fill="' . $fill_color . '"/>
										</g>
									</svg>';
								}
								$pdf_id = get_post_meta(get_the_ID(), 'guesty_floor_plan_id', true);
								$pdf_url = wp_get_attachment_url($pdf_id);
								?>
								<div class="property-header">
									<h1 class="h2 title"><?php the_title(); ?></h1>
									<div class="property-info">
										<div class="property-features">
											<p class="location-label"><?php echo get_post_meta(get_the_id(), 'guesty_address_city', true); ?>, <?php echo get_post_meta(get_the_id(), 'guesty_address_state', true); ?></p>
											<?php if (!empty($reviews_count) || !empty($reviews_avg_score)) { ?>
												<div class="property-reviews">
													<?php echo display_guesty_custom_stars($reviews_avg_score, $reviews_count); ?>
												</div>
											<?php } ?>
											<?php
											if ($pdf_url) :
											?>
												<div class="floor-plan-wrapper mobile" style="display: none;">
													<a href="<?php echo esc_url($pdf_url); ?>"
														data-fancybox="floorplan"
														data-type="pdf"
														class="view-floor-plan-btn">
														<span class="icon-floorplan">
															<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 15 15" fill="none">
																<g clip-path="url(#clip0_954_2757)">
																	<path d="M10 0.5H14.5V14.5H0.5V0.5H4.5L7.5 2.5M6.5 14.5V7.5M4 7.5H9M12 7.5H14.5" stroke="black" />
																</g>
																<defs>
																	<clipPath id="clip0_954_2757">
																		<rect width="15" height="15" fill="white" />
																	</clipPath>
																</defs>
															</svg>
														</span>
														VIEW FLOOR PLAN
													</a>
												</div>
											<?php endif; ?>
											<div class="quick-specs"><?php echo $combined_specs; ?></div>
										</div>
										<?php
										if ($pdf_url) :
										?>
											<div class="floor-plan-wrapper">
												<a href="<?php echo esc_url($pdf_url); ?>"
													data-fancybox="floorplan"
													data-type="pdf"
													class="view-floor-plan-btn">
													<span class="icon-floorplan">
														<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 15 15" fill="none">
															<g clip-path="url(#clip0_954_2757)">
																<path d="M10 0.5H14.5V14.5H0.5V0.5H4.5L7.5 2.5M6.5 14.5V7.5M4 7.5H9M12 7.5H14.5" stroke="black" />
															</g>
															<defs>
																<clipPath id="clip0_954_2757">
																	<rect width="15" height="15" fill="white" />
																</clipPath>
															</defs>
														</svg>
													</span>
													VIEW FLOOR PLAN
												</a>
											</div>
										<?php endif; ?>
									</div>
								</div>

								<div class="property-description">
									<?php the_content(); ?>
								</div>

								<div class="guesty-accordions">
									<?php
									$sections = [
									  'Description Space'        => 'The Space',
									  'Description Access'       => 'Guest Access',
									  'Description Neighborhood' => 'Neighborhood',
									  'Description Notes'        => 'Notes',
									];
									foreach ($sections as $section => $label):
										$meta_key = 'guesty_' . strtolower(str_replace(' ', '_', $section));
										$content = get_post_meta(get_the_id(), $meta_key, true);
										if ($content):
									?>
											<div class="accordion-item">
												<button class="accordion-header">
													<?php echo esc_html($label); ?>
													<span>
														<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none">
															<path d="M16.59 8.59L12 13.17L7.41 8.59L6 10L12 16L18 10L16.59 8.59Z" fill="black" />
														</svg>
													</span>
												</button>
												<div class="accordion-panel">
													<?php echo wpautop($content); ?>
												</div>
											</div>
									<?php endif;
									endforeach; ?>
									<?php
										$listing_rooms = get_post_meta($post_id, 'guesty_listing_rooms', true);
										$saved_images = get_post_meta($post_id, 'guesty_listing_rooms_data', true);
										
										$rooms_array = [];
										if ($bedrooms = get_post_meta($post_id, 'guesty_bedrooms', true)) $rooms_array[] = $bedrooms . ' BEDROOMS';
										if ($beds = get_post_meta($post_id, 'guesty_beds', true)) $rooms_array[] = $bathrooms . ' BEDS';
										$combined_rooms = implode(' • ', $rooms_array);
									?>
									<div class="accordion-item open-accordion">
										<button class="accordion-header">
											Where you’ll dream
										</button>
										<div class="accordion-panel">
											<div class="bedroomSwiper swiper">
												<span class="quick-specs"><?php echo $combined_rooms; ?></span>
												<div class="swiper-wrapper">
													<?php foreach ($listing_rooms as $index => $room_data) : 
														$bed_details = [];
														if (!empty($room_data['beds'])) {
															foreach ($room_data['beds'] as $bed) {
																$count = $bed['quantity'];
																$type = str_replace('_', ' ', $bed['type']);
																if ($count > 1) { $type = $type . 'S'; }
																$bed_details[] = $count . ' ' . $type;
															}
														}
														$bed_display_text = implode(', ', $bed_details);
														$is_disabled = empty(trim($bed_display_text)); 
														$disabled_class = $is_disabled ? 'room-disabled' : '';
														$image_id = isset($saved_images[$index]['image_id']) ? $saved_images[$index]['image_id'] : '';
													?>
														<?php if(!$is_disabled) : ?>
														<div class="swiper-slide">												
															<div class="image-preview">
																<?php if (!empty($image_id)) echo wp_get_attachment_image($image_id, 'full'); ?>
															</div>
															<h4 class="room-title">Bedroom <?php echo $room_data['roomNumber'] + 1; ?></h4>
															<p class="bed-info"><?php echo esc_html($bed_display_text); ?></p>
														</div>
														<?php endif; ?>
													<?php endforeach; ?>
												</div>
												<div class="swiper-button-next" aria-label="Next"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 20 20" fill="none"><path d="M7.15703 6.175L10.9737 10L7.15703 13.825L8.33203 15L13.332 10L8.33203 5L7.15703 6.175Z" fill="black"/></svg></div>
												<div class="swiper-button-prev" aria-label="Previous"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 20 20" fill="none"><path d="M12.843 6.175L9.0263 10L12.843 13.825L11.668 15L6.66797 10L11.668 5L12.843 6.175Z" fill="black"/></svg></div>
												<div class="swiper-pagination"></div>
											</div>											
										</div>
									</div>
									<?php
										$all_amenities = get_post_meta($post->ID, 'guesty_amenities', true);
										$selected_amenities = get_post_meta($post->ID, 'guesty_top_amenities', true);
										if (!is_array($selected_amenities)) $selected_amenities = [];
										$available_amenities = array_diff($all_amenities, $selected_amenities);
									?>
									<div class="accordion-item open-accordion" style="border-bottom: none;">
										<button class="accordion-header amenities">
											featured amenities
										</button>
										<div class="accordion-panel">
											<ul class="amenities-list">
												<?php
												if ( !empty($selected_amenities) ) :
													foreach ( $selected_amenities as $amenity ) : ?>
														<li class="amenity-item" data-id="<?php echo esc_attr($amenity); ?>"><?php echo esc_html($amenity); ?></li>
													<?php endforeach; 
												endif; ?>
												<?php
												if ( !empty($available_amenities) ) :
													foreach ( $available_amenities as $amenity ) : ?>
														<li class="amenity-item" data-id="<?php echo esc_attr($amenity); ?>"><?php echo esc_html($amenity); ?></li>
													<?php endforeach; 
												endif; ?>
											</ul>
											<button class="show-all-amenities">Show all</button>
										</div>
									</div>
								</div>
							</div>

							<aside class="content-right">
								<div class="booking-card sticky-sidebar">
									<div class="booking-card-header">
										<div class="price-header">
											<h4 class="booking-title">Entire House</h4>
											<div class="booking-price">
												<p class="text-x-large">Enter dates for accurate pricing</p>
												<p class="text-tiny">Pricing shown once dates are entered.</p>
											</div>
										</div>
										<?php
										$icon_id = get_post_meta(get_the_id(), 'guesty_property_icon_id', true);
										$icon_url_full = wp_get_attachment_image_url($icon_id, 'full');
										$icon_url_thumb = wp_get_attachment_image_url($icon_id, 'thumbnail');
										?>
										<?php if ($icon_url_full) : ?>
											<div class="booking-icon">
												<a href="<?php echo esc_url($icon_url_full); ?>" data-fancybox="property-icon">
													<img src="<?php echo esc_url($icon_url_thumb); ?>" alt="Property Icon" />
												</a>
											</div>
										<?php endif; ?>
									</div>
									<div class="notify-box" style="display:none;">
									  <span></span>
									</div>
									<div class="date-picker-mock">
										<div class="guesty-field option-date">
											<label>Check In</label>
											<?php
											$selected_checkIn = isset($_GET['checkIn']) ? sanitize_text_field($_GET['checkIn']) : '';
											if ($selected_checkIn) {
												$display_checkIn = wp_date('d M, Y', strtotime($selected_checkIn));
											} else {
												$display_checkIn = "";
											}
											?>
											<input type="text" id="booking_checkin_date_display" placeholder="Select Date" value="<?php echo esc_attr($display_checkIn); ?>" readonly>
											<input type="hidden" id="booking_checkin_date" name="checkIn" value="<?php echo esc_attr($selected_checkIn); ?>" readonly>
										</div>
										<div class="guesty-field option-date-2">
											<label>Check Out</label>
											<?php
											$selected_checkOut = isset($_GET['checkOut']) ? sanitize_text_field($_GET['checkOut']) : '';
											if ($selected_checkOut) {
												$display_checkOut = wp_date('d M, Y', strtotime($selected_checkOut));
											} else {
												$display_checkOut = "";
											}
											?>
											<input type="text" id="booking_checkout_date_display" placeholder="Select Date" value="<?php echo esc_attr($display_checkOut); ?>" readonly>
											<input type="hidden" id="booking_checkout_date" name="checkOut" value="<?php echo esc_attr($selected_checkOut); ?>" readonly>
										</div>
										<div class="guesty-field option-guests">
											<label>Guests</label>
											<select name="minOccupancy">
												<?php
												$max_allowed = get_post_meta(get_the_id(), 'guesty_accommodates', true);
												$selected_minOccupancy = isset($_GET['minOccupancy']) ? sanitize_text_field($_GET['minOccupancy']) : '';
												for ($i = 1; $i <= $max_allowed; $i++) : ?>
													<option value="<?php echo $i; ?>" <?php selected($selected_minOccupancy, $i); ?>>
														<?php echo $i; ?> <?php echo ($i > 1) ? 'Adults' : 'Adult'; ?>
													</option>
												<?php endfor; ?>
											</select>
										</div>
									</div>
									<div class="guesty-booking-cost" style="display:none;">
										<div class="total-box">
											<div class="label">Total</div>
											<div class="total-price" id="total_price" ></div>
										</div>
										<div class="fees-box">
											<div class="label-fees">Includes taxes and fees</div>
											<a href="javascript:void(0);" class="payment-list-detail-btn" >View details</a>
										</div>
										<div class="collapseExample" id="additional_items">
											<ul>
											</ul>
										</div>
									</div>
									<button class="view-availability-btn">VIEW AVAILABILITY</button>
									<button class="reserve-btn" style="display: none;">Reserve</button>
									<div class="charge-text">Additional charges may apply.</div>
								</div>
							</aside>
						</div>
					</div>
				<?php endwhile; ?>
				<?php if (did_action('elementor/loaded')) { ?>
			</div>
		</div>
	</div>
<?php } else { ?>
	</main>
<?php } ?>

<script>
	document.addEventListener('DOMContentLoaded', function() {
		// 1. Create a variable in this scope to hold the dates
		let fetchedBlockedDates = [];
		const today = new Date();
		const maxDate = new Date();
		maxDate.setFullYear(today.getFullYear() + 2);

		function getNextBlockedDate(startStr, blockedDates) {
			const startTime = new Date(startStr).getTime();
			return blockedDates
				.map(d => new Date(d))
				.filter(d => d.getTime() > startTime)
				.sort((a, b) => a - b)[0] || null;
		}

		const picker = new Litepicker({
			element: document.getElementById('booking_checkin_date_display'),
			elementEnd: document.getElementById('booking_checkout_date_display'),
			singleMode: false,
			autoApply: true,
			lockDays: [],
			minDate: today,
			maxDate: maxDate,
			format: 'DD MMM, YYYY',
			disallowLockDaysInRange: true,
			showTooltip: true,
			tooltipNumber: (totalDays) => {
				return totalDays - 1;
			},
			tooltipText: {
				one: 'day',
				other: 'days'
			},
			setup: (picker) => {
				// 1. DYNAMICALLY RESTRICT CHECK-OUT RANGE
				picker.on('preselect', (date1) => {
					if (!date1) return;
					// Check if the next 3 days are blocked
					let activeLockDays = [...fetchedBlockedDates];
					const blockingDate = [1, 2, 3].map(offset =>
						date1.clone().add(offset, 'day').format('YYYY-MM-DD')
					).find(dateStr => activeLockDays.includes(dateStr));

					if (blockingDate) {
						activeLockDays = activeLockDays.filter(d => d !== blockingDate);
						picker.setOptions({
							lockDays: activeLockDays,
							resetView: false
						});
					}
					const guestyMinNights = parseInt("<?php echo (int) get_post_meta(get_the_ID(), 'guesty_minNights', true); ?>", 10) || 1;
					const guestyMaxNights = parseInt("<?php echo (int) get_post_meta(get_the_ID(), 'guesty_maxNights', true); ?>", 10) || 1;
					picker.setOptions({
						minDays: guestyMinNights + 1,
						maxDays: guestyMaxNights + 1,
						minDate: date1.clone(),
						resetView: false
					});
					picker.gotoDate(date1);
				});

				// 2. HANDLE FINAL SELECTION
				picker.on('selected', (start, end) => {
					if (!end) return;
					const startStr = start.format('YYYY-MM-DD');
					const endStr = end.format('YYYY-MM-DD');
					document.getElementById('booking_checkin_date').value = startStr;
					document.getElementById('booking_checkout_date').value = endStr;
					picker.setOptions({
						lockDays: fetchedBlockedDates,
						minDate: today,
						minDays: null,
						maxDays: null,
						resetView: false
					});
				});

				// 3. RESET LOCKS & LIMITS IF SELECTION IS CLEARED
				picker.on('clear:selection', () => {
					picker.setOptions({
						lockDays: fetchedBlockedDates,
						minDate: today,
						minDays: null,
						maxDays: null,
						resetView: false
					});
				});
				
				picker.on('show', () => {
					const months = document.querySelector('.litepicker .container__months');
					if (!months || months.querySelector('.lp-header')) return;
	
					const header = document.createElement('div');
					header.className = 'lp-header';
	
					const title = document.createElement('div');
					title.className = 'lp-title';
					title.innerText = 'SELECT DATES';
	
					const closeBtn = document.createElement('button');
					closeBtn.className = 'lp-close';
					closeBtn.innerHTML = `
						<svg xmlns="http://www.w3.org/2000/svg" width="30" height="30" viewBox="0 0 30 30" fill="none">
						<path d="M10.2 21L9 19.8L13.8 15L9 10.2L10.2 9L15 13.8L19.8 9L21 10.2L16.2 15L21 19.8L19.8 21L15 16.2L10.2 21Z" fill="black"/>
						</svg>
					`;
	
					closeBtn.addEventListener('click', () => picker.hide());
	
					header.appendChild(title);
					header.appendChild(closeBtn);
	
					months.prepend(header);
				});
			}
		});

		async function loadGuestyDates() {
			const listingId = "<?php echo get_post_meta(get_the_ID(), 'guesty_id', true); ?>";
			if (!listingId) return;

			try {
				const response = await fetch(`<?php echo admin_url('admin-ajax.php'); ?>?action=get_blocked_dates&listing_id=${listingId}`);
				const result = await response.json();

				if (result.success && result.data.length > 0) {
					// UPDATE THE LOCAL VARIABLE FIRST
					fetchedBlockedDates = result.data;

					// Then update the picker - this triggers 'render:day' automatically
					picker.setOptions({
						lockDays: result.data,
						resetView: false
					});
				}
			} catch (e) {
				console.error("Failed to load Guesty dates", e);
			}
		}

		loadGuestyDates();
	});

	document.addEventListener('DOMContentLoaded', function () {
	  const params = new URLSearchParams(window.location.search);

	  const checkIn  = params.get('checkIn');
	  const checkOut = params.get('checkOut');

	  if (checkIn && checkOut) {
		const btn_availability = document.querySelector('.view-availability-btn');
		const btn_reserve = document.querySelector('.reserve-btn');
		if (btn_reserve) {
		  setTimeout(() => {
			btn_reserve.click();
		  }, 200);
		}
		if (btn_availability) {
		  // slight delay to ensure everything is ready
		  setTimeout(() => {
			btn_availability.click();
		  }, 300);
		}
	  }
	});
	
	document.querySelector('.view-availability-btn').addEventListener('click', async function(e) {
		e.preventDefault();

		// 1. Get current values from your inputs
		const listingId = "<?php echo get_post_meta(get_the_ID(), 'guesty_id', true); ?>";
		const checkIn = document.getElementById('booking_checkin_date').value;
		const checkOut = document.getElementById('booking_checkout_date').value;
		const guests = document.querySelector('select[name="minOccupancy"]').value;
		
		const notifyBox = document.querySelector('.notify-box');
		const bookingCostBox = document.querySelector('.guesty-booking-cost');
		const notifyText = notifyBox.querySelector('span');
		const totalPrice = document.getElementById('total_price');

		if (!checkIn || !checkOut) {
			notifyText.textContent = "Please select your dates first.";
			notifyBox.style.display = 'block';
			bookingCostBox.style.display = 'none';
			return;
		}
		
		const bookingUrl =
		`https://khove.guestybookings.com/en/properties/${listingId}` +
		`?minOccupancy=${guests}` +
		`&checkIn=${checkIn}` +
		`&checkOut=${checkOut}`;

		const btn = this;
		if (btn.innerText === 'BOOK NOW') {
		  btn.innerText = "SUBMITTING...";
		  return;
		}
		btn.innerText = "CHECKING AVAILABILITY...";
		//if (localStorage.getItem('sharedState')) {
			//localStorage.removeItem('sharedState');
		//}

		try {
			// 2. Prepare the AJAX data
			const formData = new FormData();
			formData.append('action', 'get_guesty_quote'); // This must match your PHP function
			formData.append('listing_id', listingId);
			formData.append('checkIn', checkIn);
			formData.append('checkOut', checkOut);
			formData.append('guests', guests);

			// 3. Send the request to admin-ajax.php
			const response = await fetch("<?php echo admin_url('admin-ajax.php'); ?>", {
				method: 'POST',
				body: formData
			});

			const result = await response.json();
			//localStorage.setItem('sharedState', JSON.stringify(result));
			
			function formatAUD(amount) {
			  return new Intl.NumberFormat('en-AU', {
				style: 'currency',
				currency: 'AUD',
				minimumFractionDigits: 2,
				maximumFractionDigits: 2
			  }).format(Math.round(amount * 100) / 100);
			}

			if (result.data.status === 'valid') {
			  // Hide error
			  notifyBox.style.display = 'none';
			  bookingCostBox.style.display = 'block';

			  const money = result.data.rates.ratePlans[0].money.money;
			  const price = Number(money.subTotalPrice.toFixed(2));
			  const formattedPrice = formatAUD(price);
			  totalPrice.innerHTML = `${money.currency} ${formattedPrice}`;
			  
			  const list = document.querySelector('.collapseExample ul');
			  list.innerHTML = '';
			  
			  if (Array.isArray(money.invoiceItems) && money.invoiceItems.length > 0) {
			    money.invoiceItems.forEach(item => {
			  	list.insertAdjacentHTML(
			  	  'beforeend',
			  	  `<li>
			  		${item.title}
			  		<span>${item.currency} ${formatAUD(item.amount)}</span>
			  	  </li>`
			  	);
			    });
			  }

			  btn.innerText = "BOOK NOW";
			  btn.onclick = () => window.location.href = bookingUrl;

			} else {
			  // Get API error message
			  const errorMessage =
				result?.data?.error?.message || "Sorry, these dates are not available.";

			  // Show notification
			  notifyText.textContent = errorMessage;
			  notifyBox.style.display = 'block';
			  bookingCostBox.style.display = 'none';

			  btn.innerText = "VIEW AVAILABILITY";
			}

		} catch (e) {
			console.error("Quote calculation failed", e);
			btn.innerText = "VIEW AVAILABILITY";
		}
	});

const toggleBtn = document.querySelector('.payment-list-detail-btn');
const collapseBox = document.querySelector('.collapseExample');

toggleBtn.addEventListener('click', () => {
  const isOpen = collapseBox.classList.toggle('open');
  toggleBtn.textContent = isOpen ? 'Hide details' : 'View details';
});

document.addEventListener("DOMContentLoaded", (event) => {
	const swiper = new Swiper('.bedroomSwiper', {
	  slidesPerView: 2,
	  spaceBetween: 16,
	  loop: false,
	  navigation: {
		nextEl: '.swiper-button-next',
		prevEl: '.swiper-button-prev',
	  },
	  pagination: {
		el: '.swiper-pagination',
		type: 'fraction',
	  },
	  on: {
		init: toggleArrows,
		resize: toggleArrows,
		slideChange: toggleArrows,
	  }
	});
function toggleArrows() {
  const swiper = this;
  const prev = swiper.navigation.prevEl;
  const next = swiper.navigation.nextEl;

  // If no sliding possible → hide arrows
  if (swiper.isBeginning && swiper.isEnd) {
    prev.style.display = 'none';
    next.style.display = 'none';
  } else {
    prev.style.display = '';
    next.style.display = '';
  }
}

const btn = document.querySelector('.show-all-amenities');
const list = document.querySelector('.amenities-list');

btn.addEventListener('click', function () {
  list.classList.toggle('show-all');
  this.textContent = list.classList.contains('show-all')
    ? 'Show less'
    : 'Show all';
});

const reserveBtn = document.querySelector('.reserve-btn');
const bookingCard = document.querySelector('.booking-card.sticky-sidebar');
  if (!reserveBtn || !bookingCard) return;
  reserveBtn.addEventListener('click', function () {
    bookingCard.classList.add('reserve-clicked');
  });
});
</script>

<style>
.guesty-single-property-wrapper {
	padding-top: 14px;
}
.property-back-button {
	margin-bottom: 24px;
}
.property-back-button a {
	color: var(--Black, #000);
	padding: 12px 0;
	font-family: var(--e-global-typography-accent-font-family), san-serif;
	font-size: 14px;
	font-style: normal;
	font-weight: 400;
	line-height: 16px;
	letter-spacing: 0.56px;
	text-transform: uppercase;
	display: inline-block;
}
.gallery-grid {
	display: grid;
	grid-template-columns: 2fr 1fr 1fr;
	grid-template-rows: 1fr 1fr;
	gap: 12px;
	height: 510px;
	@media (max-width: 767px){
		display: flex;
		height: auto;
	}
}
.grid-item {
	position: relative;
	overflow: hidden;
}
.grid-item img {
	width: 100%;
	height: 100%;
	object-fit: cover;
	transition: transform 0.3s ease;
}
.grid-item:hover img {
	transform: scale(1.05);
}
.item-1 {
	grid-row: 1 / span 2;
	grid-column: 1 / span 1;
}
.view-gallery-btn {
	position: absolute;
	bottom: 20px;
	right: 20px;
	padding: 12px 12px 10px;
	background: var(--White, #FFF);
	color: var(--Black, #000);
	font-family: var(--e-global-typography-accent-font-family), san-serif;
	font-size: 14px;
	font-style: normal;
	font-weight: 400;
	line-height: 14px;
	letter-spacing: 0.56px;
	text-transform: uppercase;
	cursor: pointer;
	transition: all 0.3s ease;
	&:hover {
		background: var(--Black, #000);
		color: var(--White, #FFF);
	}
}

.property-main-content {
	display: flex;
	gap: 132px;
	margin-top: 40px;
	margin-bottom: 70px;
	@media (max-width: 1380px){
		gap: 100px;
	}
	@media (max-width: 1280px){
		gap: 60px;
	}
	@media (max-width: 1180px){
		gap: 30px;
	}
	@media (max-width: 767px){
		flex-direction: column;
	}
}
.content-left {
	width: 100%;
	overflow: hidden;
}
.content-right {
	max-width: 440px;
}
.property-header h1.title {
	font-size: 46px;
	line-height: 126.087%;
	letter-spacing: -0.46px;
	margin: 0;
	padding-bottom: 24px;
}
.property-info {
	display: flex;
	justify-content: space-between;
	gap: 20px;
	font-family: var(--e-global-typography-accent-font-family), san-serif;
	margin-bottom: 30px;
}
.property-features {
	display: flex;
	flex-direction: column;
	gap: 24px;
}
.property-features .location-label {
	color: var(--Grey, #767676);
	font-size: 16px;
	font-style: normal;
	font-weight: 400;
	line-height: 100%;
	letter-spacing: 1.28px;
	text-transform: uppercase;
	margin: 0;
}
.property-features .property-reviews {
	color: var(--Grey, #767676);
	font-size: 13px;
	font-style: normal;
	font-weight: 400;
	line-height: 100%;
	letter-spacing: 1.04px;
	text-transform: uppercase;
	display: flex;
	align-items: center;
	gap: 8px;
}
.quick-specs {
	font-family: var(--e-global-typography-accent-font-family), san-serif;
	color: var(--Grey, #767676);
	font-size: 16px;
	font-style: normal;
	font-weight: 400;
	line-height: 100%;
	letter-spacing: 1.28px;
	text-transform: uppercase;
}
.view-floor-plan-btn {
	display: inline-flex;
	align-items: center;
	gap: 8px;
	padding: 20px 16px;
	border: 1px solid #000;
	transition: all 0.3s ease;
	color: var(--Black, #000);
	font-size: 16px;
	font-style: normal;
	font-weight: 400;
	line-height: 16px;
	letter-spacing: 0.64px;
	text-transform: uppercase;
	min-width: 195px;
}
.view-floor-plan-btn span.icon-floorplan {
	display: flex;
}
.view-floor-plan-btn:hover {
	background: #000;
	color: #fff;
}
.view-floor-plan-btn:hover svg path {
	stroke: #fff;
}

/* Sticky Sidebar */
.sticky-sidebar {
	position: sticky;
	top: 30px;
	border: 0.5px solid var(--Black, #000);
	background: var(--White, #FFF);
	padding: 32px 24px;
}
.booking-card-header {
	display: flex;
	gap: 20px;
	margin-bottom: 20px;
	.booking-icon {
		min-width: 155px;
		a {
			display: flex;
			img {
				width: 100%;
				height: 100%;
				object-fit: cover;
			}
		}
	}
}
.booking-card-header .price-header {
	display: flex;
	flex-direction: column;
	gap: 24px;
	justify-content: space-between;
}
.price-header .booking-title {
	margin: 0;
	font-weight: 400;
	font-size: 22px;
	line-height: 120%;
	letter-spacing: 1.32px;
}
.booking-price .text-x-large {
	color: var(--Black, #000);
	font-size: 26px;
	font-style: normal;
	font-weight: 400;
	line-height: 30px;
	letter-spacing: -0.26px;
	margin-bottom: 8px;
}
.booking-price .text-tiny {
	margin: 0;
	color: var(--Grey, #767676);
	font-size: 13px;
	font-style: normal;
	font-weight: 400;
	line-height: 130%;
}
button.view-availability-btn {
	display: flex;
	padding: 20px 16px 16px;
	flex-direction: column;
	justify-content: center;
	align-items: center;
	gap: 8px;
	align-self: stretch;
	border: 1px solid var(--Black, #000);
	width: 100%;
	color: var(--White, #FFF);
	font-size: 16px;
	font-style: normal;
	font-weight: 400;
	line-height: 16px;
	letter-spacing: 0.64px;
	text-transform: uppercase;
	margin: 16px 0;
	&:hover {
		background: transparent;
		color: var(--Black, #000);
	}
}
button.reserve-btn {
	display: flex;
	padding: 20px 16px 16px;
	flex-direction: column;
	justify-content: center;
	align-items: center;
	gap: 8px;
	align-self: stretch;
	border: 1px solid var(--Black, #000);
	width: 100%;
	color: var(--White, #FFF);
	font-size: 16px;
	font-style: normal;
	font-weight: 400;
	line-height: 16px;
	letter-spacing: 0.64px;
	text-transform: uppercase;
	margin: 0;
	&:hover {
		background: transparent;
		color: var(--Black, #000);
	}
}
.charge-text {
	color: var(--Grey, #767676);
	text-align: center;
	font-size: 13px;
	font-style: normal;
	font-weight: 400;
	line-height: 130%;
}
.litepicker .container__days .day-item.guesty-blocked {
	background: #f4f6f7;
	border: 1px solid #fff;
}

.lp-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    width: 100%;
    padding: 10px 10px 0;
}
.lp-title {
    color: var(--Black, #000);
    font-family: "League Spartan";
    font-size: 22px;
    font-style: normal;
    font-weight: 400;
    line-height: 120%;
    letter-spacing: 1.32px;
    text-transform: uppercase;
    padding-left: 10px;
}
.lp-close {
    background: none !important;
    border: none !important;
    padding: 0;
    display: grid;
}

/* Accordions */
.accordion-item {
	border-bottom: 1px solid var(--Black, #000);
}
.accordion-header {
	width: 100%;
	text-align: left;
	background: none !important;
	border: none;
	padding: 24px 8px;
	display: flex;
	justify-content: space-between;
	color: var(--Black, #000) !important;
	font-size: 22px !important;
	font-weight: 400;
	line-height: 120% !important;
	letter-spacing: 1.32px !important;
	text-transform: uppercase !important;
}
.accordion-header span {
	display: inline-flex;
	transition: transform 0.25s ease;
}
.accordion-item.active .accordion-header span {
	transform: rotate(180deg);
}
.accordion-panel {
	height: 0;
	overflow: hidden;
	transition: height 0.3s ease;
	padding: 0 8px;
	color: #666;
}
	
.accordion-item.open-accordion {
    padding: 39px 0;
}
.open-accordion button.accordion-header {
    pointer-events: none;
	padding: 20px 0;
}
.open-accordion .accordion-panel {
    height: auto;
	padding: 0;
}
.bedroomSwiper .swiper-button-next,
.bedroomSwiper .swiper-button-prev {
    display: flex;
    width: 28px;
    height: 28px;
    padding: 3px;
    justify-content: center;
    align-items: center;
    aspect-ratio: 1 / 1;
    border-radius: 100px;
    border: 0.5px solid var(--Black, #000);
    background: var(--white-60, rgba(255, 255, 255, 0.60));
	&:after {
	  display: none;
	}
}
.bedroomSwiper span.quick-specs {
    margin-bottom: 30px;
    display: block;
}
.bedroomSwiper .swiper-button-next {
    position: absolute;
    top: 23px;
    right: 0;
}
.bedroomSwiper .swiper-button-prev {
    position: absolute;
    top: 23px;
    right: 40px;
	left: unset;
}
.bedroomSwiper .swiper-pagination {
    position: absolute;
    top: 10px;
    bottom: unset;
    width: fit-content;
    right: 85px;
    left: unset;
    color: var(--Grey, #767676);
    font-family: var(--e-global-typography-accent-font-family), san-serif;
    font-size: 13px;
    font-style: normal;
    font-weight: 400;
    line-height: 100%;
    letter-spacing: 1.04px;
    text-transform: uppercase;
}
.bedroomSwiper .image-preview {
    display: grid;
	margin-bottom: 24px;
}
.bedroomSwiper .room-title {
    color: var(--Black, #000);
    font-family: "League Spartan";
    font-size: 22px;
    font-style: normal;
    font-weight: 400;
    line-height: 120%;
    letter-spacing: 1.32px;
    text-transform: uppercase;
    margin: 0;
    padding-bottom: 16px;
}
.bedroomSwiper .bed-info {
    color: var(--Grey, #767676);
    font-family: var(--e-global-typography-accent-font-family), san-serif;
    font-size: 13px;
    font-style: normal;
    font-weight: 400;
    line-height: 100%;
    letter-spacing: 1.04px;
    text-transform: uppercase;
}

ul.amenities-list {
    color: var(--Grey, #767676);
    font-family: var(--e-global-typography-accent-font-family), san-serif;
    font-size: 13px;
    font-style: normal;
    font-weight: 400;
    line-height: 1.2;
    letter-spacing: 1.04px;
    text-transform: uppercase;
    padding-left: 14px;
    display: flex;
    flex-wrap: wrap;
    gap: 16px;
}
.open-accordion button.accordion-header.amenities {
    padding: 0 0 30px;
}
li.amenity-item {
    width: calc(50% - 8px);
}
ul.amenities-list li {
    display: none;
}
ul.amenities-list li:nth-child(-n+6) {
    display: list-item;
}
.amenities-list.show-all li {
	display: list-item;
}
button.show-all-amenities {
    border: none;
    background: none;
    color: var(--Black, #000);
    font-family: var(--e-global-typography-accent-font-family), san-serif;
    font-size: 14px;
    font-style: normal;
    font-weight: 400;
    line-height: 16px;
    letter-spacing: 0.56px;
    text-transform: uppercase;
    padding: 12px 0;
    margin-top: 24px;
}

	
.notify-box {
    font-size: 14px;
    text-align: center;
    padding: 10px;
    line-height: 1.4;
    color: red;
    border: 1px solid;
    margin-bottom: 10px;
}
.guesty-booking-cost {
    margin-top: 15px;
}
.total-box {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
	font-weight: 600;
    font-size: 20px;
	color: #000;
}
.fees-box {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    font-size: 14px;
}
.label-fees {
    color: var(--Grey, #767676);
}
.payment-list-detail-btn {
    font-weight: 600;
	cursor: pointer;
}
.collapseExample {
	max-height: 0;
	overflow: hidden;
	transition: max-height 0.3s ease;
}
.collapseExample.open {
	max-height: 600px;
}
.collapseExample ul {
    list-style: none;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 10px;
    margin-top: 15px;
}
.collapseExample ul li {
    display: flex;
    align-items: center;
    gap: 10px;
    justify-content: space-between;
    font-size: 14px;
	line-height: 1.4;
}
	
@media (max-width: 1024px){
.e-con {
    --container-default-padding-right: 20px;
    --container-default-padding-left: 20px;
}
.property-main-content {
    margin-top: 30px;
}
.floor-plan-wrapper {
    display: none;
}
.floor-plan-wrapper.mobile {
	display: block !important;
}
.floor-plan-wrapper.mobile .view-floor-plan-btn {
    padding: 12px;
}
}

@media (max-width: 767px){
.single-property .e-con {
    --container-default-padding-right: 16px;
    --container-default-padding-left: 16px;
}
.single-property .e-con-inner {
    padding: 0;
}
.gallery-grid .grid-item.item-2,
.gallery-grid .grid-item.item-3,
.gallery-grid .grid-item.item-4,
.gallery-grid .grid-item.item-5 {
    display: none;
}
.view-gallery-btn.mobile {
    display: block !important;
    bottom: 16px;
    right: 16px;
    padding: 10px;
}
.property-back-button {
    display: none;
}
.guesty-single-property-wrapper {
    padding-top: 0;
}
.grid-item:hover img {
    transform: scale(1);
}
.gallery-grid {
    margin-left: -16px;
    margin-right: -16px;
}
.property-back-button.mobile {
    display: block !important;
    position: absolute;
    top: 16px;
    left: 16px;
    z-index: 1;
}
.property-back-button.mobile a.back-link {
    padding: 10px;
    background: #FFF;
    line-height: 1;
    display: grid;
}
.property-main-content {
    margin-top: 20px;
}
.property-header h1.title {
    font-size: 36px;
    line-height: 111.111%;
    letter-spacing: -0.36px;
    padding-bottom: 16px;
}
.booking-card.sticky-sidebar {
    padding: 16px;
    border: none;
    border-top: 0.5px solid var(--Grey, #767676);
    position: fixed;
    left: 0;
    bottom: 0;
    top: unset;
    background: #FFF;
    z-index: 999999;
	width: 100%;
}
.booking-card.sticky-sidebar .booking-card-header {
    display: none;
}
.booking-card.sticky-sidebar .charge-text {
    display: none;
}
button.view-availability-btn {
    margin: 16px 0 0;
}
.booking-card.sticky-sidebar:not(.reserve-clicked) .date-picker-mock {
    display: none;
}
.booking-card.sticky-sidebar:not(.reserve-clicked) button.view-availability-btn {
    display: none;
}
.booking-card.sticky-sidebar:not(.reserve-clicked) button.reserve-btn {
    display: block !important;
}
.litepicker {
    position: fixed !important;
    top: 0 !important;
    left: 0 !important;
    width: 100% !important;
	height: calc(100% - 230px) !important;
    z-index: 99999 !important;
    background: #fff;
	overflow-y: auto;
}
.litepicker .container__main .container__months {
    box-shadow: none;
    border-radius: 0px;
    margin: 0 auto;
}
}
</style>

<link rel="stylesheet" href="<?php echo plugin_dir_url(__FILE__); ?>css/fancybox.css" />
<script src="<?php echo plugin_dir_url(__FILE__); ?>js/fancybox.umd.js"></script>

<script>
	// Wait for the window to finish loading everything
	document.addEventListener("DOMContentLoaded", function() {
		if (typeof Fancybox !== "undefined") {
			Fancybox.bind("[data-fancybox]", {});
			Fancybox.bind("[data-fancybox='property-gallery']", {
				dragToClose: false,
				Toolbar: {
					display: {
						left: ["infobar"],
						middle: [],
						right: ["iterateZoom", "slideshow", "fullScreen", "download", "thumbs", "close"],
					},
				},
			});
		} else {
			console.error("Fancybox library failed to load.");
		}
	});
	function openFancyboxFirst(event) {
		event.preventDefault();
		event.stopPropagation();
		if (typeof Fancybox !== "undefined") {
			Fancybox.fromNodes(
				document.querySelectorAll('[data-fancybox="property-gallery"]'), {
					startIndex: 0
				}
			);
		}
	}
	document.querySelectorAll('.accordion-header').forEach(button => {
		button.addEventListener('click', () => {
			const item = button.closest('.accordion-item');
			const panel = button.nextElementSibling;
			const isOpening = !item.classList.contains('active');

			if (isOpening) {
				item.classList.add('active');
				panel.style.height = panel.scrollHeight + 'px';
			} else {
				const startHeight = panel.scrollHeight;
				panel.style.height = startHeight + 'px';
				panel.offsetHeight;
				panel.style.height = '0';
				item.classList.remove('active');
			}
		});
	});
</script>
<?php get_footer(); ?>