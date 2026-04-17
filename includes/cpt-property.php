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
 * Supported bed types — aligned with Guesty's bed-type picker
 * Primary types match the main grid; secondary types match the "More bed types" section.
 * Legacy keys (FULL_BED, TWIN_BED, etc.) are kept so existing synced data still renders.
 */
function guesty_get_bed_types() {
    return [
        // ── Primary (Guesty main grid) ──────────────────────────────────────
        'KING_BED'       => 'King Bed',
        'QUEEN_BED'      => 'Queen Bed',
        'DOUBLE_BED'     => 'Double Bed',
        'BUNK_BED'       => 'Bunk Bed',
        'SINGLE_BED'     => 'Single Bed',
        'SOFA_BED'       => 'Sofa Bed',
        // ── More bed types (Guesty secondary section) ───────────────────────
        'CRIB'           => 'Crib',
        'TODDLER_BED'    => 'Toddler Bed',
        'AIR_MATTRESS'   => 'Air Mattress',
        'FLOOR_MATTRESS' => 'Floor Mattress',
        'WATER_BED'      => 'Water Bed',
        // ── Legacy keys — kept so previously synced data still displays ──────
        'FULL_BED'       => 'Full Bed',
        'TWIN_BED'       => 'Twin Bed',
        'FUTON'          => 'Futon',
        'HAMMOCK'        => 'Hammock',
    ];
}

/**
 * Supported room types — mirrors Guesty's Room Type dropdown
 * Rooms typed as BATHROOM_* omit the beds section.
 */
function guesty_get_room_types() {
    return [
        'BEDROOM'        => 'Bedroom',
        'LIVING_ROOM'    => 'Living Room',
        'BATHROOM_FULL'  => 'Full Bathroom',
        'BATHROOM_HALF'  => 'Half Bathroom',
    ];
}

/** Returns true for room types that can have beds */
function guesty_room_type_has_beds( $type ) {
    return in_array( $type, [ 'BEDROOM', 'LIVING_ROOM', '', null ], true );
}

/**
 * Render a single room card (used both in the PHP loop and as the JS clone template).
 * $is_template = true skips image URL lookups (no valid attachment ID in the template).
 */
function guesty_render_bedroom_card( $index, $bedroom, $bed_types, $is_template = false ) {
    $room_types = guesty_get_room_types();
    $room_type  = esc_attr( $bedroom['type'] ?? 'BEDROOM' );
    $name       = esc_attr( $bedroom['name'] ?? '' );
    $beds       = ! empty( $bedroom['beds'] ) ? $bedroom['beds'] : [];
    $image_id   = esc_attr( $bedroom['image_id'] ?? '' );
    $image_url  = ( ! $is_template && $image_id ) ? wp_get_attachment_image_url( $image_id, 'thumbnail' ) : '';
    $has_beds   = $is_template || guesty_room_type_has_beds( $room_type ); // template always shows beds (JS will toggle)
    ?>
    <div class="bedroom-card" data-index="<?php echo esc_attr( $index ); ?>" data-room-type="<?php echo $room_type; ?>">

        <div class="bedroom-card-header">
            <span class="dashicons dashicons-menu bedroom-drag-handle" title="Drag to reorder"></span>
            <input
                type="text"
                name="custom_bedrooms[<?php echo esc_attr( $index ); ?>][name]"
                value="<?php echo $name; ?>"
                class="bedroom-name-input regular-text"
                placeholder="e.g. Master Bedroom"
            >
            <button type="button" class="button button-link-delete bedroom-delete-btn">
                <span class="dashicons dashicons-trash"></span> Remove
            </button>
        </div>

        <div class="bedroom-type-row">
            <label class="bedroom-type-label">Room Type</label>
            <select name="custom_bedrooms[<?php echo esc_attr( $index ); ?>][type]" class="bedroom-type-select">
                <?php foreach ( $room_types as $type_key => $type_label ) : ?>
                    <option value="<?php echo esc_attr( $type_key ); ?>" <?php selected( $bedroom['type'] ?? 'BEDROOM', $type_key ); ?>>
                        <?php echo esc_html( $type_label ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="bedroom-card-body">
            <div class="bedroom-beds-section" <?php echo $has_beds ? '' : 'style="display:none;"'; ?>>
                <p class="label-row"><strong>Beds in this room</strong></p>
                <div class="beds-list">
                    <?php foreach ( $beds as $bed_index => $bed ) : ?>
                    <div class="bed-row" data-bed-index="<?php echo esc_attr( $bed_index ); ?>">
                        <input
                            type="number"
                            name="custom_bedrooms[<?php echo esc_attr( $index ); ?>][beds][<?php echo esc_attr( $bed_index ); ?>][quantity]"
                            value="<?php echo esc_attr( $bed['quantity'] ?? 1 ); ?>"
                            min="1" max="10"
                            class="small-text bed-qty-input"
                        >
                        <select name="custom_bedrooms[<?php echo esc_attr( $index ); ?>][beds][<?php echo esc_attr( $bed_index ); ?>][type]" class="bed-type-select">
                            <?php foreach ( $bed_types as $type_key => $type_label ) : ?>
                                <option value="<?php echo esc_attr( $type_key ); ?>" <?php selected( $bed['type'] ?? '', $type_key ); ?>>
                                    <?php echo esc_html( $type_label ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" class="button-link bed-remove-btn" title="Remove bed type">&times;</button>
                    </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="button button-small add-bed-btn" data-bedroom-index="<?php echo esc_attr( $index ); ?>">
                    + Add Bed Type
                </button>
            </div>

            <div class="bedroom-image-section">
                <p class="label-row"><strong>Room Photo</strong></p>
                <div class="bedroom-image-preview">
                    <?php if ( $image_id && $image_url ) : ?>
                        <img src="<?php echo esc_url( $image_url ); ?>" alt="Room photo">
                    <?php endif; ?>
                </div>
                <input type="hidden" name="custom_bedrooms[<?php echo esc_attr( $index ); ?>][image_id]" class="bedroom-image-id" value="<?php echo $image_id; ?>">
                <div class="bedroom-image-actions">
                    <button type="button" class="button button-small choose-custom-bedroom-image">
                        <?php echo ( $image_id && ! $is_template ) ? 'Change Photo' : 'Choose Photo'; ?>
                    </button>
                    <button type="button" class="button-link remove-custom-bedroom-image"
                        style="<?php echo ( $image_id && ! $is_template ) ? '' : 'display:none;'; ?> color:#a00; margin-left:8px;">
                        Remove Photo
                    </button>
                </div>
            </div>
        </div>

    </div>
    <?php
}

/**
 * Register the Bedroom Manager metabox
 */
add_action( 'add_meta_boxes', function () {
    add_meta_box(
        'guesty_bedroom_manager',
        'Bedroom Manager',
        'render_bedroom_manager_metabox',
        'properties',
        'normal',
        'high'
    );
} );

function render_bedroom_manager_metabox( $post ) {
    $custom_bedrooms = get_post_meta( $post->ID, 'guesty_custom_bedrooms', true );
    $is_locked       = ! empty( $custom_bedrooms ) && is_array( $custom_bedrooms );
    if ( ! is_array( $custom_bedrooms ) ) $custom_bedrooms = [];

    $bed_types = guesty_get_bed_types();

    wp_nonce_field( 'save_custom_bedrooms', 'custom_bedrooms_nonce' );
    ?>
    <div class="bedroom-manager-wrap">

        <div class="bedroom-sync-status <?php echo $is_locked ? 'status-locked' : 'status-pending'; ?>">
            <?php if ( $is_locked ) : ?>
                <span class="dashicons dashicons-lock"></span>
                <strong>Custom Managed</strong> — Bedroom sync from Guesty is paused for this property. All changes here are saved to WordPress only.
                <button type="button" class="button button-small bedroom-reset-btn" style="margin-left:10px;"
                    data-post-id="<?php echo esc_attr( $post->ID ); ?>">
                    Reset from Guesty
                </button>
            <?php else : ?>
                <span class="dashicons dashicons-info"></span>
                <strong>Pending Sync</strong> — Bedroom data will be seeded automatically on the next Guesty sync. You can also add bedrooms manually below to start custom management right now.
            <?php endif; ?>
        </div>

        <div id="bedroom-cards-container">
            <?php foreach ( $custom_bedrooms as $index => $bedroom ) : ?>
                <?php guesty_render_bedroom_card( $index, $bedroom, $bed_types ); ?>
            <?php endforeach; ?>
        </div>

        <div class="bedroom-manager-footer">
            <button type="button" id="add-bedroom-btn" class="button button-primary">
                <span class="dashicons dashicons-plus-alt2" style="vertical-align:middle;"></span> Add Room
            </button>
            <span class="bedroom-count-label">
                Total: <strong id="bedroom-count-display"><?php echo count( $custom_bedrooms ); ?></strong> room(s)
            </span>
        </div>

    </div>

    <?php
    // Hidden JS template — PHP renders this once so JS can clone it without knowing bed types
    ob_start();
    guesty_render_bedroom_card( '__INDEX__', [ 'name' => '', 'beds' => [ [ 'quantity' => 1, 'type' => 'KING_BED' ] ], 'image_id' => '' ], $bed_types, true );
    $card_template = ob_get_clean();
    ?>
    <script type="text/html" id="bedroom-card-template"><?php echo $card_template; ?></script>
    <?php
}

/**
 * Save the Bedroom Manager metabox
 */
add_action( 'save_post_properties', function ( $post_id ) {
    if ( ! isset( $_POST['custom_bedrooms_nonce'] ) || ! wp_verify_nonce( $_POST['custom_bedrooms_nonce'], 'save_custom_bedrooms' ) ) {
        return;
    }
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
    if ( ! current_user_can( 'edit_post', $post_id ) ) return;

    $allowed_bed_types  = array_keys( guesty_get_bed_types() );
    $allowed_room_types = array_keys( guesty_get_room_types() );
    $custom_bedrooms    = [];

    if ( isset( $_POST['custom_bedrooms'] ) && is_array( $_POST['custom_bedrooms'] ) ) {
        foreach ( $_POST['custom_bedrooms'] as $bedroom_raw ) {
            $room_type = sanitize_text_field( $bedroom_raw['type'] ?? 'BEDROOM' );
            if ( ! in_array( $room_type, $allowed_room_types, true ) ) $room_type = 'BEDROOM';

            $name     = sanitize_text_field( $bedroom_raw['name'] ?? '' );
            $image_id = ! empty( $bedroom_raw['image_id'] ) ? absint( $bedroom_raw['image_id'] ) : '';

            $beds = [];
            // Only process beds for room types that can have them
            if ( guesty_room_type_has_beds( $room_type ) && ! empty( $bedroom_raw['beds'] ) && is_array( $bedroom_raw['beds'] ) ) {
                foreach ( $bedroom_raw['beds'] as $bed_raw ) {
                    $qty  = max( 1, min( 10, absint( $bed_raw['quantity'] ?? 1 ) ) );
                    $type = sanitize_text_field( $bed_raw['type'] ?? 'KING_BED' );
                    if ( ! in_array( $type, $allowed_bed_types, true ) ) $type = 'KING_BED';
                    $beds[] = [ 'quantity' => $qty, 'type' => $type ];
                }
            }
            // Rooms with no beds configured are valid (e.g. Guesty "Default bedroom"
            // with all-zero counts). Don't inject a phantom bed entry.

            $custom_bedrooms[] = [
                'type'     => $room_type,
                'name'     => $name,
                'beds'     => $beds,
                'image_id' => $image_id,
            ];
        }
    }

    update_post_meta( $post_id, 'guesty_custom_bedrooms', $custom_bedrooms );

    // Derive guesty_bedrooms (BEDROOM type only) and guesty_beds totals
    $bedroom_count   = 0;
    $total_beds      = 0;
    $bathroom_count  = 0.0;

    foreach ( $custom_bedrooms as $room ) {
        $t = $room['type'] ?? 'BEDROOM';
        if ( $t === 'BEDROOM' ) {
            $bedroom_count++;
            foreach ( ( $room['beds'] ?? [] ) as $bed ) {
                $total_beds += (int) ( $bed['quantity'] ?? 0 );
            }
        } elseif ( $t === 'BATHROOM_FULL' ) {
            $bathroom_count += 1.0;
        } elseif ( $t === 'BATHROOM_HALF' ) {
            $bathroom_count += 0.5;
        }
    }

    update_post_meta( $post_id, 'guesty_bedrooms', $bedroom_count );
    update_post_meta( $post_id, 'guesty_beds', $total_beds );

    // Only override guesty_bathrooms when the admin has explicitly added bathroom-type rooms
    if ( $bathroom_count > 0 ) {
        update_post_meta( $post_id, 'guesty_bathrooms', $bathroom_count );
    }
} );

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