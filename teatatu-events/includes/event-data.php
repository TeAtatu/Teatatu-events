<?php
/**
 * The event data model: post meta registration, the single validated writer
 * every code path uses (admin forms, the native meta box, REST, importers,
 * series and copies), derived values (end-time defaults, neighbourhood,
 * duplicate-matching keys), date formatting, the shared upcoming/past/range
 * query, and the normalised item array used by every renderer and feed.
 *
 * Start and end are stored as UTC Unix timestamps so upcoming/past queries
 * are plain numeric comparisons with no time zone or DST problems; every
 * value is shown with wp_date() in the site's time zone.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Event status values and labels.
 *
 * @return string[]
 */
function teatatu_events_statuses() {
	return array(
		'scheduled'   => __( 'Scheduled', 'teatatu-events' ),
		'cancelled'   => __( 'Cancelled', 'teatatu-events' ),
		'postponed'   => __( 'Postponed', 'teatatu-events' ),
		'rescheduled' => __( 'Rescheduled', 'teatatu-events' ),
		'sold_out'    => __( 'Sold out', 'teatatu-events' ),
	);
}

/**
 * Link mode values and labels.
 *
 * @return string[]
 */
function teatatu_events_link_modes() {
	return array(
		'auto'     => __( 'Automatic (external if a Read More URL is set)', 'teatatu-events' ),
		'external' => __( 'Always link to the Read More URL', 'teatatu-events' ),
		'local'    => __( "Always use this event's own page", 'teatatu-events' ),
	);
}

/**
 * Full meta key for an event field.
 *
 * @param string $field Field name.
 * @return string
 */
function teatatu_events_mk( $field ) {
	return '_teatatu_events_' . $field;
}

add_action( 'init', 'teatatu_events_register_post_meta' );

/**
 * Registers the event meta that is exposed over REST. Writes need
 * edit_post on the event; derived/flag values are read through the `event`
 * REST field instead (see includes/rest-api.php).
 */
function teatatu_events_register_post_meta() {
	$fields = array(
		'start'         => array( 'integer', 'absint' ),
		'end'           => array( 'integer', 'absint' ),
		'all_day'       => array( 'boolean', 'rest_sanitize_boolean' ),
		'status'        => array( 'string', 'sanitize_key' ),
		'ticket_url'    => array( 'string', 'esc_url_raw' ),
		'price'         => array( 'string', 'sanitize_text_field' ),
		'is_free'       => array( 'boolean', 'rest_sanitize_boolean' ),
		'read_more_url' => array( 'string', 'esc_url_raw' ),
		'link_mode'     => array( 'string', 'sanitize_key' ),
		'image_url'     => array( 'string', 'esc_url_raw' ),
		'room'          => array( 'string', 'sanitize_text_field' ),
		'address'       => array( 'string', 'sanitize_textarea_field' ),
		'no_linking'    => array( 'boolean', 'rest_sanitize_boolean' ),
	);
	foreach ( $fields as $field => $def ) {
		register_post_meta(
			'teatatu_event',
			teatatu_events_mk( $field ),
			array(
				'type'              => $def[0],
				'single'            => true,
				'sanitize_callback' => $def[1],
				'show_in_rest'      => true,
				'auth_callback'     => function ( $allowed, $meta_key, $post_id ) {
					return current_user_can( 'edit_post', $post_id );
				},
			)
		);
	}
}

// ---------------------------------------------------------------------------
// Reading
// ---------------------------------------------------------------------------

/**
 * Event start (UTC timestamp).
 *
 * @param int $post_id Event ID.
 * @return int 0 if unset.
 */
function teatatu_events_get_start( $post_id ) {
	return (int) get_post_meta( $post_id, teatatu_events_mk( 'start' ), true );
}

/**
 * Event end (UTC timestamp).
 *
 * @param int $post_id Event ID.
 * @return int 0 if unset.
 */
function teatatu_events_get_end( $post_id ) {
	return (int) get_post_meta( $post_id, teatatu_events_mk( 'end' ), true );
}

/**
 * Whether the event is all-day.
 *
 * @param int $post_id Event ID.
 * @return bool
 */
function teatatu_events_is_all_day( $post_id ) {
	return (bool) get_post_meta( $post_id, teatatu_events_mk( 'all_day' ), true );
}

/**
 * Whether the event has finished (after its end time).
 *
 * @param int $post_id Event ID.
 * @return bool
 */
function teatatu_events_is_past( $post_id ) {
	$end = teatatu_events_get_end( $post_id );
	return $end > 0 && $end < time();
}

/**
 * Event status, defaulting to scheduled.
 *
 * @param int $post_id Event ID.
 * @return string
 */
function teatatu_events_get_status( $post_id ) {
	$status = get_post_meta( $post_id, teatatu_events_mk( 'status' ), true );
	return array_key_exists( $status, teatatu_events_statuses() ) ? $status : 'scheduled';
}

/**
 * Every editable event field as stored (start/end as timestamps).
 *
 * @param int $post_id Event ID.
 * @return array
 */
function teatatu_events_get_fields( $post_id ) {
	$out = array();
	foreach ( array_keys( teatatu_events_field_defaults() ) as $field ) {
		$out[ $field ] = get_post_meta( $post_id, teatatu_events_mk( $field ), true );
	}
	$out['start']   = (int) $out['start'];
	$out['end']     = (int) $out['end'];
	$out['all_day'] = (bool) $out['all_day'];
	$out['is_free'] = (bool) $out['is_free'];
	$out['no_linking'] = (bool) $out['no_linking'];
	$out['status']  = teatatu_events_get_status( $post_id );
	$out['link_mode'] = $out['link_mode'] ? $out['link_mode'] : 'auto';
	return $out;
}

/**
 * Editable event fields and their defaults.
 *
 * @return array
 */
function teatatu_events_field_defaults() {
	return array(
		'start'         => 0,
		'end'           => 0,
		'all_day'       => false,
		'status'        => 'scheduled',
		'ticket_url'    => '',
		'price'         => '',
		'is_free'       => false,
		'read_more_url' => '',
		'link_mode'     => 'auto',
		'image_url'     => '',
		'room'          => '',
		'address'       => '',
		'no_linking'    => false,
	);
}

// ---------------------------------------------------------------------------
// Dates
// ---------------------------------------------------------------------------

/**
 * Parses a date/time into a UTC timestamp. Accepts a timestamp, ISO 8601
 * with or without an offset (no offset = site time), "Y-m-d H:i[:s]" or
 * "Y-m-d" (all-day: start of day, or end of day when $end_of_day).
 *
 * @param mixed $value      Value.
 * @param bool  $end_of_day For date-only values, use 23:59:59 instead of 00:00.
 * @return int|null
 */
function teatatu_events_parse_local_datetime( $value, $end_of_day = false ) {
	if ( is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) && strlen( $value ) >= 9 ) ) {
		return (int) $value;
	}
	$value = trim( (string) $value );
	if ( '' === $value ) {
		return null;
	}
	$tz = wp_timezone();
	if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
		$dt = DateTimeImmutable::createFromFormat( '!Y-m-d', $value, $tz );
		if ( ! $dt ) {
			return null;
		}
		return $end_of_day ? $dt->setTime( 23, 59, 59 )->getTimestamp() : $dt->getTimestamp();
	}
	try {
		$has_offset = (bool) preg_match( '/(Z|[+-]\d{2}:?\d{2})$/i', $value );
		$dt         = $has_offset ? new DateTimeImmutable( $value ) : new DateTimeImmutable( str_replace( 'T', ' ', $value ), $tz );
		return $dt->getTimestamp();
	} catch ( Exception $e ) {
		return null;
	}
}

/**
 * Start of the local day containing a timestamp.
 *
 * @param int $ts Timestamp.
 * @return int
 */
function teatatu_events_local_day_start( $ts ) {
	$dt = ( new DateTimeImmutable( '@' . (int) $ts ) )->setTimezone( wp_timezone() );
	return $dt->setTime( 0, 0, 0 )->getTimestamp();
}

/**
 * End (23:59:59) of the local day containing a timestamp.
 *
 * @param int $ts Timestamp.
 * @return int
 */
function teatatu_events_local_day_end( $ts ) {
	$dt = ( new DateTimeImmutable( '@' . (int) $ts ) )->setTimezone( wp_timezone() );
	return $dt->setTime( 23, 59, 59 )->getTimestamp();
}

/**
 * Formats a time compactly: "7pm", "7:30pm".
 *
 * @param int $ts Timestamp.
 * @return string
 */
function teatatu_events_format_time( $ts ) {
	$custom = teatatu_events_setting( 'time_format' );
	if ( $custom ) {
		return wp_date( $custom, $ts );
	}
	return '00' === wp_date( 'i', $ts ) ? wp_date( 'ga', $ts ) : wp_date( 'g:ia', $ts );
}

/**
 * Formats a date: "Sat 3 Oct" (with the year when it isn't this year).
 *
 * @param int  $ts        Timestamp.
 * @param bool $with_year Force the year.
 * @return string
 */
function teatatu_events_format_date( $ts, $with_year = null ) {
	$custom = teatatu_events_setting( 'date_format' );
	if ( $custom ) {
		return wp_date( $custom, $ts );
	}
	if ( null === $with_year ) {
		$with_year = wp_date( 'Y', $ts ) !== wp_date( 'Y' );
	}
	return wp_date( $with_year ? 'D j M Y' : 'D j M', $ts );
}

/**
 * Human-readable "when": "Sat 3 Oct, 7–9pm", "Sat 3 – Mon 5 Oct",
 * "Sat 3 Oct (all day)", "Sat 3 Oct, 7pm – Mon 5 Oct, 2pm".
 *
 * @param int  $start   Start timestamp.
 * @param int  $end     End timestamp.
 * @param bool $all_day All-day event.
 * @return string
 */
function teatatu_events_format_when( $start, $end, $all_day ) {
	if ( ! $start ) {
		return __( 'Date to be confirmed', 'teatatu-events' );
	}
	$end      = $end ? $end : $start;
	$same_day = wp_date( 'Y-m-d', $start ) === wp_date( 'Y-m-d', $end );

	if ( $all_day ) {
		if ( $same_day ) {
			/* translators: %s: date. */
			return sprintf( __( '%s (all day)', 'teatatu-events' ), teatatu_events_format_date( $start ) );
		}
		if ( ! teatatu_events_setting( 'date_format' ) && wp_date( 'Y-m', $start ) === wp_date( 'Y-m', $end ) ) {
			return wp_date( 'D j', $start ) . ' – ' . teatatu_events_format_date( $end );
		}
		return teatatu_events_format_date( $start ) . ' – ' . teatatu_events_format_date( $end );
	}

	if ( $same_day ) {
		$from = teatatu_events_format_time( $start );
		$to   = teatatu_events_format_time( $end );
		if ( $start === $end ) {
			return teatatu_events_format_date( $start ) . ', ' . $from;
		}
		if ( ! teatatu_events_setting( 'time_format' ) && wp_date( 'a', $start ) === wp_date( 'a', $end ) ) {
			$from = preg_replace( '/(am|pm)$/', '', $from );
		}
		return teatatu_events_format_date( $start ) . ', ' . $from . '–' . $to;
	}
	return teatatu_events_format_date( $start ) . ', ' . teatatu_events_format_time( $start ) . ' – ' . teatatu_events_format_date( $end ) . ', ' . teatatu_events_format_time( $end );
}

// ---------------------------------------------------------------------------
// Writing (the one validated writer)
// ---------------------------------------------------------------------------

/**
 * Validates and normalises event fields against a current set of values
 * (no writing). Unknown keys are ignored; omitted keys keep their current
 * values.
 *
 * Start/end accept anything teatatu_events_parse_local_datetime() does. An
 * empty end becomes start + the default duration (or the end of the start
 * day for all-day events). All-day events snap to whole local days.
 *
 * @param array $fields  Fields to apply.
 * @param array $current Current values (see teatatu_events_field_defaults()).
 * @return array|WP_Error Normalised values.
 */
function teatatu_events_validate_fields( $fields, $current = null ) {
	$current = null === $current ? teatatu_events_field_defaults() : array_merge( teatatu_events_field_defaults(), $current );
	$new     = $current;

	if ( array_key_exists( 'all_day', $fields ) ) {
		$new['all_day'] = (bool) rest_sanitize_boolean( $fields['all_day'] );
	}
	if ( array_key_exists( 'start', $fields ) ) {
		$parsed = teatatu_events_parse_local_datetime( $fields['start'] );
		if ( null === $parsed && '' !== trim( (string) $fields['start'] ) ) {
			return new WP_Error( 'teatatu_events_invalid_date', __( 'The start date could not be understood.', 'teatatu-events' ), array( 'status' => 400 ) );
		}
		$new['start'] = (int) $parsed;
	}
	if ( array_key_exists( 'end', $fields ) ) {
		$raw_end = $fields['end'];
		$parsed  = '' === trim( (string) $raw_end ) ? null : teatatu_events_parse_local_datetime( $raw_end, true );
		if ( null === $parsed && '' !== trim( (string) $raw_end ) ) {
			return new WP_Error( 'teatatu_events_invalid_date', __( 'The end date could not be understood.', 'teatatu-events' ), array( 'status' => 400 ) );
		}
		$new['end'] = (int) $parsed;
	} elseif ( array_key_exists( 'start', $fields ) && $current['start'] && $current['end'] && $new['start'] ) {
		// Moving the start without giving an end keeps the same duration.
		$new['end'] = $new['start'] + max( 0, $current['end'] - $current['start'] );
	}

	if ( ! $new['start'] ) {
		return new WP_Error( 'teatatu_events_missing_start', __( 'An event needs a start date.', 'teatatu-events' ), array( 'status' => 400 ) );
	}

	if ( $new['all_day'] ) {
		$new['start'] = teatatu_events_local_day_start( $new['start'] );
		$new['end']   = teatatu_events_local_day_end( $new['end'] ? $new['end'] : $new['start'] );
	} elseif ( ! $new['end'] ) {
		$new['end'] = $new['start'] + max( 5, (int) teatatu_events_setting( 'default_duration' ) ) * MINUTE_IN_SECONDS;
	}
	if ( $new['end'] < $new['start'] ) {
		return new WP_Error( 'teatatu_events_invalid_range', __( 'The event ends before it starts.', 'teatatu-events' ), array( 'status' => 400 ) );
	}

	if ( array_key_exists( 'status', $fields ) ) {
		$status = sanitize_key( $fields['status'] );
		if ( ! array_key_exists( $status, teatatu_events_statuses() ) ) {
			return new WP_Error( 'rest_invalid_param', __( 'Unknown event status.', 'teatatu-events' ), array( 'status' => 400 ) );
		}
		$new['status'] = $status;
	}
	if ( array_key_exists( 'link_mode', $fields ) ) {
		$mode             = sanitize_key( $fields['link_mode'] );
		$new['link_mode'] = array_key_exists( $mode, teatatu_events_link_modes() ) ? $mode : 'auto';
	}
	foreach ( array( 'ticket_url', 'read_more_url', 'image_url' ) as $url_field ) {
		if ( array_key_exists( $url_field, $fields ) ) {
			$raw = trim( (string) $fields[ $url_field ] );
			$url = esc_url_raw( $raw, array( 'http', 'https' ) );
			if ( '' !== $raw && '' === $url ) {
				return new WP_Error( 'rest_invalid_param', __( 'A URL is not valid.', 'teatatu-events' ), array( 'status' => 400, 'field' => $url_field ) );
			}
			$new[ $url_field ] = $url;
		}
	}
	foreach ( array( 'price', 'room' ) as $text_field ) {
		if ( array_key_exists( $text_field, $fields ) ) {
			$new[ $text_field ] = sanitize_text_field( (string) $fields[ $text_field ] );
		}
	}
	if ( array_key_exists( 'address', $fields ) ) {
		$new['address'] = sanitize_textarea_field( (string) $fields['address'] );
	}
	foreach ( array( 'is_free', 'no_linking' ) as $bool_field ) {
		if ( array_key_exists( $bool_field, $fields ) ) {
			$new[ $bool_field ] = (bool) rest_sanitize_boolean( $fields[ $bool_field ] );
		}
	}
	return $new;
}

/**
 * Validates and saves event fields. Every write path uses this.
 *
 * @param int   $post_id Event ID.
 * @param array $fields  Fields to save.
 * @return true|WP_Error
 */
function teatatu_events_save_fields( $post_id, $fields ) {
	$new = teatatu_events_validate_fields( $fields, teatatu_events_get_fields( $post_id ) );
	if ( is_wp_error( $new ) ) {
		return $new;
	}
	foreach ( teatatu_events_field_defaults() as $field => $default ) {
		$value = $new[ $field ];
		if ( is_bool( $value ) ) {
			$value = $value ? 1 : 0;
		}
		update_post_meta( $post_id, teatatu_events_mk( $field ), $value );
	}
	return true;
}

/**
 * Pulls event fields out of a flat request array (admin forms use names
 * like `tte_start_date`/`tte_start_time`).
 *
 * @param array $src Unslashed request data.
 * @return array
 */
function teatatu_events_fields_from_form( $src ) {
	$all_day = ! empty( $src['tte_all_day'] );
	$start   = trim( ( $src['tte_start_date'] ?? '' ) . ( $all_day ? '' : ' ' . ( $src['tte_start_time'] ?? '' ) ) );
	$end_d   = trim( (string) ( $src['tte_end_date'] ?? '' ) );
	$end_t   = trim( (string) ( $src['tte_end_time'] ?? '' ) );
	if ( '' === $end_d && '' !== $end_t && ! $all_day ) {
		$end_d = (string) ( $src['tte_start_date'] ?? '' );
	}
	$end = '' === $end_d ? '' : trim( $end_d . ( $all_day ? '' : ' ' . $end_t ) );
	return array(
		'start'         => $start,
		'end'           => $end,
		'all_day'       => $all_day,
		'status'        => $src['tte_status'] ?? 'scheduled',
		'ticket_url'    => $src['tte_ticket_url'] ?? '',
		'price'         => $src['tte_price'] ?? '',
		'is_free'       => ! empty( $src['tte_is_free'] ),
		'read_more_url' => $src['tte_read_more_url'] ?? '',
		'link_mode'     => $src['tte_link_mode'] ?? 'auto',
		'image_url'     => $src['tte_image_url'] ?? '',
		'room'          => $src['tte_room'] ?? '',
		'address'       => $src['tte_address'] ?? '',
		'no_linking'    => ! empty( $src['tte_no_linking'] ),
	);
}

// ---------------------------------------------------------------------------
// Derived values
// ---------------------------------------------------------------------------

/**
 * Recomputes everything derived from an event's stored fields and terms:
 * the default end time, the neighbourhood term, the needs-venue flag (cleared
 * once a venue is set), duplicate-matching keys, and change notifications
 * (linked-event snapshots, cache versions). Safe to call repeatedly.
 *
 * @param int $post_id Event ID.
 */
function teatatu_events_refresh_derived( $post_id ) {
	static $running = array();
	$post_id = (int) $post_id;
	if ( isset( $running[ $post_id ] ) || 'teatatu_event' !== get_post_type( $post_id ) ) {
		return;
	}
	$running[ $post_id ] = true;

	$start = teatatu_events_get_start( $post_id );
	$end   = teatatu_events_get_end( $post_id );
	if ( $start && ( ! $end || $end < $start ) ) {
		$all_day = teatatu_events_is_all_day( $post_id );
		$end     = $all_day ? teatatu_events_local_day_end( $start ) : $start + max( 5, (int) teatatu_events_setting( 'default_duration' ) ) * MINUTE_IN_SECONDS;
		update_post_meta( $post_id, teatatu_events_mk( 'end' ), $end );
	}
	if ( ! get_post_meta( $post_id, teatatu_events_mk( 'status' ), true ) ) {
		update_post_meta( $post_id, teatatu_events_mk( 'status' ), 'scheduled' );
	}

	$venue_id = teatatu_events_first_term_id( $post_id, 'teatatu_events_venue' );
	if ( $venue_id ) {
		delete_post_meta( $post_id, teatatu_events_mk( 'needs_venue' ) );
	}

	teatatu_events_assign_event_neighbourhood( $post_id );

	update_post_meta( $post_id, teatatu_events_mk( 'fuzzy_key' ), teatatu_events_compute_fuzzy_key( $post_id ) );

	teatatu_events_bump_cache_version();
	do_action( 'teatatu_events_event_changed', $post_id );

	unset( $running[ $post_id ] );
}

add_action( 'save_post_teatatu_event', 'teatatu_events_refresh_on_save', 30 );

/**
 * Refreshes derived values after any save (classic editor, block editor,
 * wp_insert_post from importers).
 *
 * @param int $post_id Event ID.
 */
function teatatu_events_refresh_on_save( $post_id ) {
	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
		return;
	}
	if ( ! empty( $GLOBALS['teatatu_events_defer_refresh'] ) ) {
		return;
	}
	teatatu_events_refresh_derived( $post_id );
}

add_action( 'set_object_terms', 'teatatu_events_refresh_on_terms', 10, 4 );

/**
 * Venue/tag/source changes alter the place, keys and badges.
 *
 * @param int    $object_id Object ID.
 * @param array  $terms     Terms.
 * @param array  $tt_ids    Term taxonomy IDs.
 * @param string $taxonomy  Taxonomy.
 */
function teatatu_events_refresh_on_terms( $object_id, $terms, $tt_ids, $taxonomy ) {
	if ( in_array( $taxonomy, array( 'teatatu_events_venue', 'teatatu_events_tag', 'teatatu_events_category', 'teatatu_events_source' ), true ) && empty( $GLOBALS['teatatu_events_defer_refresh'] ) ) {
		teatatu_events_refresh_derived( $object_id );
	}
}

/**
 * Runs a callback with automatic refreshes suspended, then refreshes once.
 * Used by multi-step writers (admin forms, importers) so derived values are
 * computed after meta *and* terms are all in place.
 *
 * @param int      $post_id  Event ID (0 if the callback creates it; return the ID).
 * @param callable $callback Callback; must return the event ID or a WP_Error.
 * @return int|WP_Error
 */
function teatatu_events_batch_write( $post_id, $callback ) {
	$GLOBALS['teatatu_events_defer_refresh'] = ( $GLOBALS['teatatu_events_defer_refresh'] ?? 0 ) + 1;
	try {
		$result = call_user_func( $callback, $post_id );
	} finally {
		$GLOBALS['teatatu_events_defer_refresh']--;
	}
	if ( ! is_wp_error( $result ) && $result ) {
		teatatu_events_refresh_derived( (int) $result );
	}
	return $result;
}

/**
 * Fuzzy duplicate key: normalised title + start (minute, or date for
 * all-day) + place (venue slug, else neighbourhood + normalised street).
 *
 * @param int $post_id Event ID.
 * @return string
 */
function teatatu_events_compute_fuzzy_key( $post_id ) {
	$start = teatatu_events_get_start( $post_id );
	if ( ! $start ) {
		return '';
	}
	$title = teatatu_events_fold_text( get_post_field( 'post_title', $post_id, 'raw' ) );
	$when  = teatatu_events_is_all_day( $post_id ) ? wp_date( 'Y-m-d', $start ) : gmdate( 'Y-m-d H:i', $start );
	$venue = teatatu_events_first_term( $post_id, 'teatatu_events_venue' );
	if ( $venue ) {
		$place = 'v:' . $venue->slug;
	} else {
		$parts = teatatu_events_parse_address_text( get_post_meta( $post_id, teatatu_events_mk( 'address' ), true ) );
		$nbhd  = teatatu_events_first_term( $post_id, 'teatatu_events_neighbourhood' );
		$place = 'a:' . ( $nbhd ? $nbhd->slug : '' ) . '|' . teatatu_events_normalize_street( $parts['street'] ) . '|' . $parts['number'];
	}
	return sha1( $title . '|' . $when . '|' . $place );
}

// ---------------------------------------------------------------------------
// Links and places
// ---------------------------------------------------------------------------

/**
 * Where an event's card links: its own page, or its Read More URL.
 *
 * @param int $post_id Event ID.
 * @return array {mode: 'local'|'external', url: string}
 */
function teatatu_events_resolve_link( $post_id ) {
	$mode = get_post_meta( $post_id, teatatu_events_mk( 'link_mode' ), true );
	$more = get_post_meta( $post_id, teatatu_events_mk( 'read_more_url' ), true );
	if ( 'local' === $mode || ( 'external' !== $mode && ! $more ) || ! $more ) {
		return array( 'mode' => 'local', 'url' => get_permalink( $post_id ) );
	}
	return array( 'mode' => 'external', 'url' => $more );
}

/**
 * The event's place: Venue (Location, its structured address) with Room, or
 * the event's own Address with Room, or nothing.
 *
 * @param int $post_id Event ID.
 * @return array {location, room, address, line, map_url, postal, lat, lng, venue_id, venue_slug}
 */
function teatatu_events_get_place( $post_id ) {
	$room  = (string) get_post_meta( $post_id, teatatu_events_mk( 'room' ), true );
	$venue = teatatu_events_first_term( $post_id, 'teatatu_events_venue' );
	$place = array(
		'location'   => '',
		'room'       => $room,
		'address'    => '',
		'line'       => '',
		'map_url'    => '',
		'postal'     => array(),
		'lat'        => '',
		'lng'        => '',
		'venue_id'   => 0,
		'venue_slug' => '',
	);
	if ( $venue ) {
		$place['location']   = $venue->name;
		$place['venue_id']   = (int) $venue->term_id;
		$place['venue_slug'] = $venue->slug;
		$place['address']    = teatatu_events_format_address( $venue->term_id, 'single_line' );
		$place['postal']     = teatatu_events_venue_postal_address( $venue->term_id );
		$place['lat']        = get_term_meta( $venue->term_id, 'teatatu_events_addr_lat', true );
		$place['lng']        = get_term_meta( $venue->term_id, 'teatatu_events_addr_lng', true );
		$place['map_url']    = get_term_meta( $venue->term_id, 'teatatu_events_venue_map_url', true );
	} else {
		$address          = (string) get_post_meta( $post_id, teatatu_events_mk( 'address' ), true );
		$place['address'] = trim( preg_replace( '/\s*[\r\n]+\s*/', ', ', $address ) );
	}
	$place['line'] = implode( ', ', array_filter( array( $place['location'], $room, $place['address'] ) ) );
	if ( ! $place['map_url'] && ( $place['address'] || $place['location'] ) ) {
		$query            = $place['address'] ? $place['address'] : $place['location'];
		$place['map_url'] = 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( $query );
	}
	return $place;
}

/**
 * Term data of a post for rendering: [{slug, name, color}].
 *
 * @param int    $post_id  Post ID.
 * @param string $taxonomy Taxonomy.
 * @return array[]
 */
function teatatu_events_term_list( $post_id, $taxonomy ) {
	$terms = get_the_terms( $post_id, $taxonomy );
	if ( empty( $terms ) || is_wp_error( $terms ) ) {
		return array();
	}
	$out = array();
	foreach ( $terms as $term ) {
		$out[] = array(
			'id'    => (int) $term->term_id,
			'slug'  => $term->slug,
			'name'  => $term->name,
			'color' => 'teatatu_events_tag' === $taxonomy ? (string) get_term_meta( $term->term_id, 'teatatu_events_tag_color', true ) : '',
		);
	}
	return $out;
}

/**
 * Builds the plain-array representation of an event used by every
 * renderer, the REST API, network aggregation and iCal output.
 *
 * @param WP_Post $post Event post.
 * @return array
 */
function teatatu_events_normalize_item( $post ) {
	$id       = (int) $post->ID;
	$fields   = teatatu_events_get_fields( $id );
	$link     = teatatu_events_resolve_link( $id );
	$nbhd     = teatatu_events_first_term( $id, 'teatatu_events_neighbourhood' );
	$source   = teatatu_events_first_term( $id, 'teatatu_events_source' );
	$category = teatatu_events_first_term( $id, 'teatatu_events_category' );
	$statuses = teatatu_events_statuses();
	$site_id  = get_current_blog_id();
	$ext_key  = (string) get_post_meta( $id, teatatu_events_mk( 'external_key' ), true );

	return array(
		'id'            => $id,
		'site_id'       => $site_id,
		'type'          => 'event',
		'title'         => get_the_title( $post ),
		'excerpt'       => get_the_excerpt( $post ),
		'permalink'     => get_permalink( $post ),
		'link'          => $link['url'],
		'link_mode'     => $link['mode'],
		'read_more_url' => $fields['read_more_url'],
		'image_url'     => teatatu_events_get_image_url( $id ),
		'start'         => $fields['start'],
		'end'           => $fields['end'],
		'all_day'       => $fields['all_day'],
		'status'        => $fields['status'],
		'status_label'  => $statuses[ $fields['status'] ],
		'display_when'  => teatatu_events_format_when( $fields['start'], $fields['end'], $fields['all_day'] ),
		'is_past'       => $fields['end'] && $fields['end'] < time(),
		'place'         => teatatu_events_get_place( $id ),
		'neighbourhood' => $nbhd ? array( 'id' => (int) $nbhd->term_id, 'slug' => $nbhd->slug, 'name' => $nbhd->name ) : null,
		'tags'          => teatatu_events_term_list( $id, 'teatatu_events_tag' ),
		'categories'    => teatatu_events_term_list( $id, 'teatatu_events_category' ),
		'category'      => $category ? $category->name : '',
		'source'        => $source ? $source->name : '',
		'source_slug'   => $source ? $source->slug : '',
		'ticket_url'    => $fields['ticket_url'],
		'price'         => $fields['price'],
		'is_free'       => $fields['is_free'],
		'series_id'     => (int) get_post_meta( $id, teatatu_events_mk( 'series_id' ), true ),
		'external_key'  => $ext_key,
		'fuzzy_key'     => (string) get_post_meta( $id, teatatu_events_mk( 'fuzzy_key' ), true ),
		'imported'      => (bool) get_post_meta( $id, teatatu_events_mk( 'feed_id' ), true ),
		'modified_gmt'  => get_post_field( 'post_modified_gmt', $post ),
		'uid'           => teatatu_events_ics_uid( $ext_key, $id, $site_id ),
		'linked'        => false,
		'home_site'     => teatatu_events_site_info( $site_id ),
		'link_note'     => '',
		'also_on'       => array(),
	);
}

// ---------------------------------------------------------------------------
// Queries
// ---------------------------------------------------------------------------

/**
 * WP_Query arguments for upcoming/past/all events within an optional range,
 * sorted by start. The single source of these rules for every listing.
 *
 * Upcoming = not yet ended (end ≥ now). Past = ended. A range matches any
 * event overlapping it (start ≤ to and end ≥ from).
 *
 * @param string   $when  'upcoming', 'past' or 'all'.
 * @param int|null $from  Range start timestamp.
 * @param int|null $to    Range end timestamp.
 * @param string   $order 'ASC' or 'DESC'.
 * @return array
 */
function teatatu_events_query_args( $when = 'upcoming', $from = null, $to = null, $order = 'ASC' ) {
	$now        = time();
	$meta_query = array(
		'relation'  => 'AND',
		'tte_start' => array(
			'key'     => teatatu_events_mk( 'start' ),
			'type'    => 'NUMERIC',
			'compare' => 'EXISTS',
		),
	);
	if ( 'upcoming' === $when ) {
		$meta_query[] = array( 'key' => teatatu_events_mk( 'end' ), 'value' => $now, 'type' => 'NUMERIC', 'compare' => '>=' );
	} elseif ( 'past' === $when ) {
		$meta_query[] = array( 'key' => teatatu_events_mk( 'end' ), 'value' => $now, 'type' => 'NUMERIC', 'compare' => '<' );
	}
	if ( $to ) {
		$meta_query[] = array( 'key' => teatatu_events_mk( 'start' ), 'value' => (int) $to, 'type' => 'NUMERIC', 'compare' => '<=' );
	}
	if ( $from ) {
		$meta_query[] = array( 'key' => teatatu_events_mk( 'end' ), 'value' => (int) $from, 'type' => 'NUMERIC', 'compare' => '>=' );
	}
	return array(
		'meta_query' => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		'orderby'    => array( 'tte_start' => 'DESC' === strtoupper( $order ) ? 'DESC' : 'ASC' ),
	);
}

/**
 * Parses a shortcode/REST date bound: "YYYY-MM-DD", or relative ("+30 days",
 * "today", "next monday").
 *
 * @param string $value      Value.
 * @param bool   $end_of_day Use the end of that day.
 * @return int|null
 */
function teatatu_events_parse_bound( $value, $end_of_day = false ) {
	$value = trim( (string) $value );
	if ( '' === $value ) {
		return null;
	}
	if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
		return teatatu_events_parse_local_datetime( $value, $end_of_day );
	}
	try {
		$dt = new DateTimeImmutable( $value, wp_timezone() );
		return $end_of_day ? $dt->setTime( 23, 59, 59 )->getTimestamp() : $dt->getTimestamp();
	} catch ( Exception $e ) {
		return null;
	}
}

add_action( 'admin_notices', 'teatatu_events_missing_start_notice' );

/**
 * Warns on the native edit screen when an event has no start date yet.
 */
function teatatu_events_missing_start_notice() {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || 'teatatu_event' !== $screen->post_type || 'post' !== $screen->base ) {
		return;
	}
	global $post;
	if ( $post && 'auto-draft' !== $post->post_status && ! teatatu_events_get_start( $post->ID ) ) {
		echo '<div class="notice notice-warning"><p>' . esc_html__( 'This event has no start date yet, so it will not appear in any listing. Set it in Event Details.', 'teatatu-events' ) . '</p></div>';
	}
}
