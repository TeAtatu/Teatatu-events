<?php
/**
 * Site footer.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$footer_text = get_theme_mod( 'ttev_footer_text', '' );
?>
</main>
<footer class="ttev-footer">
	<div class="ttev-wrap ttev-footer__grid">
		<div>
			<a class="tal-wordmark" href="<?php echo esc_url( home_url( '/' ) ); ?>">Te Atatū <span><?php esc_html_e( 'Events', 'teatatu-events-theme' ); ?></span></a>
			<p><?php echo esc_html( $footer_text ? $footer_text : __( "What's on around Te Atatū Peninsula, Auckland.", 'teatatu-events-theme' ) ); ?></p>
		</div>
		<?php if ( ttev_plugin_ready() ) : ?>
			<?php $base = ttev_archive_url(); ?>
			<nav aria-labelledby="ttev-footer-when">
				<h2 id="ttev-footer-when"><?php esc_html_e( "What's on", 'teatatu-events-theme' ); ?></h2>
				<ul>
					<li><a href="<?php echo esc_url( ttev_filters_url( array( 'when' => 'today' ), $base ) ); ?>"><?php esc_html_e( 'Today', 'teatatu-events-theme' ); ?></a></li>
					<li><a href="<?php echo esc_url( ttev_filters_url( array( 'when' => 'weekend' ), $base ) ); ?>"><?php esc_html_e( 'This weekend', 'teatatu-events-theme' ); ?></a></li>
					<li><a href="<?php echo esc_url( ttev_filters_url( array( 'free' => true ), $base ) ); ?>"><?php esc_html_e( 'Free events', 'teatatu-events-theme' ); ?></a></li>
					<li><a href="<?php echo esc_url( ttev_filters_url( array( 'view' => 'calendar' ), $base ) ); ?>"><?php esc_html_e( 'Calendar', 'teatatu-events-theme' ); ?></a></li>
					<li><a href="<?php echo esc_url( ttev_filters_url( array( 'when' => 'past' ), $base ) ); ?>"><?php esc_html_e( 'Past events', 'teatatu-events-theme' ); ?></a></li>
				</ul>
			</nav>
			<nav aria-labelledby="ttev-footer-areas">
				<h2 id="ttev-footer-areas"><?php esc_html_e( 'Neighbourhoods', 'teatatu-events-theme' ); ?></h2>
				<ul>
					<?php foreach ( get_terms( array( 'taxonomy' => 'teatatu_events_neighbourhood', 'hide_empty' => false ) ) as $area ) : ?>
						<li><a href="<?php echo esc_url( get_term_link( $area ) ); ?>"><?php echo esc_html( $area->name ); ?></a></li>
					<?php endforeach; ?>
				</ul>
			</nav>
			<?php
			$tags = get_terms( array( 'taxonomy' => 'teatatu_events_tag', 'hide_empty' => true, 'orderby' => 'count', 'order' => 'DESC', 'number' => 6 ) );
			if ( $tags && ! is_wp_error( $tags ) ) :
				?>
				<nav aria-labelledby="ttev-footer-types">
					<h2 id="ttev-footer-types"><?php esc_html_e( 'Kinds of event', 'teatatu-events-theme' ); ?></h2>
					<ul>
						<?php foreach ( $tags as $tag ) : ?>
							<li><a href="<?php echo esc_url( get_term_link( $tag ) ); ?>"><?php echo esc_html( $tag->name ); ?></a></li>
						<?php endforeach; ?>
					</ul>
				</nav>
			<?php endif; ?>
		<?php endif; ?>
		<?php if ( has_nav_menu( 'footer' ) ) : ?>
			<nav aria-labelledby="ttev-footer-site">
				<h2 id="ttev-footer-site"><?php bloginfo( 'name' ); ?></h2>
				<?php wp_nav_menu( array( 'theme_location' => 'footer', 'container' => false, 'depth' => 1 ) ); ?>
			</nav>
		<?php endif; ?>
	</div>
</footer>
<?php wp_footer(); ?>
</body>
</html>
