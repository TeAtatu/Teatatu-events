<?php
/**
 * Home page: what's on — search, happening next, this week and this
 * weekend, neighbourhoods, kinds of event, calendar subscription and the
 * organiser call to action.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
get_header();

$hero_image = get_theme_mod( 'ttev_hero_image', '' );
$hero_alt   = get_theme_mod( 'ttev_hero_image_alt', '' );
$submit_url = get_theme_mod( 'ttev_submit_url', '' );
$ready      = ttev_plugin_ready();
$base       = $ready ? ttev_archive_url() : home_url( '/' );
$next       = $ready ? teatatu_events_search( array( 'when' => 'upcoming' ), 1, 30 ) : array( 'items' => array(), 'total' => 0 );
$next_items = ttev_collapse_dates( $next['items'], 5 );
?>

<div class="ttev-wrap">
<section class="tal-band ttev-hero-band" aria-labelledby="ttev-hero-title">
<div class="ttev-hero">
	<div class="ttev-hero__copy">
		<p class="ttev-eyebrow"><?php esc_html_e( 'Te Atatū Peninsula · West Auckland', 'teatatu-events-theme' ); ?></p>
		<h1 id="ttev-hero-title"><?php esc_html_e( "What's on around the Peninsula.", 'teatatu-events-theme' ); ?></h1>
		<p class="ttev-lead"><?php esc_html_e( 'Markets, music, sport, meetings and school fairs — every local event in one calendar.', 'teatatu-events-theme' ); ?></p>

		<?php if ( $ready ) : ?>
			<form class="ttev-search-card" role="search" method="get" action="<?php echo esc_url( $base ); ?>">
				<fieldset class="ttev-switch">
					<legend><?php esc_html_e( 'When', 'teatatu-events-theme' ); ?></legend>
					<?php foreach ( array( 'upcoming' => __( 'Any time', 'teatatu-events-theme' ), 'today' => __( 'Today', 'teatatu-events-theme' ), 'weekend' => __( 'This weekend', 'teatatu-events-theme' ), 'week' => __( 'Next 7 days', 'teatatu-events-theme' ) ) as $value => $label ) : ?>
						<label><input type="radio" name="when" value="<?php echo esc_attr( $value ); ?>" <?php checked( 'upcoming', $value ); ?> /><span><?php echo esc_html( $label ); ?></span></label>
					<?php endforeach; ?>
				</fieldset>
				<div class="ttev-search-card__grid">
					<label class="ttev-field ttev-field--full"><?php esc_html_e( 'Keyword', 'teatatu-events-theme' ); ?>
						<input class="ttev-control" type="search" name="q" placeholder="<?php esc_attr_e( 'e.g. market, quiz, rugby, kids', 'teatatu-events-theme' ); ?>" />
					</label>
					<label class="ttev-field"><?php esc_html_e( 'Neighbourhood', 'teatatu-events-theme' ); ?>
						<?php ttev_select( 'neighbourhood', 'ttev-hero-nbhd', ttev_term_options( 'teatatu_events_neighbourhood', __( 'Whole Peninsula', 'teatatu-events-theme' ) ), '' ); ?>
					</label>
					<?php $categories = ttev_term_options( 'teatatu_events_category', __( 'Any kind', 'teatatu-events-theme' ) ); ?>
					<?php if ( count( $categories ) > 1 ) : ?>
						<label class="ttev-field"><?php esc_html_e( 'Category', 'teatatu-events-theme' ); ?>
							<?php ttev_select( 'category', 'ttev-hero-cat', $categories, '' ); ?>
						</label>
					<?php endif; ?>
				</div>
				<button class="tal-btn tal-btn-primary tal-btn-block" type="submit"><?php echo ttev_icon( 'search' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?><?php esc_html_e( 'Find events', 'teatatu-events-theme' ); ?></button>
			</form>

			<div class="ttev-chips">
				<a class="ttev-chip" href="<?php echo esc_url( ttev_filters_url( array( 'when' => 'today' ), $base ) ); ?>"><?php esc_html_e( 'On today', 'teatatu-events-theme' ); ?></a>
				<a class="ttev-chip" href="<?php echo esc_url( ttev_filters_url( array( 'free' => true ), $base ) ); ?>"><?php esc_html_e( 'Free events', 'teatatu-events-theme' ); ?></a>
				<a class="ttev-chip" href="<?php echo esc_url( ttev_filters_url( array( 'view' => 'calendar' ), $base ) ); ?>"><?php echo ttev_icon( 'grid', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?><?php esc_html_e( 'Month calendar', 'teatatu-events-theme' ); ?></a>
			</div>
		<?php endif; ?>
	</div>

	<?php if ( $hero_image ) : ?>
		<div class="ttev-hero__media">
			<img src="<?php echo esc_url( $hero_image ); ?>" alt="<?php echo esc_attr( $hero_alt ); ?>" fetchpriority="high" />
		</div>
	<?php elseif ( $ready ) : ?>
		<div class="ttev-next" aria-labelledby="ttev-next-title">
			<h2 id="ttev-next-title"><?php echo ttev_icon( 'bell', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?><?php esc_html_e( 'Happening next', 'teatatu-events-theme' ); ?></h2>
			<?php if ( $next_items ) : ?>
				<ul class="ttev-next__list">
					<?php foreach ( $next_items as $item ) : ?>
						<li class="tal-event-row">
							<?php ttev_date_block( $item ); ?>
							<div class="ttev-next__body">
								<a class="ttev-next__title ttev-stretch" href="<?php echo esc_url( ttev_event_url( $item ) ); ?>"<?php echo ttev_is_offsite( $item ) ? ' target="_blank" rel="noopener"' : ''; ?>><?php echo esc_html( $item['title'] ); ?></a>
								<span class="ttev-next__meta"><?php echo esc_html( implode( ' · ', array_filter( array( ttev_time_text( $item ), $item['place']['location'], ttev_more_dates_text( $item ) ) ) ) ); ?></span>
							</div>
							<?php echo ttev_icon( 'chevron-right', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?>
						</li>
					<?php endforeach; ?>
				</ul>
				<a class="ttev-arrow-link" href="<?php echo esc_url( $base ); ?>">
					<?php
					/* translators: %s: number of events. */
					echo esc_html( sprintf( _n( 'See %s upcoming event', 'See all %s upcoming events', $next['total'], 'teatatu-events-theme' ), number_format_i18n( $next['total'] ) ) );
					?>
					<?php echo ttev_icon( 'arrow', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?>
				</a>
			<?php else : ?>
				<p class="ttev-muted"><?php esc_html_e( 'Nothing is listed yet. Check back soon.', 'teatatu-events-theme' ); ?></p>
			<?php endif; ?>
		</div>
	<?php endif; ?>
</div>
</section>
</div>

<?php if ( $ready ) : ?>
	<section class="ttev-wrap ttev-section" aria-labelledby="ttev-coming-heading">
		<div class="ttev-section-head">
			<div>
				<h2 id="ttev-coming-heading"><?php esc_html_e( 'Coming up', 'teatatu-events-theme' ); ?></h2>
				<p><?php esc_html_e( 'Plan your week on the Peninsula.', 'teatatu-events-theme' ); ?></p>
			</div>
			<div class="ttev-tabs" role="tablist" aria-label="<?php esc_attr_e( 'When', 'teatatu-events-theme' ); ?>" data-ttev-tabs hidden>
				<button type="button" role="tab" id="ttev-tab-week" aria-controls="ttev-panel-week" aria-selected="true"><?php esc_html_e( 'Next 7 days', 'teatatu-events-theme' ); ?></button>
				<button type="button" role="tab" id="ttev-tab-weekend" aria-controls="ttev-panel-weekend" aria-selected="false" tabindex="-1"><?php esc_html_e( 'This weekend', 'teatatu-events-theme' ); ?></button>
			</div>
		</div>
		<?php
		$panels = array(
			'week'    => array( __( 'Next 7 days', 'teatatu-events-theme' ), __( 'Nothing listed for the next 7 days yet.', 'teatatu-events-theme' ), __( 'See everything in the next 7 days', 'teatatu-events-theme' ) ),
			'weekend' => array( __( 'This weekend', 'teatatu-events-theme' ), __( 'Nothing listed for this weekend yet.', 'teatatu-events-theme' ), __( 'See everything this weekend', 'teatatu-events-theme' ) ),
		);
		foreach ( $panels as $when => $panel ) :
			$found          = teatatu_events_search( array( 'when' => $when ), 1, 40 );
			$found['items'] = ttev_collapse_dates( $found['items'], 6 );
			?>
			<div class="ttev-tabpanel" role="tabpanel" id="ttev-panel-<?php echo esc_attr( $when ); ?>" aria-labelledby="ttev-tab-<?php echo esc_attr( $when ); ?>">
				<h3 class="screen-reader-text"><?php echo esc_html( $panel[0] ); ?></h3>
				<?php if ( $found['items'] ) : ?>
					<div class="ttev-card-grid">
						<?php foreach ( $found['items'] as $item ) : ?>
							<?php ttev_card( $item ); ?>
						<?php endforeach; ?>
					</div>
				<?php else : ?>
					<div class="ttev-empty"><p><?php echo esc_html( $panel[1] ); ?></p></div>
				<?php endif; ?>
				<p class="ttev-more-link"><a class="ttev-arrow-link" href="<?php echo esc_url( ttev_filters_url( array( 'when' => $when ), $base ) ); ?>"><?php echo esc_html( $panel[2] ); ?><?php echo ttev_icon( 'arrow', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?></a></p>
			</div>
		<?php endforeach; ?>
	</section>

	<section class="ttev-wrap ttev-section" aria-labelledby="ttev-areas-heading">
		<div class="ttev-section-head">
			<div>
				<h2 id="ttev-areas-heading"><?php esc_html_e( 'Close to home', 'teatatu-events-theme' ); ?></h2>
				<p><?php esc_html_e( "What's on in your corner of the Peninsula.", 'teatatu-events-theme' ); ?></p>
			</div>
		</div>
		<div class="ttev-tiles">
			<?php foreach ( get_terms( array( 'taxonomy' => 'teatatu_events_neighbourhood', 'hide_empty' => false ) ) as $area ) : ?>
				<?php $count = teatatu_events_upcoming_count( 'teatatu_events_neighbourhood', $area->slug ); ?>
				<a class="ttev-tile" href="<?php echo esc_url( get_term_link( $area ) ); ?>">
					<span class="ttev-tile__icon"><?php echo ttev_icon( 'pin', 24 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?></span>
					<span class="ttev-tile__title"><?php echo esc_html( $area->name ); ?></span>
					<?php if ( $area->description ) : ?>
						<span class="ttev-tile__text"><?php echo esc_html( wp_trim_words( $area->description, 18 ) ); ?></span>
					<?php endif; ?>
					<span class="ttev-tile__meta">
						<?php
						echo esc_html(
							$count
								/* translators: %s: number of events. */
								? sprintf( _n( '%s upcoming event', '%s upcoming events', $count, 'teatatu-events-theme' ), number_format_i18n( $count ) )
								/* translators: %s: neighbourhood. */
								: sprintf( __( 'Events in %s', 'teatatu-events-theme' ), $area->name )
						);
						?>
					</span>
				</a>
			<?php endforeach; ?>
		</div>
	</section>

	<?php
	$tags = get_terms( array( 'taxonomy' => 'teatatu_events_tag', 'hide_empty' => true, 'orderby' => 'count', 'order' => 'DESC', 'number' => 12 ) );
	if ( $tags && ! is_wp_error( $tags ) ) :
		?>
		<section class="ttev-wrap ttev-section ttev-section--tight" aria-labelledby="ttev-tags-heading">
			<div class="ttev-section-head">
				<div>
					<h2 id="ttev-tags-heading"><?php esc_html_e( 'Find your kind of event', 'teatatu-events-theme' ); ?></h2>
				</div>
			</div>
			<div class="ttev-chips ttev-chips--lg">
				<?php foreach ( $tags as $tag ) : ?>
					<a class="ttev-chip" href="<?php echo esc_url( get_term_link( $tag ) ); ?>"><?php echo esc_html( $tag->name ); ?></a>
				<?php endforeach; ?>
			</div>
		</section>
	<?php endif; ?>

	<section class="ttev-wrap ttev-section" aria-labelledby="ttev-subscribe-heading">
		<div class="tal-band ttev-cta">
			<div>
				<h2 id="ttev-subscribe-heading"><?php esc_html_e( 'Never miss a thing', 'teatatu-events-theme' ); ?></h2>
				<p><?php esc_html_e( 'Add the Peninsula calendar to your phone. New and changed events appear on their own.', 'teatatu-events-theme' ); ?></p>
			</div>
			<?php ttev_subscribe_buttons( array(), get_bloginfo( 'name' ) ); ?>
		</div>
	</section>
<?php else : ?>
	<section class="ttev-wrap ttev-section"><?php ttev_plugin_missing_message(); ?></section>
<?php endif; ?>

<?php if ( $submit_url ) : ?>
	<section class="ttev-wrap ttev-section ttev-section--tight">
		<div class="ttev-panel ttev-organiser">
			<div>
				<h2><?php esc_html_e( 'Running something on the Peninsula?', 'teatatu-events-theme' ); ?></h2>
				<p class="ttev-muted"><?php esc_html_e( 'Clubs, schools, churches, venues and neighbours — tell us about it and we will put it in the calendar.', 'teatatu-events-theme' ); ?></p>
			</div>
			<a class="tal-btn tal-btn-primary" href="<?php echo esc_url( $submit_url ); ?>"><?php echo ttev_icon( 'plus', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?><?php esc_html_e( 'Add your event', 'teatatu-events-theme' ); ?></a>
		</div>
	</section>
<?php endif; ?>

<?php
get_footer();
