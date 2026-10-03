<?php
/**
 * HTML page importer, for sites with an events listing page but no feed:
 * fetches the page, finds every element matching the "Repeating Item"
 * selector, and maps each one through the Content Mapping. "Follow each
 * item's link" lets Start/End/Venue/etc. come from the linked detail page.
 *
 * The item's resolved link (Read More URL, found within the item) is its
 * identity; an item with no link is skipped. "Only inside" limits items to
 * one part of the page (e.g. the main listing, not a "popular" sidebar).
 * Repeated links are dropped by the import pipeline (first one wins).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'teatatu_events_html_cron_tick', 'teatatu_events_run_html_feeds' );

/**
 * Cron tick for HTML feeds.
 */
function teatatu_events_run_html_feeds() {
	teatatu_events_run_due_feeds( 'html' );
}

/**
 * Builds candidates from an HTML listing page.
 *
 * @param array $config Feed config.
 * @param int   $limit  Max items.
 * @return array {candidates, error, complete}
 */
function teatatu_events_html_build_candidates( $config, $limit ) {
	$page = teatatu_events_fetch_url( $config['url'] );
	if ( '' === trim( $page ) ) {
		return array( 'candidates' => array(), 'error' => __( 'Could not fetch the page, or it returned no content.', 'teatatu-events' ), 'complete' => false );
	}
	$sel   = $config['item_selector'];
	$nodes = teatatu_events_dom_select_all( $page, $sel['tag'], $sel['attr_type'], $sel['attr_value'], $limit + 1, $config['item_container'] ?? null );
	if ( ! $nodes ) {
		return array( 'candidates' => array(), 'error' => __( 'The Repeating Item selector matched no elements on the page.', 'teatatu-events' ), 'complete' => false );
	}
	$complete = count( $nodes ) <= $limit;
	$nodes    = array_slice( $nodes, 0, $limit );

	$map = $config['field_map'];
	if ( ! $config['follow_links'] ) {
		// Without "follow", page sources are not allowed: fall back to not mapped.
		foreach ( $map as $field => $mapping ) {
			if ( in_array( $mapping['source'], array( 'page', 'page_jsonld' ), true ) ) {
				$map[ $field ]['source'] = 'none';
			}
		}
	}

	$candidates = array();
	$follows    = 0;
	foreach ( $nodes as $node ) {
		$item_html = teatatu_events_dom_inner_html( $node );
		$link      = teatatu_events_resolve_mapped(
			'read_more_url',
			array_merge( $map['read_more_url'], array( 'source' => 'item' ) ),
			array( 'item_html' => $item_html, 'link' => '', 'base_url' => $config['url'] )
		);
		if ( ! $link && 'a' === strtolower( $node->nodeName ) ) {
			$link = teatatu_events_resolve_relative_url( $node->getAttribute( 'href' ), $config['url'] );
		}
		if ( ! $link ) {
			continue;
		}
		$link    = esc_url_raw( $link );
		$use_map = $map;
		if ( $config['follow_links'] && ++$follows > TEATATU_EVENTS_MAX_FOLLOWS ) {
			foreach ( $use_map as $field => $mapping ) {
				if ( in_array( $mapping['source'], array( 'page', 'page_jsonld' ), true ) ) {
					$use_map[ $field ]['source'] = 'none';
				}
			}
		}
		$c = teatatu_events_candidate_from_map(
			$use_map,
			array(
				'item_html' => $item_html,
				'link'      => $link,
				'base_url'  => $config['url'],
			),
			$link
		);
		$sessions = ( $config['follow_links'] && $follows <= TEATATU_EVENTS_MAX_FOLLOWS ) ? teatatu_events_apply_sessions( $c, $config, $link ) : array( $c );
		foreach ( $sessions as $one ) {
			$candidates[] = $one;
		}
	}
	return array( 'candidates' => $candidates, 'error' => '', 'complete' => $complete && count( $candidates ) > 0 );
}
