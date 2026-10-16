<?php
/**
 * Event row for lists (search results, "other dates"): date block, kicker,
 * title, time and place, a short description and badges, and the event's
 * picture when it has one. Expects $args['item'] — a normalised item.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$item    = $args['item'];
$kicker  = ttev_kicker( $item );
$price   = ttev_price_text( $item );
$offsite = ttev_is_offsite( $item );
$badges  = array();
if ( ! empty( $item['neighbourhood'] ) ) {
	$badges[] = $item['neighbourhood']['name'];
}
foreach ( array_slice( (array) $item['tags'], 0, 3 ) as $tag ) {
	if ( $tag['name'] !== $kicker ) {
		$badges[] = $tag['name'];
	}
}
if ( ! empty( $item['series_id'] ) ) {
	$badges[] = __( 'Repeats', 'teatatu-events-theme' );
}
?>
<article class="ttev-row<?php echo 'cancelled' === $item['status'] ? ' is-cancelled' : ''; ?><?php echo $item['image_url'] ? ' has-image' : ''; ?>">
	<?php ttev_date_block( $item ); ?>
	<div class="ttev-row__body">
		<?php if ( $kicker || 'scheduled' !== $item['status'] || ! empty( $item['is_past'] ) ) : ?>
			<div class="tal-event-kicker">
				<?php if ( $kicker ) : ?>
					<span class="tal-event-cat"><?php echo esc_html( $kicker ); ?></span>
				<?php endif; ?>
				<?php ttev_status_badge( $item ); ?>
			</div>
		<?php endif; ?>
		<h3 class="tal-event-title"><a class="ttev-stretch" href="<?php echo esc_url( ttev_event_url( $item ) ); ?>"<?php echo $offsite ? ' target="_blank" rel="noopener"' : ''; ?>><?php echo esc_html( $item['title'] ); ?></a></h3>
		<ul class="tal-event-facts">
			<li><?php echo ttev_icon( 'clock', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?><?php echo esc_html( ttev_time_text( $item ) ); ?></li>
			<?php if ( $item['place']['line'] ) : ?>
				<li><?php echo ttev_icon( 'pin', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?><?php echo esc_html( $item['place']['line'] ); ?></li>
			<?php endif; ?>
			<?php if ( $price ) : ?>
				<li><?php echo ttev_icon( 'price', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?><?php echo esc_html( $price ); ?></li>
			<?php endif; ?>
		</ul>
		<?php if ( $item['excerpt'] ) : ?>
			<p class="ttev-row__excerpt"><?php echo esc_html( wp_trim_words( wp_strip_all_tags( $item['excerpt'] ), 26 ) ); ?></p>
		<?php endif; ?>
		<?php if ( $badges || ! empty( $item['linked'] ) ) : ?>
			<div class="ttev-badges">
				<?php foreach ( $badges as $badge ) : ?>
					<span class="tal-badge tal-badge-local"><?php echo esc_html( $badge ); ?></span>
				<?php endforeach; ?>
				<?php if ( ! empty( $item['linked'] ) && ! empty( $item['home_site']['name'] ) ) : ?>
					<span class="tal-badge tal-badge-local">
						<?php
						/* translators: %s: site name. */
						echo esc_html( sprintf( __( 'From %s', 'teatatu-events-theme' ), $item['home_site']['name'] ) );
						?>
					</span>
				<?php endif; ?>
			</div>
		<?php endif; ?>
	</div>
	<?php if ( $item['image_url'] ) : ?>
		<div class="ttev-row__media"><img src="<?php echo esc_url( $item['image_url'] ); ?>" alt="" loading="lazy" decoding="async" referrerpolicy="no-referrer" /></div>
	<?php endif; ?>
</article>
