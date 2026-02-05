<?php
if (!defined('ABSPATH')) exit;

/**
 * Register the Shortcode [guesty_property_map]
 */
function guesty_map_shortcode_handler() {
    $api_key = get_option('google_map_api');
    if (empty($api_key)) return 'Please set Google API Key.';
	
	$map_id = get_option('google_map_id');
    if (empty($map_id)) return 'Please set Google Map ID.';

    // 1. Enqueue Google Maps
    wp_enqueue_script('google-maps', "https://maps.googleapis.com/maps/api/js?key={$api_key}&libraries=marker", array(), null, true);
    
    // 2. Register and Enqueue your custom JS file
    wp_register_script('guesty-map-js', plugin_dir_url(__FILE__) . 'js/map-logic.js', array('google-maps'), '1.0', true);

    // 3. Fetch Properties from Custom Post Type
    $args = array(
        'post_type'      => 'properties',
        'posts_per_page' => -1,
    );
    $query = new WP_Query($args);
    $properties = [];

    if ($query->have_posts()) {
        while ($query->have_posts()) {
            $query->the_post();
            
			// Fetch individual meta values
			$guests   = get_post_meta(get_the_ID(), 'guesty_accommodates', true);
			$bedrooms = get_post_meta(get_the_ID(), 'guesty_bedrooms', true);
			$bathrooms = get_post_meta(get_the_ID(), 'guesty_bathrooms', true);

			// Create the combined string
			$specs_array = [];
			if ($guests)   $specs_array[] = $guests . ' GUESTS';
			if ($bedrooms) $specs_array[] = $bedrooms . ' BEDROOMS';
			if ($bathrooms) $specs_array[] = $bathrooms . ' BATHROOMS';

			// Join them with the bullet point separator
			$combined_specs = implode(' • ', $specs_array);
			
			$icon_id  = get_post_meta(get_the_ID(), 'guesty_property_icon_id', true);
			$icon_url = $icon_id ? wp_get_attachment_image_url($icon_id, 'thumbnail') : GUESTY_SYNC_URL .'assets/no-icon.png';
			
			// Fetch Multiple Images (Limit to 4)
			$images = [];
			$attached_media = get_attached_media('image', get_the_ID());
			if ($attached_media) {
				// Only take the first 4 attachments
				$gallery_limit = array_slice($attached_media, 0, 4);
				foreach ($gallery_limit as $attachment) {
					$images[] = wp_get_attachment_image_url($attachment->ID, 'guesty_medium');
				}
			} elseif (has_post_thumbnail()) {
				// Fallback if no gallery exists
				$images[] = get_the_post_thumbnail_url(get_the_ID(), 'guesty_medium');
			}
	
            // Assuming you use ACF or standard Meta for coordinates/details
            $properties[] = [
                'lat'      => (float) get_post_meta(get_the_ID(), 'guesty_address_lat', true),
                'lng'      => (float) get_post_meta(get_the_ID(), 'guesty_address_lng', true),
                'title'    => get_the_title(),
                'region'   => get_post_meta(get_the_ID(), 'guesty_address_city', true),
                'specs'    => $combined_specs,
                'images'    => $images,
                'logo'     => $icon_url,
                'link'     => get_permalink()
            ];
        }
        wp_reset_postdata();
    }

    // 4. Pass data to JS
    wp_localize_script('guesty-map-js', 'guestyData', array(
        'locations' => $properties
    ));
    wp_enqueue_script('guesty-map-js');
	

    return '<div id="guesty-custom-map" style="width: 100%; height: 800px;"></div>';
}
add_shortcode('guesty_property_map', 'guesty_map_shortcode_handler');
