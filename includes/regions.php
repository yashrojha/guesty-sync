<?php
if (!defined('ABSPATH')) exit;

/**
 * Shared region list for this website.
 *
 * Guesty cities are added during sync. Editors can add more from the property
 * screen. The list is not a taxonomy.
 *
 * guesty_address_city remains the Guesty region. It is the only region shown
 * on cards and the property page, and sync may overwrite it.
 *
 * guesty_extra_region (one row per region) is assigned here and is never
 * written by sync.
 */

function guesty_clean_region_name($name) {
	if (is_array($name) || is_object($name)) {
		return '';
	}

	$name = sanitize_text_field((string) $name);
	$name = preg_replace('/\s+/u', ' ', trim($name));
	if (!is_string($name) || $name === '') {
		return '';
	}

	if (function_exists('mb_strlen') && mb_strlen($name, 'UTF-8') > 80) {
		$name = mb_substr($name, 0, 80, 'UTF-8');
	} elseif (strlen($name) > 80) {
		$name = substr($name, 0, 80);
	}

	return trim($name);
}

function guesty_region_catalog_key($name) {
	if (function_exists('mb_strtolower')) {
		return mb_strtolower($name, 'UTF-8');
	}
	return strtolower($name);
}

function guesty_register_region($name) {
	$name = guesty_clean_region_name($name);
	if ($name === '') {
		return '';
	}

	$catalog = get_option('guesty_region_catalog', array());
	if (!is_array($catalog)) {
		$catalog = array();
	}

	$key = guesty_region_catalog_key($name);
	foreach ($catalog as $existing) {
		$existing = guesty_clean_region_name($existing);
		if ($existing !== '' && guesty_region_catalog_key($existing) === $key) {
			return $existing;
		}
	}

	$catalog[] = $name;
	$stored = array();
	foreach ($catalog as $existing) {
		$existing = guesty_clean_region_name($existing);
		if ($existing === '') {
			continue;
		}
		$existing_key = guesty_region_catalog_key($existing);
		if (!isset($stored[$existing_key])) {
			$stored[$existing_key] = $existing;
		}
	}
	natcasesort($stored);
	update_option('guesty_region_catalog', array_values($stored), false);
	guesty_get_region_catalog(true);

	return $name;
}

function guesty_distinct_saved_regions() {
	global $wpdb;

	$values = $wpdb->get_col($wpdb->prepare(
		"SELECT DISTINCT pm.meta_value
		 FROM {$wpdb->postmeta} pm
		 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
		 WHERE pm.meta_key IN (%s, %s)
		   AND p.post_type = %s
		   AND p.post_status = 'publish'
		   AND pm.meta_value != ''",
		'guesty_address_city',
		'guesty_extra_region',
		'properties'
	));

	return is_array($values) ? $values : array();
}

function guesty_get_region_catalog($refresh = false) {
	static $cached = null;
	if ($refresh) {
		$cached = null;
	}
	if (is_array($cached)) {
		return $cached;
	}

	$stored = get_option('guesty_region_catalog', array());
	if (!is_array($stored)) {
		$stored = array();
	}

	$names = array();
	foreach ($stored as $name) {
		$clean = guesty_clean_region_name($name);
		if ($clean === '') {
			continue;
		}
		$key = guesty_region_catalog_key($clean);
		if (!isset($names[$key])) {
			$names[$key] = $clean;
		}
	}

	foreach (guesty_distinct_saved_regions() as $name) {
		$clean = guesty_clean_region_name($name);
		if ($clean === '') {
			continue;
		}
		$key = guesty_region_catalog_key($clean);
		if (!isset($names[$key])) {
			$names[$key] = $clean;
		}
	}

	natcasesort($names);
	$catalog = array_values($names);

	$current = array();
	foreach ($stored as $name) {
		$clean = guesty_clean_region_name($name);
		if ($clean !== '') {
			$current[] = $clean;
		}
	}
	if ($current !== $catalog) {
		update_option('guesty_region_catalog', $catalog, false);
	}

	$cached = $catalog;
	return $cached;
}

function guesty_canonical_region_names($names) {
	$by_key = array();
	foreach (guesty_get_region_catalog() as $region) {
		$by_key[guesty_region_catalog_key($region)] = $region;
	}

	$out = array();
	foreach ((array) $names as $name) {
		$clean = guesty_clean_region_name($name);
		if ($clean === '') {
			continue;
		}
		$key = guesty_region_catalog_key($clean);
		$canonical = isset($by_key[$key]) ? $by_key[$key] : $clean;
		$out[guesty_region_catalog_key($canonical)] = $canonical;
	}

	return array_values($out);
}

function guesty_get_property_extra_regions($post_id) {
	$values = get_post_meta($post_id, 'guesty_extra_region', false);
	if (!is_array($values)) {
		return array();
	}

	$names = array();
	foreach ($values as $value) {
		$clean = guesty_clean_region_name($value);
		if ($clean === '') {
			continue;
		}
		$names[guesty_region_catalog_key($clean)] = $clean;
	}

	natcasesort($names);
	return array_values($names);
}

function guesty_set_property_extra_regions($post_id, $regions, $guesty_city = '') {
	$city_key = guesty_region_catalog_key(guesty_clean_region_name($guesty_city));
	$clean = array();

	foreach ((array) $regions as $region) {
		$name = guesty_register_region($region);
		if ($name === '') {
			continue;
		}
		$key = guesty_region_catalog_key($name);
		if ($city_key !== '' && $key === $city_key) {
			continue;
		}
		$clean[$key] = $name;
	}

	delete_post_meta($post_id, 'guesty_extra_region');
	foreach ($clean as $name) {
		add_post_meta($post_id, 'guesty_extra_region', $name, false);
	}
}

add_action('add_meta_boxes', function () {
	add_meta_box(
		'guesty_region_manager',
		'Regions',
		'guesty_render_region_metabox',
		'properties',
		'side',
		'high'
	);
});

function guesty_render_region_metabox($post) {
	$city = guesty_clean_region_name(get_post_meta($post->ID, 'guesty_address_city', true));
	$city_key = $city === '' ? '' : guesty_region_catalog_key($city);
	$assigned = guesty_get_property_extra_regions($post->ID);
	$assigned_keys = array();
	foreach ($assigned as $region) {
		$assigned_keys[guesty_region_catalog_key($region)] = true;
	}

	wp_nonce_field('guesty_save_regions', 'guesty_regions_nonce');
	?>
	<p class="guesty-region-note">Guesty cities and regions you add share one list. The card and property page show only the Guesty region. Search and widgets include every region assigned here. Regions you add stay on this website through later syncs.</p>
	<div id="guesty-region-list" class="guesty-region-list">
		<?php if ($city !== '') : ?>
			<label class="guesty-region-choice is-guesty" data-region="<?php echo esc_attr($city); ?>">
				<input type="checkbox" checked disabled>
				<span><?php echo esc_html($city); ?></span>
				<em class="guesty-region-source">Guesty</em>
			</label>
		<?php endif; ?>
		<?php foreach (guesty_get_region_catalog() as $region) :
			$key = guesty_region_catalog_key($region);
			if ($city_key !== '' && $key === $city_key) {
				continue;
			}
			?>
			<label class="guesty-region-choice" data-region="<?php echo esc_attr($region); ?>">
				<input type="checkbox" name="guesty_extra_regions[]" value="<?php echo esc_attr($region); ?>" <?php checked(!empty($assigned_keys[$key])); ?>>
				<span><?php echo esc_html($region); ?></span>
			</label>
		<?php endforeach; ?>
	</div>
	<div class="guesty-region-add">
		<input type="text" id="guesty-new-region" name="guesty_new_region" placeholder="New region" maxlength="80" autocomplete="off">
		<button type="button" class="button" id="guesty-add-region">Add</button>
	</div>
	<?php
}

add_action('save_post_properties', function ($post_id) {
	if (!isset($_POST['guesty_regions_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['guesty_regions_nonce'])), 'guesty_save_regions')) {
		return;
	}
	if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
		return;
	}
	if (!current_user_can('edit_post', $post_id)) {
		return;
	}

	$posted = array();
	if (isset($_POST['guesty_extra_regions']) && is_array($_POST['guesty_extra_regions'])) {
		foreach ($_POST['guesty_extra_regions'] as $region) {
			$posted[] = wp_unslash($region);
		}
	}
	if (isset($_POST['guesty_new_region'])) {
		$posted[] = wp_unslash($_POST['guesty_new_region']);
	}

	$city = get_post_meta($post_id, 'guesty_address_city', true);
	guesty_set_property_extra_regions($post_id, $posted, $city);
});
