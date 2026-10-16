<?php
/**
 * Front-end query API for themes: the URL filters for the events archive
 * (/events/) and every event taxonomy archive (/event-neighbourhoods/…,
 * /event-tags/…, /event-categories/…, /event-venues/…, /event-sources/…),
 * applied to the main query, plus a search function for theme sections.
 *
 * Filter names (GET):
 *   when          upcoming (default) | today | tomorrow | weekend | week | month | past | all
 *   from, to      YYYY-MM-DD (override `when`'s range)
 *   q             keyword
 *   neighbourhood comma list or array of slugs
 *   category      comma list or array of slugs
 *   kind          comma list or array of Event Tag slugs (in the URL it is
 *                 "kind", because "tag" is WordPress's own blog-tag query
 *                 variable; code may pass "tag" to teatatu_events_search())
 *   venue         slug
 *   free          1 = free events only
 *   view          list (default) | calendar — display only; `month` (YYYY-MM) for the calendar
 *
 * The plugin owns these rules; a theme only renders forms that use these
 * names and the items this returns (teatatu_events_item_from_post()).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Taxonomy filters: filter name => taxonomy.
 *
 * @return string[]
 */
function teatatu_events_archive_taxonomies() {
	return array(
		'neighbourhood' => 'teatatu_events_neighbourhood',
		'category'      => 'teatatu_events_category',
		'tag'           => 'teatatu_events_tag',
		'venue'         => 'teatatu_events_venue',
		'source'        => 'teatatu_events_source',
	);
}

/**
 * The "when" choices: value => label.
 *
 * @return string[]
 */
function teatatu_events_when_options() {
	return array(
		'upcoming' => __( 'Upcoming', 'teatatu-events' ),
		'today'    => __( 'Today', 'teatatu-events' ),
		'tomorrow' => __( 'Tomorrow', 'teatatu-events' ),
		'weekend'  => __( 'This weekend', 'teatatu-events' ),
		'week'     => __( 'Next 7 days', 'teatatu-events' ),
		'month'    => __( 'This month', 'teatatu-events' ),
		'past'     => __( 'Past events', 'teatatu-events' ),
		'all'      => __( 'All dates', 'teatatu-events' ),
	);
}

/**
 * Sanitises a filter array (from the URL or a theme).
 *
 * @param array $raw Raw values.
 * @return array
 */
function teatatu_events_sanitize_filters( $raw ) {
	$list = function ( $value ) {
		if ( is_array( $value ) ) {
			$value = implode( ',', $value );
		}
		return array_values( array_unique( array_filter( array_map( 'sanitize_title', explode( ',', (string) $value ) ) ) ) );
	};
	$date = function ( $value ) {
		$value = trim( (string) $value );
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) && checkdate( (int) substr( $value, 5, 2 ), (int) substr( $value, 8, 2 ), (int) substr( $value, 0, 4 ) ) ? $value : '';
	};
	$when = sanitize_key( (string) ( $raw['when'] ?? '' ) );
	$out  = array(
		'when'          => isset( teatatu_events_when_options()[ $when ] ) ? $when : 'upcoming',
		'from'          => $date( $raw['from'] ?? '' ),
		'to'            => $date( $raw['to'] ?? '' ),
		'q'             => trim( sanitize_text_field( (string) ( $raw['q'] ?? '' ) ) ),
		'neighbourhood' => array_map( 'teatatu_events_current_neighbourhood_slug', $list( $raw['neighbourhood'] ?? '' ) ),
		'category'      => $list( $raw['category'] ?? '' ),
		'tag'           => $list( $raw['kind'] ?? ( $raw['tag'] ?? '' ) ),
		'venue'         => $list( $raw['venue'] ?? '' ),
		'source'        => $list( $raw['source'] ?? '' ),
		'free'          => ! empty( $raw['free'] ),
		'view'          => 'calendar' === ( $raw['view'] ?? '' ) ? 'calendar' : 'list',
		'month'         => preg_match( '/^\d{4}-(0[1-9]|1[0-2])$/', (string) ( $raw['month'] ?? '' ) ) ? (string) $raw['month'] : '',
	);
	return $out;
}

/**
 * The current request's filters. On a taxonomy archive the archive's own
 * term is included (so /event-neighbourhoods/matipo/ means neighbourhood=matipo).
 *
 * @return array
 */
function teatatu_events_current_filters() {
	static $cache = null;
	if ( null !== $cache ) {
		return $cache;
	}
	$filters = teatatu_events_sanitize_filters( wp_unslash( $_GET ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only search filters.
	foreach ( teatatu_events_archive_taxonomies() as $key => $taxonomy ) {
		if ( is_tax( $taxonomy ) ) {
			$term = get_queried_object();
			if ( $term && ! in_array( $term->slug, $filters[ $key ], true ) ) {
				$filters[ $key ][] = $term->slug;
			}
		}
	}
	$cache = $filters;
	return $filters;
}

/**
 * Filters that differ from the defaults (for "remove filter" chips and
 * links). View and month are left out (display only).
 *
 * @param array $filters Sanitised filters.
 * @return array
 */
function teatatu_events_active_filters( $filters ) {
	$active = array();
	foreach ( $filters as $key => $value ) {
		if ( in_array( $key, array( 'view', 'month' ), true ) ) {
			continue;
		}
		if ( 'when' === $key ? 'upcoming' !== $value : ( is_array( $value ) ? (bool) $value : ( is_bool( $value ) ? $value : '' !== (string) $value ) ) ) {
			$active[ $key ] = $value;
		}
	}
	return $active;
}

/**
 * Start/end timestamps (site time) for a "when" choice, or nulls.
 *
 * @param string $when Choice.
 * @return array {from: int|null, to: int|null, scope: upcoming|past|all}
 */
function teatatu_events_when_range( $when ) {
	$tz    = wp_timezone();
	$today = new DateTimeImmutable( 'today', $tz );
	$range = array( 'from' => null, 'to' => null, 'scope' => 'upcoming' );
	switch ( $when ) {
		case 'today':
			$range['to'] = $today->setTime( 23, 59, 59 )->getTimestamp();
			break;
		case 'tomorrow':
			$range['from'] = $today->modify( '+1 day' )->getTimestamp();
			$range['to']   = $today->modify( '+1 day' )->setTime( 23, 59, 59 )->getTimestamp();
			break;
		case 'weekend':
			$dow           = (int) $today->format( 'N' ); // 1 = Monday … 7 = Sunday.
			$saturday      = $dow >= 6 ? $today->modify( 'saturday this week' ) : $today->modify( 'next saturday' );
			$range['from'] = max( time(), $saturday->getTimestamp() );
			$range['to']   = $saturday->modify( '+1 day' )->setTime( 23, 59, 59 )->getTimestamp();
			break;
		case 'week':
			$range['to'] = $today->modify( '+6 days' )->setTime( 23, 59, 59 )->getTimestamp();
			break;
		case 'month':
			$range['to'] = $today->modify( 'last day of this month' )->setTime( 23, 59, 59 )->getTimestamp();
			break;
		case 'past':
			$range['scope'] = 'past';
			break;
		case 'all':
			$range['scope'] = 'all';
			break;
	}
	return $range;
}

/**
 * WP_Query arguments for a set of filters (shared by the main archive
 * query and teatatu_events_search()).
 *
 * @param array $filters Sanitised filters.
 * @return array
 */
function teatatu_events_filter_query_args( $filters ) {
	$range = teatatu_events_when_range( $filters['when'] );
	$from  = $filters['from'] ? teatatu_events_parse_bound( $filters['from'] ) : $range['from'];
	$to    = $filters['to'] ? teatatu_events_parse_bound( $filters['to'], true ) : $range['to'];
	$scope = ( $filters['from'] || $filters['to'] ) && 'upcoming' === $range['scope'] && 'upcoming' === $filters['when'] ? 'all' : $range['scope'];
	if ( 'upcoming' === $filters['when'] && $filters['from'] && teatatu_events_parse_bound( $filters['from'] ) > time() ) {
		$scope = 'all';
	}
	$args = teatatu_events_query_args( $scope, $from, $to, 'past' === $scope ? 'DESC' : 'ASC' );

	$args['meta_query'][] = array( 'key' => teatatu_events_mk( 'draft_of' ), 'compare' => 'NOT EXISTS' );
	$args['meta_query'][] = array( 'key' => '_teatatu_events_link_hidden', 'compare' => 'NOT EXISTS' );
	$args['meta_query'][] = array( 'key' => '_teatatu_events_link_shadowed', 'compare' => 'NOT EXISTS' );
	if ( teatatu_events_setting( 'hide_cancelled' ) ) {
		$args['meta_query'][] = array( 'key' => teatatu_events_mk( 'status' ), 'value' => 'cancelled', 'compare' => '!=' );
	}
	if ( $filters['free'] ) {
		$args['meta_query'][] = array( 'key' => teatatu_events_mk( 'is_free' ), 'value' => '1' );
	}

	$tax_query = array();
	foreach ( teatatu_events_archive_taxonomies() as $key => $taxonomy ) {
		if ( $filters[ $key ] ) {
			$tax_query[] = array( 'taxonomy' => $taxonomy, 'field' => 'slug', 'terms' => $filters[ $key ] );
		}
	}
	if ( $tax_query ) {
		$args['tax_query'] = array_merge( array( 'relation' => 'AND' ), $tax_query ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
	}
	if ( '' !== $filters['q'] ) {
		$args['s'] = $filters['q'];
	}
	$args['post_type']   = teatatu_events_linked_enabled() ? array( 'teatatu_event', 'teatatu_evt_link' ) : array( 'teatatu_event' );
	$args['post_status'] = 'publish';
	return $args;
}

/**
 * Whether a query is the main events archive or an event taxonomy archive.
 *
 * @param WP_Query $query Query.
 * @return bool
 */
function teatatu_events_is_events_archive( $query ) {
	return $query->is_post_type_archive( 'teatatu_event' ) || $query->is_tax( array_values( teatatu_events_archive_taxonomies() ) );
}

add_action( 'pre_get_posts', 'teatatu_events_filter_main_query' );

/**
 * Applies the URL filters to the main query on the events archive and every
 * event taxonomy archive: date range (upcoming by default, soonest first),
 * keyword, neighbourhood / category / tag / venue / source, free. Draft
 * copies, hidden or shadowed linked events, and (per the setting) cancelled
 * events are left out. The calendar view loads a whole month.
 *
 * @param WP_Query $query Query.
 */
function teatatu_events_filter_main_query( $query ) {
	if ( is_admin() || ! $query->is_main_query() || ! teatatu_events_is_events_archive( $query ) ) {
		return;
	}
	$filters = teatatu_events_current_filters();
	if ( 'calendar' === $filters['view'] ) {
		$month              = teatatu_events_calendar_month( $filters );
		$filters['when']    = 'all';
		$filters['from']    = $month->format( 'Y-m-01' );
		$filters['to']      = $month->format( 'Y-m-t' );
		$query->set( 'posts_per_page', 300 );
		$query->set( 'no_found_rows', true );
	} else {
		$query->set( 'posts_per_page', (int) apply_filters( 'teatatu_events_archive_per_page', 12 ) );
	}
	foreach ( teatatu_events_filter_query_args( $filters ) as $key => $value ) {
		$query->set( $key, $value );
	}
	// The archive's own term is already in the tax_query.
	foreach ( teatatu_events_archive_taxonomies() as $taxonomy ) {
		if ( $query->is_tax( $taxonomy ) ) {
			$query->set( $taxonomy, '' );
			$query->set( 'term', '' );
			$query->set( 'taxonomy', '' );
		}
	}
	$query->set( 'ignore_sticky_posts', true );
}

/**
 * The month the calendar view shows (first day, site time).
 *
 * @param array $filters Filters.
 * @return DateTimeImmutable
 */
function teatatu_events_calendar_month( $filters ) {
	$tz = wp_timezone();
	if ( $filters['month'] ) {
		return new DateTimeImmutable( $filters['month'] . '-01', $tz );
	}
	if ( $filters['from'] ) {
		return new DateTimeImmutable( substr( $filters['from'], 0, 7 ) . '-01', $tz );
	}
	return new DateTimeImmutable( 'first day of this month', $tz );
}

/**
 * A normalised item for any post an events query returns (local event or
 * linked event).
 *
 * @param WP_Post $post Post.
 * @return array
 */
function teatatu_events_item_from_post( $post ) {
	return 'teatatu_evt_link' === $post->post_type ? teatatu_events_normalize_link_item( $post ) : teatatu_events_normalize_item( $post );
}

/**
 * Searches events with archive filters (for theme sections: "this
 * weekend", "more at this venue"…).
 *
 * @param array $filters  Raw or sanitised filters.
 * @param int   $page     Page (1-based).
 * @param int   $per_page Items per page.
 * @return array {items: array[], total: int}
 */
function teatatu_events_search( $filters, $page = 1, $per_page = 12 ) {
	$filters = teatatu_events_sanitize_filters( $filters );
	$query   = new WP_Query(
		array_merge(
			teatatu_events_filter_query_args( $filters ),
			array(
				'posts_per_page'      => max( 1, (int) $per_page ),
				'paged'               => max( 1, (int) $page ),
				'ignore_sticky_posts' => true,
			)
		)
	);
	return array(
		'items' => array_values( array_filter( array_map( 'teatatu_events_item_from_post', $query->posts ) ) ),
		'total' => (int) $query->found_posts,
	);
}

/**
 * Number of upcoming events in a term (for neighbourhood/tag tiles).
 *
 * @param string $taxonomy Taxonomy.
 * @param string $slug     Term slug.
 * @return int
 */
function teatatu_events_upcoming_count( $taxonomy, $slug ) {
	$key = array_search( $taxonomy, teatatu_events_archive_taxonomies(), true );
	if ( false === $key ) {
		return 0;
	}
	return teatatu_events_search( array( $key => $slug ), 1, 1 )['total'];
}

/**
 * The subscribe feed (calendar.ics) URL for a set of filters (taxonomy
 * filters and free are carried over; dates are not — a feed is always the
 * whole upcoming calendar).
 *
 * @param array $filters Sanitised filters.
 * @return string
 */
function teatatu_events_feed_url( $filters = array() ) {
	$args = array();
	foreach ( array( 'neighbourhood', 'category', 'tag', 'venue', 'source' ) as $key ) {
		if ( ! empty( $filters[ $key ] ) ) {
			$args[ $key ] = implode( ',', (array) $filters[ $key ] );
		}
	}
	return add_query_arg( $args, rest_url( 'teatatu-events/v1/calendar.ics' ) );
}
