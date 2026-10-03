<?php
/**
 * Cross-site duplicates. When several sites import the same external event,
 * each keeps its own copy, but network listings show it once.
 *
 *  - External key (exact): built from the source's own identity, the same on
 *    every site that imports it (iCal UID + occurrence start, JSON-LD @id/url,
 *    RSS/HTML item link — URLs normalised first).
 *  - Fuzzy key (fallback): normalised title + start + place, computed for
 *    every event on save (see teatatu_events_compute_fuzzy_key()).
 *
 * Removing duplicates never changes or deletes anything; it only affects
 * what network listings display. The canonical copy comes from the site
 * highest in the Duplicate priority setting, but the newest status and
 * dates in the group are always shown.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Normalises a URL for identity comparison: https, lower-case host without
 * www., no fragment, no trailing slash, tracking parameters removed.
 *
 * @param string $url URL.
 * @return string
 */
function teatatu_events_normalize_url( $url ) {
	$parts = wp_parse_url( trim( (string) $url ) );
	if ( empty( $parts['host'] ) ) {
		return strtolower( trim( (string) $url ) );
	}
	$host  = preg_replace( '/^www\./', '', strtolower( $parts['host'] ) );
	$path  = isset( $parts['path'] ) ? rtrim( $parts['path'], '/' ) : '';
	$query = '';
	if ( ! empty( $parts['query'] ) ) {
		parse_str( $parts['query'], $args );
		foreach ( array_keys( $args ) as $key ) {
			if ( preg_match( '/^(utm_|fbclid$|gclid$|mc_cid$|mc_eid$|_ga$|ref$|igshid$)/i', $key ) ) {
				unset( $args[ $key ] );
			}
		}
		ksort( $args );
		$query = $args ? '?' . http_build_query( $args ) : '';
	}
	return 'https://' . $host . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) . $path . $query;
}

/**
 * Builds an external key.
 *
 * @param string $kind     'ical', 'ld' or 'url'.
 * @param string $identity UID+start, @id, or URL.
 * @return string
 */
function teatatu_events_external_key( $kind, $identity ) {
	$identity = (string) $identity;
	if ( '' === $identity ) {
		return '';
	}
	if ( 'url' === $kind || ( 'ld' === $kind && preg_match( '#^https?://#i', $identity ) ) ) {
		$identity = teatatu_events_normalize_url( $identity );
		$kind     = 'url';
	}
	return sha1( $kind . ':' . $identity );
}

/**
 * Priority rank of each site for choosing the canonical copy (lower wins):
 * the Duplicate priority list first, then the Master Site, then by site ID.
 *
 * @return int[] site ID => rank
 */
function teatatu_events_site_priority_ranks() {
	static $ranks = null;
	if ( null !== $ranks ) {
		return $ranks;
	}
	$ranks = array();
	$list  = array_filter( array_map( 'absint', explode( ',', (string) teatatu_events_setting( 'dedupe_priority' ) ) ) );
	$rank  = 0;
	foreach ( $list as $id ) {
		if ( ! isset( $ranks[ $id ] ) ) {
			$ranks[ $id ] = $rank++;
		}
	}
	$master = teatatu_events_get_master_site_id();
	if ( $master && ! isset( $ranks[ $master ] ) ) {
		$ranks[ $master ] = $rank++;
	}
	foreach ( teatatu_events_network_site_ids() as $id ) {
		if ( ! isset( $ranks[ $id ] ) ) {
			$ranks[ $id ] = $rank + $id;
		}
	}
	return $ranks;
}

/**
 * Collapses duplicate events in a list of normalised items (from several
 * sites) into one item each, before sorting/paging.
 *
 * @param array[] $items Items.
 * @param bool    $keep_groups Return every copy, each tagged with its group (for ?dedupe=0 troubleshooting).
 * @return array[]
 */
function teatatu_events_dedupe_items( $items, $keep_groups = false ) {
	if ( ! teatatu_events_setting( 'dedupe_enabled' ) && ! $keep_groups ) {
		return $items;
	}
	$fuzzy  = (bool) teatatu_events_setting( 'dedupe_fuzzy' );
	$manual = (bool) teatatu_events_setting( 'dedupe_manual' );
	$n      = count( $items );
	$parent = range( 0, max( 0, $n - 1 ) );
	$find   = function ( $i ) use ( &$parent ) {
		while ( $parent[ $i ] !== $i ) {
			$parent[ $i ] = $parent[ $parent[ $i ] ];
			$i            = $parent[ $i ];
		}
		return $i;
	};
	$union  = function ( $a, $b ) use ( &$parent, $find ) {
		$ra = $find( $a );
		$rb = $find( $b );
		if ( $ra !== $rb ) {
			$parent[ $rb ] = $ra;
		}
	};

	$by_ext   = array();
	$by_fuzzy = array();
	foreach ( $items as $i => $item ) {
		if ( ! empty( $item['external_key'] ) ) {
			if ( isset( $by_ext[ $item['external_key'] ] ) ) {
				$union( $by_ext[ $item['external_key'] ], $i );
			} else {
				$by_ext[ $item['external_key'] ] = $i;
			}
		}
		if ( $fuzzy && ! empty( $item['fuzzy_key'] ) ) {
			$key = $item['fuzzy_key'];
			if ( isset( $by_fuzzy[ $key ] ) ) {
				$other = $items[ $by_fuzzy[ $key ] ];
				// Only merge when at least one copy was imported (or hand-made merging is on),
				// and never two events from the same site.
				if ( ( $manual || ! empty( $item['imported'] ) || ! empty( $other['imported'] ) ) && $other['site_id'] !== $item['site_id'] ) {
					$union( $by_fuzzy[ $key ], $i );
				}
			} else {
				$by_fuzzy[ $key ] = $i;
			}
		}
	}

	$groups = array();
	foreach ( $items as $i => $item ) {
		$groups[ $find( $i ) ][] = $i;
	}
	$ranks = teatatu_events_site_priority_ranks();
	$out   = array();
	foreach ( $groups as $root => $members ) {
		if ( 1 === count( $members ) ) {
			$out[] = $items[ $members[0] ];
			continue;
		}
		usort(
			$members,
			function ( $a, $b ) use ( $items, $ranks ) {
				$ra = $ranks[ $items[ $a ]['site_id'] ] ?? PHP_INT_MAX;
				$rb = $ranks[ $items[ $b ]['site_id'] ] ?? PHP_INT_MAX;
				if ( $ra !== $rb ) {
					return $ra <=> $rb;
				}
				return strcmp( $items[ $b ]['modified_gmt'], $items[ $a ]['modified_gmt'] );
			}
		);
		if ( $keep_groups ) {
			foreach ( $members as $pos => $i ) {
				$copy                  = $items[ $i ];
				$copy['dedupe_group']  = 'g' . $root;
				$copy['canonical']     = 0 === $pos;
				$out[]                 = $copy;
			}
			continue;
		}
		$canonical = $items[ $members[0] ];
		$newest    = $canonical;
		foreach ( $members as $i ) {
			if ( strcmp( $items[ $i ]['modified_gmt'], $newest['modified_gmt'] ) > 0 ) {
				$newest = $items[ $i ];
			}
		}
		// A stale copy must never hide a cancellation or a new date.
		foreach ( array( 'status', 'status_label', 'start', 'end', 'all_day', 'display_when', 'is_past' ) as $field ) {
			$canonical[ $field ] = $newest[ $field ];
		}
		foreach ( array_slice( $members, 1 ) as $i ) {
			$canonical['also_on'][] = array(
				'site_id' => $items[ $i ]['site_id'],
				'name'    => $items[ $i ]['home_site']['name'],
				'url'     => $items[ $i ]['link'],
			);
		}
		$out[] = $canonical;
	}
	return $out;
}

add_action( 'teatatu_events_backfill_keys', 'teatatu_events_run_backfill_keys' );

/**
 * One-off background job (batches of 100): computes duplicate-matching keys
 * for events that don't have them yet.
 *
 * @param int $offset Offset.
 */
function teatatu_events_run_backfill_keys( $offset = 0 ) {
	$ids = get_posts(
		array(
			'post_type'      => 'teatatu_event',
			'post_status'    => 'any',
			'posts_per_page' => 100,
			'offset'         => (int) $offset,
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'fields'         => 'ids',
		)
	);
	foreach ( $ids as $id ) {
		if ( ! get_post_meta( $id, teatatu_events_mk( 'fuzzy_key' ), true ) ) {
			update_post_meta( $id, teatatu_events_mk( 'fuzzy_key' ), teatatu_events_compute_fuzzy_key( $id ) );
		}
		if ( ! get_post_meta( $id, teatatu_events_mk( 'external_key' ), true ) ) {
			$link = get_post_meta( $id, teatatu_events_mk( 'source_link' ), true );
			if ( $link ) {
				update_post_meta( $id, teatatu_events_mk( 'external_key' ), teatatu_events_external_key( 'url', $link ) );
			}
		}
	}
	if ( count( $ids ) === 100 ) {
		wp_schedule_single_event( time() + 10, 'teatatu_events_backfill_keys', array( $offset + 100 ) );
	}
}
