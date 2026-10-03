<?php
/**
 * iCal (.ics) importer, with a minimal built-in RFC 5545 parser (no bundled
 * library): line unfolding, escaping, VEVENT, DTSTART/DTEND/DURATION (TZID,
 * VALUE=DATE, Z), SUMMARY, DESCRIPTION, LOCATION, URL, UID, STATUS, RRULE,
 * EXDATE, RDATE, RECURRENCE-ID, ATTACH/IMAGE, CATEGORIES and GEO.
 *
 * Recurring VEVENTs are expanded with the same RRULE engine as local series
 * (includes/recurrence.php), keeping up to 12 upcoming occurrences per
 * VEVENT; each occurrence is its own imported event (never a local series,
 * so editors don't manage a rule the source owns). Identity: the UID for a
 * one-off event; UID + occurrence start (or RECURRENCE-ID) for an
 * occurrence, so a moved occurrence stays the same event.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'teatatu_events_ical_cron_tick', 'teatatu_events_run_ical_feeds' );

/**
 * Cron tick for iCal feeds.
 */
function teatatu_events_run_ical_feeds() {
	teatatu_events_run_due_feeds( 'ical' );
}

/**
 * Maps common Windows time zone names (used by Outlook/Exchange) to IANA.
 *
 * @param string $tzid TZID.
 * @return DateTimeZone
 */
function teatatu_events_ical_timezone( $tzid ) {
	$tzid    = trim( (string) $tzid, '"' );
	$windows = array(
		'New Zealand Standard Time' => 'Pacific/Auckland',
		'AUS Eastern Standard Time' => 'Australia/Sydney',
		'UTC'                       => 'UTC',
		'GMT Standard Time'         => 'Europe/London',
		'Pacific Standard Time'     => 'America/Los_Angeles',
		'Eastern Standard Time'     => 'America/New_York',
	);
	if ( isset( $windows[ $tzid ] ) ) {
		$tzid = $windows[ $tzid ];
	}
	try {
		return $tzid ? new DateTimeZone( $tzid ) : wp_timezone();
	} catch ( Exception $e ) {
		return wp_timezone();
	}
}

/**
 * Parses an iCal DATE or DATE-TIME value.
 *
 * @param string $value  Value (20261003, 20261003T190000, 20261003T060000Z).
 * @param array  $params Property params (TZID, VALUE).
 * @param bool   $unused Kept for signature compatibility with UNTIL parsing.
 * @return int Timestamp (0 if unreadable).
 */
function teatatu_events_ical_parse_date( $value, $params = array(), $unused = false ) {
	$info = teatatu_events_ical_parse_date_info( $value, $params );
	return $info ? $info['ts'] : 0;
}

/**
 * Parses an iCal date, also reporting whether it was date-only.
 *
 * @param string $value  Value.
 * @param array  $params Params.
 * @return array|null {ts, date_only, tz}
 */
function teatatu_events_ical_parse_date_info( $value, $params = array() ) {
	$value = trim( (string) $value );
	$tz    = isset( $params['TZID'] ) ? teatatu_events_ical_timezone( $params['TZID'] ) : wp_timezone();
	if ( preg_match( '/^(\d{4})(\d{2})(\d{2})$/', $value, $m ) ) {
		$dt = ( new DateTimeImmutable( 'now', wp_timezone() ) )->setDate( (int) $m[1], (int) $m[2], (int) $m[3] )->setTime( 0, 0 );
		return array( 'ts' => $dt->getTimestamp(), 'date_only' => true, 'tz' => wp_timezone() );
	}
	if ( preg_match( '/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})?(Z)?$/i', $value, $m ) ) {
		$zone = ! empty( $m[7] ) ? new DateTimeZone( 'UTC' ) : $tz;
		$dt   = ( new DateTimeImmutable( 'now', $zone ) )->setDate( (int) $m[1], (int) $m[2], (int) $m[3] )->setTime( (int) $m[4], (int) $m[5], (int) ( $m[6] ?? 0 ) );
		return array( 'ts' => $dt->getTimestamp(), 'date_only' => false, 'tz' => $zone );
	}
	return null;
}

/**
 * Parses an ISO 8601 duration (P1DT2H30M) to seconds.
 *
 * @param string $value Duration.
 * @return int
 */
function teatatu_events_ical_duration( $value ) {
	if ( ! preg_match( '/^([+-])?P(?:(\d+)W)?(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?)?$/', trim( $value ), $m ) ) {
		return 0;
	}
	$seconds = (int) ( $m[2] ?? 0 ) * WEEK_IN_SECONDS + (int) ( $m[3] ?? 0 ) * DAY_IN_SECONDS + (int) ( $m[4] ?? 0 ) * HOUR_IN_SECONDS + (int) ( $m[5] ?? 0 ) * MINUTE_IN_SECONDS + (int) ( $m[6] ?? 0 );
	return '-' === ( $m[1] ?? '' ) ? -$seconds : $seconds;
}

/**
 * Unescapes an iCal TEXT value.
 *
 * @param string $value Value.
 * @return string
 */
function teatatu_events_ical_unescape( $value ) {
	return strtr( (string) $value, array( '\\n' => "\n", '\\N' => "\n", '\\,' => ',', '\\;' => ';', '\\\\' => '\\' ) );
}

/**
 * Parses iCalendar text into VEVENT property maps.
 *
 * @param string $ics Calendar text.
 * @return array[] Each VEVENT: NAME => [ [value, params], … ].
 */
function teatatu_events_ical_parse( $ics ) {
	$ics    = preg_replace( "/\r\n|\r/", "\n", (string) $ics );
	$ics    = preg_replace( "/\n[ \t]/", '', $ics ); // Unfold.
	$events = array();
	$cur    = null;
	$depth  = 0;
	foreach ( explode( "\n", $ics ) as $line ) {
		if ( '' === trim( $line ) ) {
			continue;
		}
		if ( 0 === strcasecmp( $line, 'BEGIN:VEVENT' ) ) {
			$cur   = array();
			$depth = 0;
			continue;
		}
		if ( null === $cur ) {
			continue;
		}
		if ( 0 === stripos( $line, 'BEGIN:' ) ) {
			$depth++; // e.g. VALARM inside VEVENT.
			continue;
		}
		if ( 0 === stripos( $line, 'END:' ) ) {
			if ( $depth > 0 ) {
				$depth--;
				continue;
			}
			if ( 0 === strcasecmp( $line, 'END:VEVENT' ) ) {
				$events[] = $cur;
				$cur      = null;
			}
			continue;
		}
		if ( $depth > 0 ) {
			continue;
		}
		// NAME;PARAM=VALUE;PARAM="VALUE":value — the first colon outside quotes ends the name.
		$in_quotes = false;
		$split     = -1;
		$len       = strlen( $line );
		for ( $i = 0; $i < $len; $i++ ) {
			if ( '"' === $line[ $i ] ) {
				$in_quotes = ! $in_quotes;
			} elseif ( ':' === $line[ $i ] && ! $in_quotes ) {
				$split = $i;
				break;
			}
		}
		if ( $split < 0 ) {
			continue;
		}
		$head   = substr( $line, 0, $split );
		$value  = substr( $line, $split + 1 );
		$bits   = explode( ';', $head );
		$name   = strtoupper( array_shift( $bits ) );
		$params = array();
		foreach ( $bits as $bit ) {
			if ( false !== strpos( $bit, '=' ) ) {
				list( $k, $v )               = explode( '=', $bit, 2 );
				$params[ strtoupper( $k ) ] = trim( $v, '"' );
			}
		}
		$cur[ $name ][] = array( $value, $params );
	}
	return $events;
}

/**
 * First value of a property.
 *
 * @param array  $ev   VEVENT.
 * @param string $name Property.
 * @return array|null [value, params]
 */
function teatatu_events_ical_prop( $ev, $name ) {
	return isset( $ev[ $name ][0] ) ? $ev[ $name ][0] : null;
}

/**
 * Builds candidates from an iCal feed.
 *
 * @param array $config Feed config.
 * @param int   $limit  Max candidates (the full import uses a higher cap, as
 *                      calendars often hold many events).
 * @return array {candidates, error, complete}
 */
function teatatu_events_ical_build_candidates( $config, $limit ) {
	$ics = teatatu_events_fetch_url( $config['url'], 'text/calendar, text/plain, */*' );
	if ( '' === trim( $ics ) || false === stripos( $ics, 'BEGIN:VCALENDAR' ) ) {
		return array( 'candidates' => array(), 'error' => __( 'Could not fetch the calendar, or it is not an iCal file.', 'teatatu-events' ), 'complete' => false );
	}
	$cap    = $limit >= TEATATU_EVENTS_MAX_ITEMS_PER_FEED ? 400 : $limit;
	$events = teatatu_events_ical_parse( $ics );

	$masters   = array();
	$overrides = array();
	foreach ( $events as $ev ) {
		$uid = teatatu_events_ical_prop( $ev, 'UID' );
		$uid = $uid ? trim( $uid[0] ) : md5( wp_json_encode( $ev ) );
		$rid = teatatu_events_ical_prop( $ev, 'RECURRENCE-ID' );
		if ( $rid ) {
			$info = teatatu_events_ical_parse_date_info( $rid[0], $rid[1] );
			if ( $info ) {
				$overrides[ $uid ][ $info['ts'] ] = $ev;
			}
		} else {
			$masters[ $uid ] = $ev;
		}
	}

	$candidates = array();
	$cutoff     = time() - DAY_IN_SECONDS;
	foreach ( $masters as $uid => $ev ) {
		$dtstart = teatatu_events_ical_prop( $ev, 'DTSTART' );
		$info    = $dtstart ? teatatu_events_ical_parse_date_info( $dtstart[0], $dtstart[1] ) : null;
		if ( ! $info ) {
			continue;
		}
		$duration = teatatu_events_ical_event_duration( $ev, $info );
		$rrule    = teatatu_events_ical_prop( $ev, 'RRULE' );
		if ( ! $rrule ) {
			if ( $info['ts'] + max( 0, $duration ) < $cutoff ) {
				continue;
			}
			$candidates[] = teatatu_events_ical_candidate( $ev, $uid, $uid, $info, $duration );
		} else {
			$exdates = array();
			foreach ( isset( $ev['EXDATE'] ) ? $ev['EXDATE'] : array() as $ex ) {
				foreach ( explode( ',', $ex[0] ) as $value ) {
					$x = teatatu_events_ical_parse_date_info( $value, $ex[1] );
					if ( $x ) {
						$exdates[] = $x['ts'];
					}
				}
			}
			$rdates = array();
			foreach ( isset( $ev['RDATE'] ) ? $ev['RDATE'] : array() as $rd ) {
				foreach ( explode( ',', $rd[0] ) as $value ) {
					$x = teatatu_events_ical_parse_date_info( $value, $rd[1] );
					if ( $x ) {
						$rdates[] = $x['ts'];
					}
				}
			}
			$starts = teatatu_events_rrule_expand(
				$info['ts'],
				$rrule[0],
				array(
					'duration' => max( 0, $duration ),
					'min_end'  => time(),
					'limit'    => TEATATU_EVENTS_MAX_UPCOMING,
					'exdates'  => $exdates,
					'rdates'   => $rdates,
					'tz'       => $info['tz'],
				)
			);
			foreach ( $starts as $ts ) {
				$identity = $uid . '|' . gmdate( 'Ymd\THis\Z', $ts );
				if ( isset( $overrides[ $uid ][ $ts ] ) ) {
					$ov      = $overrides[ $uid ][ $ts ];
					$ostart  = teatatu_events_ical_prop( $ov, 'DTSTART' );
					$oinfo   = $ostart ? teatatu_events_ical_parse_date_info( $ostart[0], $ostart[1] ) : null;
					$candidates[] = teatatu_events_ical_candidate( array_merge( $ev, $ov ), $uid, $identity, $oinfo ? $oinfo : array_merge( $info, array( 'ts' => $ts ) ), teatatu_events_ical_event_duration( array_merge( $ev, $ov ), $oinfo ? $oinfo : $info ) );
					unset( $overrides[ $uid ][ $ts ] );
				} else {
					$candidates[] = teatatu_events_ical_candidate( $ev, $uid, $identity, array_merge( $info, array( 'ts' => $ts ) ), $duration );
				}
			}
		}
		if ( count( $candidates ) >= $cap ) {
			break;
		}
	}
	// Overrides moved into the window but not produced by the rule above.
	foreach ( $overrides as $uid => $list ) {
		foreach ( $list as $rid_ts => $ov ) {
			$ostart = teatatu_events_ical_prop( $ov, 'DTSTART' );
			$oinfo  = $ostart ? teatatu_events_ical_parse_date_info( $ostart[0], $ostart[1] ) : null;
			if ( $oinfo && $oinfo['ts'] >= $cutoff ) {
				$base         = isset( $masters[ $uid ] ) ? array_merge( $masters[ $uid ], $ov ) : $ov;
				$candidates[] = teatatu_events_ical_candidate( $base, $uid, $uid . '|' . gmdate( 'Ymd\THis\Z', $rid_ts ), $oinfo, teatatu_events_ical_event_duration( $base, $oinfo ) );
			}
		}
	}
	$capped     = count( $candidates ) > $cap;
	$candidates = array_slice( $candidates, 0, $cap );
	return array( 'candidates' => $candidates, 'error' => '', 'complete' => ! $capped && count( $candidates ) > 0 );
}

/**
 * Duration of a VEVENT in seconds (DTEND − DTSTART, or DURATION; all-day
 * events default to one day).
 *
 * @param array $ev   VEVENT.
 * @param array $info Parsed DTSTART.
 * @return int
 */
function teatatu_events_ical_event_duration( $ev, $info ) {
	$dtend = teatatu_events_ical_prop( $ev, 'DTEND' );
	if ( $dtend ) {
		$end = teatatu_events_ical_parse_date_info( $dtend[0], $dtend[1] );
		if ( $end ) {
			return $end['ts'] - $info['ts'];
		}
	}
	$dur = teatatu_events_ical_prop( $ev, 'DURATION' );
	if ( $dur ) {
		return teatatu_events_ical_duration( $dur[0] );
	}
	return $info['date_only'] ? DAY_IN_SECONDS : 0;
}

/**
 * One candidate from a VEVENT (or an occurrence of one).
 *
 * @param array  $ev       VEVENT properties.
 * @param string $uid      UID.
 * @param string $identity Identity.
 * @param array  $info     Start info {ts, date_only}.
 * @param int    $duration Seconds.
 * @return array
 */
function teatatu_events_ical_candidate( $ev, $uid, $identity, $info, $duration ) {
	$text = function ( $name ) use ( $ev ) {
		$p = teatatu_events_ical_prop( $ev, $name );
		return $p ? trim( teatatu_events_ical_unescape( $p[0] ) ) : '';
	};
	$c                 = teatatu_events_candidate_defaults();
	$c['identity']     = $identity;
	$c['ext_kind']     = 'ical';
	$c['ext_identity'] = $identity;
	$c['title']        = wp_strip_all_tags( $text( 'SUMMARY' ) );
	$description       = $text( 'DESCRIPTION' );
	$c['description']  = teatatu_events_clean_description( wpautop( esc_html( $description ) ) );
	$c['excerpt']      = wp_trim_words( $description, 40 );
	$c['read_more_url'] = esc_url_raw( $text( 'URL' ) );
	$c['address']      = $text( 'LOCATION' );
	$c['start']        = (int) $info['ts'];
	$c['all_day']      = ! empty( $info['date_only'] );
	if ( $c['all_day'] ) {
		$days     = max( 1, (int) round( max( 0, $duration ) / DAY_IN_SECONDS ) );
		$c['end'] = teatatu_events_local_day_end( $c['start'] + ( $days - 1 ) * DAY_IN_SECONDS );
	} else {
		$c['end'] = $duration > 0 ? $c['start'] + $duration : 0;
	}
	$status      = strtoupper( $text( 'STATUS' ) );
	$c['status'] = 'CANCELLED' === $status ? 'cancelled' : 'scheduled';
	$cats        = array();
	foreach ( isset( $ev['CATEGORIES'] ) ? $ev['CATEGORIES'] : array() as $cat ) {
		$cats = array_merge( $cats, array_map( 'teatatu_events_ical_unescape', explode( ',', $cat[0] ) ) );
	}
	$c['tags'] = $cats;
	foreach ( array( 'IMAGE', 'ATTACH' ) as $prop ) {
		foreach ( isset( $ev[ $prop ] ) ? $ev[ $prop ] : array() as $att ) {
			$fmt = strtolower( $att[1]['FMTTYPE'] ?? '' );
			if ( preg_match( '#^https?://#i', $att[0] ) && ( 'IMAGE' === $prop || 0 === strpos( $fmt, 'image/' ) || preg_match( '/\.(jpe?g|png|gif|webp)(\?|$)/i', $att[0] ) ) ) {
				$c['image'] = esc_url_raw( $att[0] );
				break 2;
			}
		}
	}
	$geo = teatatu_events_ical_prop( $ev, 'GEO' );
	if ( $geo && preg_match( '/^(-?\d+(?:\.\d+)?)[;,](-?\d+(?:\.\d+)?)$/', trim( $geo[0] ), $gm ) ) {
		$c['lat'] = (float) $gm[1];
		$c['lng'] = (float) $gm[2];
	}
	return $c;
}
