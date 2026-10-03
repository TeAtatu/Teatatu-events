<?php
/**
 * Multisite helpers: Master Site resolution, and the cache-version counters
 * used by the network aggregation, subscribe feeds and other cached output.
 *
 * Rather than registering and deleting individual transient keys, every
 * cache key includes a version number; any event change bumps the number,
 * which instantly orphans every stale entry (they then expire on their own).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gets the blog ID configured as the network's Master Site.
 *
 * @return int 0 if none has been configured yet.
 */
function teatatu_events_get_master_site_id() {
	if ( ! is_multisite() ) {
		return 0;
	}
	return (int) get_site_option( 'teatatu_events_master_site_id', 0 );
}

/**
 * Whether the current site is the configured Master Site.
 *
 * @return bool
 */
function teatatu_events_is_master_site() {
	if ( ! is_multisite() ) {
		return false;
	}
	$master_id = teatatu_events_get_master_site_id();
	return $master_id > 0 && get_current_blog_id() === $master_id;
}

/**
 * Current cache version for this site's own cached output (site scope).
 *
 * @return int
 */
function teatatu_events_site_cache_version() {
	return (int) get_option( 'teatatu_events_cache_ver', 1 );
}

/**
 * Current cache version for network aggregation (network scope).
 *
 * @return int
 */
function teatatu_events_network_cache_version() {
	return is_multisite() ? (int) get_site_option( 'teatatu_events_net_cache_ver', 1 ) : teatatu_events_site_cache_version();
}

/**
 * Invalidates every cached listing/feed on this site and across the network.
 * Called whenever an event, link or relevant term changes anywhere.
 */
function teatatu_events_bump_cache_version() {
	update_option( 'teatatu_events_cache_ver', teatatu_events_site_cache_version() + 1, false );
	if ( is_multisite() ) {
		update_site_option( 'teatatu_events_net_cache_ver', teatatu_events_network_cache_version() + 1 );
	}
}

/**
 * Builds a cache key from a scope and an array of query-defining values.
 *
 * @param string $scope 'site' or 'network'.
 * @param string $kind  What is cached (e.g. 'items', 'ics', 'terms').
 * @param array  $vars  Values that change the cached result.
 * @return string
 */
function teatatu_events_cache_key( $scope, $kind, $vars ) {
	ksort( $vars );
	$ver = 'network' === $scope ? teatatu_events_network_cache_version() : teatatu_events_site_cache_version();
	return 'tte_' . $kind . '_' . $ver . '_' . md5( wp_json_encode( $vars ) );
}

/**
 * Reads a cached value from the right store for the scope.
 *
 * @param string $scope 'site' or 'network'.
 * @param string $key   Cache key.
 * @return mixed false when missing.
 */
function teatatu_events_cache_get( $scope, $key ) {
	return 'network' === $scope && is_multisite() ? get_site_transient( $key ) : get_transient( $key );
}

/**
 * Writes a cached value to the right store for the scope.
 *
 * @param string $scope 'site' or 'network'.
 * @param string $key   Cache key.
 * @param mixed  $value Value.
 * @param int    $ttl   Seconds.
 */
function teatatu_events_cache_set( $scope, $key, $value, $ttl = HOUR_IN_SECONDS ) {
	if ( 'network' === $scope && is_multisite() ) {
		set_site_transient( $key, $value, $ttl );
	} else {
		set_transient( $key, $value, $ttl );
	}
}

add_action( 'save_post_teatatu_event', 'teatatu_events_bump_cache_version' );
add_action( 'save_post_teatatu_evt_link', 'teatatu_events_bump_cache_version' );
add_action( 'before_delete_post', 'teatatu_events_maybe_bump_cache_on_delete' );
add_action( 'trashed_post', 'teatatu_events_maybe_bump_cache_on_delete' );
add_action( 'edited_term', 'teatatu_events_maybe_bump_cache_on_term', 10, 3 );

/**
 * Bumps cache versions when an event or link is deleted or trashed.
 *
 * @param int $post_id Post ID.
 */
function teatatu_events_maybe_bump_cache_on_delete( $post_id ) {
	if ( in_array( get_post_type( $post_id ), array( 'teatatu_event', 'teatatu_evt_link' ), true ) ) {
		teatatu_events_bump_cache_version();
	}
}

/**
 * Bumps cache versions when one of the plugin's terms is renamed.
 *
 * @param int    $term_id  Term ID.
 * @param int    $tt_id    Term taxonomy ID.
 * @param string $taxonomy Taxonomy.
 */
function teatatu_events_maybe_bump_cache_on_term( $term_id, $tt_id, $taxonomy ) {
	if ( 0 === strpos( (string) $taxonomy, 'teatatu_events_' ) ) {
		teatatu_events_bump_cache_version();
	}
}

/**
 * Network site IDs to aggregate from, honouring an exclude list.
 *
 * @param int[] $exclude Site IDs to skip.
 * @return int[]
 */
function teatatu_events_network_site_ids( $exclude = array() ) {
	if ( ! is_multisite() ) {
		return array( get_current_blog_id() );
	}
	$ids = array_map( 'intval', get_sites( array( 'fields' => 'ids', 'number' => 500, 'archived' => 0, 'deleted' => 0, 'spam' => 0 ) ) );
	return array_values( array_diff( $ids, array_map( 'intval', (array) $exclude ) ) );
}

/**
 * Display name and home URL of a site.
 *
 * @param int $site_id Site ID.
 * @return array {id, name, url}
 */
function teatatu_events_site_info( $site_id ) {
	static $cache = array();
	$site_id = (int) $site_id;
	if ( ! isset( $cache[ $site_id ] ) ) {
		if ( is_multisite() ) {
			$details = get_blog_details( $site_id );
			$cache[ $site_id ] = array(
				'id'   => $site_id,
				'name' => $details ? $details->blogname : '',
				'url'  => $details ? $details->home : '',
			);
		} else {
			$cache[ $site_id ] = array(
				'id'   => $site_id,
				'name' => get_bloginfo( 'name' ),
				'url'  => home_url(),
			);
		}
	}
	return $cache[ $site_id ];
}
