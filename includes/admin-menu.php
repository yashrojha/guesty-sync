<?php
if (!defined('ABSPATH')) exit;

/**
 * Admin Menu
 */
add_action('admin_menu', function () {
    add_menu_page(
        'Guesty Property Sync',
        'Guesty Sync',
        'manage_options',
        'guesty-sync-settings',
        'guesty_sync_settings_page',
        'dashicons-admin-generic',
        56
    );
    add_submenu_page(
        'guesty-sync-settings',
        'Settings',
        'Settings',
        'manage_options',
        'guesty-sync-settings',
        'guesty_sync_settings_page'
    );
	add_submenu_page(
        'guesty-sync-settings',
        'Widgets',
        'Widgets',
        'manage_options',
        'guesty-sync-widgets',
        'guesty_sync_widgets_page'
    );
    add_submenu_page(
        'guesty-sync-settings',
        'Property Sync',
        'Property Sync',
        'manage_options',
        'guesty-sync-property',
        'guesty_sync_property_page'
    );
    add_submenu_page(
        'guesty-sync-settings',
        'Logs',
        'Logs',
        'manage_options',
        'guesty-sync-logs',
        'guesty_sync_logs_page'
    );
});

/**
 * Settings Page
 */
function guesty_sync_settings_page() {
    // Determine the current tab (default to 'open_api')
    $active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'open_api';
    ?>
    <div class="wrap">
        <h1>Guesty Sync Settings</h1>
        <p>Manage your Open API & Booking Engine API Setting here.</p>
        <h2 class="nav-tab-wrapper">
            <a href="?page=guesty-sync-settings&tab=open_api" 
               class="nav-tab <?php echo $active_tab == 'open_api' ? 'nav-tab-active' : ''; ?>">
               <span class="dashicons dashicons-search"></span> Guesty Open API
            </a>
            <a href="?page=guesty-sync-settings&tab=booking_engine_api" 
               class="nav-tab <?php echo $active_tab == 'booking_engine_api' ? 'nav-tab-active' : ''; ?>">
               <span class="dashicons dashicons-location"></span> Guesty Booking Engine API
            </a>
        </h2>
        <div class="guesty-tab-content" style="padding: 20px; background: #fff; border: 1px solid #ccc; border-top: none;">
            <?php
            if ($active_tab == 'open_api') {
                guesty_render_open_api_settings();
            } else {
                guesty_render_booking_engine_api_settings();
            }
            ?>
        </div>
    </div>
    <?php
}

/**
 * Tab 1: Guesty Open API Settings
 */
function guesty_register_open_api_settings() {
    register_setting('guesty_open_api_settings_group', 'guesty_client_id');
	register_setting('guesty_open_api_settings_group', 'guesty_client_secret_key');
}
add_action('admin_init', 'guesty_register_open_api_settings');
function guesty_render_open_api_settings() {
    $client_id = get_option('guesty_client_id');
    $secret_key = get_option('guesty_client_secret_key');
	$token = get_option('guesty_access_token');
	?>
	<?php if (!empty($client_id) && !empty($secret_key) && empty($token)){ ?>
		<div class="notice notice-error inline">
			<p>API Not Connected!, Please Click Below Check Connection Button.</p>
		</div>
	<?php } ?>
	<?php if (!empty($token)){ ?>
		<div class="notice notice-success inline">
			<p>API Connected Successfully!</p>
		</div>
	<?php } ?>
    <h2>Guesty Open API Connection</h2>
	<form method="post" action="options.php">
        <?php
        // This prints out all hidden setting fields (nonce, etc.)
        settings_fields('guesty_open_api_settings_group');
        do_settings_sections('guesty_open_api_settings_group');
        ?>
        <table class="form-table">
            <tr>
                <th scope="row">Client ID</th>
                <td>
                    <input type="text" class="regular-text" name="guesty_client_id" value="<?php echo esc_attr($client_id); ?>" />
                </td>
            </tr>
			<tr>
                <th scope="row">Client Secret Key</th>
                <td>
                    <input type="password" class="regular-text" name="guesty_client_secret_key" value="<?php echo esc_attr($secret_key); ?>" />
                </td>
            </tr>
        </table>
        <?php submit_button(); ?>
		<button class="button button-secondary" id="guesty-test-connection">
            Check Connection
        </button>
        <p id="guesty-test-result"></p>
    </form>
    <?php
}

/**
 * Tab 2: Guesty Booking Engine API Settings
 */
function guesty_register_booking_engine_api_settings() {
    register_setting('guesty_booking_engine_api_settings_group', 'guesty_be_client_id');
	register_setting('guesty_booking_engine_api_settings_group', 'guesty_be_client_secret_key');
}
add_action('admin_init', 'guesty_register_booking_engine_api_settings');
function guesty_render_booking_engine_api_settings() {
    $client_id_be = get_option('guesty_be_client_id');
    $secret_key_be = get_option('guesty_be_client_secret_key');
	$token_be = get_option('guesty_be_access_token');
	?>
	<?php if (!empty($client_id_be) && !empty($secret_key_be) && empty($token_be)){ ?>
		<div class="notice notice-error inline">
			<p>API Not Connected!, Please Click Below Check Connection Button.</p>
		</div>
	<?php } ?>
	<?php if (!empty($token_be)){ ?>
		<div class="notice notice-success inline">
			<p>API Connected Successfully!</p>
		</div>
	<?php } ?>
    <h2>Guesty Booking Engine API Connection</h2>
	<form method="post" action="options.php">
        <?php
        // This prints out all hidden setting fields (nonce, etc.)
        settings_fields('guesty_booking_engine_api_settings_group');
        do_settings_sections('guesty_booking_engine_api_settings_group');
        ?>
        <table class="form-table">
            <tr>
                <th scope="row">Client ID</th>
                <td>
                    <input type="text" class="regular-text" name="guesty_be_client_id" value="<?php echo esc_attr($client_id_be); ?>" />
                </td>
            </tr>
			<tr>
                <th scope="row">Client Secret Key</th>
                <td>
                    <input type="password" class="regular-text" name="guesty_be_client_secret_key" value="<?php echo esc_attr($secret_key_be); ?>" />
                </td>
            </tr>
        </table>
        <?php submit_button(); ?>
		<button class="button button-secondary" id="guesty-be-test-connection">
            Check Connection
        </button>
        <p id="guesty-be-test-result"></p>
    </form>
    <?php
}

/**
 * Guesty Property Sync Page
 */
function guesty_sync_property_page() {
	$token = get_option('guesty_access_token');
	$guesty_last_sync = get_option('guesty_last_sync');
	$guesty_sync_interval = get_option('guesty_sync_interval');
	$properties = guesty_get_properties();
	$status = guesty_get_cron_status_data();
	$active_limit = ini_get('max_execution_time'); 
	$is_too_low = ($active_limit > 0 && $active_limit < 3600); // 0 means unlimited
    ?>
    <div class="wrap">
		<?php if ($is_too_low): ?>
		<div class="notice notice-error">
			<p>Error: Your server limit (<?php echo $active_limit; ?>s) is too low.<br>
			Please contact your host to increase <strong>max_execution_time : 3600s</strong>.</p>
		</div>
		<?php endif; ?>
        <h1>Guesty Property Sync</h1>
		<?php if (empty($token)){ ?>
			<div class="notice notice-error">
                <p>Please set your <strong>Client ID</strong> and <strong>Client Secret Key</strong> first. <a href="<?php echo admin_url('options-general.php?page=guesty-sync-settings'); ?>">Go to Settings</a></p>
            </div>
		<?php } else { ?>
        <!-- LAST SYNC -->
        <p>
            <strong>Last Sync:</strong>
			<?php echo $guesty_last_sync ? esc_html(wp_date('F j, Y g:i A', $guesty_last_sync)) : 'Never'; ?>
        </p>

        <hr>

        <!-- SCHEDULE INTERVAL -->
        <h2>Auto Schedule Guesty Property Sync</h2>
		<div style="margin-bottom:20px;">
			Last Run:
			<?php echo $status['last']
				? esc_html(wp_date('F j, Y g:i A', $status['last']))
				: 'Never'; ?>
			<br>

			Next Run:
			<?php echo $status['next']
				? esc_html(wp_date('F j, Y g:i A', $status['next']))
				: 'Not scheduled'; ?>
			<br>

			Remaining Time:
			<?php echo $status['remain'] !== false
				? esc_html(guesty_human_time($status['remain']))
				: '—'; ?>
		</div>
        <form method="post">
            <?php wp_nonce_field('guesty_schedule_sync', 'guesty_sync_nonce'); ?>
            <select name="guesty_sync_interval">
				<option value="none" <?php selected($guesty_sync_interval, 'none'); ?>>Never</option>
				<option value="twicedaily" <?php selected($guesty_sync_interval, 'twicedaily'); ?>>twicedaily</option>
				<option value="daily" <?php selected($guesty_sync_interval, 'daily'); ?>>Daily</option>
				<option value="weekly" <?php selected($guesty_sync_interval, 'weekly'); ?>>Weekly</option>
				<option value="fortnightly" <?php selected($guesty_sync_interval, 'fortnightly'); ?>>Every 14 Days</option>
				<option value="monthly" <?php selected($guesty_sync_interval, 'monthly'); ?>>Monthly (30 Days)</option>
			</select>
            <input type="submit" name="guesty_schedule_submit" class="button button-primary" value="Set Schedule" <?php echo $is_too_low ? 'disabled' : ''; ?>>
        </form>

		<hr>

		<h2>Manual Guesty Properties Sync</h2>

        <button class="button button-primary" id="guesty-sync-all" <?php echo $is_too_low ? 'disabled' : ''; ?>>
            Sync ALL Properties
        </button>
		<div id="guesty-progress-wrapper" class="cssProgress" style="display: none;">
        	<div class="progress">
				<div id="cssProgress-bar" class="cssProgress-bar cssProgress-success" data-percent="0" style="width: attr(data-percent %); transition: none;">
					<span id="cssProgress-label" class="cssProgress-label">0%</span>
				</div>
			</div>
		</div>
		<p id="guesty-progress-status"></p>

        <hr>

        <table class="widefat striped guesty-table">
            <thead>
                <tr>
					<th style="width:80px;">Row No.</th>
					<th style="width:200px;">Property Iamge</th>
                    <th>Property Name</th>
                    <th>Guesty ID</th>
					<th>Gallery Image Count</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
				<?php $row = 1; ?>
                <?php foreach ($properties as $property): ?>
                    <tr>
						<td><?php echo esc_html($row); ?></td>
						<td>
							<?php if (!empty($property['picture']['thumbnail'])) : ?>
								<img 
									src="<?php echo esc_url($property['picture']['thumbnail']); ?>" 
									width="60"
									height="60"
									style="object-fit:cover;border-radius:4px;"
								>
							<?php else : ?>
								—
							<?php endif; ?>
						</td>
                        <td><?php echo esc_html($property['title']); ?></td>
                        <td><?php echo esc_html($property['_id']); ?></td>
						<td><?php echo esc_html(count($property['pictures'])); ?></td>
                        <td>
                            <button 
                                class="button guesty-sync-single"
                                data-id="<?php echo esc_attr($property['_id']); ?>">
                                Sync
                            </button>
                        </td>
                    </tr>
				<?php $row++; ?>
                <?php endforeach; ?>
            </tbody>
        </table>
		<?php } ?>
    </div>
    <?php
}
/*guesty_handle_schedule_form*/
add_action('admin_init', 'guesty_handle_schedule_form');
function guesty_handle_schedule_form() {

    if ( ! isset($_POST['guesty_schedule_submit']) ) {
        return;
    }

    if (
        ! isset($_POST['guesty_sync_nonce']) ||
        ! wp_verify_nonce($_POST['guesty_sync_nonce'], 'guesty_schedule_sync')
    ) {
        return;
    }

    if ( ! current_user_can('manage_options') ) {
        return;
    }

    $interval = sanitize_text_field($_POST['guesty_sync_interval']);

    update_option('guesty_sync_interval', $interval);

    // 🔴 IF "Nothing" selected → stop cron
    if ($interval === 'none') {

        update_option('guesty_cron_enabled', 'no');
        guesty_clear_cron();

        add_action('admin_notices', function () {
            echo '<div class="notice notice-warning"><p>Auto sync disabled.</p></div>';
        });

        return;
    }

    // 🟢 Otherwise enable + schedule
    update_option('guesty_cron_enabled', 'yes');
    guesty_schedule_cron();

    add_action('admin_notices', function () {
        echo '<div class="notice notice-success"><p>Schedule updated successfully.</p></div>';
    });
}

/**
 * Guesty Sync Log Page
 */
function guesty_sync_logs_page() {
    global $wpdb;
    $logs = $wpdb->get_results(
        "SELECT * FROM {$wpdb->prefix}guesty_sync_logs ORDER BY id DESC LIMIT 50"
    );

    echo '<div class="wrap"><h1>Sync Logs</h1><table class="widefat">';
    echo '<tr><th>Type</th><th>Message</th><th>Date</th></tr>';

    foreach ($logs as $log) {
        echo "<tr>
            <td>{$log->log_type}</td>
            <td>{$log->message}</td>
            <td>{$log->created_at}</td>
        </tr>";
    }

    echo '</table></div>';
}

/**
 * Guesty Widgets Page
 */
function guesty_sync_widgets_page() {
    // Determine the current tab (default to 'search_bar')
    $active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'search_bar';
    ?>
    <div class="wrap">
        <h1>Guesty Widgets</h1>
        <p>Manage your front-end display widgets here.</p>

        <h2 class="nav-tab-wrapper">
            <a href="?page=guesty-sync-widgets&tab=search_bar" 
               class="nav-tab <?php echo $active_tab == 'search_bar' ? 'nav-tab-active' : ''; ?>">
               <span class="dashicons dashicons-search"></span> Search Bar
            </a>
            <a href="?page=guesty-sync-widgets&tab=property_map" 
               class="nav-tab <?php echo $active_tab == 'property_map' ? 'nav-tab-active' : ''; ?>">
               <span class="dashicons dashicons-location"></span> Property Map
            </a>
			<a href="?page=guesty-sync-widgets&tab=featured_properties" 
               class="nav-tab <?php echo $active_tab == 'featured_properties' ? 'nav-tab-active' : ''; ?>">
               <span class="dashicons dashicons-clipboard"></span> Featured Properties
            </a>
			<a href="?page=guesty-sync-widgets&tab=trending_regions" 
               class="nav-tab <?php echo $active_tab == 'trending_regions' ? 'nav-tab-active' : ''; ?>">
               <span class="dashicons dashicons-chart-pie"></span> Trending Regions
            </a>
        </h2>

        <div class="guesty-tab-content" style="padding: 20px; background: #fff; border: 1px solid #ccc; border-top: none;">
            <?php
            if ($active_tab == 'search_bar') {
                guesty_render_search_bar_settings();
            } else if ($active_tab == 'property_map') {
                guesty_render_property_map_settings();
			} else if ($active_tab == 'featured_properties') {
                guesty_render_featured_properties_settings();
            } else {
                guesty_render_trending_regions_settings();
            }
            ?>
        </div>
    </div>
    <?php
}

/**
 * Tab 1: Search Bar Settings
 */
function guesty_render_search_bar_settings() {
    ?>
    <h3>Search Bar Settings</h3>
	<p><strong>How to use:</strong> Copy the shortcode below and paste it into any page or post where you want the Search Bar to appear.</p>
    <table class="form-table">
        <tr>
            <th scope="row">Shortcode</th>
            <td>
				<code class="guesty-copy-shortcode" data-shortcode="[guesty_search_bar]">
					[guesty_search_bar]
				</code>
				<span class="guesty-copy-msg"></span>
			</td>
        </tr>
    </table>
    <?php
}

/**
 * Tab 2: Property Map Settings
 */
function guesty_register_settings() {
    register_setting('guesty_settings_group', 'google_map_api');
	register_setting('guesty_settings_group', 'google_map_id');
}
add_action('admin_init', 'guesty_register_settings');
function guesty_render_property_map_settings() {
    ?>
    <h3>Property Map Settings</h3>
	<p><strong>How to use:</strong> Copy the shortcode below and paste it into any page or post where you want the Property Map to appear.</p>
	<form method="post" action="options.php">
        <?php
        // This prints out all hidden setting fields (nonce, etc.)
        settings_fields('guesty_settings_group');
        do_settings_sections('guesty_settings_group');
        
        // Retrieve the current value from the database
        $api_key = get_option('google_map_api', '');
		$map_id = get_option('google_map_id', '');
        ?>
        <table class="form-table">
            <tr>
                <th scope="row">Shortcode</th>
				<td>
					<code class="guesty-copy-shortcode" data-shortcode="[guesty_property_map]">
						[guesty_property_map]
					</code>
					<span class="guesty-copy-msg"></span>
				</td>
            </tr>
            <tr>
                <th scope="row">Google Maps API Key</th>
                <td>
                    <input type="text" 
                           class="regular-text" 
                           name="google_map_api" 
                           value="<?php echo esc_attr($api_key); ?>" />
                </td>
            </tr>
			<tr>
                <th scope="row">Google Maps ID</th>
                <td>
                    <input type="text" 
                           class="regular-text" 
                           name="google_map_id" 
                           value="<?php echo esc_attr($map_id); ?>" />
                </td>
            </tr>
        </table>
        <?php submit_button(); ?>
    </form>
    <?php
}

/**
 * Tab 3: Featured Properties Settings
 */
function guesty_render_featured_properties_settings() {
	$all_properties = get_guesty_properties_list();
    
    $saved_property_ids = get_option('guesty_featured_properties', true);
    if (!is_array($saved_property_ids)) $saved_property_ids = [];

    // 3. Filter "Available" to exclude those already selected
    $available_properties = array_diff($all_properties, $saved_property_ids);
    ?>
    <h3>Featured Properties Settings</h3>
	<p><strong>How to use:</strong> Copy the shortcode below and paste it into any page or post where you want the Featured Properties to appear.</p>
    <table class="form-table">
        <tr>
            <th scope="row">Shortcode</th>
			<td>
				<code class="guesty-copy-shortcode" data-shortcode="[guesty_featured_properties]">
					[guesty_featured_properties]
				</code>
				<span class="guesty-copy-msg"></span>
			</td>
        </tr>
		<tr>
            <th scope="row">All Properties</th>
			<td>
				<div class="note">Drag regions from All Properties into Featured Properties to feature them. Reorder the Featured list to control display order.</div>
				<div class="guesty-drag-box">
				  <div class="item-column">
					<h4>All Properties</h4>
					<ul id="all-properties" class="item-sortable-list">
						<?php 
						if ( !empty($available_properties) ) :
							foreach ( $available_properties as $post_id ) : ?>
								<li class="sort-item" data-id="<?php echo esc_attr($post_id); ?>"><span class="dashicons dashicons-menu"></span><?php echo esc_html(get_the_title($post_id)); ?></li>
							<?php endforeach; 
						endif; ?>
					</ul>
				  </div>
				  <div class="item-column">
					<h4>Featured Properties</h4>
					<ul id="featured-properties" class="item-sortable-list highlight">
					  <?php 
						if ( !empty($saved_property_ids) ) :
							foreach ( $saved_property_ids as $post_id ) : ?>
								<li class="sort-item" data-id="<?php echo esc_attr($post_id); ?>"><span class="dashicons dashicons-menu"></span><?php echo esc_html(get_the_title($post_id)); ?></li>
							<?php endforeach; 
						endif; ?>
					</ul>
				  </div>
				</div>
			</td>
        </tr>
    </table>
    <?php
}

/**
 * Tab 4: Trending Regions Settings
 */
function guesty_render_trending_regions_settings() {
	$all_regions = get_guesty_property_cities();
    
    $saved_regions = get_option('guesty_trending_regions', true);
    if (!is_array($saved_regions)) $saved_regions = [];

    // 3. Filter "Available" to exclude those already selected
    $available_regions = array_diff($all_regions, $saved_regions);
    ?>
    <h3>Trending Regions Settings</h3>
	<p><strong>How to use:</strong> Copy the shortcode below and paste it into any page or post where you want the Trending Regions to appear.</p>
    <table class="form-table">
        <tr>
            <th scope="row">Shortcode</th>
			<td>
				<code class="guesty-copy-shortcode" data-shortcode="[guesty_trending_regions]">
					[guesty_trending_regions]
				</code>
				<span class="guesty-copy-msg"></span>
			</td>
        </tr>
		<tr>
            <th scope="row">All Regions</th>
			<td>
				<div class="note">Drag regions from All Regions into Trending Regions to feature them. Reorder the Trending list to control display order.</div>
				<div class="guesty-drag-box">
				  <div class="item-column">
					<h4>All Regions</h4>
					<ul id="all-regions" class="item-sortable-list">
						<?php 
						if ( !empty($available_regions) ) :
							foreach ( $available_regions as $regions ) : ?>
								<li class="sort-item" data-value="<?php echo esc_html($regions); ?>"><span class="dashicons dashicons-menu"></span><?php echo esc_html($regions); ?></li>
							<?php endforeach; 
						endif; ?>
					</ul>
				  </div>
				  <div class="item-column">
					<h4>Trending Regions</h4>
					<ul id="trending-regions" class="item-sortable-list highlight">
					  <?php 
						if ( !empty($saved_regions) ) :
							foreach ( $saved_regions as $regions ) : ?>
								<li class="sort-item" data-value="<?php echo esc_html($regions); ?>"><span class="dashicons dashicons-menu"></span><?php echo esc_html($regions); ?></li>
							<?php endforeach; 
						endif; ?>
					</ul>
				  </div>
				</div>
			</td>
        </tr>
    </table>
    <?php
}