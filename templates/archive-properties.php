<?php get_header(); ?>
<?php
if (did_action('elementor/loaded')) {
	add_action('wp_enqueue_scripts', function () {
		if (is_singular('properties')) {
			if (class_exists('\Elementor\Plugin')) {
				wp_enqueue_style('elementor-frontend');
			}
		}
	}, 20);
}
?>
<?php if (did_action('elementor/loaded')) { ?>
	<div data-elementor-type="wp-post" data-elementor-id="<?php the_ID(); ?>" class="elementor elementor-<?php the_ID(); ?>">
		<div class="elementor-element e-flex e-con-boxed e-con e-parent" data-element_type="container" style="display: flex;">
			<div class="e-con-inner">
			<?php } else { ?>
				<main id="content" <?php post_class('site-main'); ?>>
				<?php } ?>
				<div class="guesty-archive-wrapper">
					<div class="archive-search-container">
						<?php echo do_shortcode('[guesty_search_bar]'); ?>
					</div>

					<div class="archive-controls">
						<span class="region-label">ALL REGIONS</span>
						<span class="filter-trigger">FILTER</span>
					</div>

					<div class="property-grid">
						<?php if (have_posts()) : while (have_posts()) : the_post();
								// Fetch metadata
								$guests = get_post_meta(get_the_ID(), 'guesty_accommodates', true);
								$beds = get_post_meta(get_the_ID(), 'guesty_bedrooms', true);
								$baths = get_post_meta(get_the_ID(), 'guesty_bathrooms', true);
								$city = get_post_meta(get_the_ID(), 'guesty_address_city', true);
								$type = get_post_meta(get_the_ID(), 'guesty_property_type', true);
						?>
								<article class="guesty-card">
									<div class="card-media">
										<a href="<?php the_permalink(); ?>">
											<?php the_post_thumbnail('large'); ?>
										</a>
									</div>

									<div class="card-content">
										<h2 class="property-title">
											<a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
										</h2>
										<p class="property-city"><?php echo esc_html(strtoupper($city)); ?></p>

										<div class="property-excerpt">
											<?php echo wp_trim_words(get_the_excerpt(), 20); ?>
										</div>

										<div class="property-specs-bar">
											<?php echo strtoupper($type); ?> • <?php echo $guests; ?> GUESTS • <?php echo $beds; ?> BEDROOMS • <?php echo $baths; ?> BATHROOMS
										</div>

										<a href="<?php the_permalink(); ?>" class="view-property-link">VIEW PROPERTY</a>
									</div>
								</article>
						<?php endwhile;
						endif; ?>
					</div>
					<div class="guesty-pagination">
						<?php
						echo paginate_links(array(
							'prev_text' => '&laquo; Previous',
							'next_text' => 'Next &raquo;',
							'mid_size'  => 2
						));
						?>
					</div>

					<section class="guesty-archive-map-section">
						<h2 class="guesty-archive-map-title">Find your perfect spot</h2>
						<?php echo do_shortcode('[guesty_property_map]'); ?>
					</section>
				</div>

				<?php if (did_action('elementor/loaded')) { ?>
			</div>
		</div>
	</div>
<?php } else { ?>
	</main>
<?php } ?>

<?php get_footer(); ?>