<?php
/**
 * RSS/Atom importer: fetches a feed with fetch_feed() (core SimplePie — no
 * bundled library) and turns each item into a candidate through the
 * per-field Content Mapping. Most feeds hold the event date in the
 * description or on the linked page, so dates usually come from a selector
 * or (the default) the linked page's Schema.org JSON-LD.
 *
 * The item's own link is always its identity — remapping Read More URL
 * never changes which event an item matches.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'teatatu_events_rss_cron_tick', 'teatatu_events_run_rss_feeds' );

/**
 * Cron tick for RSS feeds.
 */
function teatatu_events_run_rss_feeds() {
	teatatu_events_run_due_feeds( 'rss' );
}

/**
 * Builds candidates from an RSS/Atom feed.
 *
 * @param array $config Feed config.
 * @param int   $limit  Max items.
 * @return array {candidates, error, complete}
 */
function teatatu_events_rss_build_candidates( $config, $limit ) {
	if ( ! function_exists( 'fetch_feed' ) ) {
		require_once ABSPATH . WPINC . '/feed.php';
	}
	$cadence  = max( 5, (int) $config['cadence'] );
	$lifetime = function () use ( $cadence ) {
		return max( 5 * MINUTE_IN_SECONDS, ( $cadence - 1 ) * MINUTE_IN_SECONDS );
	};
	add_filter( 'wp_feed_cache_transient_lifetime', $lifetime );
	$feed = fetch_feed( $config['url'] );
	remove_filter( 'wp_feed_cache_transient_lifetime', $lifetime );
	if ( is_wp_error( $feed ) ) {
		return array( 'candidates' => array(), 'error' => $feed->get_error_message(), 'complete' => false );
	}

	$total      = (int) $feed->get_item_quantity();
	$items      = $feed->get_items( 0, $limit );
	$candidates = array();
	foreach ( $items as $item ) {
		$link = $item->get_permalink();
		if ( ! $link ) {
			continue;
		}
		$link = esc_url_raw( $link );
		$ctx  = array(
			'rss_item' => $item,
			'link'     => $link,
			'base_url' => $link,
		);
		$c = teatatu_events_candidate_from_map( $config['field_map'], $ctx, $link );
		$more = teatatu_events_resolve_mapped( 'read_more_url', $config['field_map']['read_more_url'], $ctx );
		if ( $more ) {
			$c['read_more_url'] = esc_url_raw( $more );
		}
		foreach ( teatatu_events_apply_sessions( $c, $config, $link ) as $one ) {
			$candidates[] = $one;
		}
	}
	// Only judge "removed at source" when we saw the feed's whole list.
	return array(
		'candidates' => $candidates,
		'error'      => '',
		'complete'   => count( $items ) > 0 && $total <= $limit,
	);
}
