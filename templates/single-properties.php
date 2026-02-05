<?php get_header(); ?>
<?php
if ( did_action( 'elementor/loaded' ) ) {
add_action('wp_enqueue_scripts', function() {
    if (is_singular('properties')) {
        if (class_exists('\Elementor\Plugin')) {
            wp_enqueue_style('elementor-frontend');
        }
    }
}, 20);
}
?>
<?php if ( did_action( 'elementor/loaded' ) ) { ?>
<div data-elementor-type="wp-post" data-elementor-id="<?php the_ID(); ?>" class="elementor elementor-<?php the_ID(); ?>">
	<div class="elementor-element e-flex e-con-boxed e-con e-parent" data-element_type="container" style="display: flex;">
		<div class="e-con-inner">
<?php } else { ?>
<main id="content" <?php post_class( 'site-main' ); ?>>
<?php }?>
			<?php while (have_posts()) : the_post(); ?>
				<div class="khove-single-property-wrapper">
					<div class="property-back-button">
						<a href="<?php echo esc_url( get_post_type_archive_link( 'properties' ) ); ?>" class="back-link">← See all Properties</a>
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
							<?php foreach ($display_images as $attachment) : 
								$full_url = wp_get_attachment_image_url($attachment->ID, 'full');
								$thumb_url = wp_get_attachment_image_url($attachment->ID, 'guesty_large');
								$i++;
							?>
								<a href="<?php echo $full_url; ?>" 
								   class="grid-item item-<?php echo $i; ?>" 
								   data-fancybox="property-gallery">
								   
									<img src="<?php echo $thumb_url; ?>" alt="Property Image">
									
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
									if ( ! empty($api_data) && isset($api_data['data'][0]) ) {
										
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
								
								function display_guesty_custom_stars($avg_score, $count) {
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
								function get_guesty_star_svg($fill_color, $border_color = 'black') {
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
										<div class="quick-specs"><?php echo $combined_specs; ?></div>
									</div>
									<?php
									$pdf_id = get_post_meta(get_the_ID(), 'guesty_floor_plan_id', true);
									$pdf_url = wp_get_attachment_url($pdf_id); 

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
														<path d="M10 0.5H14.5V14.5H0.5V0.5H4.5L7.5 2.5M6.5 14.5V7.5M4 7.5H9M12 7.5H14.5" stroke="black"/>
													  </g>
													  <defs>
														<clipPath id="clip0_954_2757">
														  <rect width="15" height="15" fill="white"/>
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

							<div class="khove-accordions">
								<?php 
								$sections = ['Description Space', 'Description Access', 'Description Neighborhood', 'Description Notes'];
								foreach($sections as $section): 
									$meta_key = 'guesty_' . strtolower(str_replace(' ', '_', $section));
									$content = get_post_meta(get_the_id(), $meta_key, true);
									if($content):
								?>
									<div class="accordion-item">
										<button class="accordion-header"><?php echo $section; ?> <span>+</span></button>
										<div class="accordion-panel"><p><?php echo wpautop($content); ?></p></div>
									</div>
								<?php endif; endforeach; ?>
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
								<div class="date-picker-mock">
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
										<input type="text" id="booking_checkin_date_display" placeholder="Select Date" value="<?php echo esc_attr($display_checkIn); ?>" readonly>
										<input type="hidden" id="booking_checkin_date" name="checkIn" value="<?php echo esc_attr($selected_checkIn); ?>"  readonly>
									</div>
									<div class="guesty-field option-date-2">
										<label>Check Out</label>
										<?php
											$selected_checkOut = isset($_GET['checkOut']) ? sanitize_text_field($_GET['checkOut']) : '';
											if ($selected_checkOut) {
												$display_checkOut = wp_date('d M, Y',strtotime($selected_checkOut));
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
											for ( $i = 1; $i <= $max_allowed; $i++ ) : ?>
												<option value="<?php echo $i; ?>" <?php selected($selected_minOccupancy, $i); ?>>
													<?php echo $i; ?> <?php echo ($i > 1) ? 'Adults' : 'Adult'; ?>
												</option>
											<?php endfor; ?>
										</select>
									</div>
								</div>
								<button class="view-availability-btn">VIEW AVAILABILITY</button>
								<div class="charge-text">Additional charges may apply.</div>
							</div>
						</aside>
					</div>
                </div>
			<?php endwhile; ?>
<?php if ( did_action( 'elementor/loaded' ) ) { ?>			
		</div>
	</div>
</div>
<?php } else { ?>
</main>
<?php }?>

<script>
document.addEventListener('DOMContentLoaded', function () {
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
				const guestyMinNights = parseInt("<?php echo (int) get_post_meta(get_the_ID(), 'guesty_minNights', true); ?>",10) || 1;
				const guestyMaxNights = parseInt("<?php echo (int) get_post_meta(get_the_ID(), 'guesty_maxNights', true); ?>",10) || 1;
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

document.querySelector('.view-availability-btn').addEventListener('click', async function(e) {
    e.preventDefault();
    
    // 1. Get current values from your inputs
    const listingId = "<?php echo get_post_meta(get_the_ID(), 'guesty_id', true); ?>";
    const checkIn = document.getElementById('booking_checkin_date').value;
    const checkOut = document.getElementById('booking_checkout_date').value;
    const guests = document.querySelector('select[name="minOccupancy"]').value;

    if (!checkIn || !checkOut) {
        alert("Please select your dates first.");
        return;
    }

    const btn = this;
    btn.innerText = "CALCULATING...";

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

        if (result.success) {
            // 4. Show the price in your .charge-text div
            const money = result.data.money;
            document.querySelector('.charge-text').innerHTML = 
                `<strong>Total Price: ${money.currency} ${money.totalPrice}</strong>`;
            btn.innerText = "VIEW AVAILABILITY";
        } else {
            alert("Sorry, these dates are not available.");
            btn.innerText = "VIEW AVAILABILITY";
        }
    } catch (e) {
        console.error("Quote calculation failed", e);
        btn.innerText = "VIEW AVAILABILITY";
    }
});
</script>

<style>
.khove-single-property-wrapper {
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
    gap: 10px;
    height: 510px;
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
    background: white;
    padding: 12px;
    color: var(--Black, #000);
    font-family: var(--e-global-typography-accent-font-family), san-serif;
    font-size: 14px;
    font-style: normal;
    font-weight: 400;
    line-height: 16px;
    letter-spacing: 0.56px;
    text-transform: uppercase;
}

/* Two Column Layout */
.property-main-content {
    display: flex;
    gap: 50px;
    margin-top: 30px;
}
.content-left { flex: 2; }
.content-right { flex: 1; }
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
.property-features .quick-specs {
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
    border: 1px solid var(--Grey, #767676);
    background: var(--White, #FFF);
    padding: 32px 24px;
}
.booking-card-header {
    display: flex;
    gap: 10px;
	margin-bottom: 20px;
}
.booking-card-header .booking-icon {
    min-width: 150px;
}
.booking-card-header .price-header {
    display: flex;
    flex-direction: column;
    gap: 10px;
	justify-content: space-between;
}
.price-header .booking-title {
    margin: 0;
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
.booking-card-header .booking-icon a {
    display: flex;
}

/* .date-picker-mock styles are in includes/forntend/css/frontend.css – edit there to affect both search bar and single-property date picker */
button.view-availability-btn {
    display: flex;
    height: 54px;
    padding: 20px 16px;
    flex-direction: column;
    justify-content: center;
    align-items: center;
    gap: 8px;
    align-self: stretch;
    border: none;
    width: 100%;
    color: var(--White, #FFF);
    font-size: 16px;
    font-style: normal;
    font-weight: 400;
    line-height: 16px;
    letter-spacing: 0.64px;
    text-transform: uppercase;
    margin: 16px 0;
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


/* Accordions */
.accordion-header {
    width: 100%;
    text-align: left;
    background: none !important;
    border: none;
    border-bottom: 1px solid var(--Black, #000);
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
.accordion-panel {
    display: none;
    padding: 20px 0;
    color: #666;
}
</style>

<link rel="stylesheet" href="<?php echo plugin_dir_url(__FILE__); ?>css/fancybox.css"/>
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
            document.querySelectorAll('[data-fancybox="property-gallery"]'), 
            {
                startIndex: 0
            }
        );
    }
}
document.querySelectorAll('.accordion-header').forEach(button => {
    button.addEventListener('click', () => {
        const panel = button.nextElementSibling;
        button.classList.toggle('active');
        panel.style.display = panel.style.display === 'block' ? 'none' : 'block';
        button.querySelector('span').textContent = panel.style.display === 'block' ? '-' : '+';
    });
});
</script>
<?php get_footer(); ?>