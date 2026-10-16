<?php
/**
 * Events list: the events archive and every event taxonomy archive (see
 * ttev_events_taxonomy_template()). The plugin has already applied the URL
 * filters to the main query; this template renders the filter form, the
 * results (by day, or a month calendar), a venue map and subscribe links.
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

global $wp_query;
$filters  = teatatu_events_current_filters();
$active   = teatatu_events_active_filters( $filters );
$base_url = ttev_current_base_url();
$items    = array_values( array_filter( array_map( 'teatatu_events_item_from_post', $wp_query->posts ) ) );
$total    = (int) $wp_query->found_posts;
$calendar = 'calendar' === $filters['view'];
$on_tax   = array();
foreach ( teatatu_events_archive_taxonomies() as $key => $taxonomy ) {
	if ( is_tax( $taxonomy ) ) {
		$on_tax[ $key ] = get_queried_object()->slug;
	}
}
// Chips and hidden fields leave out the archive's own term (it is in the URL path).
$own = function ( $key, $value ) use ( $on_tax ) {
	if ( ! isset( $on_tax[ $key ] ) ) {
		return $value;
	}
	return array_values( array_diff( (array) $value, array( $on_tax[ $key ] ) ) );
};
$chips = array();
foreach ( $active as $key => $value ) {
	$value = $own( $key, $value );
	if ( array() !== $value ) {
		$chips[ $key ] = $value;
	}
}
$keep = $chips; // Filters to carry into view/month links.
?>

<section class="ttev-filterbar" aria-label="<?php esc_attr_e( 'Event filters', 'teatatu-events-theme' ); ?>">
	<form class="ttev-wrap" role="search" method="get" action="<?php echo esc_url( $base_url ); ?>">
		<?php if ( ! $calendar ) : ?>
			<label class="ttev-field ttev-field--sm"><?php esc_html_e( 'When', 'teatatu-events-theme' ); ?>
				<?php ttev_select( 'when', 'ttev-when', teatatu_events_when_options(), $filters['when'] ); ?>
			</label>
		<?php else : ?>
			<input type="hidden" name="view" value="calendar" />
			<?php if ( $filters['month'] ) : ?>
				<input type="hidden" name="month" value="<?php echo esc_attr( $filters['month'] ); ?>" />
			<?php endif; ?>
		<?php endif; ?>
		<label class="ttev-field ttev-field--grow"><?php esc_html_e( 'Keyword', 'teatatu-events-theme' ); ?>
			<input class="ttev-control" type="search" name="q" value="<?php echo esc_attr( $filters['q'] ); ?>" placeholder="<?php esc_attr_e( 'Market, quiz, rugby…', 'teatatu-events-theme' ); ?>" />
		</label>
		<?php if ( ! isset( $on_tax['neighbourhood'] ) ) : ?>
			<label class="ttev-field ttev-field--sm"><?php esc_html_e( 'Neighbourhood', 'teatatu-events-theme' ); ?>
				<?php ttev_select( 'neighbourhood', 'ttev-nbhd', ttev_term_options( 'teatatu_events_neighbourhood', __( 'Whole Peninsula', 'teatatu-events-theme' ) ), $filters['neighbourhood'] ? $filters['neighbourhood'][0] : '' ); ?>
			</label>
		<?php endif; ?>
		<?php $categories = ttev_term_options( 'teatatu_events_category', __( 'Any kind', 'teatatu-events-theme' ) ); ?>
		<?php if ( ! isset( $on_tax['category'] ) && count( $categories ) > 1 ) : ?>
			<label class="ttev-field ttev-field--sm"><?php esc_html_e( 'Category', 'teatatu-events-theme' ); ?>
				<?php ttev_select( 'category', 'ttev-cat', $categories, $filters['category'] ? $filters['category'][0] : '' ); ?>
			</label>
		<?php endif; ?>
		<button class="tal-btn tal-btn-primary" type="submit"><?php echo ttev_icon( 'search', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?><?php esc_html_e( 'Search', 'teatatu-events-theme' ); ?></button>

		<details <?php echo ( $own( 'tag', $filters['tag'] ) || $own( 'venue', $filters['venue'] ) || $filters['free'] || $filters['from'] || $filters['to'] ) ? 'open' : ''; ?>>
			<summary><?php echo ttev_icon( 'filter', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?><?php esc_html_e( 'More filters', 'teatatu-events-theme' ); ?></summary>
			<div class="ttev-more">
				<?php
				$tags = get_terms( array( 'taxonomy' => 'teatatu_events_tag', 'hide_empty' => false ) );
				if ( ! isset( $on_tax['tag'] ) && $tags && ! is_wp_error( $tags ) ) :
					?>
					<fieldset>
						<legend><?php esc_html_e( 'Kind of event', 'teatatu-events-theme' ); ?></legend>
						<?php foreach ( $tags as $term ) : ?>
							<label class="ttev-check"><input type="checkbox" name="kind[]" value="<?php echo esc_attr( $term->slug ); ?>" <?php checked( in_array( $term->slug, $filters['tag'], true ) ); ?> /> <?php echo esc_html( $term->name ); ?></label>
						<?php endforeach; ?>
					</fieldset>
				<?php endif; ?>
				<fieldset>
					<legend><?php esc_html_e( 'Where and cost', 'teatatu-events-theme' ); ?></legend>
					<?php $venues = ttev_term_options( 'teatatu_events_venue', __( 'Any venue', 'teatatu-events-theme' ) ); ?>
					<?php if ( ! isset( $on_tax['venue'] ) && count( $venues ) > 1 ) : ?>
						<label class="ttev-field"><?php esc_html_e( 'Venue', 'teatatu-events-theme' ); ?>
							<?php ttev_select( 'venue', 'ttev-venue', $venues, $filters['venue'] ? $filters['venue'][0] : '' ); ?>
						</label>
					<?php endif; ?>
					<label class="ttev-check"><input type="checkbox" name="free" value="1" <?php checked( $filters['free'] ); ?> /> <?php esc_html_e( 'Free events only', 'teatatu-events-theme' ); ?></label>
				</fieldset>
				<?php if ( ! $calendar ) : ?>
					<fieldset>
						<legend><?php esc_html_e( 'Dates', 'teatatu-events-theme' ); ?></legend>
						<label class="ttev-field"><?php esc_html_e( 'From', 'teatatu-events-theme' ); ?>
							<input class="ttev-control" type="date" name="from" value="<?php echo esc_attr( $filters['from'] ); ?>" />
						</label>
						<label class="ttev-field"><?php esc_html_e( 'To', 'teatatu-events-theme' ); ?>
							<input class="ttev-control" type="date" name="to" value="<?php echo esc_attr( $filters['to'] ); ?>" />
						</label>
					</fieldset>
				<?php endif; ?>
			</div>
		</details>
	</form>
</section>

<section class="ttev-wrap ttev-section ttev-section--tight">
	<div class="ttev-results__head">
		<div>
			<h1 class="ttev-display"><?php echo esc_html( $calendar ? ttev_results_heading( array_merge( $filters, array( 'when' => 'upcoming', 'from' => '', 'to' => '' ) ) ) : ttev_results_heading( $filters ) ); ?></h1>
			<?php if ( ! $calendar ) : ?>
				<p class="ttev-muted" role="status">
					<?php
					/* translators: %s: number of events. */
					echo esc_html( sprintf( _n( '%s event', '%s events', $total, 'teatatu-events-theme' ), number_format_i18n( $total ) ) );
					?>
				</p>
			<?php endif; ?>
		</div>
		<div class="ttev-results__tools">
			<?php if ( $chips ) : ?>
				<div class="ttev-chips">
					<?php
					foreach ( $chips as $key => $value ) :
						$without = $chips;
						unset( $without[ $key ] );
						$without['view'] = $filters['view'];
						/* translators: %s: filter description. */
						$remove_label = sprintf( __( 'Remove filter: %s', 'teatatu-events-theme' ), ttev_filter_chip_label( $key, $value ) );
						?>
						<a class="ttev-chip ttev-chip--active" href="<?php echo esc_url( ttev_filters_url( $without, $base_url ) ); ?>" aria-label="<?php echo esc_attr( $remove_label ); ?>"><?php echo esc_html( ttev_filter_chip_label( $key, $value ) ); ?><?php echo ttev_icon( 'close', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?></a>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
			<div class="ttev-viewswitch" role="group" aria-label="<?php esc_attr_e( 'View', 'teatatu-events-theme' ); ?>">
				<a href="<?php echo esc_url( ttev_filters_url( array_diff_key( $keep, array( 'month' => 1 ) ), $base_url ) ); ?>"<?php echo $calendar ? '' : ' aria-current="page"'; ?>><?php echo ttev_icon( 'list', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?><?php esc_html_e( 'List', 'teatatu-events-theme' ); ?></a>
				<a href="<?php echo esc_url( ttev_filters_url( array_merge( array_diff_key( $keep, array_flip( array( 'when', 'from', 'to' ) ) ), array( 'view' => 'calendar' ) ), $base_url ) ); ?>"<?php echo $calendar ? ' aria-current="page"' : ''; ?>><?php echo ttev_icon( 'grid', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?><?php esc_html_e( 'Calendar', 'teatatu-events-theme' ); ?></a>
			</div>
		</div>
	</div>

	<div class="ttev-results">
		<div class="ttev-results__list">
			<?php if ( $calendar ) : ?>
				<?php
				$month = teatatu_events_calendar_month( $filters );
				$prev  = $month->modify( '-1 month' );
				$nextm = $month->modify( '+1 month' );
				$calkeep = array_merge( array_diff_key( $keep, array_flip( array( 'when', 'from', 'to' ) ) ), array( 'view' => 'calendar' ) );
				?>
				<div class="ttev-month-nav">
					<a class="ttev-icon-btn" href="<?php echo esc_url( ttev_filters_url( array_merge( $calkeep, array( 'month' => $prev->format( 'Y-m' ) ) ), $base_url ) ); ?>" aria-label="<?php echo esc_attr( wp_date( 'F Y', $prev->getTimestamp() ) ); ?>"><?php echo ttev_icon( 'chevron-left' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?></a>
					<h2><?php echo esc_html( wp_date( 'F Y', $month->getTimestamp() ) ); ?></h2>
					<a class="ttev-icon-btn" href="<?php echo esc_url( ttev_filters_url( array_merge( $calkeep, array( 'month' => $nextm->format( 'Y-m' ) ) ), $base_url ) ); ?>" aria-label="<?php echo esc_attr( wp_date( 'F Y', $nextm->getTimestamp() ) ); ?>"><?php echo ttev_icon( 'chevron-right' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?></a>
				</div>
				<?php ttev_month_grid( $items, $month, $filters ); ?>
				<?php if ( ! $items ) : ?>
					<p class="ttev-muted ttev-month-empty"><?php esc_html_e( 'Nothing listed this month.', 'teatatu-events-theme' ); ?></p>
				<?php endif; ?>
			<?php elseif ( $items ) : ?>
				<?php foreach ( ttev_group_by_day( $items, 'past' === $filters['when'] ) as $day => $day_items ) : ?>
					<section class="ttev-day" aria-label="<?php echo esc_attr( ttev_day_heading( $day ) ); ?>">
						<h2 class="ttev-day__heading"><?php echo esc_html( ttev_day_heading( $day ) ); ?></h2>
						<?php foreach ( $day_items as $item ) : ?>
							<?php ttev_card( $item, 'row' ); ?>
						<?php endforeach; ?>
					</section>
				<?php endforeach; ?>
				<nav class="ttev-pagination" aria-label="<?php esc_attr_e( 'Results pages', 'teatatu-events-theme' ); ?>">
					<div class="nav-links">
						<?php
						echo paginate_links( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core-escaped.
							array(
								'total'     => (int) $wp_query->max_num_pages,
								'current'   => max( 1, (int) get_query_var( 'paged' ) ),
								'prev_text' => __( 'Earlier', 'teatatu-events-theme' ),
								'next_text' => __( 'Later', 'teatatu-events-theme' ),
							)
						);
						?>
					</div>
				</nav>
			<?php else : ?>
				<div class="ttev-empty">
					<h2><?php esc_html_e( 'Nothing on that matches', 'teatatu-events-theme' ); ?></h2>
					<p class="ttev-muted"><?php esc_html_e( 'Try another day, or remove a filter.', 'teatatu-events-theme' ); ?></p>
					<div class="ttev-cta__actions">
						<a class="tal-btn tal-btn-secondary" href="<?php echo esc_url( $base_url ); ?>"><?php esc_html_e( 'All upcoming events', 'teatatu-events-theme' ); ?></a>
						<a class="tal-btn tal-btn-ghost" href="<?php echo esc_url( ttev_filters_url( array_merge( $keep, array( 'view' => 'calendar' ) ), $base_url ) ); ?>"><?php esc_html_e( 'Browse the calendar', 'teatatu-events-theme' ); ?></a>
					</div>
				</div>
			<?php endif; ?>
		</div>

		<aside class="ttev-results__aside" aria-label="<?php esc_attr_e( 'Map and calendar subscription', 'teatatu-events-theme' ); ?>">
			<?php $points = $calendar ? array() : ttev_map_points( $items ); ?>
			<?php if ( $points ) : ?>
				<?php ttev_map( $points, __( 'Map of the venues on this page', 'teatatu-events-theme' ) ); ?>
				<p class="ttev-small"><?php esc_html_e( 'Events at a listed venue are pinned.', 'teatatu-events-theme' ); ?></p>
			<?php endif; ?>
			<div class="ttev-panel">
				<h2><?php echo ttev_icon( 'calendar', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?><?php esc_html_e( 'Add to your calendar', 'teatatu-events-theme' ); ?></h2>
				<p class="ttev-small">
					<?php
					$feed_filters = array_intersect_key( $filters, array_flip( array( 'neighbourhood', 'category', 'tag', 'venue', 'source' ) ) );
					echo esc_html(
						array_filter( $feed_filters )
							? __( 'Subscribe to events like these. New and changed events appear in your calendar on their own.', 'teatatu-events-theme' )
							: __( 'Subscribe to every Peninsula event. New and changed events appear in your calendar on their own.', 'teatatu-events-theme' )
					);
					?>
				</p>
				<?php ttev_subscribe_buttons( $feed_filters, ttev_results_heading( array_merge( $filters, array( 'when' => 'upcoming', 'from' => '', 'to' => '', 'free' => false ) ) ) ); ?>
			</div>
		</aside>
	</div>
</section>

<?php
get_footer();
