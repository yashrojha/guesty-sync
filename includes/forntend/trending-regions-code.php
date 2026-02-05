<?php
if (!defined('ABSPATH')) exit;

/**
 * Register the Shortcode [guesty_trending_regions]
 */
function guesty_render_trending_regions() {
	$regions = get_option('guesty_trending_regions', []);
	if (empty($regions)) return '';
    ob_start(); ?>
	<div class="guesty-trending-wrapper">
		<div class="guesty-trending-items">
		<?php foreach ($regions as $region) {
			$url = home_url('/properties') . '?city=' . $region;
		?>
			<a href="<?php echo esc_url($url); ?>" class="region-name"><?php echo esc_html(strtoupper($region))?></a>
		<?php } ?>
		</div>
	</div>
	<?php
    return ob_get_clean();
}
add_shortcode('guesty_trending_regions', 'guesty_render_trending_regions');
