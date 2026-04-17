<?php
if (!defined('ABSPATH')) exit;

// Start sync lock
function guesty_start_sync_lock($mode = 'single', $property = null, $property_total = 0, $image_total = 0) {
    update_option('guesty_sync_lock', time());
    guesty_set_sync_ui([
        'running'          => true,
        'mode'             => $mode,
        'property'         => $property,
        'status'           => 'Starting...',
        'property_current' => 0,
        'property_total'   => $property_total,
        'image_current'    => 0,
        'image_total'      => $image_total,
        'updated'          => time(),
    ]);
}
// End sync lock
function guesty_end_sync_lock() {
    delete_option('guesty_sync_lock');
    guesty_clear_sync_ui();
}
// Set/update sync UI
function guesty_set_sync_ui($data) {
    $ui = get_option('guesty_sync_ui', []);
    update_option('guesty_sync_ui', array_merge($ui, $data));
}
// Clear sync UI
function guesty_clear_sync_ui() {
    delete_option('guesty_sync_ui');
}
// Get sync UI
function guesty_get_sync_ui() {
    return get_option('guesty_sync_ui', [
        'running'          => false,
        'mode'             => null,
        'property'         => null,
        'status'           => '',
        'property_current' => 0,
        'property_total'   => 0,
        'image_current'    => 0,
        'image_total'      => 0,
        'updated'          => time(),
    ]);
}

/**
 * Convert Guesty room beds data to our internal [{quantity, type}] format.
 *
 * Guesty's Spaces API uses a flat object for beds:
 *   "beds":  { "KING_BED": 1, "QUEEN_BED": 0, ... }
 *   "other": { "FLOOR_MATTRESS": 0, "CRIB": 1, ... }
 *
 * The legacy listing API may return an array:
 *   "beds": [ { "quantity": 1, "type": "KING_BED" }, ... ]
 *
 * We normalise both to [{quantity: N, type: "KEY"}], omitting zero-count entries.
 */
function guesty_normalize_room_beds( $room ) {
	$beds_raw = $room['beds'] ?? [];
	$normalized = [];

	if ( empty($beds_raw) ) return $normalized;

	$first = reset($beds_raw);

	if ( is_array($first) && isset($first['quantity']) ) {
		// Legacy array format: [{quantity: X, type: "BED_TYPE"}, ...]
		foreach ($beds_raw as $bed) {
			$qty = (int) ($bed['quantity'] ?? 0);
			if ($qty > 0) {
				$normalized[] = ['quantity' => $qty, 'type' => (string) $bed['type']];
			}
		}
	} else {
		// New flat format: {KING_BED: 1, QUEEN_BED: 0, ...}
		// Merge primary beds + "other" object (CRIB, WATER_BED, etc.)
		$all = array_merge($beds_raw, $room['other'] ?? []);
		foreach ($all as $bed_type => $count) {
			$count = (int) $count;
			if ($count > 0) {
				$normalized[] = ['quantity' => $count, 'type' => (string) $bed_type];
			}
		}
	}

	return $normalized;
}

/**
 * Upsert a property from Guesty data (detect updated / skipped)
 */
function guesty_upsert_property($property) {

    $existing = get_posts([
        'post_type'  => 'properties',
        'meta_key'   => 'guesty_id',
        'meta_value' => $property['_id'],
        'fields'     => 'ids',
    ]);
	
    $post_id = $existing ? $existing[0] : 0;

    $post_data = [
        'post_type'   => 'properties',
        'post_title'  => sanitize_text_field($property['title']),
        'post_status' => 'publish',
        'post_content'=> wp_kses_post($property['publicDescription']['summary'] ?? ''),
        'ID'          => $post_id,
    ];

    $post_id = wp_insert_post($post_data);

	if (is_wp_error($post_id)) {
        return;
    }
	
	
	update_post_meta($post_id, 'guesty_sync_status', 'running');
	
	// ---------------- META ----------------
    update_post_meta($post_id, 'guesty_id', $property['_id']);
    update_post_meta($post_id, 'guesty_price', $property['prices']['basePrice']);
	update_post_meta($post_id, 'guesty_property_type', $property['propertyType']);
	update_post_meta($post_id, 'guesty_accommodates', $property['accommodates']);
	update_post_meta($post_id, 'guesty_bathrooms', $property['bathrooms']);
	update_post_meta($post_id, 'guesty_address_full', $property['address']['full']);
	update_post_meta($post_id, 'guesty_address_city', $property['address']['city']);
	update_post_meta($post_id, 'guesty_address_state', $property['address']['state']);
	update_post_meta($post_id, 'guesty_address_country', $property['address']['country']);
	update_post_meta($post_id, 'guesty_address_zipcode', $property['address']['zipcode']);
	update_post_meta($post_id, 'guesty_address_lat', $property['address']['lat']);
	update_post_meta($post_id, 'guesty_address_lng', $property['address']['lng']);
	update_post_meta($post_id, 'guesty_description_space', $property['publicDescription']['space']);
	update_post_meta($post_id, 'guesty_description_access', $property['publicDescription']['access']);
	update_post_meta($post_id, 'guesty_description_neighborhood', $property['publicDescription']['neighborhood']);
	update_post_meta($post_id, 'guesty_description_notes', $property['publicDescription']['notes']);
	update_post_meta($post_id, 'guesty_amenities', $property['amenities']);
	update_post_meta($post_id, 'guesty_minNights', $property['terms']['minNights']);
	update_post_meta($post_id, 'guesty_maxNights', $property['terms']['maxNights']);

	// ---------------- BEDROOM SYNC (locked once custom bedrooms are set) ----------------
	$custom_bedrooms_locked = !empty(get_post_meta($post_id, 'guesty_custom_bedrooms', true));

	if (!$custom_bedrooms_locked) {
		update_post_meta($post_id, 'guesty_bedrooms', $property['bedrooms']);
		update_post_meta($post_id, 'guesty_beds', $property['beds']);
		update_post_meta($post_id, 'guesty_listing_rooms', $property['listingRooms']);

		// -------------------------------------------------------------------
		// Data source priority for room/bed configuration:
		//   1. Spaces API  – GET /properties/spaces/unit-type/{id}
		//      The authoritative source. Each space carries: name, type
		//      (BEDROOM / SHARED_SPACE / FULL_BATHROOM / HALF_BATHROOM),
		//      beds {KING_BED: N, …} and other {CRIB: N, …}.
		//   2. bedArrangements.bedrooms from the listing response
		//      Same flat-object format as the Spaces API.
		//   3. listingRooms from the listing response (legacy)
		//      Array of {roomNumber, beds: [{quantity, type}]};
		//      often MISSING name and type fields.
		// -------------------------------------------------------------------
		$spaces_rooms = guesty_get_property_spaces( $property['_id'] );

		if ( empty( $spaces_rooms ) && ! empty( $property['bedArrangements']['bedrooms'] ) && is_array( $property['bedArrangements']['bedrooms'] ) ) {
			$spaces_rooms = $property['bedArrangements']['bedrooms'];
		}

		$listing_rooms = ! empty( $spaces_rooms )
			? $spaces_rooms
			: ( is_array( $property['listingRooms'] ) ? $property['listingRooms'] : [] );

		if ( ! empty( $listing_rooms ) ) {
			$existing_images = get_post_meta( $post_id, 'guesty_listing_rooms_data', true );
			if ( ! is_array( $existing_images ) ) $existing_images = [];

			$room_type_map = [
				'BEDROOM'        => 'BEDROOM',
				'SHARED_SPACE'   => 'LIVING_ROOM',
				'LIVING_ROOM'    => 'LIVING_ROOM',
				'FULL_BATHROOM'  => 'BATHROOM_FULL',
				'HALF_BATHROOM'  => 'BATHROOM_HALF',
				'BATHROOM'       => 'BATHROOM_FULL',
				'FULL_BATH'      => 'BATHROOM_FULL',
				'HALF_BATH'      => 'BATHROOM_HALF',
			];

			$custom_bedrooms = [];
			$bedroom_seq     = 0;
			$bathroom_seq    = 0;

			foreach ( $listing_rooms as $index => $room ) {
				$room_number = isset( $room['roomNumber'] ) ? (int) $room['roomNumber'] : $index;
				$image_id    = isset( $existing_images[ $room_number ]['image_id'] )
					? (int) $existing_images[ $room_number ]['image_id']
					: '';

				$guesty_type = strtoupper( $room['type'] ?? 'BEDROOM' );
				$room_type   = $room_type_map[ $guesty_type ] ?? 'BEDROOM';

				if ( $room_type === 'BEDROOM' ) {
					$bedroom_seq++;
					$default_name = 'Bedroom ' . $bedroom_seq;
				} elseif ( $room_type === 'BATHROOM_FULL' || $room_type === 'BATHROOM_HALF' ) {
					$bathroom_seq++;
					$default_name = ( $room_type === 'BATHROOM_FULL' ? 'Full' : 'Half' ) . ' Bathroom ' . $bathroom_seq;
				} else {
					$default_name = 'Living Room';
				}

				$custom_bedrooms[] = [
					'type'     => $room_type,
					'name'     => ! empty( $room['name'] ) ? $room['name'] : $default_name,
					'beds'     => guesty_normalize_room_beds( $room ),
					'image_id' => $image_id,
				];
			}
			update_post_meta( $post_id, 'guesty_custom_bedrooms', $custom_bedrooms );
		}
	}
	// When locked, bedroom fields are managed exclusively through the WordPress admin.
	
	return $post_id;
}