<?php
if (!defined('ABSPATH')) exit;
/**
 * Create Custom CPT for Properties
 */
add_action('init', function () {
    register_post_type('properties', [
        'labels' => [
            'name'          => 'Properties',
            'singular_name' => 'Property',
        ],
        'public'      => true,
        'show_ui'     => true,
		'has_archive'   => true,
		'map_meta_cap' => true,
		'capabilities' => [
			//'create_posts' => false,
			//'delete_post'           => 'do_not_allow',
			//'delete_posts'          => 'do_not_allow',
			//'delete_private_posts'  => 'do_not_allow',
			//'delete_published_posts'=> 'do_not_allow',
			//'delete_others_posts'   => 'do_not_allow',
		],
        'menu_icon'   => 'dashicons-building',
        'supports'    => ['title', 'editor', 'thumbnail'],
        'show_in_menu'=> 'guesty-sync-settings'
    ]);
});

/**
 * Register the metabox for listing rooms
 */
add_action('add_meta_boxes', function () {
	add_meta_box(
        'listing_rooms_meta',
        'Listing Rooms Details',
        'render_listing_rooms_metabox',
        'properties',
        'normal',
        'high'
    );
});
function render_listing_rooms_metabox($post) {
    $listing_rooms = get_post_meta($post->ID, 'guesty_listing_rooms', true);
	// Get your MANUALLY SAVED images (The source of truth for photos)
	$saved_images = get_post_meta($post->ID, 'guesty_listing_rooms_data', true);
    if (!is_array($listing_rooms)) $listing_rooms = [];
    wp_nonce_field('save_listing_rooms', 'listing_rooms_nonce');
    ?>
    <div class="room-items-container">
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
            <div class="room-card <?php echo $disabled_class; ?>">
                <h4>Bedroom <?php echo $room_data['roomNumber'] + 1; ?></h4>
                <p class="bed-info"><?php echo esc_html($bed_display_text); ?></p>
                
                <?php if(!$is_disabled) : ?>
                    <p><strong>Room Image:</strong></p>
                    <div class="image-preview">
                        <?php if (!empty($image_id)) echo wp_get_attachment_image($image_id, 'thumbnail'); ?>
                    </div>
                    
                    <input type="hidden" class="room-image-id" name="rooms[<?php echo $room_data['roomNumber']; ?>][image_id]" value="<?php echo esc_attr($image_id); ?>">
                    
                    <div class="image-actions">
                        <button type="button" class="button choose-bedroom-image">
                            <?php echo $image_id ? 'Change Image' : 'Choose Bedroom Image'; ?>
                        </button>
                        <button type="button" class="button remove-bedroom-image" style="<?php echo !$image_id ? 'display:none;' : ''; ?> color: #b32d2e;">
                            Remove
                        </button>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
    <?php
}
/**
 * Save bedroom image selections to post meta
 */
add_action('save_post', function ($post_id) {
    if (!isset($_POST['listing_rooms_nonce']) || !wp_verify_nonce($_POST['listing_rooms_nonce'], 'save_listing_rooms')) {
        return;
    }
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }
    if (!current_user_can('edit_post', $post_id)) {
        return;
    }
    // Sanitize and Save the Data
    if (isset($_POST['rooms']) && is_array($_POST['rooms'])) {
        $meta_to_save = [];
        foreach ($_POST['rooms'] as $index => $room_data) {
            // We save the room index and the WordPress Image ID
            $meta_to_save[$index] = [
                'roomNumber' => (int)$index,
                'image_id'   => !empty($room_data['image_id']) ? absint($room_data['image_id']) : ''
            ];
        }

        // Save the array. WordPress serializes this automatically.
        update_post_meta($post_id, 'guesty_listing_rooms_data', $meta_to_save);
    } else {
        delete_post_meta($post_id, 'guesty_listing_rooms_data');
    }
});

/**
 * Register the metabox for Top Amenities Selection
 */
add_action('add_meta_boxes', function () {
    add_meta_box(
        'top_amenities_box',
        'Top Amenities Selection',
        'render_top_amenities_metabox',
        'properties',
        'normal',
        'high'
    );
});
function render_top_amenities_metabox($post) {
	// 1. Define all possible amenities
    $all_amenities = get_post_meta($post->ID, 'guesty_amenities', true);
    
	// 2. Get currently saved amenities
    $selected_amenities = get_post_meta($post->ID, 'guesty_top_amenities', true);
    if (!is_array($selected_amenities)) $selected_amenities = [];

    // 3. Filter "Available" to exclude those already selected
    $available_amenities = array_diff($all_amenities, $selected_amenities);
	
	wp_nonce_field('amenities_save_data', 'amenities_meta_nonce');
    ?>
	<div class="note">Drag Amenities from Available Amenities into Selected Amenities (Top) to feature them. Reorder the Selected Amenities (Top) list to control display order.</div>
	<div class="guesty-drag-box">
	  <div class="item-column">
		<h4>Available Amenities</h4>
		<ul id="available-amenities" class="amenities-list item-sortable-list">
			<?php
			if ( !empty($available_amenities) ) :
				foreach ( $available_amenities as $amenity ) : ?>
					<li class="sort-item" data-id="<?php echo esc_attr($amenity); ?>"><span class="dashicons dashicons-menu"></span><?php echo esc_html($amenity); ?></li>
				<?php endforeach; 
			endif; ?>
		</ul>
	  </div>
	  <div class="item-column">
		<h4>Selected Amenities (Top)</h4>
		<ul id="selected-amenities" class="amenities-list item-sortable-list highlight">
		  <?php
			if ( !empty($selected_amenities) ) :
				foreach ( $selected_amenities as $amenity ) : ?>
					<li class="sort-item" data-id="<?php echo esc_attr($amenity); ?>"><span class="dashicons dashicons-menu"></span><?php echo esc_html($amenity); ?></li>
				<?php endforeach; 
			endif; ?>
		</ul>
	  </div>
	</div>
	<input type="hidden" name="top_amenities_ordered" id="top_amenities_ordered" value="<?php echo implode(',', $selected_amenities); ?>">
    <?php
}
add_action('save_post', function ($post_id) {
    if (!isset($_POST['amenities_meta_nonce']) || !wp_verify_nonce($_POST['amenities_meta_nonce'], 'amenities_save_data')) return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;

    if (isset($_POST['top_amenities_ordered'])) {
        $data = sanitize_text_field($_POST['top_amenities_ordered']);
        $amenity_array = array_filter(explode(',', $data));
        update_post_meta($post_id, 'guesty_top_amenities', $amenity_array);
    }
});

/**
 * Register the metabox for Property Gallery show
 */
add_action('add_meta_boxes', function () {
    add_meta_box(
        'property_gallery',
        'Property Gallery - <span class="gallery-count"></span>',
        'render_property_gallery_box',
        'properties',
        'normal',
        'high'
    );
});
function render_property_gallery_box($post) {
    $image_ids = (array) get_post_meta($post->ID, 'guesty_gallery_ids', true);
    $count = count(array_filter($image_ids));
    ?>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
			const box = document.getElementById('property_gallery');
            if (!box) return;
            const counter = box.querySelector('.gallery-count');
            if (counter) counter.textContent = '(<?php echo $count; ?>)';
        });
    </script>
    <div id="property-gallery-wrapper">
        <ul class="property-gallery-list">
            <?php foreach ($image_ids as $id): ?>
                <li>
                    <?php echo wp_get_attachment_image($id, 'thumbnail'); ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php
}

/**
 * Register the metabox for Property Details show
 */
add_action('add_meta_boxes', function () {
    add_meta_box(
        'property_readonly_meta',
        'Property Details (Read Only)',
        'render_property_readonly_meta',
        'properties',
        'normal',
        'high'
    );
});
function guesty_property_fields() {
    return [
        'guesty_id'               => 'Guesty ID',
        'guesty_price'            => 'Base Price',
        'guesty_property_type'    => 'Property Type',
        'guesty_accommodates'     => 'Accommodates',
        'guesty_bathrooms'        => 'Bathrooms',
        'guesty_bedrooms'         => 'Bedrooms',
        'guesty_beds'             => 'Beds',
        'guesty_address_full'     => 'Full Address',
        'guesty_address_city'     => 'City',
        'guesty_address_state'    => 'State',
        'guesty_address_country'  => 'Country',
        'guesty_address_zipcode'  => 'Zip Code',
        'guesty_address_lat'      => 'Latitude',
        'guesty_address_lng'      => 'Longitude',
		'guesty_description_space' => 'Description Space',
		'guesty_description_access' => 'Description Access',
		'guesty_description_neighborhood' => 'Description Neighborhood',
		'guesty_description_notes' => 'Description Notes',
		'guesty_amenities'        => 'Amenities',
		'guesty_minNights'        => 'minNights',
		'guesty_maxNights'        => 'maxNights',
    ];
}
function render_property_readonly_meta($post) {
    $fields = guesty_property_fields();
    echo '<table class="widefat striped">';
    foreach ($fields as $key => $label) {
        $value = get_post_meta($post->ID, $key, true);
        echo '<tr>';
        echo '<th style="width:220px;">' . esc_html($label) . '</th>';
        echo '<td>';
        if (empty($value)) {
            echo '—';
        } elseif (is_array($value)) {
            echo '<ul class="' . esc_attr($key) . '">';
            foreach ($value as $item) {
                echo '<li>' . esc_html($item) . '</li>';
            }
            echo '</ul>';
        } else {
            echo esc_html($value);
        }
        echo '</td>';
        echo '</tr>';
    }
    echo '</table>';
}

/**
 * Register the metabox for Properties icon
 */
add_action('add_meta_boxes', function () {
    add_meta_box(
        'guesty_property_icon',
        'Property Icon',
        'guesty_property_icon_metabox',
        'properties',
        'side',
        'low'
    );
});
function guesty_property_icon_metabox($post) {
    $icon_id  = get_post_meta($post->ID, 'guesty_property_icon_id', true);
    $icon_url = $icon_id ? wp_get_attachment_image_url($icon_id, 'thumbnail') : '';
    wp_nonce_field('guesty_property_icon_nonce', 'guesty_property_icon_nonce');
    ?>
    <div id="guesty-icon-wrapper" style="text-align: center;">
        <div id="guesty-icon-preview" style="margin-bottom:10px; min-height: 50px; border: 1px dashed #ccc; padding: 10px;">
            <?php if ($icon_url): ?>
                <img src="<?php echo esc_url($icon_url); ?>" style="max-width:100%; height:auto; display:block; margin:0 auto;" />
            <?php else: ?>
                <span style="color:#999;">No Icon Selected</span>
            <?php endif; ?>
        </div>
        <input type="hidden" id="guesty_property_icon_id" name="guesty_property_icon_id" value="<?php echo esc_attr($icon_id); ?>" />  
        <p>
            <button type="button" class="button" id="guesty-icon-upload">
                <?php echo $icon_id ? 'Change Icon' : 'Set Property Icon'; ?>
            </button>
        </p>
        <p>
            <a href="#" class="submitdelete deletion" id="guesty-icon-remove" 
               style="<?php echo $icon_id ? '' : 'display:none;'; ?>">
                Remove Icon
            </a>
        </p>
    </div>
    <?php
}
/*Save the Icon ID - IMPORTANT: Hook name must be save_post_{post_type}*/
add_action('save_post_properties', function ($post_id) {
    if (!isset($_POST['guesty_property_icon_nonce']) || !wp_verify_nonce($_POST['guesty_property_icon_nonce'], 'guesty_property_icon_nonce')) {
        return;
    }

    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;

    if (isset($_POST['guesty_property_icon_id'])) {
        $icon_id = sanitize_text_field($_POST['guesty_property_icon_id']);
        if ($icon_id === '') {
            delete_post_meta($post_id, 'guesty_property_icon_id');
        } else {
            update_post_meta($post_id, 'guesty_property_icon_id', intval($icon_id));
        }
    }
});

/**
 * Register the metabox for Properties Floor Plan PDF Metabox
 */
add_action('add_meta_boxes', function () {
    add_meta_box(
        'guesty_property_floor_plan',
        'Property Floor Plan (PDF)',
        'guesty_property_floor_plan_metabox',
        'properties',
        'side',
        'low'
    );
});
function guesty_property_floor_plan_metabox($post) {
    $pdf_id  = get_post_meta($post->ID, 'guesty_floor_plan_id', true);
    $pdf_url = $pdf_id ? wp_get_attachment_url($pdf_id) : '';
    $pdf_name = $pdf_id ? basename($pdf_url) : 'No file selected';

    wp_nonce_field('guesty_floor_plan_nonce', 'guesty_floor_plan_nonce');
    ?>
    <div id="guesty-floor-plan-wrapper">
        <div id="pdf-preview-info" style="margin-bottom:10px; font-size:12px; color:#555;">
            <strong>Current File:</strong> <br>
            <span id="pdf-filename"><?php echo esc_html($pdf_name); ?></span>
        </div>
        
        <input type="hidden" id="guesty_floor_plan_id" name="guesty_floor_plan_id" value="<?php echo esc_attr($pdf_id); ?>" />
        
        <button type="button" class="button" id="guesty-pdf-upload">
            <?php echo $pdf_id ? 'Change PDF' : 'Upload PDF Plan'; ?>
        </button>
        
        <p>
            <a href="#" class="submitdelete deletion" id="guesty-pdf-remove" 
               style="<?php echo $pdf_id ? '' : 'display:none;'; ?> color: #a00;">
                Remove PDF
            </a>
        </p>
    </div>
    <?php
}
add_action('save_post_properties', function ($post_id) {
    if (!isset($_POST['guesty_floor_plan_nonce']) || !wp_verify_nonce($_POST['guesty_floor_plan_nonce'], 'guesty_floor_plan_nonce')) {
        return;
    }

    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;

    if (isset($_POST['guesty_floor_plan_id'])) {
        $pdf_id = sanitize_text_field($_POST['guesty_floor_plan_id']);
        if ($pdf_id === '') {
            delete_post_meta($post_id, 'guesty_floor_plan_id');
        } else {
            update_post_meta($post_id, 'guesty_floor_plan_id', intval($pdf_id));
        }
    }
});

 /**
 * Add and Reorder Multiple Columns for Properties
 */
add_filter('manage_properties_posts_columns', function ($columns) {
    // Define the new order manually
    $new_columns = [
        'cb'            => $columns['cb'],
		'thumbnail'     => 'Property Image',
        'guesty_icon'    => 'Property Icon',
        'title'         => $columns['title'],
		'floor_plan'    => 'Property Floor Plan PDF',
		'region'        => 'Property Region',
        'sync_status'   => 'Sync Status',
        'date'          => $columns['date'],
    ];
    return array_merge($new_columns, $columns);
}, 20); 
 /**
 * Handle data for all custom columns in one place
 */
add_action('manage_properties_posts_custom_column', function ($column, $post_id) {
    switch ($column) {
        case 'thumbnail':
            if (has_post_thumbnail($post_id)) {
                echo get_the_post_thumbnail($post_id, [60, 60], [
                    'style' => 'border-radius:4px;display:block;'
                ]);
            } else {
                echo '<span style="color:#ccc;">No Image</span>';
            }
            break;
			
		case 'guesty_icon':
            $icon_id = get_post_meta($post_id, 'guesty_property_icon_id', true);
            if ($icon_id) {
                echo wp_get_attachment_image($icon_id, [50, 50], false, [
                    'style' => 'border-radius:50%;display:block;border: 3px solid #fff;'
                ]);
            } else {
                echo '<span style="color:#ccc;">No Icon</span>';
            }
            break;
			
		case 'floor_plan':
            $pdf_id = get_post_meta($post_id, 'guesty_floor_plan_id', true);
            if ($pdf_id) {
                $pdf_url = wp_get_attachment_url($pdf_id);
                echo '<a href="' . esc_url($pdf_url) . '" class="button button-small" target="_blank">';
                echo '<span class="dashicons dashicons-pdf" style="vertical-align:middle; font-size:16px;"></span> PDF';
                echo '</a>';
            } else {
                echo '<span style="color:#ccc;">Not Set PDF</span>';
            }
            break;
			
		case 'region':
            $status = get_post_meta($post_id, 'guesty_address_city', true);
            echo '<strong style="text-transform: uppercase;">' . esc_html($status ?: 'Nothing') . '</strong>';
            break;
			
        case 'sync_status':
            $status = get_post_meta($post_id, 'guesty_sync_status', true);
            $color = ($status === 'Completed') ? '#46b450' : '#ffb900';
            echo '<strong style="color:' . $color . ';">' . esc_html($status ?: 'Pending') . '</strong>';
            break;
    }
}, 10, 2);

/**
 * Get the maximum occupancy value from all 'properties'
 * * @return int The highest value of 'guesty_accommodates'
 */
function get_max_property_occupancy() {
    global $wpdb;

    // We use $wpdb->get_var to get a single value directly from the database
    $max_value = $wpdb->get_var( $wpdb->prepare(
        "SELECT MAX(CAST(meta_value AS UNSIGNED)) 
         FROM {$wpdb->postmeta} pm
         LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id
         WHERE pm.meta_key = %s 
         AND p.post_type = %s 
         AND p.post_status = 'publish'",
        'guesty_accommodates',
        'properties'
    ) );

    // Return the value as an integer, default to 1 if nothing found
    return $max_value ? (int) $max_value : 1;
}

/**
 * Get a unique, sorted list of cities from the 'properties' post type
 * @return array List of unique city names
 */
function get_guesty_property_cities() {
    global $wpdb;

    $cities = $wpdb->get_col( $wpdb->prepare(
        "SELECT DISTINCT pm.meta_value 
         FROM {$wpdb->postmeta} pm
         LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id
         WHERE pm.meta_key = %s 
         AND p.post_type = %s 
         AND p.post_status = 'publish'
         AND pm.meta_value != ''
         ORDER BY pm.meta_value ASC",
        'guesty_address_city',
        'properties'
    ));

    return $cities;
}

/**
 * Get all 'properties' post type
 * @return array List of post id
 */
function get_guesty_properties_list() {
  return get_posts([
    'post_type'      => 'properties',
    'post_status'    => 'publish',
    'posts_per_page' => -1,
    'orderby'        => 'title',
    'order'          => 'ASC',
    'fields'         => 'ids', // faster
  ]);
}

/**
 * Get a WP Property id based on guesty_id
 * @return array List of WP Property id
 */
function get_wp_ids_from_guesty_ids($guesty_ids) {
    global $wpdb;
    
    // Find WordPress posts where 'guesty_id' meta matches the API IDs
    $placeholders = implode(',', array_fill(0, count($guesty_ids), '%s'));
    $query = $wpdb->prepare("
        SELECT post_id FROM {$wpdb->postmeta} 
        WHERE meta_key = 'guesty_id' 
        AND meta_value IN ($placeholders)", 
        $guesty_ids
    );

    $ids = $wpdb->get_col($query);
    return !empty($ids) ? $ids : array(0);
}

/**
 * Property Delete Then Releted images delete in media
 */
add_action('before_delete_post', 'guesty_delete_cpt_attachments');
function guesty_delete_cpt_attachments($post_id) {

    // Only your CPT
    if (get_post_type($post_id) !== 'properties') {
        return;
    }

    // Get all attachments
    $attachments = get_attached_media('', $post_id);

    if (!$attachments) return;

    foreach ($attachments as $attachment) {
        wp_delete_attachment($attachment->ID, true);
    }
}

/**
 * Admin Single Property page Hide options publush status & date
 */
add_action('admin_head', function () {
    $screen = get_current_screen();
    if ($screen && $screen->post_type === 'properties') { // 🔁 your CPT slug
        echo '<style>
            #misc-publishing-actions,
            #minor-publishing-actions,
			#remove-post-thumbnail {
                display: none;
            }
        </style>';
    }
});

/**
 * Hide Quick Edit and Trash links from the Properties list table
 */
add_filter('post_row_actions', function ($actions, $post) {
    if ($post->post_type === 'properties') {
        // Hide 'Quick Edit'
        unset($actions['inline hide-if-no-js']);
        // Hide 'Trash' (since you already restricted deletion capabilities)
        unset($actions['trash']);
    }
    return $actions;
}, 10, 2);