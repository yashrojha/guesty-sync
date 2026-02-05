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
 * Upsert Function Properties (Detect Updated / Skipped)
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
	update_post_meta($post_id, 'guesty_bedrooms', $property['bedrooms']);
	update_post_meta($post_id, 'guesty_beds', $property['beds']);
	update_post_meta($post_id, 'guesty_address_full', $property['address']['full']);
	update_post_meta($post_id, 'guesty_address_city', $property['address']['city']);
	update_post_meta($post_id, 'guesty_address_state', $property['address']['state']);
	update_post_meta($post_id, 'guesty_address_country', $property['address']['country']);
	update_post_meta($post_id, 'guesty_address_zipcode', $property['address']['zipcode']);
	update_post_meta($post_id, 'guesty_address_lat', $property['address']['lat']);
	update_post_meta($post_id, 'guesty_address_lng', $property['address']['lng']);
	update_post_meta($post_id, 'guesty_description_space', $property['publicDescription']['summary']);
	update_post_meta($post_id, 'guesty_description_access', $property['publicDescription']['access']);
	update_post_meta($post_id, 'guesty_description_neighborhood', $property['publicDescription']['neighborhood']);
	update_post_meta($post_id, 'guesty_description_notes', $property['publicDescription']['notes']);
	update_post_meta($post_id, 'guesty_amenities', $property['amenities']);
	update_post_meta($post_id, 'guesty_minNights', $property['terms']['minNights']);
	update_post_meta($post_id, 'guesty_maxNights', $property['terms']['maxNights']);
	update_post_meta($post_id, 'guesty_listing_rooms', $property['listingRooms']);
	
	return $post_id;
}