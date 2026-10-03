<?php
/**
 * REST API.
 *
 * Core routes (/wp/v2/events, /wp/v2/event-*) come from the post type and
 * taxonomy registrations. This file adds:
 *
 *  - the writable `event` field on /wp/v2/events — the recommended interface
 *    for agents and integrations (validated by the same writer as every
 *    other path);
 *  - extra /wp/v2/events query params: when, event_from, event_to,
 *    orderby=event_start, include_draft_copies;
 *  - agent rules: a start date is required on create, a near-identical
 *    event returns 409 teatatu_events_duplicate_suspected, and an address
 *    with no venue sets the needs-venue flag;
 *  - the teatatu-events/v1 namespace: public events range query, per-event
 *    .ics, subscribe feeds, network feed, venue matching, duplicate / draft
 *    copy / merge, and linked-event management.
 *
 * Agent-facing documentation lives in MCP-GUIDE.md; keep both in step.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'rest_api_init', 'teatatu_events_register_rest' );

/**
 * Registers the `event` field and the custom routes.
 */
function teatatu_events_register_rest() {
	register_rest_field(
		'teatatu_event',
		'event',
		array(
			'get_callback'    => 'teatatu_events_rest_get_event_field',
			'update_callback' => 'teatatu_events_rest_update_event_field',
			'schema'          => array(
				'description' => __( 'Event details. Send start/end as local time without an offset (site time zone), or with a correct offset; all-day events use dates only, end = last day inclusive.', 'teatatu-events' ),
				'type'        => 'object',
				'context'     => array( 'view', 'edit', 'embed' ),
				'properties'  => array(
					'start'         => array( 'type' => 'string' ),
					'end'           => array( 'type' => array( 'string', 'null' ) ),
					'all_day'       => array( 'type' => 'boolean' ),
					'status'        => array( 'type' => 'string', 'enum' => array_keys( teatatu_events_statuses() ) ),
					'room'          => array( 'type' => 'string' ),
					'address'       => array( 'type' => 'string' ),
					'ticket_url'    => array( 'type' => 'string' ),
					'price'         => array( 'type' => 'string' ),
					'is_free'       => array( 'type' => 'boolean' ),
					'link_mode'     => array( 'type' => 'string', 'enum' => array_keys( teatatu_events_link_modes() ) ),
					'read_more_url' => array( 'type' => 'string' ),
					'image_url'     => array( 'type' => 'string' ),
					'no_linking'    => array( 'type' => 'boolean' ),
					'start_ts'      => array( 'type' => 'integer', 'readonly' => true ),
					'end_ts'        => array( 'type' => 'integer', 'readonly' => true ),
					'display_when'  => array( 'type' => 'string', 'readonly' => true ),
					'display_place' => array( 'type' => 'string', 'readonly' => true ),
					'neighbourhood' => array( 'type' => array( 'object', 'null' ), 'readonly' => true ),
					'is_past'       => array( 'type' => 'boolean', 'readonly' => true ),
					'needs_venue'   => array( 'type' => 'boolean', 'readonly' => true ),
					'needs_neighbourhood' => array( 'type' => 'boolean', 'readonly' => true ),
					'removed_at_source'   => array( 'type' => array( 'string', 'null' ), 'readonly' => true ),
					'pending_update'      => array( 'type' => 'boolean', 'readonly' => true ),
					'series_id'     => array( 'type' => array( 'integer', 'null' ), 'readonly' => true ),
					'draft_of'      => array( 'type' => array( 'integer', 'null' ), 'readonly' => true ),
					'has_draft'     => array( 'type' => 'boolean', 'readonly' => true ),
				),
			),
		)
	);

	$ns = 'teatatu-events/v1';
	$id = '(?P<id>\d+)';

	register_rest_route(
		$ns,
		'/events',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'teatatu_events_rest_public_events',
			'permission_callback' => '__return_true',
		)
	);
	register_rest_route(
		$ns,
		'/events/' . $id . '/ics',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'teatatu_events_rest_event_ics',
			'permission_callback' => '__return_true',
		)
	);
	register_rest_route(
		$ns,
		'/calendar.ics',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'teatatu_events_rest_calendar_ics',
			'permission_callback' => '__return_true',
		)
	);
	register_rest_route(
		$ns,
		'/network-calendar.ics',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'teatatu_events_rest_network_calendar_ics',
			'permission_callback' => '__return_true',
		)
	);
	register_rest_route(
		$ns,
		'/network-feed',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'teatatu_events_rest_network_feed',
			'permission_callback' => '__return_true',
		)
	);
	register_rest_route(
		$ns,
		'/events/' . $id . '/duplicate',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'teatatu_events_rest_duplicate',
			'permission_callback' => function () {
				return current_user_can( 'edit_teatatu_events_items' );
			},
		)
	);
	register_rest_route(
		$ns,
		'/events/' . $id . '/draft',
		array(
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => 'teatatu_events_rest_create_draft',
				'permission_callback' => function () {
					return current_user_can( 'edit_teatatu_events_items' );
				},
			),
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => 'teatatu_events_rest_discard_draft',
				'permission_callback' => function () {
					return current_user_can( 'edit_teatatu_events_items' );
				},
			),
		)
	);
	register_rest_route(
		$ns,
		'/events/' . $id . '/merge',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'teatatu_events_rest_merge',
			'permission_callback' => function () {
				return current_user_can( 'edit_published_teatatu_events_items' );
			},
		)
	);
	register_rest_route(
		$ns,
		'/venues/match',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'teatatu_events_rest_venue_match',
			'permission_callback' => function () {
				return current_user_can( 'assign_teatatu_events_venues' );
			},
		)
	);
	register_rest_route(
		$ns,
		'/links',
		array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => 'teatatu_events_rest_list_links',
				'permission_callback' => function () {
					return current_user_can( 'manage_teatatu_events_links' );
				},
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => 'teatatu_events_rest_create_link',
				'permission_callback' => function () {
					return current_user_can( 'manage_teatatu_events_links' );
				},
			),
		)
	);
	register_rest_route(
		$ns,
		'/links/' . $id,
		array(
			'methods'             => WP_REST_Server::DELETABLE,
			'callback'            => 'teatatu_events_rest_delete_link',
			'permission_callback' => function () {
				return current_user_can( 'manage_teatatu_events_links' );
			},
		)
	);
	register_rest_route(
		$ns,
		'/linkable',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'teatatu_events_rest_linkable',
			'permission_callback' => function () {
				return current_user_can( 'manage_teatatu_events_links' );
			},
		)
	);
}

// ---------------------------------------------------------------------------
// The `event` field
// ---------------------------------------------------------------------------

/**
 * Formats a stored timestamp for REST output (local ISO 8601 with offset,
 * or a plain date for all-day events).
 *
 * @param int  $ts      Timestamp.
 * @param bool $all_day All-day.
 * @return string|null
 */
function teatatu_events_rest_time( $ts, $all_day ) {
	if ( ! $ts ) {
		return null;
	}
	return $all_day ? wp_date( 'Y-m-d', $ts ) : wp_date( 'c', $ts );
}

/**
 * GET callback for the `event` field.
 *
 * @param array $object Prepared post data.
 * @return array
 */
function teatatu_events_rest_get_event_field( $object ) {
	$id      = (int) $object['id'];
	$f       = teatatu_events_get_fields( $id );
	$nbhd    = teatatu_events_first_term( $id, 'teatatu_events_neighbourhood' );
	$removed = (int) get_post_meta( $id, teatatu_events_mk( 'removed_at_source' ), true );
	$series  = (int) get_post_meta( $id, teatatu_events_mk( 'series_id' ), true );
	$draft   = (int) get_post_meta( $id, teatatu_events_mk( 'draft_of' ), true );
	$place   = teatatu_events_get_place( $id );
	return array(
		'start'               => teatatu_events_rest_time( $f['start'], $f['all_day'] ),
		'end'                 => teatatu_events_rest_time( $f['end'], $f['all_day'] ),
		'all_day'             => $f['all_day'],
		'status'              => $f['status'],
		'room'                => $f['room'],
		'address'             => $f['address'],
		'ticket_url'          => $f['ticket_url'],
		'price'               => $f['price'],
		'is_free'             => $f['is_free'],
		'link_mode'           => $f['link_mode'],
		'read_more_url'       => $f['read_more_url'],
		'image_url'           => $f['image_url'],
		'no_linking'          => $f['no_linking'],
		'start_ts'            => $f['start'],
		'end_ts'              => $f['end'],
		'display_when'        => teatatu_events_format_when( $f['start'], $f['end'], $f['all_day'] ),
		'display_place'       => $place['line'],
		'neighbourhood'       => $nbhd ? array(
			'id'   => (int) $nbhd->term_id,
			'name' => $nbhd->name,
			'slug' => $nbhd->slug,
			'via'  => (string) get_post_meta( $id, teatatu_events_mk( 'nbhd_via' ), true ),
		) : null,
		'is_past'             => $f['end'] && $f['end'] < time(),
		'needs_venue'         => (bool) get_post_meta( $id, teatatu_events_mk( 'needs_venue' ), true ),
		'needs_neighbourhood' => (bool) get_post_meta( $id, teatatu_events_mk( 'needs_nbhd' ), true ),
		'removed_at_source'   => $removed ? wp_date( 'c', $removed ) : null,
		'pending_update'      => is_array( get_post_meta( $id, teatatu_events_mk( 'pending_update' ), true ) ),
		'series_id'           => $series ? $series : null,
		'draft_of'            => $draft ? $draft : null,
		'has_draft'           => (bool) teatatu_events_get_draft_copy_id( $id ),
	);
}

/**
 * Writable keys of the `event` field.
 *
 * @return string[]
 */
function teatatu_events_rest_writable_keys() {
	return array( 'start', 'end', 'all_day', 'status', 'room', 'address', 'ticket_url', 'price', 'is_free', 'link_mode', 'read_more_url', 'image_url', 'no_linking' );
}

/**
 * UPDATE callback for the `event` field.
 *
 * @param array   $value Field value.
 * @param WP_Post $post  Post.
 * @return true|WP_Error
 */
function teatatu_events_rest_update_event_field( $value, $post ) {
	if ( ! is_array( $value ) ) {
		return new WP_Error( 'rest_invalid_param', __( 'event must be an object.', 'teatatu-events' ), array( 'status' => 400 ) );
	}
	$fields = array_intersect_key( $value, array_flip( teatatu_events_rest_writable_keys() ) );
	if ( isset( $fields['end'] ) && null === $fields['end'] ) {
		$fields['end'] = '';
	}
	$GLOBALS['teatatu_events_defer_refresh'] = ( $GLOBALS['teatatu_events_defer_refresh'] ?? 0 ) + 1;
	$result = teatatu_events_save_fields( $post->ID, $fields );
	$GLOBALS['teatatu_events_defer_refresh']--;
	return is_wp_error( $result ) ? $result : true;
}

add_filter( 'rest_pre_insert_teatatu_event', 'teatatu_events_rest_pre_insert', 10, 2 );

/**
 * Validates the `event` field before anything is written (so a bad request
 * never leaves a half-created event behind), enforces a start date on agent
 * creates, and runs the agent duplicate guard.
 *
 * @param stdClass        $prepared Prepared post.
 * @param WP_REST_Request $request  Request.
 * @return stdClass|WP_Error
 */
function teatatu_events_rest_pre_insert( $prepared, $request ) {
	$event    = $request->get_param( 'event' );
	$creating = empty( $prepared->ID );

	if ( is_array( $event ) ) {
		$all_day = ! empty( $event['all_day'] );
		$start   = isset( $event['start'] ) ? teatatu_events_parse_local_datetime( $event['start'] ) : null;
		if ( isset( $event['start'] ) && null === $start ) {
			return new WP_Error( 'teatatu_events_invalid_date', __( 'event.start could not be understood. Use e.g. 2026-10-03T19:00:00 (site time) or 2026-10-03 for all-day.', 'teatatu-events' ), array( 'status' => 400 ) );
		}
		if ( ! empty( $event['end'] ) ) {
			$end = teatatu_events_parse_local_datetime( $event['end'], true );
			if ( null === $end ) {
				return new WP_Error( 'teatatu_events_invalid_date', __( 'event.end could not be understood.', 'teatatu-events' ), array( 'status' => 400 ) );
			}
			if ( null !== $start ) {
				$s = $all_day ? teatatu_events_local_day_start( $start ) : $start;
				$e = $all_day ? teatatu_events_local_day_end( $end ) : $end;
				if ( $e < $s ) {
					return new WP_Error( 'teatatu_events_invalid_range', __( 'event.end is before event.start.', 'teatatu-events' ), array( 'status' => 400 ) );
				}
			}
			if ( $all_day && preg_match( '/T\d/', (string) $event['end'] . (string) ( $event['start'] ?? '' ) ) ) {
				return new WP_Error( 'teatatu_events_invalid_range', __( 'All-day events take dates only (YYYY-MM-DD), not times.', 'teatatu-events' ), array( 'status' => 400 ) );
			}
		}
		if ( isset( $event['status'] ) && ! array_key_exists( $event['status'], teatatu_events_statuses() ) ) {
			return new WP_Error( 'rest_invalid_param', __( 'Unknown event.status.', 'teatatu-events' ), array( 'status' => 400 ) );
		}
		foreach ( array( 'ticket_url', 'read_more_url', 'image_url' ) as $url_field ) {
			if ( ! empty( $event[ $url_field ] ) && ! wp_http_validate_url( $event[ $url_field ] ) ) {
				return new WP_Error( 'rest_invalid_param', sprintf( /* translators: %s: field */ __( 'event.%s is not a valid http(s) URL.', 'teatatu-events' ), $url_field ), array( 'status' => 400 ) );
			}
		}
	}

	if ( $creating && teatatu_events_current_user_is_agent() ) {
		if ( ! is_array( $event ) || empty( $event['start'] ) ) {
			return new WP_Error( 'teatatu_events_missing_start', __( 'Send event.start when creating an event.', 'teatatu-events' ), array( 'status' => 400 ) );
		}
		$existing = teatatu_events_find_suspected_duplicate( $prepared->post_title ?? '', $event, $request );
		if ( $existing ) {
			return new WP_Error(
				'teatatu_events_duplicate_suspected',
				__( 'An event with the same title, date and place already exists.', 'teatatu-events' ),
				array( 'status' => 409, 'existing_id' => $existing )
			);
		}
	}
	return $prepared;
}

/**
 * Finds an existing event (published, or any draft) with the same
 * normalised title and start date at the same venue or address.
 *
 * @param string          $title   Title.
 * @param array           $event   `event` field.
 * @param WP_REST_Request $request Request.
 * @return int 0 if none.
 */
function teatatu_events_find_suspected_duplicate( $title, $event, $request ) {
	$start = teatatu_events_parse_local_datetime( $event['start'] );
	if ( ! $start || '' === trim( (string) $title ) ) {
		return 0;
	}
	$folded = teatatu_events_fold_text( $title );
	$venues = array_map( 'intval', (array) $request->get_param( 'event-venues' ) );
	$addr   = teatatu_events_parse_address_text( (string) ( $event['address'] ?? '' ) );
	$addr_k = teatatu_events_normalize_street( $addr['street'] ) . '|' . $addr['number'];

	$args = teatatu_events_query_args( 'all', teatatu_events_local_day_start( $start ), teatatu_events_local_day_end( $start ) );
	$ids  = get_posts(
		array_merge(
			$args,
			array(
				'post_type'        => 'teatatu_event',
				'post_status'      => array( 'publish', 'draft', 'pending', 'future' ),
				'posts_per_page'   => 50,
				'fields'           => 'ids',
				'suppress_filters' => true,
			)
		)
	);
	foreach ( $ids as $id ) {
		if ( get_post_meta( $id, teatatu_events_mk( 'draft_of' ), true ) ) {
			continue;
		}
		if ( teatatu_events_fold_text( get_post_field( 'post_title', $id, 'raw' ) ) !== $folded ) {
			continue;
		}
		if ( wp_date( 'Y-m-d', teatatu_events_get_start( $id ) ) !== wp_date( 'Y-m-d', $start ) ) {
			continue;
		}
		$venue = teatatu_events_first_term_id( $id, 'teatatu_events_venue' );
		if ( $venues && $venue && in_array( $venue, $venues, true ) ) {
			return (int) $id;
		}
		$other = teatatu_events_parse_address_text( (string) get_post_meta( $id, teatatu_events_mk( 'address' ), true ) );
		if ( ! $venues && ! $venue && $addr_k === teatatu_events_normalize_street( $other['street'] ) . '|' . $other['number'] ) {
			return (int) $id;
		}
	}
	return 0;
}

add_action( 'rest_after_insert_teatatu_event', 'teatatu_events_rest_after_insert', 10, 3 );

/**
 * After a REST create/update (meta and terms in place): agent needs-venue
 * flag, detaching a manually edited occurrence, and derived values.
 *
 * @param WP_Post         $post     Post.
 * @param WP_REST_Request $request  Request.
 * @param bool            $creating Creating.
 */
function teatatu_events_rest_after_insert( $post, $request, $creating ) {
	if ( teatatu_events_current_user_is_agent() ) {
		$address = (string) get_post_meta( $post->ID, teatatu_events_mk( 'address' ), true );
		if ( '' !== trim( $address ) && ! teatatu_events_first_term_id( $post->ID, 'teatatu_events_venue' ) ) {
			update_post_meta( $post->ID, teatatu_events_mk( 'needs_venue' ), 1 );
		}
	}
	if ( ! $creating && get_post_meta( $post->ID, teatatu_events_mk( 'series_id' ), true ) && ! get_post_meta( $post->ID, teatatu_events_mk( 'draft_of' ), true ) ) {
		update_post_meta( $post->ID, teatatu_events_mk( 'detached' ), 1 );
	}
	teatatu_events_refresh_derived( $post->ID );
}

// ---------------------------------------------------------------------------
// /wp/v2/events query params
// ---------------------------------------------------------------------------

add_filter( 'rest_teatatu_event_collection_params', 'teatatu_events_rest_collection_params' );

/**
 * Declares the extra collection params.
 *
 * @param array $params Params.
 * @return array
 */
function teatatu_events_rest_collection_params( $params ) {
	$params['when'] = array(
		'description' => __( 'upcoming (not yet ended), past (ended) or all.', 'teatatu-events' ),
		'type'        => 'string',
		'enum'        => array( 'upcoming', 'past', 'all' ),
	);
	$params['event_from'] = array(
		'description' => __( 'Only events overlapping this date or later (YYYY-MM-DD).', 'teatatu-events' ),
		'type'        => 'string',
	);
	$params['event_to'] = array(
		'description' => __( 'Only events overlapping this date or earlier (YYYY-MM-DD).', 'teatatu-events' ),
		'type'        => 'string',
	);
	$params['include_draft_copies'] = array(
		'description' => __( 'Include "Edit as draft" working copies.', 'teatatu-events' ),
		'type'        => 'boolean',
		'default'     => false,
	);
	if ( isset( $params['orderby']['enum'] ) ) {
		$params['orderby']['enum'][] = 'event_start';
	}
	return $params;
}

add_filter( 'rest_teatatu_event_query', 'teatatu_events_rest_collection_query', 10, 2 );

/**
 * Applies the extra collection params to the query.
 *
 * @param array           $args    Query args.
 * @param WP_REST_Request $request Request.
 * @return array
 */
function teatatu_events_rest_collection_query( $args, $request ) {
	$when = $request->get_param( 'when' );
	$from = teatatu_events_parse_bound( (string) $request->get_param( 'event_from' ) );
	$to   = teatatu_events_parse_bound( (string) $request->get_param( 'event_to' ), true );
	$by   = 'event_start' === $request->get_param( 'orderby' );
	$meta = isset( $args['meta_query'] ) ? (array) $args['meta_query'] : array();

	if ( $when || $from || $to || $by ) {
		$extra = teatatu_events_query_args( $when ? $when : 'all', $from, $to, $request->get_param( 'order' ) ? $request->get_param( 'order' ) : 'asc' );
		$meta  = array_merge( $meta, $extra['meta_query'] );
		if ( $by ) {
			$args['orderby'] = $extra['orderby'];
		}
	}
	if ( ! $request->get_param( 'include_draft_copies' ) ) {
		$meta[] = array( 'key' => teatatu_events_mk( 'draft_of' ), 'compare' => 'NOT EXISTS' );
	}
	$args['meta_query'] = $meta; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
	return $args;
}

// ---------------------------------------------------------------------------
// teatatu-events/v1 callbacks
// ---------------------------------------------------------------------------

/**
 * Builds listing attributes from a request.
 *
 * @param WP_REST_Request $request Request.
 * @return array
 */
function teatatu_events_rest_atts( WP_REST_Request $request ) {
	$atts = array(
		'count' => $request->get_param( 'per_page' ) ? (int) $request->get_param( 'per_page' ) : 20,
		'page'  => $request->get_param( 'page' ) ? (int) $request->get_param( 'page' ) : 1,
	);
	foreach ( array( 'when', 'from', 'to', 'venue', 'category', 'source', 'tag', 'neighbourhood', 'linked', 'order', 'exclude_sites', 'search', 'dedupe', 'hide_cancelled' ) as $key ) {
		if ( null !== $request->get_param( $key ) ) {
			$atts[ $key ] = sanitize_text_field( (string) $request->get_param( $key ) );
		}
	}
	$atts          = teatatu_events_shortcode_atts( $atts );
	$atts['count'] = min( 100, $atts['count'] );
	return $atts;
}

/**
 * Public, published events (this site, plus linked events).
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response
 */
function teatatu_events_rest_public_events( WP_REST_Request $request ) {
	$atts   = teatatu_events_rest_atts( $request );
	$result = teatatu_events_get_items( $atts, 'site' );
	$resp   = rest_ensure_response( array_map( 'teatatu_events_rest_public_item', $result['items'] ) );
	$resp->header( 'X-WP-Total', (string) $result['total'] );
	return $resp;
}

/**
 * Network feed (Master Site only).
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function teatatu_events_rest_network_feed( WP_REST_Request $request ) {
	if ( ! is_multisite() ) {
		return new WP_Error( 'teatatu_events_not_multisite', __( 'This endpoint is only available on a multisite network.', 'teatatu-events' ), array( 'status' => 404 ) );
	}
	if ( ! teatatu_events_is_master_site() ) {
		return new WP_Error( 'teatatu_events_not_master_site', __( "This endpoint only responds on the network's configured Master Site.", 'teatatu-events' ), array( 'status' => 404 ) );
	}
	$atts   = teatatu_events_rest_atts( $request );
	$result = teatatu_events_get_items( $atts, 'network' );
	$resp   = rest_ensure_response( array_map( 'teatatu_events_rest_public_item', $result['items'] ) );
	$resp->header( 'X-WP-Total', (string) $result['total'] );
	return $resp;
}

/**
 * Public JSON shape of a normalised item.
 *
 * @param array $item Item.
 * @return array
 */
function teatatu_events_rest_public_item( $item ) {
	$out = array(
		'id'            => $item['id'],
		'title'         => $item['title'],
		'excerpt'       => wp_strip_all_tags( $item['excerpt'] ),
		'link'          => $item['link'],
		'start'         => teatatu_events_rest_time( $item['start'], $item['all_day'] ),
		'end'           => teatatu_events_rest_time( $item['end'], $item['all_day'] ),
		'all_day'       => $item['all_day'],
		'status'        => $item['status'],
		'display_when'  => $item['display_when'],
		'place'         => $item['place']['line'],
		'venue'         => $item['place']['location'],
		'room'          => $item['place']['room'],
		'neighbourhood' => $item['neighbourhood'],
		'tags'          => array_map(
			function ( $t ) {
				return array( 'slug' => $t['slug'], 'name' => $t['name'] );
			},
			$item['tags']
		),
		'category'      => $item['category'],
		'source'        => $item['source'],
		'image_url'     => $item['image_url'],
		'ticket_url'    => $item['ticket_url'],
		'price'         => $item['price'],
		'is_free'       => $item['is_free'],
		'linked'        => ! empty( $item['linked'] ),
		'home_site'     => $item['home_site'],
		'event_id'      => $item['id'],
		'also_on'       => $item['also_on'],
	);
	if ( isset( $item['dedupe_group'] ) ) {
		$out['dedupe_group'] = $item['dedupe_group'];
		$out['canonical']    = $item['canonical'];
	}
	return $out;
}

/**
 * Prepares one event for a REST response the same way /wp/v2/events does.
 *
 * @param int $post_id Event ID.
 * @return array
 */
function teatatu_events_rest_prepare_event( $post_id ) {
	$request    = new WP_REST_Request( 'GET', '/wp/v2/events/' . (int) $post_id );
	$request->set_param( 'context', 'edit' );
	$controller = new WP_REST_Posts_Controller( 'teatatu_event' );
	$response   = $controller->prepare_item_for_response( get_post( $post_id ), $request );
	return $response->get_data();
}

/**
 * POST /events/{id}/duplicate.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function teatatu_events_rest_duplicate( WP_REST_Request $request ) {
	$copy = teatatu_events_copy_event( (int) $request['id'], 'duplicate' );
	if ( is_wp_error( $copy ) ) {
		return $copy;
	}
	$response = rest_ensure_response( teatatu_events_rest_prepare_event( $copy ) );
	$response->set_status( 201 );
	return $response;
}

/**
 * POST /events/{id}/draft.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function teatatu_events_rest_create_draft( WP_REST_Request $request ) {
	$id       = (int) $request['id'];
	$existing = teatatu_events_get_draft_copy_id( $id );
	$copy     = teatatu_events_copy_event( $id, 'draft_of' );
	if ( is_wp_error( $copy ) ) {
		return $copy;
	}
	$response = rest_ensure_response( teatatu_events_rest_prepare_event( $copy ) );
	$response->set_status( $existing ? 200 : 201 );
	return $response;
}

/**
 * DELETE /events/{id}/draft.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function teatatu_events_rest_discard_draft( WP_REST_Request $request ) {
	$result = teatatu_events_discard_draft( (int) $request['id'] );
	return is_wp_error( $result ) ? $result : rest_ensure_response( array( 'deleted' => true ) );
}

/**
 * POST /events/{id}/merge — {id} is the published event (or its draft
 * copy). Optional `take`: fields to take from the draft when the live event
 * changed since the copy was made.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function teatatu_events_rest_merge( WP_REST_Request $request ) {
	$id    = (int) $request['id'];
	$draft = get_post_meta( $id, teatatu_events_mk( 'draft_of' ), true ) ? $id : teatatu_events_get_draft_copy_id( $id );
	if ( ! $draft ) {
		return new WP_Error( 'teatatu_events_not_draft_copy', __( 'There is no draft copy to merge.', 'teatatu-events' ), array( 'status' => 404 ) );
	}
	$take   = $request->get_param( 'take' );
	$result = teatatu_events_merge_draft( $draft, is_array( $take ) ? array_map( 'sanitize_key', $take ) : null );
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	return rest_ensure_response( teatatu_events_rest_prepare_event( $result ) );
}

/**
 * GET /venues/match.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response
 */
function teatatu_events_rest_venue_match( WP_REST_Request $request ) {
	$location = sanitize_text_field( (string) $request->get_param( 'location' ) );
	$address  = sanitize_text_field( (string) $request->get_param( 'address' ) );
	$parts    = teatatu_events_parse_address_text( $address, teatatu_events_setting( 'default_country' ) );
	$match    = teatatu_events_match_venue( $location, $parts, $request->get_param( 'lat' ), $request->get_param( 'lng' ) );
	$venue    = null;
	if ( $match ) {
		$venue = array(
			'id'      => $match['term_id'],
			'name'    => $match['name'],
			'address' => teatatu_events_format_address( $match['term_id'] ),
			'matched' => $match['via'],
		);
		$slug  = (string) get_term_meta( $match['term_id'], 'teatatu_events_addr_neighbourhood', true );
		$nbhd  = array( 'slug' => $slug, 'via' => 'venue' );
	} else {
		$nbhd = teatatu_events_resolve_neighbourhood( $parts );
	}
	$nbhd_out = null;
	if ( ! empty( $nbhd['slug'] ) ) {
		$term     = get_term( teatatu_events_neighbourhood_term_id( $nbhd['slug'] ) );
		$nbhd_out = array(
			'slug' => $nbhd['slug'],
			'name' => $term && ! is_wp_error( $term ) ? $term->name : $nbhd['slug'],
			'via'  => $nbhd['via'],
		);
	}
	unset( $parts['name'] );
	return rest_ensure_response(
		array(
			'venue'         => $venue,
			'parsed'        => $parts,
			'neighbourhood' => $nbhd_out,
		)
	);
}

/**
 * GET /links.
 *
 * @return WP_REST_Response|WP_Error
 */
function teatatu_events_rest_list_links() {
	if ( ! teatatu_events_linked_enabled() ) {
		return new WP_Error( 'teatatu_events_linking_off', __( 'Linked events are turned off for this site.', 'teatatu-events' ), array( 'status' => 403 ) );
	}
	$out = array();
	foreach ( get_posts( array( 'post_type' => 'teatatu_evt_link', 'post_status' => 'any', 'posts_per_page' => -1, 'post_parent' => 0 ) ) as $link ) {
		$out[] = array(
			'id'        => $link->ID,
			'title'     => $link->post_title,
			'site_id'   => (int) get_post_meta( $link->ID, '_teatatu_events_link_site_id', true ),
			'event_id'  => (int) get_post_meta( $link->ID, '_teatatu_events_link_event_id', true ),
			'series_id' => (int) get_post_meta( $link->ID, '_teatatu_events_link_series_id', true ),
			'note'      => (string) get_post_meta( $link->ID, '_teatatu_events_link_note', true ),
			'hidden'    => (bool) get_post_meta( $link->ID, '_teatatu_events_link_hidden', true ),
			'shadowed'  => (bool) get_post_meta( $link->ID, '_teatatu_events_link_shadowed', true ),
		);
	}
	return rest_ensure_response( $out );
}

/**
 * POST /links {site_id, event_id, whole_series?, note?}.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function teatatu_events_rest_create_link( WP_REST_Request $request ) {
	$id = teatatu_events_create_link( (int) $request->get_param( 'site_id' ), (int) $request->get_param( 'event_id' ), (string) $request->get_param( 'note' ), (bool) $request->get_param( 'whole_series' ) );
	if ( is_wp_error( $id ) ) {
		return $id;
	}
	$response = rest_ensure_response( array( 'id' => $id ) );
	$response->set_status( 201 );
	return $response;
}

/**
 * DELETE /links/{id}.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function teatatu_events_rest_delete_link( WP_REST_Request $request ) {
	if ( ! teatatu_events_linked_enabled() ) {
		return new WP_Error( 'teatatu_events_linking_off', __( 'Linked events are turned off for this site.', 'teatatu-events' ), array( 'status' => 403 ) );
	}
	$id = (int) $request['id'];
	if ( 'teatatu_evt_link' !== get_post_type( $id ) ) {
		return new WP_Error( 'rest_post_invalid_id', __( 'Link not found.', 'teatatu-events' ), array( 'status' => 404 ) );
	}
	teatatu_events_delete_link( $id );
	return rest_ensure_response( array( 'deleted' => true ) );
}

/**
 * GET /linkable?search=&from=&to=&neighbourhood=.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function teatatu_events_rest_linkable( WP_REST_Request $request ) {
	if ( ! teatatu_events_linked_enabled() ) {
		return new WP_Error( 'teatatu_events_linking_off', __( 'Linked events are turned off for this site.', 'teatatu-events' ), array( 'status' => 403 ) );
	}
	$items = teatatu_events_linkable_items(
		array(
			'search'        => sanitize_text_field( (string) $request->get_param( 'search' ) ),
			'from'          => sanitize_text_field( (string) $request->get_param( 'from' ) ),
			'to'            => sanitize_text_field( (string) $request->get_param( 'to' ) ),
			'neighbourhood' => teatatu_events_current_neighbourhood_slug( sanitize_title( (string) $request->get_param( 'neighbourhood' ) ) ),
		)
	);
	return rest_ensure_response(
		array_map(
			function ( $item ) {
				return array_merge(
					teatatu_events_rest_public_item( $item ),
					array(
						'series_id'    => $item['series_id'],
						'linked_here'  => $item['linked_here'],
						'has_own_copy' => $item['has_own_copy'],
					)
				);
			},
			$items
		)
	);
}
