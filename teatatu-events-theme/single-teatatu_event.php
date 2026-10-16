<?php
/**
 * A single event. Everything shown comes from the plugin's normalised item;
 * the description is the post content (the plugin's own details box is
 * turned off by this theme, which lays the details out itself).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
get_header();

if ( ! ttev_plugin_ready() ) {
	echo '<div class="ttev-wrap ttev-section">';
	ttev_plugin_missing_message();
	echo '</div>';
	get_footer();
	return;
}

while ( have_posts() ) :
	the_post();
	$item     = teatatu_events_item_from_post( get_post() );
	$place    = $item['place'];
	$price    = ttev_price_text( $item );
	$cal      = teatatu_events_calendar_urls( $item );
	$open     = ! $item['is_past'] && ! in_array( $item['status'], array( 'cancelled', 'postponed' ), true );
	$category = $item['categories'] ? $item['categories'][0] : null;
	$others   = function_exists( 'teatatu_events_series_other_dates' ) ? teatatu_events_series_other_dates( $item['id'] ) : array();
	$banners  = array(
		'cancelled'   => __( 'This event has been cancelled.', 'teatatu-events-theme' ),
		'postponed'   => __( 'This event has been postponed. A new date will be listed when it is set.', 'teatatu-events-theme' ),
		'rescheduled' => __( 'This event has a new date — check the details below.', 'teatatu-events-theme' ),
		'sold_out'    => __( 'This event is sold out.', 'teatatu-events-theme' ),
	);
	?>

	<article class="ttev-wrap ttev-single">
		<nav class="ttev-crumbs" aria-label="<?php esc_attr_e( 'Breadcrumb', 'teatatu-events-theme' ); ?>">
			<ol>
				<li><a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Home', 'teatatu-events-theme' ); ?></a></li>
				<li><a href="<?php echo esc_url( ttev_archive_url() ); ?>"><?php esc_html_e( 'Events', 'teatatu-events-theme' ); ?></a></li>
				<?php if ( $category ) : ?>
					<li><a href="<?php echo esc_url( $category['link'] ?? ttev_filters_url( array( 'category' => $category['slug'] ), ttev_archive_url() ) ); ?>"><?php echo esc_html( $category['name'] ); ?></a></li>
				<?php endif; ?>
				<li aria-current="page"><?php echo esc_html( $item['title'] ); ?></li>
			</ol>
		</nav>

		<?php if ( isset( $banners[ $item['status'] ] ) ) : ?>
			<p class="tal-notice <?php echo 'cancelled' === $item['status'] ? 'tal-notice-error' : 'tal-notice-warning'; ?>" role="status"><?php echo ttev_icon( 'alert' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?><span><?php echo esc_html( $banners[ $item['status'] ] ); ?></span></p>
		<?php elseif ( $item['is_past'] ) : ?>
			<p class="ttev-status-banner"><?php echo ttev_icon( 'clock' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?><?php esc_html_e( 'This event has finished.', 'teatatu-events-theme' ); ?> <a href="<?php echo esc_url( ttev_archive_url() ); ?>"><?php esc_html_e( "See what's on next", 'teatatu-events-theme' ); ?></a></p>
		<?php endif; ?>

		<header class="ttev-single__head">
			<?php ttev_date_block( $item ); ?>
			<div class="ttev-single__title">
				<?php $kicker = ttev_kicker( $item ); ?>
				<?php if ( $kicker ) : ?>
					<p class="tal-event-cat"><?php echo esc_html( $kicker ); ?></p>
				<?php endif; ?>
				<h1 class="ttev-display"><?php echo esc_html( $item['title'] ); ?></h1>
				<ul class="ttev-single__facts">
					<li><?php echo ttev_icon( 'clock', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?><?php echo esc_html( $item['display_when'] ); ?></li>
					<?php if ( $place['line'] ) : ?>
						<li><?php echo ttev_icon( 'pin', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?><?php echo esc_html( $place['location'] ? $place['location'] . ( $place['room'] ? ' · ' . $place['room'] : '' ) : $place['line'] ); ?></li>
					<?php endif; ?>
				</ul>
			</div>
			<button type="button" class="ttev-icon-btn" data-ttev-share="<?php echo esc_url( $item['permalink'] ); ?>" aria-label="<?php esc_attr_e( 'Share this event', 'teatatu-events-theme' ); ?>"><?php echo ttev_icon( 'share' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?></button>
		</header>

		<?php if ( $item['image_url'] ) : ?>
			<figure class="ttev-single__media"><img src="<?php echo esc_url( $item['image_url'] ); ?>" alt="" fetchpriority="high" decoding="async" referrerpolicy="no-referrer" /></figure>
		<?php endif; ?>

		<div class="ttev-single__cols">
			<div class="ttev-single__main">
				<?php if ( trim( wp_strip_all_tags( get_the_content() ) ) || $item['excerpt'] ) : ?>
					<section class="ttev-block" aria-labelledby="ttev-about">
						<h2 id="ttev-about"><?php esc_html_e( 'About this event', 'teatatu-events-theme' ); ?></h2>
						<div class="ttev-prose">
							<?php
							if ( trim( wp_strip_all_tags( get_the_content() ) ) ) {
								the_content();
							} else {
								echo wp_kses_post( wpautop( esc_html( $item['excerpt'] ) ) );
							}
							?>
						</div>
					</section>
				<?php endif; ?>

				<?php if ( $item['tags'] ) : ?>
					<div class="ttev-chips" aria-label="<?php esc_attr_e( 'Kinds of event', 'teatatu-events-theme' ); ?>">
						<?php foreach ( $item['tags'] as $tag ) : ?>
							<a class="ttev-chip" href="<?php echo esc_url( ttev_filters_url( array( 'tag' => $tag['slug'] ), ttev_archive_url() ) ); ?>"><?php echo esc_html( $tag['name'] ); ?></a>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>

				<?php if ( $others ) : ?>
					<section class="ttev-block" aria-labelledby="ttev-dates">
						<h2 id="ttev-dates"><?php echo ttev_icon( 'repeat', 22 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?><?php esc_html_e( 'Other dates', 'teatatu-events-theme' ); ?></h2>
						<ul class="ttev-dates">
							<?php foreach ( $others as $other_id ) : ?>
								<?php $other = teatatu_events_item_from_post( get_post( $other_id ) ); ?>
								<li class="tal-event-row">
									<?php ttev_date_block( $other ); ?>
									<a class="ttev-stretch" href="<?php echo esc_url( ttev_event_url( $other ) ); ?>"><?php echo esc_html( ttev_time_text( $other ) ); ?><?php echo 'scheduled' !== $other['status'] ? ' · ' . esc_html( $other['status_label'] ) : ''; ?></a>
									<?php echo ttev_icon( 'chevron-right', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?>
								</li>
							<?php endforeach; ?>
						</ul>
					</section>
				<?php endif; ?>

				<?php if ( $place['line'] ) : ?>
					<section class="ttev-block" aria-labelledby="ttev-where">
						<h2 id="ttev-where"><?php esc_html_e( 'Where', 'teatatu-events-theme' ); ?></h2>
						<div class="ttev-place">
							<?php if ( $place['location'] ) : ?>
								<p class="ttev-place__name"><?php echo esc_html( $place['location'] ); ?><?php echo $place['room'] ? ' · ' . esc_html( $place['room'] ) : ''; ?></p>
							<?php endif; ?>
							<?php if ( $place['address'] ) : ?>
								<p class="ttev-muted"><?php echo esc_html( $place['address'] ); ?></p>
							<?php endif; ?>
							<p class="ttev-place__links">
								<?php if ( ! empty( $item['neighbourhood'] ) ) : ?>
									<a href="<?php echo esc_url( ttev_filters_url( array( 'neighbourhood' => $item['neighbourhood']['slug'] ), ttev_archive_url() ) ); ?>">
										<?php
										/* translators: %s: neighbourhood. */
										echo esc_html( sprintf( __( 'More in %s', 'teatatu-events-theme' ), $item['neighbourhood']['name'] ) );
										?>
									</a>
								<?php endif; ?>
								<?php if ( $place['venue_slug'] ) : ?>
									<a href="<?php echo esc_url( ttev_filters_url( array( 'venue' => $place['venue_slug'] ), ttev_archive_url() ) ); ?>">
										<?php
										/* translators: %s: venue. */
										echo esc_html( sprintf( __( 'More at %s', 'teatatu-events-theme' ), $place['location'] ) );
										?>
									</a>
								<?php endif; ?>
								<?php if ( $place['map_url'] ) : ?>
									<a href="<?php echo esc_url( $place['map_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Directions', 'teatatu-events-theme' ); ?></a>
								<?php endif; ?>
							</p>
						</div>
						<?php ttev_map( ttev_map_points( array( $item ) ), __( 'Map showing where this event is', 'teatatu-events-theme' ), 'ttev-map--short' ); ?>
					</section>
				<?php endif; ?>
			</div>

			<aside class="ttev-single__aside" aria-label="<?php esc_attr_e( 'Event details', 'teatatu-events-theme' ); ?>">
				<div class="ttev-panel">
					<dl class="ttev-facts">
						<div><dt><?php esc_html_e( 'When', 'teatatu-events-theme' ); ?></dt><dd><?php echo esc_html( $item['display_when'] ); ?></dd></div>
						<?php if ( $place['line'] ) : ?>
							<div><dt><?php esc_html_e( 'Where', 'teatatu-events-theme' ); ?></dt><dd><?php echo esc_html( $place['location'] ? $place['location'] : $place['line'] ); ?></dd></div>
						<?php endif; ?>
						<?php if ( ! empty( $item['neighbourhood'] ) ) : ?>
							<div><dt><?php esc_html_e( 'Neighbourhood', 'teatatu-events-theme' ); ?></dt><dd><?php echo esc_html( $item['neighbourhood']['name'] ); ?></dd></div>
						<?php endif; ?>
						<?php if ( $price ) : ?>
							<div><dt><?php esc_html_e( 'Cost', 'teatatu-events-theme' ); ?></dt><dd><?php echo esc_html( $price ); ?></dd></div>
						<?php endif; ?>
						<?php if ( 'scheduled' !== $item['status'] ) : ?>
							<div><dt><?php esc_html_e( 'Status', 'teatatu-events-theme' ); ?></dt><dd><?php echo esc_html( $item['status_label'] ); ?></dd></div>
						<?php endif; ?>
					</dl>

					<?php if ( $item['ticket_url'] && $open ) : ?>
						<a class="tal-btn tal-btn-primary tal-btn-block" href="<?php echo esc_url( $item['ticket_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo ttev_icon( 'ticket', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?><?php echo 'sold_out' === $item['status'] ? esc_html__( 'Sold out — join the waitlist', 'teatatu-events-theme' ) : esc_html__( 'Tickets and registration', 'teatatu-events-theme' ); ?></a>
					<?php endif; ?>

					<?php if ( $cal && ! $item['is_past'] ) : ?>
						<div class="ttev-addcal">
							<p class="ttev-addcal__label"><?php echo ttev_icon( 'calendar', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?><?php esc_html_e( 'Add to your calendar', 'teatatu-events-theme' ); ?></p>
							<div class="ttev-addcal__buttons">
								<a class="tal-btn tal-btn-secondary tal-btn-sm" href="<?php echo esc_url( $cal['ics'] ); ?>"><?php esc_html_e( 'Apple / .ics', 'teatatu-events-theme' ); ?></a>
								<a class="tal-btn tal-btn-secondary tal-btn-sm" href="<?php echo esc_url( $cal['google'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Google', 'teatatu-events-theme' ); ?></a>
								<a class="tal-btn tal-btn-secondary tal-btn-sm" href="<?php echo esc_url( $cal['outlook'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Outlook', 'teatatu-events-theme' ); ?></a>
							</div>
						</div>
					<?php endif; ?>

					<?php if ( $item['read_more_url'] ) : ?>
						<a class="ttev-arrow-link" href="<?php echo esc_url( $item['read_more_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo ttev_icon( 'link', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?><?php esc_html_e( 'More information from the organiser', 'teatatu-events-theme' ); ?></a>
					<?php endif; ?>
					<?php if ( $item['source'] ) : ?>
						<p class="ttev-small">
							<?php
							/* translators: %s: source name. */
							echo esc_html( sprintf( __( 'Listed by %s', 'teatatu-events-theme' ), $item['source'] ) );
							?>
						</p>
					<?php endif; ?>
				</div>
			</aside>
		</div>

		<?php
		$more_filters = $place['venue_slug'] ? array( 'venue' => $place['venue_slug'] ) : ( ! empty( $item['neighbourhood'] ) ? array( 'neighbourhood' => $item['neighbourhood']['slug'] ) : array() );
		$more         = array_slice(
			array_values(
				array_filter(
					teatatu_events_search( $more_filters, 1, 4 )['items'],
					function ( $other ) use ( $item ) {
						return $other['id'] !== $item['id'] || ! empty( $other['linked'] );
					}
				)
			),
			0,
			3
		);
		if ( $more ) :
			if ( $place['venue_slug'] ) {
				/* translators: %s: venue. */
				$more_heading = sprintf( __( 'More at %s', 'teatatu-events-theme' ), $place['location'] );
			} elseif ( ! empty( $item['neighbourhood'] ) ) {
				/* translators: %s: neighbourhood. */
				$more_heading = sprintf( __( "What's on in %s", 'teatatu-events-theme' ), $item['neighbourhood']['name'] );
			} else {
				$more_heading = __( 'Coming up on the Peninsula', 'teatatu-events-theme' );
			}
			?>
			<section class="ttev-block" aria-labelledby="ttev-more">
				<h2 id="ttev-more" class="ttev-h2"><?php echo esc_html( $more_heading ); ?></h2>
				<div class="ttev-card-grid">
					<?php foreach ( $more as $other ) : ?>
						<?php ttev_card( $other ); ?>
					<?php endforeach; ?>
				</div>
			</section>
		<?php endif; ?>
	</article>
	<?php
endwhile;

get_footer();
