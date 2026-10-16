<?php
/**
 * Site header: the Te Atatū Local NetworkNav — the shared black strip
 * linking all five sites, then the Events header (wordmark, search, one
 * primary action), the section links, and the 4px Pōhutukawa accent rule.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$submit_url = get_theme_mod( 'ttev_submit_url', '' );
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<a class="skip-link screen-reader-text" href="#content"><?php esc_html_e( 'Skip to content', 'teatatu-events-theme' ); ?></a>
<header class="tal-nav" data-site="events">
	<nav class="tal-network" aria-label="<?php esc_attr_e( 'Te Atatū Local sites', 'teatatu-events-theme' ); ?>">
		<?php foreach ( ttev_network_sites() as $key => $site ) : ?>
			<a class="tal-site" data-site="<?php echo esc_attr( $key ); ?>" href="<?php echo esc_url( $site[1] ); ?>" <?php echo 'events' === $key ? 'aria-current="page"' : ''; ?>><span class="tal-site-dot" aria-hidden="true"></span><?php echo esc_html( $site[0] ); ?></a>
		<?php endforeach; ?>
	</nav>
	<div class="tal-nav-bar">
		<a class="tal-wordmark" href="<?php echo esc_url( home_url( '/' ) ); ?>" rel="home">Te Atatū <span><?php esc_html_e( 'Events', 'teatatu-events-theme' ); ?></span></a>
		<?php if ( ttev_plugin_ready() ) : ?>
			<form class="tal-nav-search" role="search" method="get" action="<?php echo esc_url( ttev_archive_url() ); ?>">
				<?php echo ttev_icon( 'search', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?>
				<label class="screen-reader-text" for="tal-nav-q"><?php esc_html_e( 'Search events', 'teatatu-events-theme' ); ?></label>
				<input id="tal-nav-q" type="search" name="q" placeholder="<?php esc_attr_e( 'Search markets, music, meetings…', 'teatatu-events-theme' ); ?>" />
			</form>
		<?php endif; ?>
		<?php if ( $submit_url ) : ?>
			<a class="tal-btn tal-btn-primary tal-btn-sm" href="<?php echo esc_url( $submit_url ); ?>"><?php echo ttev_icon( 'plus', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?><?php esc_html_e( 'Add an event', 'teatatu-events-theme' ); ?></a>
		<?php endif; ?>
	</div>
	<nav class="tal-nav-sub" aria-label="<?php esc_attr_e( 'Events', 'teatatu-events-theme' ); ?>">
		<?php
		wp_nav_menu(
			array(
				'theme_location' => 'primary',
				'container'      => false,
				'depth'          => 1,
				'fallback_cb'    => 'ttev_primary_menu_fallback',
			)
		);
		?>
	</nav>
	<div class="tal-nav-accent" aria-hidden="true"></div>
</header>
<main id="content">
