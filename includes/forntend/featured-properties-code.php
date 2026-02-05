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
							// Fetch metadata
							$guests = get_post_meta($post_id, 'guesty_accommodates', true);
							$beds = get_post_meta($post_id, 'guesty_bedrooms', true);
							$baths = get_post_meta($post_id, 'guesty_bathrooms', true);
							$city = get_post_meta($post_id, 'guesty_address_city', true);
							$type = get_post_meta($post_id, 'guesty_property_type', true);
						?>
						<article class="guesty-card">
							<div class="card-media">
								<a href="<?php echo get_the_permalink($post_id); ?>">
									<?php echo get_the_post_thumbnail($post_id, 'large' ); ?>
								</a>
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