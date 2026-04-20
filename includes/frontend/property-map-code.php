<?php
if (!defined('ABSPATH')) exit;

// Register the Shortcode [guesty_property_map]
function guesty_map_shortcode_handler()
{
    $api_key = get_option('google_map_api');
    if (empty($api_key)) return 'Please set Google API Key.';

    $map_id = get_option('google_map_id');
    if (empty($map_id)) return 'Please set Google Map ID.';

    wp_enqueue_script(
        'google-maps',
        "https://maps.googleapis.com/maps/api/js?key={$api_key}&libraries=marker&loading=async",
        array(),
        '1.23.3',
        null,
        true
    );
    wp_register_script(
        'guesty-map-js',
        plugin_dir_url(__FILE__) . 'js/map-logic.js',
        array('google-maps'),
        rand(100000, 999999),
        true
    );

    static $defer_map_scripts_added = false;
    if (!$defer_map_scripts_added) {
        add_filter('script_loader_tag', function ($tag, $handle, $src) {
            if (in_array($handle, array('google-maps', 'guesty-map-js'), true)) {
                return str_replace(' src', ' defer src', $tag);
            }
            return $tag;
        }, 10, 3);
        $defer_map_scripts_added = true;
    }

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
            $icon_url = $icon_id ? wp_get_attachment_image_url($icon_id, 'thumbnail') : GUESTY_SYNC_URL . 'assets/no-icon.png';

            // Fetch Multiple Images (Limit to 5): Featured image + first 4 from gallery
            $images = [];

            // 1. Get featured image first
            if (has_post_thumbnail()) {
                $featured_id = get_post_thumbnail_id();
                $featured_url = wp_get_attachment_image_url($featured_id, 'guesty_medium');
                if ($featured_url) {
                    $images[] = $featured_url;
                }
            }

		// 2. Get gallery images from guesty_gallery_ids (excluding featured image)
		$gallery_data = get_post_meta(get_the_ID(), 'guesty_gallery_ids', true);
		
		// Handle both string ("1,2,3") and array formats
		$gallery_ids = is_array($gallery_data) ? $gallery_data : explode(',', $gallery_data);
		$gallery_ids = array_filter($gallery_ids); // Remove empty values
		
		if (!empty($gallery_ids)) {
			$featured_id = has_post_thumbnail() ? get_post_thumbnail_id() : 0;
			$count = 0;
			foreach ($gallery_ids as $id) {
				// Stop after we have 5 total images
				if (count($images) >= 5) {
					break;
				}
				
				$id = intval($id);
				
				// Skip if this is the featured image (already added)
				if ($id === $featured_id) {
					continue;
				}
				
				// Verify it's an actual image attachment
				$attachment = get_post($id);
				if ($attachment && $attachment->post_type === 'attachment') {
					$mime_type = get_post_mime_type($id);
					// Only include actual images (not PDFs, icons stored as attachments, etc.)
					if ($mime_type && strpos($mime_type, 'image/') === 0) {
						$img_url = wp_get_attachment_image_url($id, 'guesty_medium');
						if ($img_url) {
							$images[] = $img_url;
						}
					}
				}
			}
		}
		
		// 3. Fallback: If no images found, add a placeholder to prevent empty carousel
		if (empty($images)) {
			$images[] = GUESTY_SYNC_URL . 'assets/no-icon.png';
		}

		// 4. Get and validate coordinates
		$lat = (float) get_post_meta(get_the_ID(), 'guesty_address_lat', true);
		$lng = (float) get_post_meta(get_the_ID(), 'guesty_address_lng', true);
		
		// Skip properties without valid coordinates
		if (empty($lat) || empty($lng) || ($lat == 0 && $lng == 0)) {
			continue;
		}

            $properties[] = [
                'lat'      => $lat,
                'lng'      => $lng,
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

    wp_localize_script('guesty-map-js', 'guestyData', array(
        'locations' => $properties,
        'mapId'     => $map_id,
    ));
    wp_enqueue_script('guesty-map-js');


    return '<div id="guesty-custom-map" style="width: 100%; height: 800px;"></div>';
}
add_shortcode('guesty_property_map', 'guesty_map_shortcode_handler');
