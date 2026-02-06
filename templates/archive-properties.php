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
						<span class="filter-trigger">
							<svg xmlns="http://www.w3.org/2000/svg" width="21" height="21" viewBox="0 0 21 21" fill="none">
								<path d="M6.125 9.625H14.875V11.375H6.125V9.625ZM3.5 6.125H17.5V7.875H3.5V6.125ZM8.75 13.125H12.25V14.875H8.75V13.125Z" fill="black" />
							</svg>
							<span>FILTER</span>
						</span>
					</div>

					<div class="property-grid">
						<?php if (have_posts()) : while (have_posts()) : the_post();
								// Fetch metadata
								$guests = get_post_meta(get_the_ID(), 'guesty_accommodates', true);
								$beds = get_post_meta(get_the_ID(), 'guesty_bedrooms', true);
								$baths = get_post_meta(get_the_ID(), 'guesty_bathrooms', true);
								$city = get_post_meta(get_the_ID(), 'guesty_address_city', true);
								$type = get_post_meta(get_the_ID(), 'guesty_property_type', true);
								$propertyIcon = get_post_meta(get_the_ID(), 'guesty_property_icon_id', true);
						?>
								<?php
									$gallery_ids = (array) get_post_meta(get_the_ID(), 'guesty_gallery_ids', true);
									$gallery_ids = array_filter(array_map('intval', $gallery_ids));
									$card_images = array_slice($gallery_ids, 0, 4);
									if (empty($card_images) && has_post_thumbnail()) {
										$card_images = [get_post_thumbnail_id()];
									}
								?>
								<article class="guesty-card">
									<div class="card-media">
										<div class="card-swiper swiper">
											<div class="swiper-wrapper">
												<?php foreach ($card_images as $img_id) :
													$img_url = wp_get_attachment_image_url($img_id, 'large');
													if (!$img_url) continue;
												?>
												<div class="swiper-slide">
													<a href="<?php the_permalink(); ?>">
														<img class="property-card-featured-image" src="<?php echo esc_url($img_url); ?>" alt="">
													</a>
												</div>
												<?php endforeach; ?>
											</div>
											<div class="swiper-button-next" aria-label="Next"></div>
											<div class="swiper-button-prev" aria-label="Previous"></div>
											<div class="swiper-pagination"></div>
										</div>
										<?php if ($propertyIcon) { ?>
											<a href="<?php the_permalink(); ?>" class="property-card-icon-wrap">
												<img class="property-card-icon" src="<?php echo esc_url(wp_get_attachment_image_url($propertyIcon, 'thumbnail')); ?>" alt="">
											</a>
										<?php } ?>
									</div>

									<div class="card-content">
										<h2 class="property-title">
											<a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
										</h2>
										<p class="property-city"><a href="<?php echo esc_url(home_url('/properties')) . '?city=' . esc_attr($city); ?>"><?php echo esc_html(strtoupper($city)); ?></a></p>

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
							'prev_text' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 320 512" height="15" width="15"><path d="M9.4 233.4c-12.5 12.5-12.5 32.8 0 45.3l192 192c12.5 12.5 32.8 12.5 45.3 0s12.5-32.8 0-45.3L77.3 256 246.6 86.6c12.5-12.5 12.5-32.8 0-45.3s-32.8-12.5-45.3 0l-192 192z"/></svg>',
							'next_text' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 320 512" height="15" width="15"><path d="M311.1 233.4c12.5 12.5 12.5 32.8 0 45.3l-192 192c-12.5 12.5-32.8 12.5-45.3 0s-12.5-32.8 0-45.3L243.2 256 73.9 86.6c-12.5-12.5-12.5-32.8 0-45.3s32.8-12.5 45.3 0l192 192z"/></svg>',
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