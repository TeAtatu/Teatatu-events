<?php
/**
 * Event card for grids: the design system's EventCard (.tal-event) — date
 * block, kicker, title, time and place, then neighbourhood and price.
 * Expects $args['item'] — a normalised item from the plugin.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$item    = $args['item'];
$kicker  = ttev_kicker( $item );
$price   = ttev_price_text( $item );
$offsite = ttev_is_offsite( $item );
$more    = ttev_more_dates_text( $item );
?>
<article class="tal-event ttev-event<?php echo 'cancelled' === $item['status'] ? ' is-cancelled' : ''; ?>">
	<?php ttev_date_block( $item ); ?>
	<div class="ttev-event__main">
		<?php if ( $kicker || 'scheduled' !== $item['status'] ) : ?>
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
			<?php if ( $item['place']['location'] || $item['place']['line'] ) : ?>
				<li><?php echo ttev_icon( 'pin', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?><?php echo esc_html( $item['place']['location'] ? $item['place']['location'] : $item['place']['line'] ); ?></li>
			<?php endif; ?>
		</ul>
	</div>
	<?php if ( ! empty( $item['neighbourhood'] ) || $price || ! empty( $item['linked'] ) || $more ) : ?>
		<div class="tal-event-foot">
			<span class="ttev-event__badges">
				<?php if ( ! empty( $item['neighbourhood'] ) ) : ?>
					<span class="tal-badge tal-badge-local"><?php echo esc_html( $item['neighbourhood']['name'] ); ?></span>
				<?php endif; ?>
				<?php if ( $more ) : ?>
					<span class="tal-badge ttev-badge-dates"><?php echo ttev_icon( 'repeat', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?><?php echo esc_html( $more ); ?></span>
				<?php endif; ?>
				<?php if ( ! empty( $item['linked'] ) && ! empty( $item['home_site']['name'] ) ) : ?>
					<span class="tal-badge tal-badge-local">
						<?php
						/* translators: %s: site name. */
						echo esc_html( sprintf( __( 'From %s', 'teatatu-events-theme' ), $item['home_site']['name'] ) );
						?>
					</span>
				<?php endif; ?>
			</span>
			<?php if ( $price ) : ?>
				<span class="ttev-event__price<?php echo $item['is_free'] ? ' is-free' : ''; ?>"><?php echo esc_html( $price ); ?></span>
			<?php endif; ?>
		</div>
	<?php endif; ?>
</article>
