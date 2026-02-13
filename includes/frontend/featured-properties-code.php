<?php
if (!defined('ABSPATH')) exit;

/**
 * Register the Shortcode [guesty_featured_properties]
 */
function guesty_render_featured_properties() {
	$property_ids  = get_option('guesty_featured_properties', []);
	if (empty($property_ids)) return '';
    ob_start(); ?>
		
		<div class="property-wrapper">
			<div class="property-grid">
				<?php
					if ( !empty($property_ids) ) :
						foreach ( $property_ids as $post_id ) : ?>
						<?php 
							$guests = get_post_meta($post_id, 'guesty_accommodates', true);
							$beds = get_post_meta($post_id, 'guesty_bedrooms', true);
							$baths = get_post_meta($post_id, 'guesty_bathrooms', true);
							$city = get_post_meta($post_id, 'guesty_address_city', true);
							$type = get_post_meta($post_id, 'guesty_property_type', true);
							$property_icon = get_post_meta($post_id, 'guesty_property_icon_id', true);
							$gallery_ids = (array) get_post_meta($post_id, 'guesty_gallery_ids', true);
							$gallery_ids = array_filter(array_map('intval', $gallery_ids));
							$card_images = array_slice($gallery_ids, 0, 5);
							if (empty($card_images) && has_post_thumbnail($post_id)) {
								$card_images = [get_post_thumbnail_id($post_id)];
							}
						?>
						<article class="guesty-card">
							<div class="card-media">
								<div class="card-swiper swiper">
									<div class="swiper-wrapper">
										<?php foreach ($card_images as $img_id) :
											$img_url = wp_get_attachment_image_url($img_id, 'guesty_large');
											if (!$img_url) continue;
										?>
										<div class="swiper-slide">
											<a href="<?php echo get_the_permalink($post_id); ?>">
												<img class="property-card-featured-image" src="<?php echo esc_url($img_url); ?>" alt="">
											</a>
										</div>
										<?php endforeach; ?>
									</div>
									<div class="swiper-button-next" aria-label="Next"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 20 20" fill="none"><path d="M7.15703 6.175L10.9737 10L7.15703 13.825L8.33203 15L13.332 10L8.33203 5L7.15703 6.175Z" fill="black"/></svg></div>
									<div class="swiper-button-prev" aria-label="Previous"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 20 20" fill="none"><path d="M12.843 6.175L9.0263 10L12.843 13.825L11.668 15L6.66797 10L11.668 5L12.843 6.175Z" fill="black"/></svg></div>
									<div class="swiper-pagination"></div>
								</div>
								<?php if ($property_icon) { ?>
									<a href="<?php echo get_the_permalink($post_id); ?>" class="property-card-icon-wrap">
										<img class="property-card-icon" src="<?php echo esc_url(wp_get_attachment_image_url($property_icon, 'thumbnail')); ?>" alt="">
									</a>
								<?php } ?>
							</div>

							<div class="card-content">
								<h2 class="property-title">
									<a href="<?php echo get_the_permalink($post_id); ?>"><?php echo get_the_title($post_id); ?></a>
								</h2>
								<p class="property-city"><a href="<?php echo esc_url(home_url('/properties')) . '?city=' . esc_attr($city); ?>"><?php echo esc_html(strtoupper($city)); ?></a></p>
								
								<div class="property-excerpt">
									<?php echo wp_trim_words(get_the_excerpt($post_id), 20); ?>
								</div>

								<div class="property-specs-bar">
									<?php echo strtoupper($type); ?> • <?php echo $guests; ?> GUESTS • <?php echo $beds; ?> BEDROOMS • <?php echo $baths; ?> BATHROOMS
								</div>

								<a href="<?php echo get_the_permalink($post_id); ?>" class="view-property-link">VIEW PROPERTY</a>
							</div>
						</article>
					<?php endforeach; 
				endif; ?>
			</div>
		</div>
			
	<?php
    return ob_get_clean();
}
add_shortcode('guesty_featured_properties', 'guesty_render_featured_properties');