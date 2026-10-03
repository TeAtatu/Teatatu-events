<?php
/**
 * Plugin Name: Teatatu Events
 * Plugin URI: https://github.com/TeAtatu/Teatatu-events
 * Description: Community events for WordPress: single, multi-day, all-day and recurring events with Venues, Neighbourhoods and Event Tags; RSS, HTML-page, iCal and Schema.org JSON-LD importers; calendar subscriptions; linked events and cross-site de-duplication on multisite; link-only images; and a draft-only REST workflow for AI agents. Uses only standard WordPress database tables.
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: TeAtatu
 * Author URI: https://github.com/TeAtatu
 * License: GPL-3.0
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: teatatu-events
 * Update URI: https://github.com/TeAtatu/Teatatu-events
 * Network: true
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TEATATU_EVENTS_VERSION', '1.0.0' );
define( 'TEATATU_EVENTS_FILE', __FILE__ );
define( 'TEATATU_EVENTS_DIR', plugin_dir_path( __FILE__ ) );
define( 'TEATATU_EVENTS_URL', plugin_dir_url( __FILE__ ) );
define( 'TEATATU_EVENTS_BASENAME', plugin_basename( __FILE__ ) );

require_once TEATATU_EVENTS_DIR . 'includes/capabilities.php';
require_once TEATATU_EVENTS_DIR . 'includes/multisite.php';
require_once TEATATU_EVENTS_DIR . 'includes/settings.php';
require_once TEATATU_EVENTS_DIR . 'includes/post-type.php';
require_once TEATATU_EVENTS_DIR . 'includes/taxonomies.php';
require_once TEATATU_EVENTS_DIR . 'includes/event-data.php';
require_once TEATATU_EVENTS_DIR . 'includes/address.php';
require_once TEATATU_EVENTS_DIR . 'includes/neighbourhoods.php';
require_once TEATATU_EVENTS_DIR . 'includes/image-url.php';
require_once TEATATU_EVENTS_DIR . 'includes/meta-boxes.php';
require_once TEATATU_EVENTS_DIR . 'includes/admin-columns.php';
require_once TEATATU_EVENTS_DIR . 'includes/copy-draft.php';
require_once TEATATU_EVENTS_DIR . 'includes/recurrence.php';
require_once TEATATU_EVENTS_DIR . 'includes/dedupe.php';
require_once TEATATU_EVENTS_DIR . 'includes/linked-events.php';
require_once TEATATU_EVENTS_DIR . 'includes/calendar-ics.php';
require_once TEATATU_EVENTS_DIR . 'includes/shortcodes.php';
require_once TEATATU_EVENTS_DIR . 'includes/rest-api.php';
require_once TEATATU_EVENTS_DIR . 'includes/import-common.php';
require_once TEATATU_EVENTS_DIR . 'includes/feeds-admin.php';
require_once TEATATU_EVENTS_DIR . 'includes/rss-importer.php';
require_once TEATATU_EVENTS_DIR . 'includes/html-importer.php';
require_once TEATATU_EVENTS_DIR . 'includes/ical-importer.php';
require_once TEATATU_EVENTS_DIR . 'includes/ld-importer.php';
require_once TEATATU_EVENTS_DIR . 'includes/cron.php';
require_once TEATATU_EVENTS_DIR . 'includes/admin-page.php';
require_once TEATATU_EVENTS_DIR . 'includes/admin-series.php';
require_once TEATATU_EVENTS_DIR . 'includes/admin-places.php';
require_once TEATATU_EVENTS_DIR . 'includes/admin-linked.php';
require_once TEATATU_EVENTS_DIR . 'includes/admin-shortcodes.php';
require_once TEATATU_EVENTS_DIR . 'includes/admin-bulk.php';
require_once TEATATU_EVENTS_DIR . 'includes/updater.php';

/**
 * Fired on plugin activation. Handles both single-site and network activation.
 * Only a "Network Activate" provisions every site; a plain per-site
 * "Activate" on a multisite install provisions just the current site.
 *
 * @param bool $network_wide Whether the plugin is being network-activated.
 */
function teatatu_events_activate( $network_wide ) {
	if ( is_multisite() && $network_wide ) {
		$site_ids = get_sites( array( 'fields' => 'ids' ) );
		foreach ( $site_ids as $site_id ) {
			switch_to_blog( $site_id );
			teatatu_events_provision_site();
			restore_current_blog();
		}
	} else {
		teatatu_events_provision_site();
	}
}
register_activation_hook( TEATATU_EVENTS_FILE, 'teatatu_events_activate' );

/**
 * Runs the per-site provisioning routine: roles/capabilities, post types,
 * taxonomies, the three seeded Neighbourhood terms, the street rules (loaded
 * once per install from the bundled data file), a rewrite reset, and
 * every recurring cron job.
 */
function teatatu_events_provision_site() {
	teatatu_events_add_role_and_caps();
	teatatu_events_register_post_types();
	teatatu_events_register_taxonomies();
	teatatu_events_seed_neighbourhood_terms();
	teatatu_events_seed_street_rules();
	teatatu_events_reset_rewrite_rules();
	teatatu_events_schedule_all_cron();
	update_option( 'teatatu_events_caps_version', TEATATU_EVENTS_VERSION );
}

/**
 * Makes the current site rebuild its rewrite rules. Inside switch_to_blog()
 * flush_rewrite_rules() would build them with the wrong site's permalink
 * context, so on a switched site the stored rules are deleted instead and
 * WordPress regenerates them on that site's next request.
 */
function teatatu_events_reset_rewrite_rules() {
	if ( is_multisite() && ms_is_switched() ) {
		delete_option( 'rewrite_rules' );
	} else {
		flush_rewrite_rules();
	}
}

/**
 * Fired on plugin deactivation: clears every recurring job and resets
 * rewrites, on every site for a network deactivation.
 *
 * @param bool $network_wide Whether the plugin was network-activated.
 */
function teatatu_events_deactivate( $network_wide ) {
	if ( is_multisite() && $network_wide ) {
		$site_ids = get_sites( array( 'fields' => 'ids' ) );
		foreach ( $site_ids as $site_id ) {
			switch_to_blog( $site_id );
			teatatu_events_reset_rewrite_rules();
			teatatu_events_unschedule_all_cron();
			restore_current_blog();
		}
	} else {
		teatatu_events_reset_rewrite_rules();
		teatatu_events_unschedule_all_cron();
	}
}
register_deactivation_hook( TEATATU_EVENTS_FILE, 'teatatu_events_deactivate' );

/**
 * Provisions a brand-new site created after network activation.
 *
 * @param WP_Site $new_site Newly created site.
 */
function teatatu_events_new_site( $new_site ) {
	if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	if ( ! is_plugin_active_for_network( TEATATU_EVENTS_BASENAME ) ) {
		return;
	}
	switch_to_blog( (int) $new_site->blog_id );
	teatatu_events_provision_site();
	restore_current_blog();
}
add_action( 'wp_initialize_site', 'teatatu_events_new_site' );
