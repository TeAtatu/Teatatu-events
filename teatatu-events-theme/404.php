<?php
/**
 * Not found. Events that have been removed land here too, so point people
 * back to what's on.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
get_header();
?>
<div class="ttev-wrap ttev-page">
	<h1><?php esc_html_e( 'This page has moved on', 'teatatu-events-theme' ); ?></h1>
	<p class="ttev-lead ttev-lead--gap"><?php esc_html_e( 'The event may have finished, or been taken down by its organiser.', 'teatatu-events-theme' ); ?></p>
	<a class="tal-btn tal-btn-primary" href="<?php echo esc_url( ttev_plugin_ready() ? ttev_archive_url() : home_url( '/' ) ); ?>"><?php esc_html_e( "See what's on", 'teatatu-events-theme' ); ?></a>
</div>
<?php
get_footer();
