<?php
/**
 * Add to calendar: per-event .ics files, Google/Outlook links, and the
 * subscribe feeds (this site's calendar.ics and the network's
 * network-calendar.ics). The REST routes are registered in
 * includes/rest-api.php; this file builds the iCalendar text and serves it
 * raw (text/calendar) instead of JSON.
 *
 * UIDs: an imported event's UID comes from its external key, so every site
 * (and the network feed) uses the same UID for the same real-world event and
 * subscribers never see it twice. Other events use "{site}-{post}@{host}".
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * iCalendar UID for an event.
 *
 * @param string $external_key External key ('' for local events).
 * @param int    $post_id      Post ID.
 * @param int    $site_id      Site ID.
 * @return string
 */
function teatatu_events_ics_uid( $external_key, $post_id, $site_id ) {
	if ( $external_key ) {
		return 'ext-' . substr( $external_key, 0, 40 ) . '@teatatu-events';
	}
	$host = wp_parse_url( home_url(), PHP_URL_HOST );
	return (int) $site_id . '-' . (int) $post_id . '@' . ( $host ? $host : 'localhost' );
}

/**
 * Escapes an iCalendar TEXT value.
 *
 * @param string $text Text.
 * @return string
 */
function teatatu_events_ics_escape( $text ) {
	$text = wp_strip_all_tags( html_entity_decode( (string) $text, ENT_QUOTES, 'UTF-8' ) );
	$text = str_replace( array( '\\', ';', ',', "\r\n", "\n", "\r" ), array( '\\\\', '\;', '\,', '\n', '\n', '\n' ), $text );
	return $text;
}

/**
 * Folds a content line at 75 octets.
 *
 * @param string $line Line.
 * @return string
 */
function teatatu_events_ics_fold( $line ) {
	$out = '';
	while ( strlen( $line ) > 75 ) {
		$cut = 75;
		// Don't split a multi-byte character.
		while ( $cut > 0 && ( ord( $line[ $cut ] ) & 0xC0 ) === 0x80 ) {
			$cut--;
		}
		$out .= substr( $line, 0, $cut ) . "\r\n ";
		$line = substr( $line, $cut );
	}
	return $out . $line . "\r\n";
}

/**
 * Renders a VCALENDAR from normalised items.
 *
 * @param array[] $items   Items.
 * @param string  $calname Calendar name.
 * @return string
 */
function teatatu_events_ics_render( $items, $calname ) {
	$tz    = wp_timezone();
	$lines = array(
		'BEGIN:VCALENDAR',
		'VERSION:2.0',
		'PRODID:-//TeAtatu//Teatatu Events ' . TEATATU_EVENTS_VERSION . '//EN',
		'CALSCALE:GREGORIAN',
		'METHOD:PUBLISH',
		'X-WR-CALNAME:' . teatatu_events_ics_escape( $calname ),
		'X-WR-TIMEZONE:' . $tz->getName(),
	);
	foreach ( $items as $item ) {
		if ( empty( $item['start'] ) ) {
			continue;
		}
		$lines[] = 'BEGIN:VEVENT';
		$lines[] = 'UID:' . $item['uid'];
		$lines[] = 'DTSTAMP:' . gmdate( 'Ymd\THis\Z' );
		if ( $item['all_day'] ) {
			$lines[] = 'DTSTART;VALUE=DATE:' . wp_date( 'Ymd', $item['start'], $tz );
			$lines[] = 'DTEND;VALUE=DATE:' . wp_date( 'Ymd', $item['end'] + 1, $tz );
		} else {
			$lines[] = 'DTSTART:' . gmdate( 'Ymd\THis\Z', $item['start'] );
			$lines[] = 'DTEND:' . gmdate( 'Ymd\THis\Z', $item['end'] );
		}
		$summary = $item['title'];
		if ( 'cancelled' === $item['status'] ) {
			/* translators: %s: title. */
			$summary = sprintf( __( 'CANCELLED: %s', 'teatatu-events' ), $summary );
		}
		$lines[] = 'SUMMARY:' . teatatu_events_ics_escape( $summary );
		if ( $item['excerpt'] ) {
			$lines[] = 'DESCRIPTION:' . teatatu_events_ics_escape( $item['excerpt'] );
		}
		if ( $item['place']['line'] ) {
			$lines[] = 'LOCATION:' . teatatu_events_ics_escape( $item['place']['line'] );
		}
		if ( ! empty( $item['place']['lat'] ) && ! empty( $item['place']['lng'] ) ) {
			$lines[] = 'GEO:' . (float) $item['place']['lat'] . ';' . (float) $item['place']['lng'];
		}
		$lines[] = 'URL:' . esc_url_raw( $item['link'] );
		$lines[] = 'STATUS:' . ( 'cancelled' === $item['status'] ? 'CANCELLED' : 'CONFIRMED' );
		if ( ! empty( $item['tags'] ) ) {
			$lines[] = 'CATEGORIES:' . implode( ',', array_map( 'teatatu_events_ics_escape', wp_list_pluck( $item['tags'], 'name' ) ) );
		}
		if ( ! empty( $item['modified_gmt'] ) ) {
			$lines[] = 'LAST-MODIFIED:' . gmdate( 'Ymd\THis\Z', strtotime( $item['modified_gmt'] . ' UTC' ) );
		}
		$lines[] = 'END:VEVENT';
	}
	$lines[] = 'END:VCALENDAR';
	$out     = '';
	foreach ( $lines as $line ) {
		$out .= teatatu_events_ics_fold( $line );
	}
	return $out;
}

/**
 * Marker class: a REST response body that should be sent as raw iCalendar.
 */
class Teatatu_Events_Ics_Body {
	/**
	 * Calendar text.
	 *
	 * @var string
	 */
	public $ics;

	/**
	 * Download file name.
	 *
	 * @var string
	 */
	public $filename;

	/**
	 * Constructor.
	 *
	 * @param string $ics      Calendar text.
	 * @param string $filename File name.
	 */
	public function __construct( $ics, $filename ) {
		$this->ics      = $ics;
		$this->filename = $filename;
	}
}

add_filter( 'rest_pre_serve_request', 'teatatu_events_serve_ics', 10, 4 );

/**
 * Sends our iCalendar responses as text/calendar rather than JSON.
 *
 * @param bool             $served  Already served.
 * @param WP_HTTP_Response $result  Response.
 * @param WP_REST_Request  $request Request.
 * @param WP_REST_Server   $server  Server.
 * @return bool
 */
function teatatu_events_serve_ics( $served, $result, $request, $server ) {
	$data = $result->get_data();
	if ( $served || ! ( $data instanceof Teatatu_Events_Ics_Body ) ) {
		return $served;
	}
	if ( ! headers_sent() ) {
		header( 'Content-Type: text/calendar; charset=utf-8' );
		header( 'Content-Disposition: inline; filename="' . sanitize_file_name( $data->filename ) . '"' );
		header( 'Cache-Control: public, max-age=900' );
	}
	echo $data->ics; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- iCalendar text, escaped per RFC 5545.
	return true;
}

/**
 * REST: one event's .ics (published events only).
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function teatatu_events_rest_event_ics( WP_REST_Request $request ) {
	$post = get_post( (int) $request['id'] );
	if ( ! $post || 'teatatu_event' !== $post->post_type || 'publish' !== $post->post_status ) {
		return new WP_Error( 'rest_post_invalid_id', __( 'Event not found.', 'teatatu-events' ), array( 'status' => 404 ) );
	}
	$item = teatatu_events_normalize_item( $post );
	return rest_ensure_response( new Teatatu_Events_Ics_Body( teatatu_events_ics_render( array( $item ), $item['title'] ), sanitize_title( $item['title'] ) . '.ics' ) );
}

/**
 * Filter attributes accepted by the subscribe feeds.
 *
 * @param WP_REST_Request $request Request.
 * @return array
 */
function teatatu_events_feed_atts_from_request( WP_REST_Request $request ) {
	$atts = array(
		'count'    => 500,
		'when'     => 'all',
		'from'     => wp_date( 'Y-m-d', time() - 30 * DAY_IN_SECONDS ),
		'to'       => '',
		'order'    => 'ASC',
		'_variant' => 'ics',
	);
	foreach ( array( 'neighbourhood', 'category', 'tag', 'venue', 'source', 'exclude_sites', 'linked' ) as $key ) {
		if ( null !== $request->get_param( $key ) ) {
			$atts[ $key ] = sanitize_text_field( (string) $request->get_param( $key ) );
		}
	}
	return teatatu_events_shortcode_atts( $atts );
}

/**
 * REST: this site's subscribe feed.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response
 */
function teatatu_events_rest_calendar_ics( WP_REST_Request $request ) {
	$atts  = teatatu_events_feed_atts_from_request( $request );
	$key   = teatatu_events_cache_key( 'site', 'ics', $atts );
	$ics   = teatatu_events_cache_get( 'site', $key );
	if ( false === $ics ) {
		$items = teatatu_events_get_items( $atts, 'site' );
		$ics   = teatatu_events_ics_render( $items['items'], get_bloginfo( 'name' ) );
		teatatu_events_cache_set( 'site', $key, $ics, 15 * MINUTE_IN_SECONDS );
	}
	return rest_ensure_response( new Teatatu_Events_Ics_Body( $ics, 'events.ics' ) );
}

/**
 * REST: the network subscribe feed (Master Site only).
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function teatatu_events_rest_network_calendar_ics( WP_REST_Request $request ) {
	if ( ! is_multisite() || ! teatatu_events_is_master_site() ) {
		return new WP_Error( 'teatatu_events_not_master_site', __( "This feed is only available on the network's Master Site.", 'teatatu-events' ), array( 'status' => 404 ) );
	}
	$atts = teatatu_events_feed_atts_from_request( $request );
	$key  = teatatu_events_cache_key( 'network', 'ics', $atts );
	$ics  = teatatu_events_cache_get( 'network', $key );
	if ( false === $ics ) {
		$items = teatatu_events_get_items( $atts, 'network' );
		$ics   = teatatu_events_ics_render( $items['items'], get_network() ? get_network()->site_name : get_bloginfo( 'name' ) );
		teatatu_events_cache_set( 'network', $key, $ics, 15 * MINUTE_IN_SECONDS );
	}
	return rest_ensure_response( new Teatatu_Events_Ics_Body( $ics, 'network-events.ics' ) );
}

/**
 * Add-to-calendar links (.ics, Google, Outlook) for one item.
 *
 * @param array $item Normalised item.
 * @return string HTML.
 */
function teatatu_events_render_calendar_links( $item ) {
	if ( empty( $item['start'] ) || 'cancelled' === $item['status'] ) {
		return '';
	}
	$site_url = ! empty( $item['home_site']['url'] ) ? $item['home_site']['url'] : home_url();
	$ics_url  = trailingslashit( $site_url ) . 'wp-json/teatatu-events/v1/events/' . (int) $item['id'] . '/ics';
	if ( $item['all_day'] ) {
		$g_dates = wp_date( 'Ymd', $item['start'] ) . '/' . wp_date( 'Ymd', $item['end'] + 1 );
		$o_start = wp_date( 'Y-m-d', $item['start'] );
		$o_end   = wp_date( 'Y-m-d', $item['end'] + 1 );
	} else {
		$g_dates = gmdate( 'Ymd\THis\Z', $item['start'] ) . '/' . gmdate( 'Ymd\THis\Z', $item['end'] );
		$o_start = gmdate( 'Y-m-d\TH:i:s\Z', $item['start'] );
		$o_end   = gmdate( 'Y-m-d\TH:i:s\Z', $item['end'] );
	}
	$details = wp_strip_all_tags( $item['excerpt'] ) . ( $item['link'] ? "\n" . $item['link'] : '' );
	$google  = add_query_arg(
		array(
			'action'   => 'TEMPLATE',
			'text'     => rawurlencode( $item['title'] ),
			'dates'    => $g_dates,
			'details'  => rawurlencode( $details ),
			'location' => rawurlencode( $item['place']['line'] ),
		),
		'https://calendar.google.com/calendar/render'
	);
	$outlook = add_query_arg(
		array_filter(
			array(
				'path'     => '/calendar/action/compose',
				'rru'      => 'addevent',
				'subject'  => rawurlencode( $item['title'] ),
				'startdt'  => rawurlencode( $o_start ),
				'enddt'    => rawurlencode( $o_end ),
				'body'     => rawurlencode( $details ),
				'location' => rawurlencode( $item['place']['line'] ),
				'allday'   => $item['all_day'] ? 'true' : '',
			)
		),
		'https://outlook.live.com/calendar/0/deeplink/compose'
	);
	return '<span class="tte-calendar-links">'
		. '<a href="' . esc_url( $ics_url ) . '">' . esc_html__( 'Add to calendar (.ics)', 'teatatu-events' ) . '</a> · '
		. '<a href="' . esc_url( $google ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Google', 'teatatu-events' ) . '</a> · '
		. '<a href="' . esc_url( $outlook ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Outlook', 'teatatu-events' ) . '</a>'
		. '</span>';
}

/**
 * Subscribe links for a feed URL (webcal, Google, Outlook, plain .ics).
 *
 * @param string $feed_url https feed URL.
 * @param string $name     Calendar name.
 * @return string HTML.
 */
function teatatu_events_render_subscribe_links( $feed_url, $name ) {
	$webcal  = preg_replace( '#^https?://#', 'webcal://', $feed_url );
	$google  = 'https://calendar.google.com/calendar/r?cid=' . rawurlencode( $webcal );
	$outlook = add_query_arg(
		array(
			'url'  => rawurlencode( $feed_url ),
			'name' => rawurlencode( $name ),
		),
		'https://outlook.live.com/calendar/0/addfromweb'
	);
	ob_start();
	?>
	<div class="tte-subscribe">
		<p class="tte-subscribe-intro"><?php esc_html_e( 'Subscribe to these events in your calendar app — new and changed events appear automatically.', 'teatatu-events' ); ?></p>
		<p class="tte-subscribe-links">
			<a class="tte-button" href="<?php echo esc_url( $webcal, array( 'webcal', 'https', 'http' ) ); ?>"><?php esc_html_e( 'Apple / iPhone', 'teatatu-events' ); ?></a>
			<a class="tte-button" href="<?php echo esc_url( $google ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Google Calendar', 'teatatu-events' ); ?></a>
			<a class="tte-button" href="<?php echo esc_url( $outlook ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Outlook', 'teatatu-events' ); ?></a>
		</p>
		<p class="tte-subscribe-url"><label><?php esc_html_e( 'Calendar address:', 'teatatu-events' ); ?> <input type="text" readonly value="<?php echo esc_attr( $feed_url ); ?>" onclick="this.select();" /></label></p>
	</div>
	<?php
	return ob_get_clean();
}
