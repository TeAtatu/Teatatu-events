<?php
/**
 * Uninstall routine. Runs standalone — the main plugin file is not loaded.
 * Always removes the `teatatu_events_agent` role/capabilities, every
 * scheduled job and the plugin's own settings on every site in the network
 * (or the single site). Deletes all Events content only if the admin opted
 * in with "Delete all Teatatu Events data when this plugin is uninstalled".
 *
 * Only `teatatu_event`, `teatatu_evt_*`, `teatatu_events_*` data is ever
 * touched — never anything belonging to Teatatu News. Images were never
 * stored locally (link-only), so there is no media to clean up.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/includes/capabilities.php';
require_once __DIR__ . '/includes/taxonomies.php';

// Populate $wp_taxonomies so get_terms() recognises our taxonomies; the main
// plugin file (and its 'init' hooks) never load during uninstall.
teatatu_events_register_taxonomies();

/**
 * Cleans up the current site.
 *
 * @param bool $delete_data Whether to also delete all Events content.
 */
function teatatu_events_uninstall_cleanup_site( $delete_data ) {
	teatatu_events_remove_role_and_caps();
	foreach ( array( 'teatatu_events_rss_cron_tick', 'teatatu_events_html_cron_tick', 'teatatu_events_ical_cron_tick', 'teatatu_events_ld_cron_tick', 'teatatu_events_series_cron_tick', 'teatatu_events_links_cron_tick' ) as $hook ) {
		wp_clear_scheduled_hook( $hook );
	}
	wp_unschedule_hook( 'teatatu_events_nbhd_reresolve' );
	wp_unschedule_hook( 'teatatu_events_backfill_keys' );

	foreach ( array( 'teatatu_events_caps_version', 'teatatu_events_cache_ver', 'teatatu_events_nbhd_term_ids', 'teatatu_events_nbhd_rules_seen', 'teatatu_events_show_linked' ) as $option ) {
		delete_option( $option );
	}
	delete_transient( 'teatatu_events_attention_count' );

	if ( ! $delete_data ) {
		return;
	}

	foreach ( array( 'teatatu_event', 'teatatu_evt_series', 'teatatu_evt_link', 'teatatu_evt_rssfeed', 'teatatu_evt_webfeed', 'teatatu_evt_icalfeed', 'teatatu_evt_ldfeed' ) as $post_type ) {
		$post_ids = get_posts(
			array(
				'post_type'        => $post_type,
				'post_status'      => array_keys( get_post_stati() ), // 'any' would skip the trash.
				'numberposts'      => -1,
				'fields'           => 'ids',
				'suppress_filters' => true,
			)
		);
		foreach ( $post_ids as $post_id ) {
			wp_delete_post( $post_id, true );
		}
	}

	foreach ( array( 'teatatu_events_source', 'teatatu_events_category', 'teatatu_events_venue', 'teatatu_events_tag', 'teatatu_events_neighbourhood' ) as $taxonomy ) {
		$term_ids = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'fields'     => 'ids',
			)
		);
		if ( is_wp_error( $term_ids ) ) {
			continue;
		}
		foreach ( $term_ids as $term_id ) {
			wp_delete_term( $term_id, $taxonomy );
		}
	}
}

$teatatu_events_network_options = array(
	'teatatu_events_master_site_id',
	'teatatu_events_github_repo',
	'teatatu_events_github_token',
	'teatatu_events_delete_data_on_uninstall',
	'teatatu_events_settings',
	'teatatu_events_net_cache_ver',
	'teatatu_events_link_registry',
	'teatatu_events_nbhd_overrides',
	'teatatu_events_nbhd_street_rules',
	'teatatu_events_nbhd_slugs',
	'teatatu_events_nbhd_slug_aliases',
	'teatatu_events_nbhd_rules_seeded',
	'teatatu_events_nbhd_rules_ver',
);

if ( is_multisite() ) {
	$delete_data = (bool) get_site_option( 'teatatu_events_delete_data_on_uninstall', false );
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $site_id ) {
		switch_to_blog( $site_id );
		teatatu_events_uninstall_cleanup_site( $delete_data );
		restore_current_blog();
	}
	foreach ( $teatatu_events_network_options as $option ) {
		delete_site_option( $option );
	}
	delete_site_transient( 'teatatu_events_github_release' );
} else {
	$delete_data = (bool) get_option( 'teatatu_events_delete_data_on_uninstall', false );
	teatatu_events_uninstall_cleanup_site( $delete_data );
	foreach ( $teatatu_events_network_options as $option ) {
		delete_option( $option );
	}
	delete_transient( 'teatatu_events_github_release' );
}
