<?php
/**
 * Template tags: small rendering helpers shared by the templates. They take
 * the plugin's normalised event items (teatatu_events_item_from_post()) and
 * turn them into markup — no data is read from post meta directly.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Inline outline icon (decorative: aria-hidden). Lucide-style geometry at
 * the design system's 1.75 stroke on a 24px grid.
 *
 * @param string $name Icon name.
 * @param int    $size Pixel size.
 * @return string SVG markup.
 */
function ttev_icon( $name, $size = 20 ) {
	$paths = array(
		'search'   => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
		'arrow'    => '<path d="M5 12h14M13 6l6 6-6 6"/>',
		'back'     => '<path d="M19 12H5M11 6l-6 6 6 6"/>',
		'calendar' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/>',
		'clock'    => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
		'pin'      => '<path d="M12 21s-7-6.5-7-12a7 7 0 0 1 14 0c0 5.5-7 12-7 12z"/><circle cx="12" cy="9" r="2.5"/>',
		'ticket'   => '<path d="M3 8a2 2 0 0 0 0 4v0a2 2 0 0 1 0 4v2h18v-2a2 2 0 0 1 0-4 2 2 0 0 0 0-4V6H3z"/><path d="M14 6v12" stroke-dasharray="2 2"/>',
		'price'    => '<path d="M20 12 12 20 4 12V4h8z"/><circle cx="8.5" cy="8.5" r="1.5"/>',
		'repeat'   => '<path d="M4 12a8 8 0 0 1 14-5.3L20 9"/><path d="M20 4v5h-5"/><path d="M20 12a8 8 0 0 1-14 5.3L4 15"/><path d="M4 20v-5h5"/>',
		'share'    => '<path d="M12 15V3M7 8l5-5 5 5"/><path d="M5 13v7h14v-7"/>',
		'link'     => '<path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1"/><path d="M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"/>',
		'check'    => '<path d="m5 12 5 5 9-10"/>',
		'filter'   => '<path d="M4 6h16M7 12h10M10 18h4"/>',
		'close'    => '<path d="M6 6l12 12M18 6 6 18"/>',
		'alert'    => '<circle cx="12" cy="12" r="9"/><path d="M12 7.5v5.5"/><path d="M12 16.5h.01"/>',
		'list'     => '<path d="M9 6h11M9 12h11M9 18h11"/><path d="M4 6h.01M4 12h.01M4 18h.01"/>',
		'grid'     => '<rect x="3" y="4" width="18" height="17" rx="2"/><path d="M3 9h18M9 9v12M15 9v12"/>',
		'bell'     => '<path d="M6 16V11a6 6 0 0 1 12 0v5l2 2H4z"/><path d="M10 20a2 2 0 0 0 4 0"/>',
		'plus'     => '<path d="M12 5v14M5 12h14"/>',
		'chevron-left'  => '<path d="m15 6-6 6 6 6"/>',
		'chevron-right' => '<path d="m9 6 6 6-6 6"/>',
	);
	if ( ! isset( $paths[ $name ] ) ) {
		return '';
	}
	return sprintf(
		'<svg width="%1$d" height="%1$d" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">%2$s</svg>',
		(int) $size,
		$paths[ $name ]
	);
}

/**
 * Base URL of the events list (the post type archive).
 *
 * @return string
 */
function ttev_archive_url() {
	$url = get_post_type_archive_link( 'teatatu_event' );
	return $url ? $url : home_url( '/events/' );
}

/**
 * The current list's URL without query string or page number.
 *
 * @return string
 */
function ttev_current_base_url() {
	if ( is_tax() ) {
		$link = get_term_link( get_queried_object() );
		if ( ! is_wp_error( $link ) ) {
			return $link;
		}
	}
	return ttev_archive_url();
}

/**
 * Events list URL with the given filters (array values become comma lists;
 * defaults are left out).
 *
 * @param array  $filters Filters to put in the URL.
 * @param string $base    Base URL (defaults to the current list).
 * @return string
 */
function ttev_filters_url( $filters, $base = '' ) {
	$args = array();
	foreach ( $filters as $key => $value ) {
		// Event Tags are "kind" in URLs ("tag" is WordPress's blog-tag query variable).
		if ( 'tag' === $key ) {
			$key = 'kind';
		}
		if ( is_array( $value ) ) {
			$value = implode( ',', $value );
		}
		if ( is_bool( $value ) ) {
			$value = $value ? '1' : '';
		}
		if ( ( 'when' === $key && 'upcoming' === $value ) || ( 'view' === $key && 'list' === $value ) ) {
			continue;
		}
		if ( '' !== (string) $value && '0' !== (string) $value ) {
			$args[ $key ] = $value;
		}
	}
	return add_query_arg( array_map( 'rawurlencode', $args ), $base ? $base : ttev_current_base_url() );
}

/**
 * The link for an event card: its own page, its external page, or (linked
 * events) its page on the site it belongs to — the plugin decides.
 *
 * @param array $item Normalised item.
 * @return string
 */
function ttev_event_url( $item ) {
	return ! empty( $item['link'] ) ? $item['link'] : $item['permalink'];
}

/**
 * Whether an event's link leaves this site (opened in a new tab).
 *
 * @param array $item Normalised item.
 * @return bool
 */
function ttev_is_offsite( $item ) {
	$host = wp_parse_url( ttev_event_url( $item ), PHP_URL_HOST );
	return $host && wp_parse_url( home_url(), PHP_URL_HOST ) !== $host;
}

/**
 * The design system's date block (.tal-date): weekday, day, month.
 *
 * @param array $item Normalised item.
 */
function ttev_date_block( $item ) {
	if ( empty( $item['start'] ) ) {
		return;
	}
	printf(
		'<time class="tal-date" datetime="%1$s"><span class="tal-date-dow">%2$s</span><span class="tal-date-day">%3$s</span><span class="tal-date-mon">%4$s</span></time>',
		esc_attr( wp_date( $item['all_day'] ? 'Y-m-d' : 'c', $item['start'] ) ),
		esc_html( wp_date( 'D', $item['start'] ) ),
		esc_html( wp_date( 'j', $item['start'] ) ),
		esc_html( wp_date( 'M', $item['start'] ) )
	);
}

/**
 * Short time text for a card, next to the date block: "7–9:30pm",
 * "10am", "All day", or "Until Sun 21 Feb" for events that run over
 * several days.
 *
 * @param array $item Normalised item.
 * @return string
 */
function ttev_time_text( $item ) {
	$start = (int) $item['start'];
	$end   = (int) $item['end'];
	if ( ! $start ) {
		return '';
	}
	$clock = function ( $ts, $with_meridiem = true ) {
		$text = wp_date( 'i', $ts ) === '00' ? wp_date( 'g', $ts ) : wp_date( 'g:i', $ts );
		return $with_meridiem ? $text . wp_date( 'a', $ts ) : $text;
	};
	$same_day = $end && wp_date( 'Y-m-d', $start ) === wp_date( 'Y-m-d', $end - ( $item['all_day'] ? 1 : 0 ) );
	if ( $end && ! $same_day && $end - $start > DAY_IN_SECONDS ) {
		/* translators: %s: last day, e.g. "Sun 21 Feb". */
		return sprintf( __( 'Until %s', 'teatatu-events-theme' ), wp_date( 'D j M', $end - ( $item['all_day'] ? 1 : 0 ) ) );
	}
	if ( $item['all_day'] ) {
		return __( 'All day', 'teatatu-events-theme' );
	}
	if ( ! $end || $end === $start ) {
		return $clock( $start );
	}
	$same_half = wp_date( 'a', $start ) === wp_date( 'a', $end );
	return $clock( $start, ! $same_half ) . '–' . $clock( $end );
}

/**
 * Status badge for events that aren't simply scheduled.
 *
 * @param array $item Normalised item.
 */
function ttev_status_badge( $item ) {
	if ( 'scheduled' !== $item['status'] ) {
		printf( '<span class="ttev-status ttev-status--%s">%s</span>', esc_attr( $item['status'] ), esc_html( $item['status_label'] ) );
	} elseif ( ! empty( $item['is_past'] ) ) {
		printf( '<span class="ttev-status ttev-status--past">%s</span>', esc_html__( 'Finished', 'teatatu-events-theme' ) );
	}
}

/**
 * The price text for an event ('' when not stated).
 *
 * @param array $item Normalised item.
 * @return string
 */
function ttev_price_text( $item ) {
	if ( $item['price'] ) {
		return $item['price'];
	}
	return $item['is_free'] ? __( 'Free', 'teatatu-events-theme' ) : '';
}

/**
 * The kicker over an event title: its category, else its first tag.
 *
 * @param array $item Normalised item.
 * @return string
 */
function ttev_kicker( $item ) {
	if ( ! empty( $item['category'] ) ) {
		return $item['category'];
	}
	return ! empty( $item['tags'][0]['name'] ) ? $item['tags'][0]['name'] : '';
}

/**
 * Shows each event once in an overview: an event with several dates (a
 * series, or sessions imported from one page) keeps only its next date,
 * with 'more_dates' counting the others in the set.
 *
 * @param array[] $items Normalised items, soonest first.
 * @param int     $limit Max items to return.
 * @return array[]
 */
function ttev_collapse_dates( $items, $limit ) {
	$out   = array();
	$index = array();
	foreach ( $items as $item ) {
		$group = $item['group'] ?? '';
		if ( '' !== $group && isset( $index[ $group ] ) ) {
			$out[ $index[ $group ] ]['more_dates']++;
			continue;
		}
		$item['more_dates'] = 0;
		if ( '' !== $group ) {
			$index[ $group ] = count( $out );
		}
		$out[] = $item;
	}
	return array_slice( $out, 0, $limit );
}

/**
 * "+2 more dates" for a collapsed event ('' when it has none).
 *
 * @param array $item Item from ttev_collapse_dates().
 * @return string
 */
function ttev_more_dates_text( $item ) {
	if ( empty( $item['more_dates'] ) ) {
		return '';
	}
	/* translators: %d: number of further dates. */
	return sprintf( _n( '+%d more date', '+%d more dates', $item['more_dates'], 'teatatu-events-theme' ), $item['more_dates'] );
}

/**
 * An event card (grid, the design system's EventCard) or row (lists).
 *
 * @param array  $item    Normalised item.
 * @param string $variant 'card' or 'row'.
 */
function ttev_card( $item, $variant = 'card' ) {
	if ( $item ) {
		get_template_part( 'template-parts/event', $variant, array( 'item' => $item ) );
	}
}

/**
 * Heading for an events list, e.g. "Music events this weekend in Matipo".
 *
 * @param array $filters Sanitised filters.
 * @return string
 */
function ttev_results_heading( $filters ) {
	$term_name = function ( $slugs, $taxonomy ) {
		if ( 1 !== count( $slugs ) ) {
			return '';
		}
		$term = get_term_by( 'slug', $slugs[0], $taxonomy );
		return $term ? $term->name : '';
	};
	$what = $term_name( $filters['category'], 'teatatu_events_category' );
	if ( '' === $what ) {
		$what = $term_name( $filters['tag'], 'teatatu_events_tag' );
	}
	$noun = $filters['free'] ? __( 'Free events', 'teatatu-events-theme' ) : __( 'Events', 'teatatu-events-theme' );

	if ( $filters['from'] || $filters['to'] ) {
		$from = $filters['from'] ? wp_date( 'j M', ttev_local_ts( $filters['from'] ) ) : '';
		$to   = $filters['to'] ? wp_date( 'j M', ttev_local_ts( $filters['to'] ) ) : '';
		if ( $from && $to && $from === $to ) {
			/* translators: %s: date. */
			$when = sprintf( __( 'on %s', 'teatatu-events-theme' ), $from );
		} elseif ( $from && $to ) {
			/* translators: 1: date, 2: date. */
			$when = sprintf( __( 'from %1$s to %2$s', 'teatatu-events-theme' ), $from, $to );
		} elseif ( $from ) {
			/* translators: %s: date. */
			$when = sprintf( __( 'from %s', 'teatatu-events-theme' ), $from );
		} else {
			/* translators: %s: date. */
			$when = sprintf( __( 'until %s', 'teatatu-events-theme' ), $to );
		}
		$phrase = $noun . ' ' . $when;
	} else {
		$phrases = array(
			/* translators: %s: e.g. "Events". */
			'upcoming' => __( 'Upcoming %s', 'teatatu-events-theme' ),
			/* translators: %s: e.g. "Events". */
			'today'    => __( '%s today', 'teatatu-events-theme' ),
			/* translators: %s: e.g. "Events". */
			'tomorrow' => __( '%s tomorrow', 'teatatu-events-theme' ),
			/* translators: %s: e.g. "Events". */
			'weekend'  => __( '%s this weekend', 'teatatu-events-theme' ),
			/* translators: %s: e.g. "Events". */
			'week'     => __( '%s in the next 7 days', 'teatatu-events-theme' ),
			/* translators: %s: e.g. "Events". */
			'month'    => __( '%s this month', 'teatatu-events-theme' ),
			/* translators: %s: e.g. "Events". */
			'past'     => __( 'Past %s', 'teatatu-events-theme' ),
			/* translators: %s: e.g. "Events". */
			'all'      => __( 'All %s', 'teatatu-events-theme' ),
		);
		$lower  = in_array( $filters['when'], array( 'upcoming', 'past', 'all' ), true ) ? ( function_exists( 'mb_strtolower' ) ? mb_strtolower( $noun ) : strtolower( $noun ) ) : $noun;
		$phrase = sprintf( $phrases[ $filters['when'] ], $lower );
	}

	$where = __( 'on the Peninsula', 'teatatu-events-theme' );
	$venue = $term_name( $filters['venue'], 'teatatu_events_venue' );
	$area  = $term_name( $filters['neighbourhood'], 'teatatu_events_neighbourhood' );
	if ( $venue ) {
		/* translators: %s: venue. */
		$where = sprintf( __( 'at %s', 'teatatu-events-theme' ), $venue );
	} elseif ( $area ) {
		/* translators: %s: neighbourhood. */
		$where = sprintf( __( 'in %s', 'teatatu-events-theme' ), $area );
	}
	$heading = ucfirst( $phrase ) . ' ' . $where;
	if ( '' !== $what ) {
		/* translators: 1: category or tag, e.g. "Markets"; 2: e.g. "upcoming events on the Peninsula". */
		$heading = sprintf( __( '%1$s: %2$s', 'teatatu-events-theme' ), $what, ( function_exists( 'mb_strtolower' ) ? mb_strtolower( mb_substr( $heading, 0, 1 ) ) . mb_substr( $heading, 1 ) : lcfirst( $heading ) ) );
	}
	return $heading;
}

/**
 * Label for an active filter chip.
 *
 * @param string $key   Filter key.
 * @param mixed  $value Value.
 * @return string
 */
function ttev_filter_chip_label( $key, $value ) {
	$taxonomies = teatatu_events_archive_taxonomies();
	if ( isset( $taxonomies[ $key ] ) ) {
		$names = array();
		foreach ( (array) $value as $slug ) {
			$term    = get_term_by( 'slug', $slug, $taxonomies[ $key ] );
			$names[] = $term ? $term->name : $slug;
		}
		return implode( ', ', $names );
	}
	switch ( $key ) {
		case 'when':
			$options = teatatu_events_when_options();
			return $options[ $value ] ?? $value;
		case 'q':
			/* translators: %s: search text. */
			return sprintf( __( '“%s”', 'teatatu-events-theme' ), $value );
		case 'from':
			/* translators: %s: date. */
			return sprintf( __( 'From %s', 'teatatu-events-theme' ), wp_date( 'j M Y', ttev_local_ts( $value ) ) );
		case 'to':
			/* translators: %s: date. */
			return sprintf( __( 'Until %s', 'teatatu-events-theme' ), wp_date( 'j M Y', ttev_local_ts( $value ) ) );
		case 'free':
			return __( 'Free', 'teatatu-events-theme' );
	}
	return (string) $value;
}

/**
 * Midday (site time) on a Y-m-d date, as a timestamp for wp_date(). Dates
 * are read in the site's timezone — never with strtotime(), which reads
 * them as UTC and can land on the next or previous day.
 *
 * @param string $ymd Date.
 * @param string $time Time of day.
 * @return int
 */
function ttev_local_ts( $ymd, $time = '12:00' ) {
	try {
		return ( new DateTimeImmutable( $ymd . ' ' . $time, wp_timezone() ) )->getTimestamp();
	} catch ( Exception $e ) {
		return time();
	}
}

/**
 * Groups items by the day they appear under in a list: their start date,
 * or today for events that started earlier and are still on.
 *
 * @param array[] $items Normalised items.
 * @param bool    $past  Listing past events (group by start, newest first).
 * @return array day key (Y-m-d) => items
 */
function ttev_group_by_day( $items, $past = false ) {
	$today  = wp_date( 'Y-m-d' );
	$groups = array();
	foreach ( $items as $item ) {
		$day = wp_date( 'Y-m-d', $item['start'] );
		if ( ! $past && $day < $today ) {
			$day = $today;
		}
		$groups[ $day ][] = $item;
	}
	return $groups;
}

/**
 * Heading for a day group ("Today", "Tomorrow", "Saturday 31 October").
 *
 * @param string $day Y-m-d.
 * @return string
 */
function ttev_day_heading( $day ) {
	$ts = ttev_local_ts( $day );
	if ( wp_date( 'Y-m-d' ) === $day ) {
		/* translators: %s: date, e.g. "Saturday 31 October". */
		return sprintf( __( 'Today · %s', 'teatatu-events-theme' ), wp_date( 'l j F', $ts ) );
	}
	if ( wp_date( 'Y-m-d', time() + DAY_IN_SECONDS ) === $day ) {
		/* translators: %s: date. */
		return sprintf( __( 'Tomorrow · %s', 'teatatu-events-theme' ), wp_date( 'l j F', $ts ) );
	}
	return wp_date( wp_date( 'Y' ) === wp_date( 'Y', $ts ) ? 'l j F' : 'l j F Y', $ts );
}

/**
 * Map points for a set of items: events at a venue with coordinates. Events
 * at the same venue share one pin.
 *
 * @param array[] $items Normalised items.
 * @return array[]
 */
function ttev_map_points( $items ) {
	$points = array();
	foreach ( $items as $item ) {
		$place = $item['place'] ?? array();
		if ( empty( $place['lat'] ) || empty( $place['lng'] ) || ! is_numeric( $place['lat'] ) || ! is_numeric( $place['lng'] ) ) {
			continue;
		}
		$key = $place['lat'] . ',' . $place['lng'];
		if ( ! isset( $points[ $key ] ) ) {
			$points[ $key ] = array(
				'lat'    => (float) $place['lat'],
				'lng'    => (float) $place['lng'],
				'label'  => wp_date( 'j M', $item['start'] ),
				'title'  => $place['location'] ? $place['location'] : $place['line'],
				'url'    => ttev_event_url( $item ),
				'count'  => 0,
			);
		}
		$points[ $key ]['count']++;
		if ( $points[ $key ]['count'] > 1 ) {
			/* translators: %d: number of events. */
			$points[ $key ]['label'] = sprintf( _n( '%d event', '%d events', $points[ $key ]['count'], 'teatatu-events-theme' ), $points[ $key ]['count'] );
			if ( ! empty( $place['venue_slug'] ) ) {
				$points[ $key ]['url'] = ttev_filters_url( array( 'venue' => $place['venue_slug'] ), ttev_archive_url() );
			}
		}
	}
	return array_values( $points );
}

/**
 * Prints a map container (Leaflet is loaded only when there are points).
 *
 * @param array[] $points Points from ttev_map_points().
 * @param string  $label  Accessible label.
 * @param string  $class  Extra class.
 */
function ttev_map( $points, $label, $class = '' ) {
	if ( ! $points ) {
		return;
	}
	ttev_enqueue_map();
	printf(
		'<div class="ttev-map %1$s" role="region" aria-label="%2$s" data-points="%3$s"></div>',
		esc_attr( $class ),
		esc_attr( $label ),
		esc_attr( wp_json_encode( $points ) )
	);
}

/**
 * Section links under the Events header when no menu is assigned: Today,
 * This weekend, Next 7 days, Free, Calendar. The current one gets
 * aria-current (underlined in the site colour).
 */
function ttev_primary_menu_fallback() {
	if ( ! ttev_plugin_ready() ) {
		return;
	}
	$filters = ttev_is_events_list() ? teatatu_events_current_filters() : null;
	$base    = ttev_archive_url();
	$links   = array(
		array( __( 'All upcoming', 'teatatu-events-theme' ), $base, $filters && is_post_type_archive( 'teatatu_event' ) && ! teatatu_events_active_filters( $filters ) && 'list' === $filters['view'] ),
		array( __( 'Today', 'teatatu-events-theme' ), ttev_filters_url( array( 'when' => 'today' ), $base ), $filters && 'today' === $filters['when'] && 'list' === $filters['view'] ),
		array( __( 'This weekend', 'teatatu-events-theme' ), ttev_filters_url( array( 'when' => 'weekend' ), $base ), $filters && 'weekend' === $filters['when'] && 'list' === $filters['view'] ),
		array( __( 'Next 7 days', 'teatatu-events-theme' ), ttev_filters_url( array( 'when' => 'week' ), $base ), $filters && 'week' === $filters['when'] && 'list' === $filters['view'] ),
		array( __( 'Free', 'teatatu-events-theme' ), ttev_filters_url( array( 'free' => true ), $base ), $filters && $filters['free'] && 'list' === $filters['view'] ),
		array( __( 'Calendar', 'teatatu-events-theme' ), ttev_filters_url( array( 'view' => 'calendar' ), $base ), $filters && 'calendar' === $filters['view'] ),
	);
	foreach ( $links as $link ) {
		printf( '<a href="%s"%s>%s</a>', esc_url( $link[1] ), $link[2] ? ' aria-current="page"' : '', esc_html( $link[0] ) );
	}
}

/**
 * Prints a <select> of options.
 *
 * @param string $name    Field name.
 * @param string $id      Element ID.
 * @param array  $options value => label (first is the "any" option).
 * @param mixed  $current Current value.
 * @param string $extra   Extra attributes (pre-escaped).
 */
function ttev_select( $name, $id, $options, $current, $extra = '' ) {
	printf( '<select class="ttev-control" name="%s" id="%s" %s>', esc_attr( $name ), esc_attr( $id ), $extra ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- caller passes static attributes.
	foreach ( $options as $value => $label ) {
		printf( '<option value="%s"%s>%s</option>', esc_attr( $value ), selected( (string) $current, (string) $value, false ), esc_html( $label ) );
	}
	echo '</select>';
}

/**
 * Term options for a taxonomy select: '' => any label, slug => name.
 *
 * @param string $taxonomy Taxonomy.
 * @param string $any      Label for "any".
 * @return array
 */
function ttev_term_options( $taxonomy, $any ) {
	$options = array( '' => $any );
	$terms   = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false ) );
	foreach ( is_wp_error( $terms ) ? array() : $terms as $term ) {
		$options[ $term->slug ] = $term->name;
	}
	return $options;
}

/**
 * "Subscribe in your calendar" buttons for a feed.
 *
 * @param array  $filters Filters the feed follows (taxonomies, free).
 * @param string $name    Calendar name.
 */
function ttev_subscribe_buttons( $filters, $name ) {
	$urls = teatatu_events_subscribe_urls( teatatu_events_feed_url( $filters ), $name );
	?>
	<div class="ttev-subscribe">
		<a class="tal-btn tal-btn-secondary tal-btn-sm" href="<?php echo esc_url( $urls['webcal'], array( 'webcal', 'https', 'http' ) ); ?>"><?php esc_html_e( 'Apple / iPhone', 'teatatu-events-theme' ); ?></a>
		<a class="tal-btn tal-btn-secondary tal-btn-sm" href="<?php echo esc_url( $urls['google'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Google Calendar', 'teatatu-events-theme' ); ?></a>
		<a class="tal-btn tal-btn-secondary tal-btn-sm" href="<?php echo esc_url( $urls['outlook'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Outlook', 'teatatu-events-theme' ); ?></a>
	</div>
	<?php
}

/**
 * Month calendar grid for the calendar view: Monday–Sunday weeks, up to
 * three events per day, "+N more" linking to that day's list.
 *
 * @param array[]           $items   Normalised items in the month.
 * @param DateTimeImmutable $month   First day of the month.
 * @param array             $filters Current filters (kept in links).
 */
function ttev_month_grid( $items, $month, $filters ) {
	$by_day = array();
	foreach ( $items as $item ) {
		$first = max( $item['start'], $month->getTimestamp() );
		$last  = $item['end'] ? $item['end'] : $item['start'];
		// Multi-day events appear on each day they run (within the month, max 31).
		$day = new DateTimeImmutable( wp_date( 'Y-m-d', $first ), wp_timezone() );
		for ( $i = 0; $day->getTimestamp() <= $last && $i < 31; $i++, $day = $day->modify( '+1 day' ) ) {
			$by_day[ $day->format( 'Y-m-d' ) ][] = $item;
		}
	}
	$start    = $month->modify( 'monday this week' );
	if ( (int) $month->format( 'N' ) === 1 ) {
		$start = $month;
	}
	$end      = $month->modify( 'last day of this month' )->modify( 'sunday this week' );
	$today    = wp_date( 'Y-m-d' );
	$keep     = array_diff_key( teatatu_events_active_filters( $filters ), array_flip( array( 'when', 'from', 'to' ) ) );
	$weekdays = array();
	for ( $i = 0, $d = $start; $i < 7; $i++, $d = $d->modify( '+1 day' ) ) {
		$weekdays[] = array( wp_date( 'D', $d->getTimestamp() ), wp_date( 'l', $d->getTimestamp() ) );
	}
	?>
	<div class="ttev-month" role="table" aria-label="<?php echo esc_attr( wp_date( 'F Y', $month->getTimestamp() ) ); ?>">
		<div class="ttev-month__row ttev-month__head" role="row">
			<?php foreach ( $weekdays as $day ) : ?>
				<span role="columnheader"><abbr title="<?php echo esc_attr( $day[1] ); ?>"><?php echo esc_html( $day[0] ); ?></abbr></span>
			<?php endforeach; ?>
		</div>
		<?php for ( $week = $start; $week <= $end; $week = $week->modify( '+7 days' ) ) : ?>
			<div class="ttev-month__row" role="row">
				<?php
				for ( $i = 0; $i < 7; $i++ ) :
					$day    = $week->modify( '+' . $i . ' days' );
					$key    = $day->format( 'Y-m-d' );
					$events = $by_day[ $key ] ?? array();
					$out    = $day->format( 'm' ) !== $month->format( 'm' );
					$class  = 'ttev-month__day' . ( $out ? ' is-out' : '' ) . ( $key === $today ? ' is-today' : '' ) . ( $events ? ' has-events' : '' );
					?>
					<div class="<?php echo esc_attr( $class ); ?>" role="cell">
						<span class="ttev-month__date"><span class="ttev-month__dow"><?php echo esc_html( wp_date( 'D', $day->getTimestamp() ) ); ?> </span><?php echo esc_html( $day->format( 'j' ) ); ?></span>
						<?php if ( $events ) : ?>
							<ul>
								<?php foreach ( array_slice( $events, 0, 3 ) as $event ) : ?>
									<li><a href="<?php echo esc_url( ttev_event_url( $event ) ); ?>"<?php echo 'cancelled' === $event['status'] ? ' class="is-cancelled"' : ''; ?>><?php if ( ! $event['all_day'] && wp_date( 'Y-m-d', $event['start'] ) === $key ) : ?><span class="ttev-month__time"><?php echo esc_html( wp_date( 'g:ia', $event['start'] ) ); ?></span> <?php endif; ?><?php echo esc_html( $event['title'] ); ?></a></li>
								<?php endforeach; ?>
							</ul>
							<?php if ( count( $events ) > 3 ) : ?>
								<a class="ttev-month__more" href="<?php echo esc_url( ttev_filters_url( array_merge( $keep, array( 'from' => $key, 'to' => $key, 'when' => 'all' ) ) ) ); ?>">
									<?php
									/* translators: %d: number of further events. */
									echo esc_html( sprintf( __( '+%d more', 'teatatu-events-theme' ), count( $events ) - 3 ) );
									?>
								</a>
							<?php endif; ?>
						<?php endif; ?>
					</div>
				<?php endfor; ?>
			</div>
		<?php endfor; ?>
	</div>
	<?php
}

/**
 * Notice shown in place of events when the plugin is missing.
 */
function ttev_plugin_missing_message() {
	echo '<div class="ttev-empty"><p>' . esc_html__( 'Events are not available right now.', 'teatatu-events-theme' ) . '</p></div>';
}
