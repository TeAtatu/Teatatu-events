<?php
/**
 * Structured Data importer: reads Schema.org Event objects from a page's
 * JSON-LD (inside @graph, ItemList and arrays). "Follow event URLs" fetches
 * each linked page (capped per run) when the listing only links to events.
 * Identity: the event's @id, or else its url.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'teatatu_events_ld_cron_tick', 'teatatu_events_run_ld_feeds' );

/**
 * Cron tick for Structured Data feeds.
 */
function teatatu_events_run_ld_feeds() {
	teatatu_events_run_due_feeds( 'ld' );
}

/**
 * Builds candidates from a page's JSON-LD.
 *
 * @param array $config Feed config.
 * @param int   $limit  Max candidates.
 * @return array {candidates, error, complete}
 */
function teatatu_events_ld_build_candidates( $config, $limit ) {
	$page = teatatu_events_fetch_url( $config['url'] );
	if ( '' === trim( $page ) ) {
		return array( 'candidates' => array(), 'error' => __( 'Could not fetch the page, or it returned no content.', 'teatatu-events' ), 'complete' => false );
	}
	$events = teatatu_events_extract_jsonld_events( $page );
	$capped = false;

	if ( $config['follow_links'] ) {
		// Listing pages often link to events whose own pages carry the JSON-LD:
		// gather same-site links from ItemList entries, else every same-host link.
		$urls = array();
		foreach ( $events as $ev ) {
			if ( empty( $ev['startDate'] ) && ! empty( $ev['url'] ) ) {
				$urls[] = teatatu_events_resolve_relative_url( (string) $ev['url'], $config['url'] );
			}
		}
		if ( ! $urls && preg_match_all( '#<a[^>]+href=["\']([^"\'#]+)["\']#i', $page, $m ) ) {
			$host = wp_parse_url( $config['url'], PHP_URL_HOST );
			foreach ( $m[1] as $href ) {
				$abs = teatatu_events_resolve_relative_url( html_entity_decode( $href ), $config['url'] );
				if ( $abs && wp_parse_url( $abs, PHP_URL_HOST ) === $host && rtrim( $abs, '/' ) !== rtrim( $config['url'], '/' ) ) {
					$urls[] = $abs;
				}
			}
		}
		$urls = array_values( array_unique( $urls ) );
		if ( count( $urls ) > TEATATU_EVENTS_MAX_FOLLOWS ) {
			$capped = true;
			$urls   = array_slice( $urls, 0, TEATATU_EVENTS_MAX_FOLLOWS );
		}
		foreach ( $urls as $url ) {
			foreach ( teatatu_events_extract_jsonld_events( teatatu_events_cached_page( $url ) ) as $ev ) {
				if ( empty( $ev['url'] ) ) {
					$ev['url'] = $url;
				}
				$events[] = $ev;
			}
		}
	}
	if ( ! $events ) {
		return array( 'candidates' => array(), 'error' => __( 'No Schema.org Event data was found on the page.', 'teatatu-events' ), 'complete' => false );
	}

	$candidates = array();
	$seen       = array();
	foreach ( $events as $ev ) {
		if ( empty( $ev['startDate'] ) ) {
			continue;
		}
		$ld       = teatatu_events_jsonld_to_candidate( $ev, $config['url'] );
		$identity = $ld['id'] ? $ld['id'] : ( $ld['url'] ? $ld['url'] : md5( ( $ld['title'] ?? '' ) . '|' . ( $ld['start'] ?? '' ) ) );
		if ( isset( $seen[ $identity ] ) ) {
			continue;
		}
		$seen[ $identity ] = true;
		$c                  = array_merge( teatatu_events_candidate_defaults(), $ld );
		$c['identity']      = $identity;
		$c['ext_kind']      = 'ld';
		$c['ext_identity']  = $identity;
		$c['read_more_url'] = $ld['url'] ? esc_url_raw( $ld['url'] ) : '';
		$candidates[]       = $c;
		if ( count( $candidates ) > $limit ) {
			$capped = true;
			break;
		}
	}
	$candidates = array_slice( $candidates, 0, $limit );
	return array( 'candidates' => $candidates, 'error' => '', 'complete' => ! $capped && count( $candidates ) > 0 );
}
