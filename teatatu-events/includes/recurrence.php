<?php
/**
 * Recurring series: an RFC 5545 RRULE engine (shared with the iCal
 * importer) and the series sync that keeps up to 12 upcoming occurrences
 * generated as real `teatatu_event` posts.
 *
 * Supported RRULE subset: FREQ (DAILY/WEEKLY/MONTHLY/YEARLY), INTERVAL,
 * COUNT, UNTIL, BYDAY (incl. "2TU" / "-1FR" for monthly/yearly), BYMONTHDAY
 * (incl. negative), BYMONTH. Plus EXDATE (excluded dates) and RDATE (extra
 * dates, which also covers irregular hand-picked dates).
 *
 * Occurrences are ordinary events, so every listing, feed and REST route
 * handles them with no recurrence-specific code.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const TEATATU_EVENTS_MAX_UPCOMING = 12;

/**
 * Parses an RRULE string ("FREQ=WEEKLY;BYDAY=TU,TH;COUNT=10").
 *
 * @param string $rrule RRULE (with or without the "RRULE:" prefix).
 * @return array|null Parsed rule, or null if unusable.
 */
function teatatu_events_rrule_parse( $rrule ) {
	$rrule = trim( preg_replace( '/^RRULE:/i', '', (string) $rrule ) );
	if ( '' === $rrule ) {
		return null;
	}
	$rule = array(
		'freq'       => '',
		'interval'   => 1,
		'count'      => 0,
		'until'      => 0,
		'byday'      => array(),
		'bymonthday' => array(),
		'bymonth'    => array(),
	);
	foreach ( explode( ';', $rrule ) as $pair ) {
		if ( false === strpos( $pair, '=' ) ) {
			continue;
		}
		list( $key, $value ) = array_map( 'trim', explode( '=', $pair, 2 ) );
		switch ( strtoupper( $key ) ) {
			case 'FREQ':
				$rule['freq'] = strtoupper( $value );
				break;
			case 'INTERVAL':
				$rule['interval'] = max( 1, (int) $value );
				break;
			case 'COUNT':
				$rule['count'] = max( 0, (int) $value );
				break;
			case 'UNTIL':
				$until_info    = teatatu_events_ical_parse_date_info( $value );
				$rule['until'] = $until_info ? ( $until_info['date_only'] ? teatatu_events_local_day_end( $until_info['ts'] ) : $until_info['ts'] ) : 0;
				break;
			case 'BYDAY':
				foreach ( explode( ',', strtoupper( $value ) ) as $day ) {
					if ( preg_match( '/^([+-]?\d{1,2})?(MO|TU|WE|TH|FR|SA|SU)$/', trim( $day ), $m ) ) {
						$rule['byday'][] = array( isset( $m[1] ) && '' !== $m[1] ? (int) $m[1] : 0, $m[2] );
					}
				}
				break;
			case 'BYMONTHDAY':
				$rule['bymonthday'] = array_values( array_filter( array_map( 'intval', explode( ',', $value ) ) ) );
				break;
			case 'BYMONTH':
				$rule['bymonth'] = array_values( array_filter( array_map( 'intval', explode( ',', $value ) ) ) );
				break;
		}
	}
	if ( ! in_array( $rule['freq'], array( 'DAILY', 'WEEKLY', 'MONTHLY', 'YEARLY' ), true ) ) {
		return null;
	}
	return $rule;
}

/**
 * Builds an RRULE string from a parsed rule.
 *
 * @param array $rule Rule.
 * @return string
 */
function teatatu_events_rrule_build( $rule ) {
	$parts = array( 'FREQ=' . $rule['freq'] );
	if ( $rule['interval'] > 1 ) {
		$parts[] = 'INTERVAL=' . (int) $rule['interval'];
	}
	if ( ! empty( $rule['byday'] ) ) {
		$parts[] = 'BYDAY=' . implode(
			',',
			array_map(
				function ( $d ) {
					return ( $d[0] ? $d[0] : '' ) . $d[1];
				},
				$rule['byday']
			)
		);
	}
	if ( ! empty( $rule['bymonthday'] ) ) {
		$parts[] = 'BYMONTHDAY=' . implode( ',', $rule['bymonthday'] );
	}
	if ( ! empty( $rule['bymonth'] ) ) {
		$parts[] = 'BYMONTH=' . implode( ',', $rule['bymonth'] );
	}
	if ( $rule['count'] ) {
		$parts[] = 'COUNT=' . (int) $rule['count'];
	} elseif ( $rule['until'] ) {
		$parts[] = 'UNTIL=' . gmdate( 'Ymd\THis\Z', $rule['until'] );
	}
	return implode( ';', $parts );
}

/**
 * Days of a month that match a BYDAY list (with optional ordinal) or a
 * BYMONTHDAY list, as day numbers.
 *
 * @param int   $year       Year.
 * @param int   $month      Month.
 * @param array $byday      [[n, 'MO'], …].
 * @param int[] $bymonthday Day numbers (negative counts from the end).
 * @param int   $default    Day to use when neither list is given.
 * @return int[]
 */
function teatatu_events_rrule_month_days( $year, $month, $byday, $bymonthday, $default ) {
	$days_in = (int) gmdate( 't', gmmktime( 0, 0, 0, $month, 1, $year ) );
	$days    = array();
	if ( $bymonthday ) {
		foreach ( $bymonthday as $d ) {
			$day = $d > 0 ? $d : $days_in + $d + 1;
			if ( $day >= 1 && $day <= $days_in ) {
				$days[] = $day;
			}
		}
	} elseif ( $byday ) {
		$codes = array( 'MO' => 1, 'TU' => 2, 'WE' => 3, 'TH' => 4, 'FR' => 5, 'SA' => 6, 'SU' => 7 );
		foreach ( $byday as $bd ) {
			list( $n, $code ) = $bd;
			$matches = array();
			for ( $day = 1; $day <= $days_in; $day++ ) {
				if ( (int) gmdate( 'N', gmmktime( 0, 0, 0, $month, $day, $year ) ) === $codes[ $code ] ) {
					$matches[] = $day;
				}
			}
			if ( 0 === $n ) {
				$days = array_merge( $days, $matches );
			} elseif ( $n > 0 && isset( $matches[ $n - 1 ] ) ) {
				$days[] = $matches[ $n - 1 ];
			} elseif ( $n < 0 && isset( $matches[ count( $matches ) + $n ] ) ) {
				$days[] = $matches[ count( $matches ) + $n ];
			}
		}
	} elseif ( $default <= $days_in ) {
		$days[] = $default;
	}
	$days = array_unique( $days );
	sort( $days );
	return $days;
}

/**
 * Expands a rule into occurrence start timestamps, in order.
 *
 * DTSTART is always the first occurrence. COUNT counts every rule
 * occurrence; EXDATEs are removed afterwards; RDATEs are added.
 *
 * @param int          $dtstart Start of the first occurrence (timestamp).
 * @param array|string $rule    Parsed rule or RRULE string.
 * @param array        $args    {
 *     @type int          $duration Seconds; with $min_end, skip occurrences that ended before it.
 *     @type int          $min_end  Only return occurrences whose end is ≥ this.
 *     @type int          $limit    Max occurrences to return (after filtering).
 *     @type int          $until    Hard stop (timestamp), in addition to UNTIL.
 *     @type string[]|int[] $exdates Dates ('Y-m-d' local, or timestamps) to exclude.
 *     @type string[]|int[] $rdates  Extra starts ('Y-m-d H:i' local, 'Y-m-d', or timestamps).
 *     @type DateTimeZone $tz       Time zone (default: site).
 * }
 * @return int[]
 */
function teatatu_events_rrule_expand( $dtstart, $rule, $args = array() ) {
	$args = array_merge(
		array(
			'duration' => 0,
			'min_end'  => 0,
			'limit'    => TEATATU_EVENTS_MAX_UPCOMING,
			'until'    => 0,
			'exdates'  => array(),
			'rdates'   => array(),
			'tz'       => wp_timezone(),
		),
		$args
	);
	$tz   = $args['tz'];
	$rule = is_array( $rule ) ? $rule : teatatu_events_rrule_parse( $rule );

	$start = ( new DateTimeImmutable( '@' . (int) $dtstart ) )->setTimezone( $tz );
	$h     = (int) $start->format( 'G' );
	$i     = (int) $start->format( 'i' );
	$s     = (int) $start->format( 's' );

	$excluded = array();
	foreach ( (array) $args['exdates'] as $ex ) {
		$excluded[ is_numeric( $ex ) && strlen( (string) $ex ) > 8 ? wp_date( 'Y-m-d', (int) $ex, $tz ) : substr( (string) $ex, 0, 10 ) ] = true;
	}

	$at = function ( $year, $month, $day ) use ( $tz, $h, $i, $s ) {
		return ( new DateTimeImmutable( 'now', $tz ) )->setDate( $year, $month, $day )->setTime( $h, $i, $s )->getTimestamp();
	};

	$raw   = array();
	$count = 0;
	if ( $rule ) {
		$until    = $rule['until'] ? $rule['until'] : PHP_INT_MAX;
		if ( $args['until'] ) {
			$until = min( $until, $args['until'] );
		}
		$interval = max( 1, (int) $rule['interval'] );
		$codes    = array( 'MO' => 1, 'TU' => 2, 'WE' => 3, 'TH' => 4, 'FR' => 5, 'SA' => 6, 'SU' => 7 );
		$wanted   = (int) $args['limit'] > 0 ? (int) $args['limit'] : PHP_INT_MAX;
		$found    = 0;
		$y        = (int) $start->format( 'Y' );
		$m        = (int) $start->format( 'n' );
		$d        = (int) $start->format( 'j' );

		// Without COUNT, whole periods that ended before min_end can be skipped
		// (keeps long-running rules fast). With COUNT every occurrence matters.
		$first = 0;
		if ( ! $rule['count'] && $args['min_end'] > (int) $dtstart ) {
			$target = ( new DateTimeImmutable( '@' . max( (int) $dtstart, (int) $args['min_end'] - (int) $args['duration'] ) ) )->setTimezone( $tz );
			switch ( $rule['freq'] ) {
				case 'DAILY':
					$elapsed = (int) $start->setTime( 0, 0 )->diff( $target->setTime( 0, 0 ) )->days;
					break;
				case 'WEEKLY':
					$elapsed = intdiv( (int) $start->setTime( 0, 0 )->diff( $target->setTime( 0, 0 ) )->days, 7 );
					break;
				case 'MONTHLY':
					$elapsed = ( (int) $target->format( 'Y' ) * 12 + (int) $target->format( 'n' ) ) - ( $y * 12 + $m );
					break;
				default:
					$elapsed = (int) $target->format( 'Y' ) - $y;
			}
			$first = max( 0, intdiv( max( 0, $elapsed ), $interval ) - 1 );
		}

		for ( $period = $first; $period < $first + 5000; $period++ ) {
			$candidates = array();
			switch ( $rule['freq'] ) {
				case 'DAILY':
					$day          = $start->setTime( 0, 0 )->modify( '+' . ( $period * $interval ) . ' days' );
					$candidates[] = $at( (int) $day->format( 'Y' ), (int) $day->format( 'n' ), (int) $day->format( 'j' ) );
					break;
				case 'WEEKLY':
					$monday   = $start->setTime( 0, 0 )->modify( '-' . ( (int) $start->format( 'N' ) - 1 ) . ' days' )->modify( '+' . ( $period * $interval * 7 ) . ' days' );
					$weekdays = $rule['byday'] ? array_unique( array_map( function ( $bd ) use ( $codes ) { return $codes[ $bd[1] ]; }, $rule['byday'] ) ) : array( (int) $start->format( 'N' ) );
					sort( $weekdays );
					foreach ( $weekdays as $wd ) {
						$day          = $monday->modify( '+' . ( $wd - 1 ) . ' days' );
						$candidates[] = $at( (int) $day->format( 'Y' ), (int) $day->format( 'n' ), (int) $day->format( 'j' ) );
					}
					break;
				case 'MONTHLY':
					$total = ( $y * 12 + ( $m - 1 ) ) + $period * $interval;
					$yy    = intdiv( $total, 12 );
					$mm    = $total % 12 + 1;
					foreach ( teatatu_events_rrule_month_days( $yy, $mm, $rule['byday'], $rule['bymonthday'], $d ) as $day ) {
						$candidates[] = $at( $yy, $mm, $day );
					}
					break;
				case 'YEARLY':
					$yy     = $y + $period * $interval;
					$months = $rule['bymonth'] ? $rule['bymonth'] : array( $m );
					sort( $months );
					foreach ( $months as $mm ) {
						$byday = $rule['byday'];
						if ( ! $rule['bymonthday'] && ! $byday ) {
							$days = teatatu_events_rrule_month_days( $yy, $mm, array(), array(), $d );
						} else {
							$days = teatatu_events_rrule_month_days( $yy, $mm, $byday, $rule['bymonthday'], $d );
						}
						foreach ( $days as $day ) {
							$candidates[] = $at( $yy, $mm, $day );
						}
					}
					break;
			}
			sort( $candidates );
			foreach ( $candidates as $ts ) {
				if ( $ts < (int) $dtstart ) {
					continue;
				}
				if ( $rule['bymonth'] && 'YEARLY' !== $rule['freq'] && ! in_array( (int) wp_date( 'n', $ts, $tz ), $rule['bymonth'], true ) ) {
					continue;
				}
				if ( 'DAILY' === $rule['freq'] && $rule['byday'] ) {
					$dow = (int) wp_date( 'N', $ts, $tz );
					if ( ! in_array( $dow, array_map( function ( $bd ) use ( $codes ) { return $codes[ $bd[1] ]; }, $rule['byday'] ), true ) ) {
						continue;
					}
				}
				if ( 'DAILY' === $rule['freq'] && $rule['bymonthday'] && ! in_array( (int) wp_date( 'j', $ts, $tz ), $rule['bymonthday'], true ) ) {
					continue;
				}
				if ( $ts > $until ) {
					break 2;
				}
				$count++;
				if ( $rule['count'] && $count > $rule['count'] ) {
					break 2;
				}
				$raw[] = $ts;
				if ( empty( $excluded[ wp_date( 'Y-m-d', $ts, $tz ) ] ) && ( ! $args['min_end'] || $ts + $args['duration'] >= $args['min_end'] ) ) {
					$found++;
					if ( $found >= $wanted + count( (array) $args['rdates'] ) ) {
						break 2;
					}
				}
			}
		}
	} else {
		$raw[] = (int) $dtstart;
	}

	foreach ( (array) $args['rdates'] as $rd ) {
		$ts = is_numeric( $rd ) && strlen( (string) $rd ) > 8 ? (int) $rd : teatatu_events_parse_local_datetime( strlen( (string) $rd ) <= 10 ? $rd . ' ' . sprintf( '%02d:%02d', $h, $i ) : $rd );
		if ( $ts ) {
			$raw[] = $ts;
		}
	}
	$raw = array_values( array_unique( $raw ) );
	sort( $raw );

	$out = array();
	foreach ( $raw as $ts ) {
		if ( ! empty( $excluded[ wp_date( 'Y-m-d', $ts, $tz ) ] ) ) {
			continue;
		}
		if ( $args['min_end'] && $ts + $args['duration'] < $args['min_end'] ) {
			continue;
		}
		$out[] = $ts;
		if ( (int) $args['limit'] > 0 && count( $out ) >= (int) $args['limit'] ) {
			break;
		}
	}
	return $out;
}

/**
 * Plain-language description of a rule ("Every week on Tue, Thu").
 *
 * @param array|string $rule Rule.
 * @return string
 */
function teatatu_events_rrule_describe( $rule ) {
	$rule = is_array( $rule ) ? $rule : teatatu_events_rrule_parse( $rule );
	if ( ! $rule ) {
		return __( 'Does not repeat', 'teatatu-events' );
	}
	$units = array(
		'DAILY'   => array( __( 'day', 'teatatu-events' ), __( 'days', 'teatatu-events' ) ),
		'WEEKLY'  => array( __( 'week', 'teatatu-events' ), __( 'weeks', 'teatatu-events' ) ),
		'MONTHLY' => array( __( 'month', 'teatatu-events' ), __( 'months', 'teatatu-events' ) ),
		'YEARLY'  => array( __( 'year', 'teatatu-events' ), __( 'years', 'teatatu-events' ) ),
	);
	$names = array( 'MO' => 'Mon', 'TU' => 'Tue', 'WE' => 'Wed', 'TH' => 'Thu', 'FR' => 'Fri', 'SA' => 'Sat', 'SU' => 'Sun' );
	$ord   = array( 1 => '1st', 2 => '2nd', 3 => '3rd', 4 => '4th', 5 => '5th', -1 => 'last', -2 => 'second-to-last' );
	$text  = 1 === (int) $rule['interval']
		/* translators: %s: unit. */
		? sprintf( __( 'Every %s', 'teatatu-events' ), $units[ $rule['freq'] ][0] )
		/* translators: 1: number, 2: unit. */
		: sprintf( __( 'Every %1$d %2$s', 'teatatu-events' ), $rule['interval'], $units[ $rule['freq'] ][1] );
	if ( $rule['byday'] ) {
		$days = array_map(
			function ( $bd ) use ( $names, $ord ) {
				return ( $bd[0] ? ( $ord[ $bd[0] ] ?? $bd[0] ) . ' ' : '' ) . $names[ $bd[1] ];
			},
			$rule['byday']
		);
		$text .= ' ' . __( 'on', 'teatatu-events' ) . ' ' . implode( ', ', $days );
	}
	if ( $rule['bymonthday'] ) {
		$text .= ' ' . __( 'on day', 'teatatu-events' ) . ' ' . implode( ', ', $rule['bymonthday'] );
	}
	if ( $rule['count'] ) {
		/* translators: %d: count. */
		$text .= ', ' . sprintf( _n( '%d time', '%d times', $rule['count'], 'teatatu-events' ), $rule['count'] );
	} elseif ( $rule['until'] ) {
		/* translators: %s: date. */
		$text .= ', ' . sprintf( __( 'until %s', 'teatatu-events' ), teatatu_events_format_date( $rule['until'], true ) );
	}
	return $text;
}

// ---------------------------------------------------------------------------
// Series
// ---------------------------------------------------------------------------

/**
 * A series' template (event fields + term IDs).
 *
 * @param int $series_id Series ID.
 * @return array
 */
function teatatu_events_series_template( $series_id ) {
	$template = get_post_meta( $series_id, '_teatatu_events_series_template', true );
	$template = is_array( $template ) ? $template : array();
	return array_merge(
		teatatu_events_field_defaults(),
		array(
			'terms'         => array(),
			'nbhd_override' => '',
		),
		$template
	);
}

/**
 * How many upcoming occurrences a series keeps (1–12).
 *
 * @param int $series_id Series ID.
 * @return int
 */
function teatatu_events_series_upcoming_count( $series_id ) {
	$n = (int) get_post_meta( $series_id, '_teatatu_events_upcoming_count', true );
	if ( ! $n ) {
		$n = (int) teatatu_events_setting( 'series_upcoming' );
	}
	return max( 1, min( TEATATU_EVENTS_MAX_UPCOMING, $n ) );
}

/**
 * The next upcoming occurrence starts a series' rule produces.
 *
 * @param int $series_id Series ID.
 * @param int $limit     How many (default: the series' upcoming count).
 * @return int[]
 */
function teatatu_events_series_upcoming_starts( $series_id, $limit = 0 ) {
	$t = teatatu_events_series_template( $series_id );
	if ( ! $t['start'] ) {
		return array();
	}
	$duration = max( 0, (int) $t['end'] - (int) $t['start'] );
	return teatatu_events_rrule_expand(
		(int) $t['start'],
		(string) get_post_meta( $series_id, '_teatatu_events_rrule', true ),
		array(
			'duration' => $duration,
			'min_end'  => time(),
			'limit'    => $limit ? $limit : teatatu_events_series_upcoming_count( $series_id ),
			'exdates'  => (array) get_post_meta( $series_id, '_teatatu_events_exdates', true ),
			'rdates'   => (array) get_post_meta( $series_id, '_teatatu_events_rdates', true ),
		)
	);
}

/**
 * Occurrence posts of a series (any status but trash).
 *
 * @param int $series_id Series ID.
 * @return int[]
 */
function teatatu_events_series_occurrences( $series_id ) {
	return array_map(
		'intval',
		get_posts(
			array(
				'post_type'        => 'teatatu_event',
				'post_status'      => array( 'publish', 'draft', 'pending', 'future', 'private' ),
				'posts_per_page'   => -1,
				'fields'           => 'ids',
				'suppress_filters' => true,
				'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array( 'key' => teatatu_events_mk( 'series_id' ), 'value' => (int) $series_id ),
					array( 'key' => teatatu_events_mk( 'draft_of' ), 'compare' => 'NOT EXISTS' ),
				),
			)
		)
	);
}

/**
 * Brings a series' occurrence posts in line with its rule: creates missing
 * upcoming occurrences (up to its upcoming count), updates ones that aren't
 * detached, trashes future non-detached ones that no longer match, and never
 * touches past occurrences.
 *
 * @param int $series_id Series ID.
 * @return array {created, updated, trashed}
 */
function teatatu_events_series_sync( $series_id ) {
	$series = get_post( $series_id );
	$stats  = array( 'created' => 0, 'updated' => 0, 'trashed' => 0 );
	if ( ! $series || 'teatatu_evt_series' !== $series->post_type ) {
		return $stats;
	}
	$GLOBALS['teatatu_events_series_sync'] = true;

	$t         = teatatu_events_series_template( $series_id );
	$duration  = max( 0, (int) $t['end'] - (int) $t['start'] );
	$status    = in_array( $series->post_status, array( 'publish', 'pending' ), true ) ? $series->post_status : 'draft';
	$now       = time();
	$desired   = array();
	foreach ( teatatu_events_series_upcoming_starts( $series_id ) as $ts ) {
		$desired[ wp_date( 'Y-m-d H:i', $ts ) ] = $ts;
	}

	$existing = array();
	foreach ( teatatu_events_series_occurrences( $series_id ) as $id ) {
		$key = (string) get_post_meta( $id, teatatu_events_mk( 'occurrence_key' ), true );
		$existing[ $key ? $key : 'id-' . $id ] = $id;
	}

	$postarr_base = array(
		'post_title'   => $series->post_title,
		'post_content' => $series->post_content,
		'post_excerpt' => $series->post_excerpt,
	);

	foreach ( $desired as $key => $ts ) {
		if ( isset( $existing[ $key ] ) ) {
			$id = $existing[ $key ];
			if ( get_post_meta( $id, teatatu_events_mk( 'detached' ), true ) ) {
				continue;
			}
			teatatu_events_series_write_occurrence( $series_id, $id, $ts, $duration, $t, $postarr_base, $status );
			$stats['updated']++;
		} else {
			teatatu_events_series_write_occurrence( $series_id, 0, $ts, $duration, $t, $postarr_base, $status );
			$stats['created']++;
		}
	}
	foreach ( $existing as $key => $id ) {
		if ( isset( $desired[ $key ] ) || get_post_meta( $id, teatatu_events_mk( 'detached' ), true ) ) {
			continue;
		}
		if ( teatatu_events_get_start( $id ) > $now ) {
			wp_trash_post( $id );
			$stats['trashed']++;
		}
	}
	update_post_meta( $series_id, '_teatatu_events_last_sync', $now );
	$GLOBALS['teatatu_events_series_sync'] = false;
	return $stats;
}

/**
 * Creates or updates one occurrence from the series template.
 *
 * @param int    $series_id Series ID.
 * @param int    $id        Occurrence ID (0 to create).
 * @param int    $ts        Start.
 * @param int    $duration  Duration in seconds.
 * @param array  $t         Template.
 * @param array  $base      Title/content/excerpt.
 * @param string $status    Post status.
 * @return int|WP_Error
 */
function teatatu_events_series_write_occurrence( $series_id, $id, $ts, $duration, $t, $base, $status ) {
	return teatatu_events_batch_write(
		$id,
		function ( $id ) use ( $series_id, $ts, $duration, $t, $base, $status ) {
			$postarr = array_merge( $base, array( 'post_status' => $status ) );
			if ( $id ) {
				$postarr['ID'] = $id;
				$result        = wp_update_post( wp_slash( $postarr ), true );
			} else {
				$postarr['post_type']   = 'teatatu_event';
				$postarr['post_author'] = (int) get_post_field( 'post_author', $series_id );
				$result                 = wp_insert_post( wp_slash( $postarr ), true );
			}
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$id     = (int) $result;
			$fields = array_intersect_key( $t, teatatu_events_field_defaults() );
			$fields['start'] = $ts;
			$fields['end']   = $ts + $duration;
			teatatu_events_save_fields( $id, $fields );
			update_post_meta( $id, teatatu_events_mk( 'series_id' ), $series_id );
			update_post_meta( $id, teatatu_events_mk( 'occurrence_key' ), wp_date( 'Y-m-d H:i', $ts ) );
			if ( $t['nbhd_override'] ) {
				update_post_meta( $id, teatatu_events_mk( 'nbhd_override' ), $t['nbhd_override'] );
			} else {
				delete_post_meta( $id, teatatu_events_mk( 'nbhd_override' ) );
			}
			foreach ( teatatu_events_copyable_taxonomies() as $taxonomy ) {
				$ids = isset( $t['terms'][ $taxonomy ] ) ? array_map( 'intval', (array) $t['terms'][ $taxonomy ] ) : array();
				wp_set_object_terms( $id, $ids, $taxonomy );
			}
			return $id;
		}
	);
}

/**
 * Other published, upcoming dates in the same series as an event — or, for
 * an imported session, the other sessions imported from the same source
 * event (the feed's "each upcoming session" mode).
 *
 * @param int $post_id Event ID.
 * @return int[]
 */
function teatatu_events_series_other_dates( $post_id ) {
	$series_id = (int) get_post_meta( $post_id, teatatu_events_mk( 'series_id' ), true );
	$group     = $series_id ? '' : (string) get_post_meta( $post_id, teatatu_events_mk( 'import_group' ), true );
	if ( ! $series_id && '' === $group ) {
		return array();
	}
	$args = teatatu_events_query_args( 'upcoming' );
	$args['meta_query'][] = $series_id
		? array( 'key' => teatatu_events_mk( 'series_id' ), 'value' => $series_id )
		: array( 'key' => teatatu_events_mk( 'import_group' ), 'value' => $group );
	return get_posts(
		array_merge(
			$args,
			array(
				'post_type'      => 'teatatu_event',
				'post_status'    => 'publish',
				'posts_per_page' => TEATATU_EVENTS_MAX_UPCOMING,
				'fields'         => 'ids',
				'post__not_in'   => array( (int) $post_id ),
			)
		)
	);
}

add_action( 'teatatu_events_series_cron_tick', 'teatatu_events_run_series_cron' );

/**
 * Hourly: tops every series back up to its upcoming count as dates end.
 */
function teatatu_events_run_series_cron() {
	$ids = get_posts(
		array(
			'post_type'      => 'teatatu_evt_series',
			'post_status'    => array( 'publish', 'draft', 'pending' ),
			'posts_per_page' => -1,
			'fields'         => 'ids',
		)
	);
	foreach ( $ids as $id ) {
		teatatu_events_series_sync( $id );
	}
}

add_action( 'wp_trash_post', 'teatatu_events_trash_series_occurrences' );

/**
 * Trashing a series trashes its future, non-detached occurrences.
 *
 * @param int $post_id Post ID.
 */
function teatatu_events_trash_series_occurrences( $post_id ) {
	if ( 'teatatu_evt_series' !== get_post_type( $post_id ) ) {
		return;
	}
	foreach ( teatatu_events_series_occurrences( $post_id ) as $id ) {
		if ( teatatu_events_get_start( $id ) > time() && ! get_post_meta( $id, teatatu_events_mk( 'detached' ), true ) ) {
			wp_trash_post( $id );
		}
	}
}

add_action( 'admin_post_teatatu_events_reset_occurrence', 'teatatu_events_handle_reset_occurrence' );

/**
 * "Reset to series": re-attaches an edited occurrence and re-syncs it.
 */
function teatatu_events_handle_reset_occurrence() {
	$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
	check_admin_referer( 'teatatu_events_reset_occurrence_' . $id );
	if ( ! $id || 'teatatu_event' !== get_post_type( $id ) || ! current_user_can( 'manage_teatatu_events_series' ) || ! current_user_can( 'edit_post', $id ) ) {
		wp_die( esc_html__( 'You do not have permission to do that.', 'teatatu-events' ), 403 );
	}
	delete_post_meta( $id, teatatu_events_mk( 'detached' ) );
	$series_id = (int) get_post_meta( $id, teatatu_events_mk( 'series_id' ), true );
	if ( $series_id ) {
		teatatu_events_series_sync( $series_id );
	}
	teatatu_events_admin_notice( __( 'Occurrence reset to match its series.', 'teatatu-events' ) );
	wp_safe_redirect( teatatu_events_edit_url( $id ) );
	exit;
}
