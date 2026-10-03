<?php
/**
 * Shared import machinery for all four feed types (RSS/Atom, HTML pages,
 * iCal, Schema.org JSON-LD):
 *
 *  - feed configuration (one post per feed, in a per-type internal post type);
 *  - generic HTML/DOM helpers (no longer RSS-prefixed — they are plain HTML);
 *  - the NZ-aware date parser and status-text parser;
 *  - the ONE import pipeline every importer feeds "candidates" into: venue
 *    matching, the recognised-venue / neighbourhood filter, per-feed dedupe,
 *    draft-first creation, unreviewed-draft resync, pending updates (or
 *    auto-apply) for published events, external keys for cross-site
 *    de-duplication, and removed-at-source handling;
 *  - Apply / Dismiss for pending updates, Check Now, and the shared cron
 *    runner.
 *
 * Draft-first and human-gated updates are the default; each feed can opt
 * into auto-publish / auto-apply, but only a user who holds
 * publish_teatatu_events_items / edit_published_teatatu_events_items can
 * switch those on. manage_teatatu_events_feeds alone never grants publishing.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const TEATATU_EVENTS_MAX_ITEMS_PER_FEED = 50;
const TEATATU_EVENTS_MAX_PAGE_BYTES     = 2 * MB_IN_BYTES;
const TEATATU_EVENTS_MAX_FOLLOWS        = 15;

/**
 * Feed types: type => [post type, label].
 *
 * @return array
 */
function teatatu_events_feed_types() {
	return array(
		'rss'  => array( 'teatatu_evt_rssfeed', __( 'RSS Feeds', 'teatatu-events' ), __( 'RSS Feed', 'teatatu-events' ) ),
		'html' => array( 'teatatu_evt_webfeed', __( 'HTML Feeds', 'teatatu-events' ), __( 'HTML Feed', 'teatatu-events' ) ),
		'ical' => array( 'teatatu_evt_icalfeed', __( 'iCal Feeds', 'teatatu-events' ), __( 'iCal Feed', 'teatatu-events' ) ),
		'ld'   => array( 'teatatu_evt_ldfeed', __( 'Structured Data', 'teatatu-events' ), __( 'Structured Data Feed', 'teatatu-events' ) ),
	);
}

/**
 * Feed type of a feed post.
 *
 * @param int $feed_id Feed ID.
 * @return string '' if not a feed.
 */
function teatatu_events_feed_type_of( $feed_id ) {
	$post_type = get_post_type( $feed_id );
	foreach ( teatatu_events_feed_types() as $type => $def ) {
		if ( $def[0] === $post_type ) {
			return $type;
		}
	}
	return '';
}

/**
 * Feed config defaults.
 *
 * @param string $type Feed type.
 * @return array
 */
function teatatu_events_feed_defaults( $type ) {
	return array(
		'url'               => '',
		'cadence'           => 60,
		'active'            => 1,
		'auto_publish'      => 0,
		'auto_apply'        => 0,
		'default_source'    => 0,
		'default_category'  => 0,
		'default_venue'     => 0,
		'default_tags'      => array(),
		'skip_ended'        => 1,
		'venue_filter'      => 'off',
		'venue_ids'         => array(),
		'neighbourhood_ids' => array(),
		'follow_links'      => 0,
		'item_selector'     => array( 'tag' => '', 'attr_type' => 'class', 'attr_value' => '' ),
		'item_container'    => array( 'tag' => '', 'attr_type' => 'class', 'attr_value' => '' ),
		'sessions'          => teatatu_events_sessions_defaults(),
		'field_map'         => teatatu_events_default_field_map( $type ),
	);
}

/**
 * Session settings defaults (RSS and HTML feeds): how to treat an event page
 * that lists several dates.
 *
 * mode:   'off'  — Start/End come from the Content Mapping only;
 *         'next' — the next upcoming session;
 *         'each' — every upcoming session becomes its own event (up to max),
 *                  grouped so each one lists the others as "Other dates".
 * source: 'jsonld' — every Schema.org event on the linked page;
 *         'selector' — every element matching tag + class/id on the linked page.
 *
 * @return array
 */
function teatatu_events_sessions_defaults() {
	return array(
		'mode'       => 'off',
		'source'     => 'jsonld',
		'tag'        => '',
		'attr_type'  => 'class',
		'attr_value' => '',
		'max'        => 12,
	);
}

/**
 * Reads a feed's full config.
 *
 * @param int $feed_id Feed ID.
 * @return array
 */
function teatatu_events_get_feed_config( $feed_id ) {
	$type   = teatatu_events_feed_type_of( $feed_id );
	$config = teatatu_events_feed_defaults( $type );
	$stored = get_post_meta( $feed_id, '_teatatu_events_feed_config', true );
	if ( is_array( $stored ) ) {
		$config = array_merge( $config, $stored );
		$config['sessions'] = array_merge( teatatu_events_sessions_defaults(), is_array( $stored['sessions'] ?? null ) ? $stored['sessions'] : array() );
		if ( isset( $stored['field_map'] ) && is_array( $stored['field_map'] ) ) {
			$config['field_map'] = array_merge( teatatu_events_default_field_map( $type ), $stored['field_map'] );
		}
	}
	$config['type'] = $type;
	$config['id']   = (int) $feed_id;
	return $config;
}

// ---------------------------------------------------------------------------
// Field mapping (RSS and HTML feeds)
// ---------------------------------------------------------------------------

/**
 * Mappable event fields and labels (RSS/HTML feeds).
 *
 * @return string[]
 */
function teatatu_events_mappable_fields() {
	return array(
		'title'         => __( 'Title', 'teatatu-events' ),
		'excerpt'       => __( 'Excerpt', 'teatatu-events' ),
		'description'   => __( 'Description', 'teatatu-events' ),
		'image'         => __( 'Image URL', 'teatatu-events' ),
		'read_more_url' => __( 'Read More URL', 'teatatu-events' ),
		'start'         => __( 'Start', 'teatatu-events' ),
		'end'           => __( 'End', 'teatatu-events' ),
		'location'      => __( 'Location', 'teatatu-events' ),
		'address'       => __( 'Address', 'teatatu-events' ),
		'room'          => __( 'Room', 'teatatu-events' ),
		'tags'          => __( 'Event Tags', 'teatatu-events' ),
		'ticket_url'    => __( 'Ticket URL', 'teatatu-events' ),
		'price'         => __( 'Price', 'teatatu-events' ),
		'status'        => __( 'Status', 'teatatu-events' ),
	);
}

/**
 * Default per-field mapping. Dates and places default to the linked page's
 * Schema.org JSON-LD, which most event pages publish.
 *
 * @param string $type 'rss' or 'html' (others have no mapping).
 * @return array
 */
function teatatu_events_default_field_map( $type ) {
	$blank = array( 'source' => 'none', 'tag' => '', 'attr_type' => 'class', 'attr_value' => '', 'format' => '', 'pattern' => '' );
	$map   = array();
	foreach ( array_keys( teatatu_events_mappable_fields() ) as $field ) {
		$map[ $field ] = $blank;
	}
	foreach ( array( 'start', 'end', 'location', 'address', 'ticket_url', 'price', 'status' ) as $field ) {
		$map[ $field ]['source'] = 'page_jsonld';
	}
	if ( 'rss' === $type ) {
		$map['title']['source']         = 'rss_title';
		$map['excerpt']['source']       = 'rss_description';
		$map['image']['source']         = 'rss_image';
		$map['read_more_url']['source'] = 'rss_link';
		$map['tags']['source']          = 'rss_categories';
	} else {
		$map['title']['source']         = 'item';
		$map['image']['source']         = 'item';
		$map['read_more_url']['source'] = 'item';
	}
	return $map;
}

/**
 * Selectable sources for a field.
 *
 * @param string $type  'rss' or 'html'.
 * @param string $field Field.
 * @return string[] value => label
 */
function teatatu_events_field_source_options( $type, $field ) {
	$opts = array( 'none' => __( '— Not mapped —', 'teatatu-events' ) );
	if ( 'rss' === $type ) {
		$opts += array(
			'rss_title'       => __( 'RSS: Title', 'teatatu-events' ),
			'rss_description' => __( 'RSS: Description', 'teatatu-events' ),
			'rss_content'     => __( 'RSS: Full Content', 'teatatu-events' ),
		);
		if ( 'image' === $field ) {
			$opts['rss_image'] = __( 'RSS: Enclosure / Media Image', 'teatatu-events' );
		}
		if ( 'read_more_url' === $field ) {
			$opts['rss_link'] = __( 'RSS: Item Link', 'teatatu-events' );
		}
		if ( 'tags' === $field ) {
			$opts['rss_categories'] = __( 'RSS: Categories', 'teatatu-events' );
		}
		if ( in_array( $field, array( 'start', 'end' ), true ) ) {
			$opts['rss_pub_date'] = __( 'RSS: Publish Date (some feeds, e.g. Eventfinda, use it for the next start)', 'teatatu-events' );
		}
	} else {
		$opts['item'] = __( 'Within Repeating Item', 'teatatu-events' );
	}
	if ( 'read_more_url' !== $field ) {
		$opts['page']        = __( 'Linked Page (fetch and scrape)', 'teatatu-events' );
		$opts['page_jsonld'] = __( 'Linked Page JSON-LD (Schema.org Event)', 'teatatu-events' );
	}
	return $opts;
}

/**
 * Sanitises a field map from $_POST (map_{field}_{source|tag|attr_type|attr_value|format}).
 * Shared by Save and Preview so both read the form identically.
 *
 * @param string $type Feed type.
 * @return array
 */
function teatatu_events_sanitize_field_map_from_request( $type ) {
	$map = teatatu_events_default_field_map( $type );
	foreach ( array_keys( $map ) as $field ) {
		$p       = 'map_' . $field . '_';
		$allowed = array_keys( teatatu_events_field_source_options( $type, $field ) );
		$source  = isset( $_POST[ $p . 'source' ] ) ? sanitize_key( wp_unslash( $_POST[ $p . 'source' ] ) ) : $map[ $field ]['source']; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- callers verify.
		$map[ $field ] = array(
			'source'     => in_array( $source, $allowed, true ) ? $source : $map[ $field ]['source'],
			'tag'        => isset( $_POST[ $p . 'tag' ] ) ? preg_replace( '/[^a-z0-9]/', '', strtolower( sanitize_text_field( wp_unslash( $_POST[ $p . 'tag' ] ) ) ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing
			'attr_type'  => ( isset( $_POST[ $p . 'attr_type' ] ) && 'id' === $_POST[ $p . 'attr_type' ] ) ? 'id' : 'class', // phpcs:ignore WordPress.Security.NonceVerification.Missing
			'attr_value' => isset( $_POST[ $p . 'attr_value' ] ) ? sanitize_html_class( wp_unslash( $_POST[ $p . 'attr_value' ] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing
			'format'     => isset( $_POST[ $p . 'format' ] ) ? sanitize_text_field( wp_unslash( $_POST[ $p . 'format' ] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing
			'pattern'    => isset( $_POST[ $p . 'pattern' ] ) ? teatatu_events_sanitize_pattern( wp_unslash( $_POST[ $p . 'pattern' ] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated as a regex.
		);
	}
	return $map;
}

/**
 * A field's extraction pattern: a regular expression without delimiters,
 * kept only if it compiles. Admin-entered (manage_teatatu_events_feeds).
 *
 * @param string $pattern Raw pattern.
 * @return string '' when empty or invalid.
 */
function teatatu_events_sanitize_pattern( $pattern ) {
	$pattern = trim( str_replace( array( "\r", "\n" ), '', (string) $pattern ) );
	if ( '' === $pattern || strlen( $pattern ) > 500 ) {
		return '';
	}
	return false === @preg_match( teatatu_events_pattern_regex( $pattern ), '' ) ? '' : $pattern; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- testing user input.
}

/**
 * The delimited regex for a stored pattern (case-insensitive, dot matches
 * newlines, UTF-8).
 *
 * @param string $pattern Pattern.
 * @return string
 */
function teatatu_events_pattern_regex( $pattern ) {
	return '~' . str_replace( '~', '\\~', $pattern ) . '~isu';
}

/**
 * Applies a field's pattern: the first capture group when the pattern has
 * one, else the whole match; '' when it doesn't match.
 *
 * @param string $text    Text or HTML.
 * @param string $pattern Pattern ('' = return the text unchanged).
 * @return string
 */
function teatatu_events_apply_pattern( $text, $pattern ) {
	if ( '' === (string) $pattern ) {
		return (string) $text;
	}
	if ( ! @preg_match( teatatu_events_pattern_regex( $pattern ), (string) $text, $m ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return '';
	}
	return trim( isset( $m[1] ) ? $m[1] : $m[0] );
}

/**
 * A tag + class/id selector from $_POST fields with the given prefix.
 *
 * @param string $prefix Field name prefix (e.g. 'item_').
 * @return array {tag, attr_type, attr_value}
 */
function teatatu_events_selector_from_request( $prefix ) {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- callers verify the nonce.
	return array(
		'tag'        => isset( $_POST[ $prefix . 'tag' ] ) ? preg_replace( '/[^a-z0-9]/', '', strtolower( sanitize_text_field( wp_unslash( $_POST[ $prefix . 'tag' ] ) ) ) ) : '',
		'attr_type'  => ( isset( $_POST[ $prefix . 'attr_type' ] ) && 'id' === $_POST[ $prefix . 'attr_type' ] ) ? 'id' : 'class',
		'attr_value' => isset( $_POST[ $prefix . 'attr_value' ] ) ? sanitize_html_class( wp_unslash( $_POST[ $prefix . 'attr_value' ] ) ) : '',
	);
	// phpcs:enable
}

/**
 * Sanitises a whole feed config from $_POST (Save and Preview share this).
 *
 * @param string $type     Feed type.
 * @param array  $existing Existing config (for capability-gated fields).
 * @return array
 */
function teatatu_events_sanitize_feed_config_from_request( $type, $existing = array() ) {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- callers verify the nonce.
	$c = array_merge( teatatu_events_feed_defaults( $type ), $existing );
	$c['url']              = isset( $_POST['feed_url'] ) ? esc_url_raw( preg_replace( '#^webcal://#i', 'https://', trim( wp_unslash( $_POST['feed_url'] ) ) ) ) : '';
	$c['cadence']          = isset( $_POST['cadence'] ) ? max( 5, absint( $_POST['cadence'] ) ) : 60;
	$c['active']           = ! empty( $_POST['active'] ) ? 1 : 0;
	$c['default_source']   = absint( $_POST['default_source'] ?? 0 );
	$c['default_category'] = absint( $_POST['default_category'] ?? 0 );
	$c['default_venue']    = absint( $_POST['default_venue'] ?? 0 );
	$c['default_tags']     = array_values( array_filter( array_map( 'absint', (array) ( $_POST['default_tags'] ?? array() ) ) ) );
	$c['skip_ended']       = ! empty( $_POST['skip_ended'] ) ? 1 : 0;
	$filter                = sanitize_key( $_POST['venue_filter'] ?? 'off' );
	$c['venue_filter']     = in_array( $filter, array( 'off', 'any', 'selected', 'neighbourhoods' ), true ) ? $filter : 'off';
	$c['venue_ids']        = array_values( array_filter( array_map( 'absint', (array) ( $_POST['venue_ids'] ?? array() ) ) ) );
	$c['neighbourhood_ids'] = array_values( array_intersect( array_map( 'sanitize_key', (array) ( $_POST['neighbourhood_ids'] ?? array() ) ), teatatu_events_neighbourhood_slugs() ) );
	$c['follow_links']     = ! empty( $_POST['follow_links'] ) ? 1 : 0;
	if ( 'html' === $type ) {
		$c['item_selector']  = teatatu_events_selector_from_request( 'item_' );
		$c['item_container'] = teatatu_events_selector_from_request( 'container_' );
	}
	if ( in_array( $type, array( 'rss', 'html' ), true ) ) {
		$c['field_map'] = teatatu_events_sanitize_field_map_from_request( $type );
		$mode           = sanitize_key( $_POST['sessions_mode'] ?? 'off' );
		$source         = sanitize_key( $_POST['sessions_source'] ?? 'jsonld' );
		$c['sessions']  = array_merge(
			teatatu_events_selector_from_request( 'sessions_' ),
			array(
				'mode'   => in_array( $mode, array( 'off', 'next', 'each' ), true ) ? $mode : 'off',
				'source' => in_array( $source, array( 'jsonld', 'selector' ), true ) ? $source : 'jsonld',
				'max'    => min( 12, max( 1, absint( $_POST['sessions_max'] ?? 12 ) ) ),
			)
		);
	}
	// Publishing toggles: only written by a user who holds the real content
	// capability; otherwise the stored value is left untouched.
	if ( current_user_can( 'publish_teatatu_events_items' ) ) {
		$c['auto_publish'] = ! empty( $_POST['auto_publish'] ) ? 1 : 0;
	}
	if ( current_user_can( 'edit_published_teatatu_events_items' ) ) {
		$c['auto_apply'] = ! empty( $_POST['auto_apply'] ) ? 1 : 0;
	}
	// phpcs:enable
	return $c;
}

// ---------------------------------------------------------------------------
// HTML / DOM helpers (shared by every importer)
// ---------------------------------------------------------------------------

/**
 * Fetches a page's HTML with wp_safe_remote_get() and a size cap.
 *
 * @param string $url    URL.
 * @param string $accept Accept header.
 * @return string '' on failure.
 */
function teatatu_events_fetch_url( $url, $accept = 'text/html' ) {
	$response = wp_safe_remote_get(
		$url,
		array(
			'timeout'             => 20,
			'limit_response_size' => TEATATU_EVENTS_MAX_PAGE_BYTES,
			'headers'             => array( 'Accept' => $accept ),
			'user-agent'          => 'TeatatuEvents/' . TEATATU_EVENTS_VERSION . '; ' . home_url(),
		)
	);
	if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
		return '';
	}
	return (string) wp_remote_retrieve_body( $response );
}

/**
 * Loads HTML into a DOMDocument (errors suppressed, UTF-8).
 *
 * @param string $html HTML.
 * @return DOMDocument|null
 */
function teatatu_events_dom_load( $html ) {
	$html = (string) $html;
	if ( '' === trim( $html ) ) {
		return null;
	}
	$dom  = new DOMDocument();
	$prev = libxml_use_internal_errors( true );
	$dom->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET );
	libxml_clear_errors();
	libxml_use_internal_errors( $prev );
	return $dom;
}

/**
 * XPath query for a tag + class/id selector. Tag and value are sanitised to
 * a closed character set first, so interpolation into XPath is safe.
 *
 * @param string $tag        Tag.
 * @param string $attr_type  'class' or 'id'.
 * @param string $attr_value Class or id.
 * @return string
 */
function teatatu_events_dom_selector_query( $tag, $attr_type, $attr_value ) {
	$tag        = $tag ? preg_replace( '/[^a-z0-9]/', '', strtolower( $tag ) ) : '';
	$attr_value = $attr_value ? sanitize_html_class( $attr_value ) : '';
	$xpath_tag  = $tag ? $tag : '*';
	if ( $attr_value && 'id' === $attr_type ) {
		return sprintf( "//body//%s[@id='%s']", $xpath_tag, $attr_value );
	}
	if ( $attr_value ) {
		return sprintf( "//body//%s[contains(concat(' ', normalize-space(@class), ' '), ' %s ')]", $xpath_tag, $attr_value );
	}
	return '//body//' . $xpath_tag;
}

/**
 * First element matching a selector in an HTML fragment (or the body when
 * no selector is given).
 *
 * @param string $html       HTML.
 * @param string $tag        Tag.
 * @param string $attr_type  'class' or 'id'.
 * @param string $attr_value Value.
 * @return DOMElement|null
 */
function teatatu_events_dom_select( $html, $tag, $attr_type, $attr_value ) {
	$dom = teatatu_events_dom_load( $html );
	if ( ! $dom ) {
		return null;
	}
	$body = $dom->getElementsByTagName( 'body' )->item( 0 );
	if ( ! $body ) {
		return null;
	}
	if ( ! $tag && ! $attr_value ) {
		return $body;
	}
	$nodes = ( new DOMXPath( $dom ) )->query( teatatu_events_dom_selector_query( $tag, $attr_type, $attr_value ) );
	return ( $nodes && $nodes->length ) ? $nodes->item( 0 ) : null;
}

/**
 * Every element matching a selector (a selector is required), optionally
 * only inside the first element matching a container selector.
 *
 * @param string     $html       HTML.
 * @param string     $tag        Tag.
 * @param string     $attr_type  'class' or 'id'.
 * @param string     $attr_value Value.
 * @param int        $limit      Max.
 * @param array|null $within     Container selector {tag, attr_type, attr_value}, or null.
 * @return DOMElement[]
 */
function teatatu_events_dom_select_all( $html, $tag, $attr_type, $attr_value, $limit, $within = null ) {
	if ( ! $tag && ! $attr_value ) {
		return array();
	}
	$dom = teatatu_events_dom_load( $html );
	if ( ! $dom ) {
		return array();
	}
	$query = teatatu_events_dom_selector_query( $tag, $attr_type, $attr_value );
	if ( is_array( $within ) && ( $within['tag'] || $within['attr_value'] ) ) {
		// "(//body//container)[1]//item": items inside the first matching container only.
		$query = '(' . teatatu_events_dom_selector_query( $within['tag'], $within['attr_type'], $within['attr_value'] ) . ')[1]' . substr( $query, strlen( '//body' ) );
	}
	$nodes = ( new DOMXPath( $dom ) )->query( $query );
	$out   = array();
	foreach ( $nodes ? $nodes : array() as $node ) {
		$out[] = $node;
		if ( count( $out ) >= $limit ) {
			break;
		}
	}
	return $out;
}

/**
 * Inner HTML of a node.
 *
 * @param DOMNode $node Node.
 * @return string
 */
function teatatu_events_dom_inner_html( $node ) {
	$html = '';
	foreach ( $node->childNodes as $child ) {
		$html .= $node->ownerDocument->saveHTML( $child );
	}
	return $html;
}

/**
 * Image URL in a node: its own src, the first <img>, or a CSS
 * background-image on it or a descendant.
 *
 * @param DOMNode $node Node.
 * @return string
 */
function teatatu_events_dom_image_url( $node ) {
	if ( ! ( $node instanceof DOMElement ) ) {
		return '';
	}
	if ( 'img' === strtolower( $node->tagName ) ) {
		return $node->getAttribute( 'src' );
	}
	$imgs = $node->getElementsByTagName( 'img' );
	if ( $imgs->length > 0 ) {
		return $imgs->item( 0 )->getAttribute( 'src' );
	}
	$styled = array( $node );
	$found  = ( new DOMXPath( $node->ownerDocument ) )->query( './/*[contains(@style,"background-image")]', $node );
	foreach ( $found ? $found : array() as $el ) {
		$styled[] = $el;
	}
	foreach ( $styled as $el ) {
		if ( $el instanceof DOMElement && preg_match( '/background-image\s*:\s*url\(\s*[\'"]?([^\'")]+)[\'"]?\s*\)/i', $el->getAttribute( 'style' ), $m ) ) {
			return trim( $m[1] );
		}
	}
	return '';
}

/**
 * Link URL in a node: its own href, or the first <a>.
 *
 * @param DOMNode $node Node.
 * @return string
 */
function teatatu_events_dom_link_url( $node ) {
	if ( ! ( $node instanceof DOMElement ) ) {
		return '';
	}
	if ( 'a' === strtolower( $node->tagName ) ) {
		return $node->getAttribute( 'href' );
	}
	$links = $node->getElementsByTagName( 'a' );
	return $links->length > 0 ? $links->item( 0 )->getAttribute( 'href' ) : '';
}

/**
 * A node's date/time text, from the first machine-readable value found on it
 * or inside it, else its visible text:
 *  - datetime="" (on the node or a <time> inside it);
 *  - content="" (microdata, e.g. itemprop="startDate");
 *  - title="" of an hCalendar "value-title" (e.g. Eventfinda's dtstart/dtend).
 *
 * @param DOMNode $node Node.
 * @return string
 */
function teatatu_events_dom_datetime_text( $node ) {
	if ( ! ( $node instanceof DOMElement ) ) {
		return '';
	}
	foreach ( array( 'datetime', 'content' ) as $attr ) {
		if ( '' !== trim( $node->getAttribute( $attr ) ) ) {
			return trim( $node->getAttribute( $attr ) );
		}
	}
	if ( false !== strpos( ' ' . $node->getAttribute( 'class' ) . ' ', ' value-title ' ) && '' !== trim( $node->getAttribute( 'title' ) ) ) {
		return trim( $node->getAttribute( 'title' ) );
	}
	$xpath = new DOMXPath( $node->ownerDocument );
	$found = $xpath->query( ".//*[@datetime] | .//*[@itemprop and @content] | .//*[contains(concat(' ', normalize-space(@class), ' '), ' value-title ') and @title]", $node );
	if ( $found && $found->length ) {
		$el = $found->item( 0 );
		foreach ( array( 'datetime', 'content', 'title' ) as $attr ) {
			if ( '' !== trim( $el->getAttribute( $attr ) ) ) {
				return trim( $el->getAttribute( $attr ) );
			}
		}
	}
	return teatatu_events_clean_text( teatatu_events_dom_inner_html( $node ) );
}

/**
 * Plain text from HTML, with non-breaking spaces normalised and trimmed.
 *
 * @param string $html HTML.
 * @return string
 */
function teatatu_events_clean_text( $html ) {
	// Line breaks and block ends become spaces, so "A<br>B" reads "A B", not "AB".
	$html = preg_replace( '#<br\s*/?>|</(?:p|div|li|h[1-6]|tr|td|th|dd|dt)>#i', '$0 ', (string) $html );
	$text = wp_strip_all_tags( html_entity_decode( $html, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	return trim( preg_replace( '/\s+/u', ' ', str_replace( "\xC2\xA0", ' ', $text ) ) );
}

/**
 * Excerpt HTML: only links survive.
 *
 * @param string $html HTML.
 * @return string
 */
function teatatu_events_clean_excerpt( $html ) {
	$clean = wp_kses( (string) $html, array( 'a' => array( 'href' => true, 'title' => true ) ) );
	return trim( preg_replace( '/\s+/u', ' ', str_replace( "\xC2\xA0", ' ', html_entity_decode( $clean, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) ) );
}

/**
 * Description HTML: simple formatting only.
 *
 * @param string $html HTML.
 * @return string
 */
function teatatu_events_clean_description( $html ) {
	return trim(
		wp_kses(
			(string) $html,
			array(
				'p'      => array(),
				'br'     => array(),
				'ul'     => array(),
				'ol'     => array(),
				'li'     => array(),
				'strong' => array(),
				'em'     => array(),
				'b'      => array(),
				'i'      => array(),
				'h3'     => array(),
				'h4'     => array(),
				'a'      => array( 'href' => true, 'title' => true ),
			)
		)
	);
}

/**
 * Resolves a possibly-relative URL against a base URL.
 *
 * @param string $url      URL.
 * @param string $base_url Base.
 * @return string
 */
function teatatu_events_resolve_relative_url( $url, $base_url ) {
	$url = trim( (string) $url );
	if ( '' === $url || preg_match( '#^(javascript|mailto|tel|data):#i', $url ) ) {
		return '';
	}
	if ( preg_match( '#^[a-z][a-z0-9+.-]*://#i', $url ) ) {
		return $url;
	}
	$base = wp_parse_url( $base_url );
	if ( empty( $base['scheme'] ) || empty( $base['host'] ) ) {
		return $url;
	}
	$origin = $base['scheme'] . '://' . $base['host'] . ( isset( $base['port'] ) ? ':' . $base['port'] : '' );
	if ( 0 === strpos( $url, '//' ) ) {
		return $base['scheme'] . ':' . $url;
	}
	if ( 0 === strpos( $url, '/' ) ) {
		return $origin . $url;
	}
	$path = isset( $base['path'] ) ? $base['path'] : '/';
	return $origin . substr( $path, 0, strrpos( $path, '/' ) + 1 ) . $url;
}

// ---------------------------------------------------------------------------
// Dates and statuses
// ---------------------------------------------------------------------------

/**
 * Parses free-text event dates into start/end. Handles, in order: ISO 8601
 * (incl. a datetime="" value); an optional PHP format hint; then a lenient
 * parser for NZ day-first dates (3/10/2026 = 3 Oct), month names, "7pm",
 * "7:30pm", "19:00", and ranges ("3–5 Oct", "7–9pm", "10am to 2pm",
 * "Sat 3 Oct 7pm – Mon 5 Oct 2pm"). Text without a time zone is site time.
 * A date with no year takes its next future occurrence.
 *
 * @param string $raw         Raw text.
 * @param string $format_hint Optional PHP date format.
 * @return array|null {start, end (0 if unknown), all_day}
 */
function teatatu_events_parse_datetime( $raw, $format_hint = '' ) {
	$raw = trim( (string) $raw );
	if ( '' === $raw ) {
		return null;
	}
	$tz = wp_timezone();

	// 1. ISO 8601.
	if ( preg_match( '/^\d{4}-\d{2}-\d{2}(?:[T ]\d{2}:\d{2}(?::\d{2})?(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})?)?$/i', $raw ) ) {
		$ts = teatatu_events_parse_local_datetime( $raw );
		return $ts ? array( 'start' => $ts, 'end' => 0, 'all_day' => 10 === strlen( $raw ) ) : null;
	}
	if ( preg_match( '#^(\d{4}-\d{2}-\d{2}(?:T[\d:.]+(?:Z|[+-][\d:]+)?)?)\s*/\s*(\d{4}-\d{2}-\d{2}(?:T[\d:.]+(?:Z|[+-][\d:]+)?)?)$#i', $raw, $m ) ) {
		$s = teatatu_events_parse_local_datetime( $m[1] );
		$e = teatatu_events_parse_local_datetime( $m[2], true );
		return $s ? array( 'start' => $s, 'end' => (int) $e, 'all_day' => 10 === strlen( $m[1] ) ) : null;
	}

	// 1b. ISO date with local times: "2026-11-07, 10:00–15:00" (Eventfinda sessions).
	$iso = str_replace( array( '&ndash;', '&mdash;', '–', '—' ), '-', html_entity_decode( $raw, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	if ( preg_match( '/^(\d{4}-\d{2}-\d{2})[,T ]\s*(\d{1,2}):(\d{2})(?:\s*-\s*(\d{1,2}):(\d{2}))?$/', trim( $iso ), $m ) ) {
		$s = teatatu_events_parse_local_datetime( sprintf( '%s %02d:%02d', $m[1], $m[2], $m[3] ) );
		if ( ! $s ) {
			return null;
		}
		$e = 0;
		if ( isset( $m[4] ) && '' !== $m[4] ) {
			$e = (int) teatatu_events_parse_local_datetime( sprintf( '%s %02d:%02d', $m[1], $m[4], $m[5] ) );
			if ( $e && $e <= $s ) {
				$e += DAY_IN_SECONDS; // Runs past midnight.
			}
		}
		return array( 'start' => $s, 'end' => $e, 'all_day' => false );
	}

	// 2. Format hint.
	if ( $format_hint ) {
		$dt = DateTimeImmutable::createFromFormat( $format_hint, $raw, $tz );
		if ( $dt ) {
			$has_time = (bool) preg_match( '/[aAgGhHisU]/', $format_hint );
			return array( 'start' => $has_time ? $dt->getTimestamp() : $dt->setTime( 0, 0 )->getTimestamp(), 'end' => 0, 'all_day' => ! $has_time );
		}
	}

	// 3. Lenient.
	// "A and B" lists two dates: the first is the start, the second the end.
	$text = strtolower( str_ireplace( array( '–', '—', ' to ', ' until ', ' till ', ' and ' ), array( '-', '-', ' - ', ' - ', ' - ', ' - ' ), $raw ) );
	$text = preg_replace( '/\b(mon|tue|tues|wed|weds|thu|thur|thurs|fri|sat|sun)(day|nesday|sday|urday)?\b\.?,?/', ' ', $text );
	$text = preg_replace( '/(\d)(st|nd|rd|th)\b/', '$1', $text );
	$text = preg_replace( '/\b(from|at|on|starting|starts|@)\b/', ' ', $text );
	$text = str_replace( array( 'noon', 'midday' ), '12pm', $text );

	// Times. A range like "7-9pm" or "11-1pm" first: the start borrows the
	// end's am/pm unless that would put it after the end ("11-1pm" = 11am).
	$times = array();
	$time_re = '(\\d{1,2})(?:[:.](\\d{2}))?\\s*(am|pm|a\\.m\\.|p\\.m\\.)?';
	if ( preg_match( '/\\b' . $time_re . '\\s*-\\s*' . $time_re . '(?=\\s|$|,)/', $text, $rm ) && ( ! empty( $rm[6] ) || ! empty( $rm[5] ) ) ) {
		// The end must carry am/pm or minutes ("7-9pm", "7:00-9:30"), so a
		// bare number after a dash ("7pm - 1 Nov") is read as a date, not a time.
		$end_mer   = ! empty( $rm[6] ) ? ( 0 === strpos( $rm[6], 'p' ) ? 'pm' : 'am' ) : ( 0 === strpos( $rm[3], 'p' ) ? 'pm' : 'am' );
		$start_mer = ! empty( $rm[3] ) ? ( 0 === strpos( $rm[3], 'p' ) ? 'pm' : 'am' ) : $end_mer;
		$sh        = (int) $rm[1];
		$eh        = (int) $rm[4];
		if ( empty( $rm[3] ) && 'pm' === $end_mer && $sh < 12 && $eh < 12 && $sh > $eh ) {
			$start_mer = 'am';
		}
		$times[] = array( $sh, (int) ( $rm[2] ?? 0 ), $start_mer );
		$times[] = array( $eh, (int) ( $rm[5] ?? 0 ), $end_mer );
		$text    = str_replace( $rm[0], ' ', $text );
	}
	if ( preg_match_all( '/\\b(\\d{1,2})(?:[:.](\\d{2}))?\\s*(am|pm|a\\.m\\.|p\\.m\\.)|\\b([01]?\\d|2[0-3]):([0-5]\\d)\\b/', $text, $tm, PREG_SET_ORDER ) ) {
		foreach ( $tm as $t ) {
			if ( ! empty( $t[3] ) ) {
				$times[] = array( (int) $t[1], (int) ( $t[2] ?? 0 ), 0 === strpos( $t[3], 'p' ) ? 'pm' : 'am' );
			} else {
				$times[] = array( (int) $t[4], (int) $t[5], '' );
			}
		}
		$text = preg_replace( '/\\b(\\d{1,2})(?:[:.](\\d{2}))?\\s*(am|pm|a\\.m\\.|p\\.m\\.)|\\b([01]?\\d|2[0-3]):([0-5]\\d)\\b/', ' ', $text );
	}
	$to24 = function ( $t, $fallback_meridiem ) {
		list( $h, $i, $mer ) = $t;
		$mer = $mer ? $mer : $fallback_meridiem;
		if ( 'pm' === $mer && $h < 12 ) {
			$h += 12;
		} elseif ( 'am' === $mer && 12 === $h ) {
			$h = 0;
		}
		return array( min( 23, $h ), min( 59, $i ) );
	};

	// Dates.
	$months = array( 'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12 );
	$mon_re = '(jan|feb|mar|apr|may|jun|jul|aug|sep|sept|oct|nov|dec)[a-z]*\.?';
	$dates  = array();
	if ( preg_match( '/\b(\d{1,2})\s*-\s*(\d{1,2})\s+' . $mon_re . '(?:\s+(\d{4}))?/', $text, $m ) ) {
		// "3-5 oct 2026".
		$dates[] = array( (int) $m[1], $months[ substr( $m[3], 0, 3 ) ], $m[4] ?? '' );
		$dates[] = array( (int) $m[2], $months[ substr( $m[3], 0, 3 ) ], $m[4] ?? '' );
	} elseif ( preg_match( '/\b' . $mon_re . '\s+(\d{1,2})\s*-\s*(\d{1,2})(?:,?\s+(\d{4}))?/', $text, $m ) ) {
		// "oct 3-5, 2026".
		$dates[] = array( (int) $m[2], $months[ substr( $m[1], 0, 3 ) ], $m[4] ?? '' );
		$dates[] = array( (int) $m[3], $months[ substr( $m[1], 0, 3 ) ], $m[4] ?? '' );
	} else {
		if ( preg_match_all( '/\b(\d{1,2})\s+' . $mon_re . '(?:,?\s+(\d{4}))?|\b' . $mon_re . '\s+(\d{1,2})(?:,?\s+(\d{4}))?|\b(\d{1,2})\/(\d{1,2})\/(\d{2,4})\b/', $text, $dm, PREG_SET_ORDER ) ) {
			foreach ( $dm as $d ) {
				if ( ! empty( $d[1] ) ) {
					$dates[] = array( (int) $d[1], $months[ substr( $d[2], 0, 3 ) ], $d[3] ?? '' );
				} elseif ( ! empty( $d[4] ) ) {
					$dates[] = array( (int) $d[5], $months[ substr( $d[4], 0, 3 ) ], $d[6] ?? '' );
				} elseif ( ! empty( $d[7] ) ) {
					$year    = strlen( $d[9] ) === 2 ? '20' . $d[9] : $d[9];
					$dates[] = array( (int) $d[7], (int) $d[8], $year );
				}
				if ( count( $dates ) >= 2 ) {
					break;
				}
			}
		}
	}
	if ( ! $dates ) {
		return null;
	}
	// Fill a missing year: shared year from the other date, else the next future occurrence.
	$known_year = '';
	foreach ( $dates as $d ) {
		if ( '' !== $d[2] ) {
			$known_year = $d[2];
		}
	}
	$now = new DateTimeImmutable( 'now', $tz );
	foreach ( $dates as $k => $d ) {
		if ( '' === $d[2] ) {
			if ( $known_year ) {
				$dates[ $k ][2] = $known_year;
			} else {
				$year = (int) $now->format( 'Y' );
				if ( checkdate( $d[1], $d[0], $year ) && $now->setDate( $year, $d[1], $d[0] )->setTime( 23, 59 ) < $now->modify( '-1 day' ) ) {
					$year++;
				}
				$dates[ $k ][2] = (string) $year;
			}
		}
		if ( ! checkdate( $dates[ $k ][1], $dates[ $k ][0], (int) $dates[ $k ][2] ) ) {
			return null;
		}
	}

	$make = function ( $d, $time = null ) use ( $tz ) {
		$dt = ( new DateTimeImmutable( 'now', $tz ) )->setDate( (int) $d[2], (int) $d[1], (int) $d[0] );
		return $time ? $dt->setTime( $time[0], $time[1] )->getTimestamp() : $dt->setTime( 0, 0 )->getTimestamp();
	};

	if ( ! $times ) {
		$start = $make( $dates[0] );
		$end   = isset( $dates[1] ) ? $make( $dates[1] ) : 0;
		return array( 'start' => $start, 'end' => $end ? $end + DAY_IN_SECONDS - 1 : 0, 'all_day' => true );
	}
	$last_mer = '';
	foreach ( $times as $t ) {
		if ( $t[2] ) {
			$last_mer = $t[2];
		}
	}
	$first = $to24( $times[0], $times[0][2] ? $times[0][2] : $last_mer );
	$start = $make( $dates[0], $first );
	$end   = 0;
	if ( isset( $times[1] ) ) {
		$second = $to24( $times[1], $times[1][2] ? $times[1][2] : $last_mer );
		$end    = $make( isset( $dates[1] ) ? $dates[1] : $dates[0], $second );
		if ( $end <= $start && ! isset( $dates[1] ) ) {
			$end += DAY_IN_SECONDS; // Runs past midnight.
		}
	} elseif ( isset( $dates[1] ) ) {
		$end = $make( $dates[1] ) + DAY_IN_SECONDS - 1;
	}
	return array( 'start' => $start, 'end' => $end, 'all_day' => false );
}

/**
 * Maps free text (or a schema.org EventStatus / iCal STATUS) to an event status.
 *
 * @param string $text Text.
 * @return string '' when nothing recognisable.
 */
function teatatu_events_parse_status_text( $text ) {
	$t = strtolower( (string) $text );
	if ( '' === trim( $t ) ) {
		return '';
	}
	if ( false !== strpos( $t, 'cancel' ) ) {
		return 'cancelled';
	}
	if ( false !== strpos( $t, 'postpon' ) ) {
		return 'postponed';
	}
	if ( false !== strpos( $t, 'reschedul' ) ) {
		return 'rescheduled';
	}
	if ( false !== strpos( $t, 'sold out' ) || false !== strpos( $t, 'soldout' ) ) {
		return 'sold_out';
	}
	if ( false !== strpos( $t, 'scheduled' ) || false !== strpos( $t, 'confirmed' ) || false !== strpos( $t, 'tentative' ) ) {
		return 'scheduled';
	}
	return '';
}

/**
 * Matches tag names/slugs (split on commas) against existing Event Tags.
 * Unknown values are ignored — imports never create tags.
 *
 * @param string[]|string $values Values.
 * @return int[] Term IDs.
 */
function teatatu_events_match_tags( $values ) {
	if ( is_string( $values ) ) {
		$values = explode( ',', $values );
	}
	$terms = get_terms( array( 'taxonomy' => 'teatatu_events_tag', 'hide_empty' => false ) );
	if ( empty( $terms ) || is_wp_error( $terms ) ) {
		return array();
	}
	$index = array();
	foreach ( $terms as $term ) {
		$index[ teatatu_events_fold_text( $term->name ) ] = (int) $term->term_id;
		$index[ teatatu_events_fold_text( str_replace( '-', ' ', $term->slug ) ) ] = (int) $term->term_id;
	}
	$out = array();
	foreach ( (array) $values as $value ) {
		foreach ( explode( ',', (string) $value ) as $part ) {
			$key = teatatu_events_fold_text( $part );
			if ( '' !== $key && isset( $index[ $key ] ) ) {
				$out[] = $index[ $key ];
			}
		}
	}
	return array_values( array_unique( $out ) );
}

// ---------------------------------------------------------------------------
// Schema.org JSON-LD extraction (Structured Data feeds and "Linked Page JSON-LD")
// ---------------------------------------------------------------------------

/**
 * Whether a schema.org @type is an event: Event and every subtype — the
 * "…Event" types plus Festival, Hackathon, CourseInstance and EventSeries.
 *
 * @param mixed $types @type value.
 * @return bool
 */
function teatatu_events_is_jsonld_event_type( $types ) {
	foreach ( (array) $types as $type ) {
		if ( ! is_string( $type ) ) {
			continue;
		}
		$type = preg_replace( '#^(https?://schema\.org/|schema:)#i', '', $type );
		if ( in_array( $type, array( 'Festival', 'Hackathon', 'CourseInstance', 'EventSeries' ), true ) ) {
			return true;
		}
		if ( preg_match( '/Event$/', $type ) && ! preg_match( '/^(Pricing|Episode)/', $type ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Replaces {"@id": …} references with the node they point to (pages often
 * list Place/Offer/Organization nodes separately and refer to them by @id).
 *
 * @param mixed $value Value.
 * @param array $index @id => node.
 * @param int   $depth Remaining depth.
 * @return mixed
 */
function teatatu_events_jsonld_deref( $value, $index, $depth = 3 ) {
	if ( ! is_array( $value ) || $depth < 0 ) {
		return $value;
	}
	if ( isset( $value['@id'] ) && is_string( $value['@id'] ) && isset( $index[ $value['@id'] ] ) && count( array_diff( array_keys( $value ), array( '@id', '@type' ) ) ) === 0 ) {
		$value = $index[ $value['@id'] ];
	}
	foreach ( $value as $key => $child ) {
		if ( is_array( $child ) && '@context' !== $key ) {
			$value[ $key ] = teatatu_events_jsonld_deref( $child, $index, $depth - 1 );
		}
	}
	return $value;
}

/**
 * Every schema.org Event in a page's JSON-LD (inside @graph, ItemList and
 * arrays), with @id references resolved across all of the page's blocks.
 *
 * @param string $html Page HTML.
 * @return array[] Raw Event objects.
 */
function teatatu_events_extract_jsonld_events( $html ) {
	$events = array();
	if ( ! preg_match_all( '#<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', (string) $html, $m ) ) {
		return $events;
	}
	$index = array();
	$walk  = function ( $node ) use ( &$walk, &$events, &$index ) {
		if ( ! is_array( $node ) ) {
			return;
		}
		if ( isset( $node['@id'] ) && is_string( $node['@id'] ) && count( $node ) > 1 && ! isset( $index[ $node['@id'] ] ) ) {
			$index[ $node['@id'] ] = $node;
		}
		if ( isset( $node['@type'] ) && teatatu_events_is_jsonld_event_type( $node['@type'] ) ) {
			$events[] = $node;
			return;
		}
		foreach ( array( '@graph', 'itemListElement', 'item', 'subEvent', 'mainEntity' ) as $key ) {
			if ( isset( $node[ $key ] ) ) {
				$walk( $node[ $key ] );
			}
		}
		if ( array_keys( $node ) === range( 0, count( $node ) - 1 ) ) {
			foreach ( $node as $child ) {
				$walk( $child );
			}
		}
	};
	foreach ( $m[1] as $json ) {
		$data = json_decode( trim( html_entity_decode( $json, ENT_QUOTES, 'UTF-8' ) ), true );
		if ( null === $data ) {
			$data = json_decode( trim( $json ), true );
		}
		$walk( $data );
	}
	if ( $index ) {
		foreach ( $events as $i => $ev ) {
			$events[ $i ] = teatatu_events_jsonld_deref( $ev, $index );
		}
	}
	return $events;
}

/**
 * Picks the session to use from several events on one page: the first
 * (by start) that hasn't ended yet, else the most recent.
 *
 * @param array[] $events Raw Event objects.
 * @return array|null
 */
function teatatu_events_jsonld_pick_upcoming( $events ) {
	$dated = array();
	foreach ( $events as $ev ) {
		$start = teatatu_events_parse_datetime( is_string( $ev['startDate'] ?? null ) ? $ev['startDate'] : '' );
		if ( $start ) {
			$end     = teatatu_events_parse_datetime( is_string( $ev['endDate'] ?? null ) ? $ev['endDate'] : '' );
			$dated[] = array( $start['start'], $end ? $end['start'] : $start['start'], $ev );
		}
	}
	if ( ! $dated ) {
		return $events ? $events[0] : null;
	}
	usort(
		$dated,
		function ( $a, $b ) {
			return $a[0] <=> $b[0];
		}
	);
	$now = time();
	foreach ( $dated as $d ) {
		if ( $d[1] >= $now ) {
			return $d[2];
		}
	}
	return $dated[ count( $dated ) - 1 ][2];
}

/**
 * Converts a schema.org Event object into a candidate's fields.
 *
 * @param array  $ev       Event object.
 * @param string $base_url Page URL (for relative URLs).
 * @return array Partial candidate.
 */
function teatatu_events_jsonld_to_candidate( $ev, $base_url = '' ) {
	$str = function ( $v ) {
		if ( is_array( $v ) ) {
			$v = isset( $v['@value'] ) ? $v['@value'] : ( isset( $v[0] ) ? $v[0] : '' );
		}
		return is_scalar( $v ) ? trim( (string) $v ) : '';
	};
	$out = array(
		'title'       => teatatu_events_clean_text( $str( $ev['name'] ?? '' ) ),
		'excerpt'     => '',
		'description' => teatatu_events_clean_description( wpautop( $str( $ev['description'] ?? '' ) ) ),
		'url'         => $str( $ev['url'] ?? '' ),
		'id'          => $str( $ev['@id'] ?? '' ),
	);
	$out['excerpt'] = wp_trim_words( teatatu_events_clean_text( $out['description'] ), 40 );
	if ( $out['url'] && $base_url ) {
		$out['url'] = teatatu_events_resolve_relative_url( $out['url'], $base_url );
	}
	$start = $str( $ev['startDate'] ?? '' );
	$end   = $str( $ev['endDate'] ?? '' );
	if ( $start ) {
		$s = teatatu_events_parse_datetime( $start );
		if ( $s ) {
			$out['start']   = $s['start'];
			$out['all_day'] = $s['all_day'];
			if ( $end ) {
				$e          = teatatu_events_parse_datetime( $end );
				$out['end'] = $e ? ( $e['all_day'] ? $e['start'] + DAY_IN_SECONDS - 1 : $e['start'] ) : 0;
			}
		}
	}
	$image = $ev['image'] ?? '';
	if ( is_array( $image ) ) {
		$image = isset( $image['url'] ) ? $image['url'] : ( isset( $image[0] ) ? ( is_array( $image[0] ) ? ( $image[0]['url'] ?? '' ) : $image[0] ) : '' );
	}
	$out['image'] = $base_url ? teatatu_events_resolve_relative_url( (string) $image, $base_url ) : (string) $image;

	$out['status'] = teatatu_events_parse_status_text( $str( $ev['eventStatus'] ?? '' ) );

	$location = $ev['location'] ?? null;
	if ( is_array( $location ) && isset( $location[0] ) ) {
		$location = $location[0];
	}
	if ( is_array( $location ) ) {
		$ltype = (array) ( $location['@type'] ?? array() );
		if ( in_array( 'VirtualLocation', $ltype, true ) ) {
			$out['online'] = true;
		} else {
			$out['location'] = $str( $location['name'] ?? '' );
			$addr            = $location['address'] ?? '';
			if ( is_array( $addr ) ) {
				$parsed = teatatu_events_parse_address_text( $str( $addr['streetAddress'] ?? '' ), teatatu_events_setting( 'default_country' ) );
				// addressLocality is often the suburb in NZ ("Te Atatū Peninsula"):
				// a locality that isn't a known town/city goes in Suburb.
				$locality = $str( $addr['addressLocality'] ?? '' );
				$region   = $str( $addr['addressRegion'] ?? '' );
				$suburb   = $parsed['suburb'];
				$city     = $locality;
				if ( '' !== $locality && ! in_array( teatatu_events_fold_text( $locality ), teatatu_events_known_cities(), true ) ) {
					$suburb = $suburb ? $suburb : $locality;
					$city   = in_array( teatatu_events_fold_text( $region ), teatatu_events_known_cities(), true ) ? $region : ( $parsed['city'] ? $parsed['city'] : '' );
				}
				$out['address_parts'] = array(
					'unit'     => $parsed['unit'],
					'number'   => $parsed['number'],
					'street'   => $parsed['street'] ? $parsed['street'] : $str( $addr['streetAddress'] ?? '' ),
					'suburb'   => $suburb,
					'city'     => $city,
					'region'   => $region,
					'postcode' => $str( $addr['postalCode'] ?? '' ),
					'country'  => $str( is_array( $addr['addressCountry'] ?? '' ) ? ( $addr['addressCountry']['name'] ?? '' ) : ( $addr['addressCountry'] ?? '' ) ),
				);
				$out['address'] = teatatu_events_format_address_parts( $out['address_parts'] );
			} elseif ( $addr ) {
				$out['address'] = $str( $addr );
			}
			if ( isset( $location['geo']['latitude'], $location['geo']['longitude'] ) ) {
				$out['lat'] = (float) $location['geo']['latitude'];
				$out['lng'] = (float) $location['geo']['longitude'];
			}
		}
	} elseif ( is_string( $location ) ) {
		$out['address'] = $location;
	}

	$offers = $ev['offers'] ?? null;
	if ( is_array( $offers ) && ! isset( $offers[0] ) ) {
		$offers = array( $offers );
	}
	if ( is_array( $offers ) && $offers ) {
		$prices    = array();
		$text      = '';
		$sold_out  = 0;
		$available = 0;
		foreach ( $offers as $offer ) {
			if ( ! is_array( $offer ) ) {
				continue;
			}
			if ( empty( $out['ticket_url'] ) && '' !== $str( $offer['url'] ?? '' ) ) {
				$out['ticket_url'] = $str( $offer['url'] );
			}
			foreach ( array( 'price', 'lowPrice', 'highPrice' ) as $key ) {
				$price = $str( $offer[ $key ] ?? '' );
				if ( '' === $price ) {
					continue;
				}
				if ( is_numeric( $price ) ) {
					$prices[] = (float) $price;
				} elseif ( '' === $text ) {
					$text = $price;
				}
			}
			if ( false !== stripos( $str( $offer['availability'] ?? '' ), 'SoldOut' ) ) {
				$sold_out++;
			} else {
				$available++;
			}
		}
		$money = function ( $n ) {
			return '$' . rtrim( rtrim( number_format( $n, 2, '.', '' ), '0' ), '.' );
		};
		if ( $prices ) {
			$low  = min( $prices );
			$high = max( $prices );
			if ( 0.0 === $high ) {
				$out['price'] = __( 'Free', 'teatatu-events' );
			} elseif ( $low === $high ) {
				$out['price'] = $money( $high );
			} else {
				$out['price'] = ( 0.0 === $low ? __( 'Free', 'teatatu-events' ) : $money( $low ) ) . '–' . $money( $high );
			}
			$out['is_free'] = 0.0 === $high;
		} elseif ( '' !== $text ) {
			$out['price'] = $text;
		}
		if ( $sold_out && ! $available && empty( $out['status'] ) ) {
			$out['status'] = 'sold_out';
		}
	}
	if ( isset( $ev['isAccessibleForFree'] ) && filter_var( $ev['isAccessibleForFree'], FILTER_VALIDATE_BOOLEAN ) ) {
		$out['is_free'] = true;
	}
	$keywords = $ev['keywords'] ?? '';
	$out['tags'] = is_array( $keywords ) ? $keywords : array_filter( array_map( 'trim', explode( ',', (string) $keywords ) ) );
	if ( isset( $ev['organizer']['name'] ) ) {
		$out['organizer'] = $str( $ev['organizer']['name'] );
	}
	return $out;
}

// ---------------------------------------------------------------------------
// The import pipeline
// ---------------------------------------------------------------------------

/**
 * Candidate defaults. Every importer produces candidates in this shape.
 *
 * @return array
 */
function teatatu_events_candidate_defaults() {
	return array(
		'identity'      => '',  // Stable per-feed dedupe identity.
		'ext_kind'      => 'url',
		'ext_identity'  => '',  // Cross-site identity (see includes/dedupe.php).
		'title'         => '',
		'excerpt'       => '',
		'description'   => '',
		'image'         => '',
		'read_more_url' => '',
		'start'         => 0,
		'end'           => 0,
		'all_day'       => false,
		'location'      => '',
		'address'       => '',
		'address_parts' => null,
		'lat'           => '',
		'lng'           => '',
		'room'          => '',
		'online'        => false,
		'tags'          => array(),
		'ticket_url'    => '',
		'price'         => '',
		'is_free'       => null,
		'status'        => '',
		'skip_reason'   => '',
		'group'         => '',  // Sessions of one source event share a group (shown as "Other dates").
	);
}

/**
 * Applies the venue-matching and filter rules to a candidate.
 *
 * @param array $c      Candidate.
 * @param array $config Feed config.
 * @return array {venue_id, nbhd_slug, pass: bool, reason: string}
 */
function teatatu_events_candidate_place( $c, $config ) {
	$parts = is_array( $c['address_parts'] ) ? array_merge( array( 'name' => '' ), $c['address_parts'] ) : teatatu_events_parse_address_text( $c['address'], teatatu_events_setting( 'default_country' ) );
	if ( '' !== $c['lat'] ) {
		$parts['lat'] = $c['lat'];
		$parts['lng'] = $c['lng'];
	}
	$match    = ( '' !== trim( $c['location'] . $c['address'] ) || '' !== $c['lat'] ) ? teatatu_events_match_venue( $c['location'], $parts, '' !== $c['lat'] ? $c['lat'] : null, '' !== $c['lng'] ? $c['lng'] : null ) : null;
	$venue_id = $match ? (int) $match['term_id'] : 0;
	if ( ! $venue_id && '' === trim( $c['location'] . $c['address'] ) && ! empty( $config['default_venue'] ) ) {
		$venue_id = (int) $config['default_venue'];
	}
	$nbhd = $venue_id ? (string) get_term_meta( $venue_id, 'teatatu_events_addr_neighbourhood', true ) : teatatu_events_resolve_neighbourhood( $parts )['slug'];

	$pass   = true;
	$reason = '';
	switch ( $config['venue_filter'] ) {
		case 'any':
			$pass   = (bool) $venue_id;
			$reason = __( 'not a recognised venue', 'teatatu-events' );
			break;
		case 'selected':
			$pass   = $venue_id && in_array( $venue_id, array_map( 'intval', $config['venue_ids'] ), true );
			$reason = __( 'not one of the selected venues', 'teatatu-events' );
			break;
		case 'neighbourhoods':
			$pass   = $nbhd && in_array( $nbhd, $config['neighbourhood_ids'], true );
			$reason = __( 'not in a selected neighbourhood', 'teatatu-events' );
			break;
	}
	return array(
		'venue_id'  => $venue_id,
		'via'       => $match ? $match['via'] : '',
		'nbhd_slug' => $nbhd,
		'pass'      => $pass,
		'reason'    => $pass ? '' : $reason,
	);
}

/**
 * The import-controlled values for a candidate (what gets written to the
 * event, compared for changes, and stored in a pending update).
 *
 * @param array $c      Candidate.
 * @param array $place  Place result.
 * @return array
 */
function teatatu_events_candidate_values( $c, $place ) {
	$address = '';
	if ( ! $place['venue_id'] ) {
		$address = $c['address'] ? $c['address'] : ( is_array( $c['address_parts'] ) ? teatatu_events_format_address_parts( $c['address_parts'] ) : '' );
		if ( '' === $address && $c['location'] ) {
			$address = $c['location'];
		} elseif ( $c['location'] && false === stripos( $address, $c['location'] ) ) {
			$address = $c['location'] . ', ' . $address;
		}
	}
	return array(
		'title'    => wp_strip_all_tags( $c['title'] ),
		'excerpt'  => $c['excerpt'],
		'content'  => $c['description'],
		'venue_id' => (int) $place['venue_id'],
		'fields'   => array(
			'start'         => (int) $c['start'],
			'end'           => (int) $c['end'],
			'all_day'       => (bool) $c['all_day'],
			'status'        => $c['status'] ? $c['status'] : 'scheduled',
			'ticket_url'    => esc_url_raw( $c['ticket_url'] ),
			'price'         => sanitize_text_field( $c['price'] ),
			'is_free'       => (bool) $c['is_free'],
			'read_more_url' => esc_url_raw( $c['read_more_url'] ),
			'image_url'     => esc_url_raw( $c['image'] ),
			'room'          => sanitize_text_field( $c['room'] ),
			'address'       => sanitize_textarea_field( $address ),
		),
	);
}

/**
 * Runs candidates from one fetch through the pipeline.
 *
 * @param array   $config     Feed config.
 * @param array[] $candidates Candidates.
 * @param bool    $complete   Whether the fetch succeeded and the source returned
 *                            its whole list (enables removed-at-source checks).
 * @return array Stats.
 */
function teatatu_events_import_candidates( $config, $candidates, $complete ) {
	$feed_id = (int) $config['id'];
	$stats   = array(
		'imported'       => 0,
		'updated'        => 0,
		'pending'        => 0,
		'unchanged'      => 0,
		'skipped_filter' => 0,
		'skipped_ended'  => 0,
		'skipped_nodate' => 0,
		'removed'        => 0,
		'restored'       => 0,
	);
	$seen = array();
	$now  = time();

	foreach ( $candidates as $c ) {
		$c = array_merge( teatatu_events_candidate_defaults(), $c );
		if ( '' === $c['identity'] || '' === trim( $c['title'] ) ) {
			continue;
		}
		if ( isset( $seen[ $c['identity'] ] ) ) {
			// The same item twice in one fetch (e.g. a listing page that repeats
			// its events in a "popular" block): the first one wins.
			$stats['duplicates'] = ( $stats['duplicates'] ?? 0 ) + 1;
			continue;
		}
		$seen[ $c['identity'] ] = true; // Seen at source, before any filtering.
		if ( ! $c['start'] ) {
			$stats['skipped_nodate']++;
			continue;
		}
		$existing = teatatu_events_find_imported_post( $feed_id, $c['identity'] );
		$ends     = $c['end'] ? $c['end'] : $c['start'] + (int) teatatu_events_setting( 'default_duration' ) * MINUTE_IN_SECONDS;
		if ( ! $existing && $config['skip_ended'] && $ends < $now ) {
			$stats['skipped_ended']++;
			continue;
		}
		$place = teatatu_events_candidate_place( $c, $config );
		if ( ! $existing && ! $place['pass'] ) {
			$stats['skipped_filter']++;
			continue;
		}
		$values = teatatu_events_candidate_values( $c, $place );
		$tags   = array_values( array_unique( array_merge( teatatu_events_match_tags( $c['tags'] ), array_map( 'intval', (array) $config['default_tags'] ) ) ) );
		$ext    = teatatu_events_external_key( $c['ext_kind'], $c['ext_identity'] ? $c['ext_identity'] : $c['identity'] );

		if ( ! $existing ) {
			$id = teatatu_events_batch_write(
				0,
				function () use ( $values, $config, $c, $tags, $ext, $place, $feed_id ) {
					$new_id = wp_insert_post(
						wp_slash(
							array(
								'post_type'    => 'teatatu_event',
								'post_status'  => $config['auto_publish'] ? 'publish' : 'draft',
								'post_title'   => $values['title'],
								'post_excerpt' => $values['excerpt'],
								'post_content' => $values['content'],
								'post_author'  => teatatu_events_import_author(),
							)
						),
						true
					);
					if ( is_wp_error( $new_id ) ) {
						return $new_id;
					}
					$r = teatatu_events_save_fields( $new_id, $values['fields'] );
					if ( is_wp_error( $r ) ) {
						return $r;
					}
					update_post_meta( $new_id, teatatu_events_mk( 'feed_id' ), $feed_id );
					update_post_meta( $new_id, teatatu_events_mk( 'feed_type' ), $config['type'] );
					update_post_meta( $new_id, teatatu_events_mk( 'import_uid' ), $c['identity'] );
					update_post_meta( $new_id, teatatu_events_mk( 'source_link' ), $c['read_more_url'] );
					update_post_meta( $new_id, teatatu_events_mk( 'external_key' ), $ext );
					if ( '' !== $c['group'] ) {
						update_post_meta( $new_id, teatatu_events_mk( 'import_group' ), $feed_id . '|' . $c['group'] );
					}
					if ( $place['venue_id'] ) {
						wp_set_object_terms( $new_id, array( $place['venue_id'] ), 'teatatu_events_venue' );
					} elseif ( '' !== trim( $values['fields']['address'] ) ) {
						update_post_meta( $new_id, teatatu_events_mk( 'needs_venue' ), 1 );
					}
					if ( $tags ) {
						wp_set_object_terms( $new_id, $tags, 'teatatu_events_tag' );
					}
					if ( $config['default_source'] ) {
						wp_set_object_terms( $new_id, array( (int) $config['default_source'] ), 'teatatu_events_source' );
					}
					if ( $config['default_category'] ) {
						wp_set_object_terms( $new_id, array( (int) $config['default_category'] ), 'teatatu_events_category' );
					}
					return $new_id;
				}
			);
			if ( ! is_wp_error( $id ) ) {
				$stats['imported']++;
			}
			continue;
		}

		update_post_meta( $existing, teatatu_events_mk( 'missing_count' ), 0 );
		update_post_meta( $existing, teatatu_events_mk( 'external_key' ), $ext );
		if ( '' !== $c['group'] ) {
			update_post_meta( $existing, teatatu_events_mk( 'import_group' ), $feed_id . '|' . $c['group'] );
		}

		// Reappeared after being removed at source.
		if ( get_post_meta( $existing, teatatu_events_mk( 'removed_at_source' ), true ) ) {
			$confirmed = (bool) get_post_meta( $existing, teatatu_events_mk( 'removal_confirmed' ), true );
			delete_post_meta( $existing, teatatu_events_mk( 'removed_at_source' ) );
			if ( ! $confirmed ) {
				$previous = get_post_meta( $existing, teatatu_events_mk( 'status_before_removal' ), true );
				teatatu_events_save_fields( $existing, array( 'status' => $previous ? $previous : 'scheduled' ) );
				teatatu_events_refresh_derived( $existing );
				$stats['restored']++;
			}
			delete_post_meta( $existing, teatatu_events_mk( 'removal_confirmed' ) );
			delete_post_meta( $existing, teatatu_events_mk( 'status_before_removal' ) );
		}

		if ( 'publish' !== get_post_status( $existing ) ) {
			// Not reviewed yet: keep it in sync, and promote it if auto-publish is on.
			teatatu_events_apply_import_values( $existing, $values, $tags, $config['auto_publish'] ? 'publish' : '' );
			$stats['updated']++;
			continue;
		}

		$diff = teatatu_events_import_diff( $existing, $values );
		if ( ! $diff ) {
			delete_post_meta( $existing, teatatu_events_mk( 'pending_update' ) );
			$stats['unchanged']++;
			continue;
		}
		if ( $config['auto_apply'] ) {
			teatatu_events_apply_import_values( $existing, $values, null );
			delete_post_meta( $existing, teatatu_events_mk( 'pending_update' ) );
			$stats['updated']++;
		} else {
			update_post_meta(
				$existing,
				teatatu_events_mk( 'pending_update' ),
				array(
					'values'       => $values,
					'diff'         => $diff,
					'date_status'  => (bool) array_intersect( array_keys( $diff ), array( 'when', 'status' ) ),
					'detected_at'  => $now,
					'feed_id'      => $feed_id,
				)
			);
			$stats['pending']++;
		}
	}

	if ( $complete && $seen ) {
		$stats['removed'] = teatatu_events_check_removed_at_source( $feed_id, $seen );
	}
	return $stats;
}

/**
 * Author for imported events: the feed's author, else the first administrator.
 *
 * @return int
 */
function teatatu_events_import_author() {
	$uid = get_current_user_id();
	if ( $uid ) {
		return $uid;
	}
	$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
	return $admins ? (int) $admins[0] : 0;
}

/**
 * Finds the event this feed previously imported with this identity.
 *
 * @param int    $feed_id  Feed ID.
 * @param string $identity Identity.
 * @return int 0 if none.
 */
function teatatu_events_find_imported_post( $feed_id, $identity ) {
	$ids = get_posts(
		array(
			'post_type'        => 'teatatu_event',
			'post_status'      => 'any',
			'posts_per_page'   => 1,
			'fields'           => 'ids',
			'suppress_filters' => true,
			'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array( 'key' => teatatu_events_mk( 'feed_id' ), 'value' => (int) $feed_id ),
				array( 'key' => teatatu_events_mk( 'import_uid' ), 'value' => (string) $identity ),
				array( 'key' => teatatu_events_mk( 'draft_of' ), 'compare' => 'NOT EXISTS' ),
			),
		)
	);
	return $ids ? (int) $ids[0] : 0;
}

/**
 * Human-readable differences between an event and freshly imported values.
 *
 * @param int   $post_id Event ID.
 * @param array $values  Candidate values.
 * @return array field => [old, new]
 */
function teatatu_events_import_diff( $post_id, $values ) {
	$f    = teatatu_events_get_fields( $post_id );
	$new  = array_merge( $f, $values['fields'] );
	$end  = $new['end'] ? $new['end'] : 0;
	$diff = array();

	$old_when = teatatu_events_format_when( $f['start'], $f['end'], $f['all_day'] );
	$tmp_end  = $end ? $end : ( $new['all_day'] ? teatatu_events_local_day_end( $new['start'] ) : $new['start'] + ( $f['end'] - $f['start'] ) );
	$new_when = teatatu_events_format_when( $new['all_day'] ? teatatu_events_local_day_start( $new['start'] ) : $new['start'], $tmp_end, $new['all_day'] );
	if ( $old_when !== $new_when ) {
		$diff['when'] = array( $old_when, $new_when );
	}
	$statuses = teatatu_events_statuses();
	if ( $f['status'] !== $new['status'] && ! get_post_meta( $post_id, teatatu_events_mk( 'removed_at_source' ), true ) ) {
		$diff['status'] = array( $statuses[ $f['status'] ], $statuses[ $new['status'] ] ?? $new['status'] );
	}
	$checks = array(
		'title'         => array( get_post_field( 'post_title', $post_id, 'raw' ), $values['title'] ),
		'excerpt'       => array( get_post_field( 'post_excerpt', $post_id, 'raw' ), $values['excerpt'] ),
		'image_url'     => array( $f['image_url'], $new['image_url'] ),
		'read_more_url' => array( $f['read_more_url'], $new['read_more_url'] ),
		'ticket_url'    => array( $f['ticket_url'], $new['ticket_url'] ),
		'price'         => array( $f['price'], $new['price'] ),
		'room'          => array( $f['room'], $new['room'] ),
	);
	$venue = teatatu_events_first_term_id( $post_id, 'teatatu_events_venue' );
	$checks['place'] = array(
		$venue ? get_term( $venue )->name : $f['address'],
		$values['venue_id'] ? get_term( $values['venue_id'] )->name : $values['fields']['address'],
	);
	foreach ( $checks as $key => $pair ) {
		if ( trim( (string) $pair[0] ) !== trim( (string) $pair[1] ) && ! ( '' === trim( (string) $pair[1] ) && in_array( $key, array( 'image_url', 'ticket_url', 'price', 'room' ), true ) ) ) {
			$diff[ $key ] = $pair;
		}
	}
	return $diff;
}

/**
 * Writes imported values to an event. Shared by unreviewed-draft resync,
 * auto-apply and the manual Apply action, so all three have the same effect.
 *
 * @param int        $post_id Event ID.
 * @param array      $values  Candidate values.
 * @param int[]|null $tags    Tag IDs to set (null = leave tags alone).
 * @param string     $status  Post status to set ('' = unchanged).
 */
function teatatu_events_apply_import_values( $post_id, $values, $tags = null, $status = '' ) {
	teatatu_events_batch_write(
		$post_id,
		function ( $id ) use ( $values, $tags, $status ) {
			$postarr = array(
				'ID'           => $id,
				'post_title'   => $values['title'],
				'post_excerpt' => $values['excerpt'],
			);
			if ( '' !== trim( (string) $values['content'] ) ) {
				$postarr['post_content'] = $values['content'];
			}
			if ( $status ) {
				$postarr['post_status'] = $status;
			}
			wp_update_post( wp_slash( $postarr ), true );
			$fields = $values['fields'];
			foreach ( array( 'image_url', 'ticket_url', 'price', 'room' ) as $keep_if_empty ) {
				if ( '' === (string) $fields[ $keep_if_empty ] ) {
					unset( $fields[ $keep_if_empty ] );
				}
			}
			if ( get_post_meta( $id, teatatu_events_mk( 'removed_at_source' ), true ) ) {
				unset( $fields['status'] );
			}
			teatatu_events_save_fields( $id, $fields );
			if ( $values['venue_id'] ) {
				wp_set_object_terms( $id, array( (int) $values['venue_id'] ), 'teatatu_events_venue' );
				delete_post_meta( $id, teatatu_events_mk( 'needs_venue' ) );
			} elseif ( '' !== trim( $values['fields']['address'] ) && ! teatatu_events_first_term_id( $id, 'teatatu_events_venue' ) ) {
				update_post_meta( $id, teatatu_events_mk( 'needs_venue' ), 1 );
			}
			if ( null !== $tags && $tags ) {
				wp_set_object_terms( $id, $tags, 'teatatu_events_tag' );
			}
			return $id;
		}
	);
}

/**
 * Removed at source: events this feed imported that are still upcoming but
 * were missing from a successful, complete fetch. After N consecutive
 * misses (setting), a published event is set to Cancelled immediately
 * (bypassing review — a vanished event is urgent) and flagged; a draft is
 * only flagged. Events are never deleted.
 *
 * @param int   $feed_id Feed ID.
 * @param array $seen    identity => true for everything the source returned.
 * @return int Number newly marked removed.
 */
function teatatu_events_check_removed_at_source( $feed_id, $seen ) {
	$args = teatatu_events_query_args( 'upcoming' );
	$args['meta_query'][] = array( 'key' => teatatu_events_mk( 'feed_id' ), 'value' => (int) $feed_id );
	$args['meta_query'][] = array( 'key' => teatatu_events_mk( 'draft_of' ), 'compare' => 'NOT EXISTS' );
	$ids = get_posts(
		array_merge(
			$args,
			array(
				'post_type'        => 'teatatu_event',
				'post_status'      => array( 'publish', 'draft', 'pending' ),
				'posts_per_page'   => -1,
				'fields'           => 'ids',
				'suppress_filters' => true,
			)
		)
	);
	$threshold = max( 1, (int) teatatu_events_setting( 'removed_threshold' ) );
	$marked    = 0;
	foreach ( $ids as $id ) {
		$uid = (string) get_post_meta( $id, teatatu_events_mk( 'import_uid' ), true );
		if ( isset( $seen[ $uid ] ) || get_post_meta( $id, teatatu_events_mk( 'removed_at_source' ), true ) ) {
			continue;
		}
		$missing = (int) get_post_meta( $id, teatatu_events_mk( 'missing_count' ), true ) + 1;
		update_post_meta( $id, teatatu_events_mk( 'missing_count' ), $missing );
		if ( $missing < $threshold ) {
			continue;
		}
		update_post_meta( $id, teatatu_events_mk( 'removed_at_source' ), time() );
		if ( 'publish' === get_post_status( $id ) ) {
			update_post_meta( $id, teatatu_events_mk( 'status_before_removal' ), teatatu_events_get_status( $id ) );
			teatatu_events_save_fields( $id, array( 'status' => 'cancelled' ) );
		}
		teatatu_events_refresh_derived( $id );
		$marked++;
	}
	return $marked;
}

/**
 * Fetches and imports one feed now. Always stamps last-checked (even on
 * failure) so a broken feed isn't hammered every tick.
 *
 * @param int $feed_id Feed ID.
 * @return array Stats (or {error}).
 */
function teatatu_events_process_feed( $feed_id ) {
	$config = teatatu_events_get_feed_config( $feed_id );
	update_post_meta( $feed_id, '_teatatu_events_feed_last_checked', time() );
	if ( ! $config['url'] || ! $config['type'] ) {
		return array( 'error' => 'no url' );
	}
	$built = teatatu_events_build_candidates( $config, TEATATU_EVENTS_MAX_ITEMS_PER_FEED );
	if ( $built['error'] ) {
		update_post_meta( $feed_id, '_teatatu_events_feed_last_error', $built['error'] );
		return array( 'error' => $built['error'] );
	}
	$stats = teatatu_events_import_candidates( $config, $built['candidates'], $built['complete'] );
	$note  = '';
	if ( $stats['skipped_nodate'] ) {
		/* translators: %d: count. */
		$note = sprintf( _n( '%d item skipped: its start date could not be read.', '%d items skipped: their start dates could not be read.', $stats['skipped_nodate'], 'teatatu-events' ), $stats['skipped_nodate'] );
	}
	update_post_meta( $feed_id, '_teatatu_events_feed_last_error', $note );
	update_post_meta( $feed_id, '_teatatu_events_feed_last_stats', $stats );
	return $stats;
}

/**
 * Builds candidates for a feed, dispatching to its importer.
 *
 * @param array $config Feed config.
 * @param int   $limit  Max items.
 * @return array {candidates, error, complete}
 */
function teatatu_events_build_candidates( $config, $limit ) {
	$fn = 'teatatu_events_' . $config['type'] . '_build_candidates';
	if ( ! function_exists( $fn ) ) {
		return array( 'candidates' => array(), 'error' => __( 'Unknown feed type.', 'teatatu-events' ), 'complete' => false );
	}
	return call_user_func( $fn, $config, $limit );
}

/**
 * Cron runner shared by the four importer ticks: processes the active feeds
 * of one type whose own cadence has elapsed.
 *
 * @param string $type Feed type.
 */
function teatatu_events_run_due_feeds( $type ) {
	$types = teatatu_events_feed_types();
	if ( ! isset( $types[ $type ] ) ) {
		return;
	}
	$ids = get_posts(
		array(
			'post_type'      => $types[ $type ][0],
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		)
	);
	$now = time();
	foreach ( $ids as $id ) {
		$config = teatatu_events_get_feed_config( $id );
		if ( ! $config['active'] ) {
			continue;
		}
		$last = (int) get_post_meta( $id, '_teatatu_events_feed_last_checked', true );
		if ( $now - $last >= max( 5, (int) $config['cadence'] ) * MINUTE_IN_SECONDS ) {
			teatatu_events_process_feed( $id );
		}
	}
}

/**
 * Page-fetch helper with a per-run cache.
 *
 * @param string $url URL.
 * @return string
 */
function teatatu_events_cached_page( $url ) {
	static $cache = array();
	if ( '' === $url ) {
		return '';
	}
	if ( ! array_key_exists( $url, $cache ) ) {
		$cache[ $url ] = teatatu_events_fetch_url( $url );
	}
	return $cache[ $url ];
}

/**
 * The linked page's Schema.org data as a partial candidate, from the next
 * upcoming of its events (cached per URL for the run).
 *
 * @param string $url Linked page URL.
 * @return array|null
 */
function teatatu_events_linked_jsonld( $url ) {
	static $cache = array();
	if ( ! array_key_exists( $url, $cache ) ) {
		$ev            = teatatu_events_jsonld_pick_upcoming( teatatu_events_extract_jsonld_events( teatatu_events_cached_page( $url ) ) );
		$cache[ $url ] = $ev ? teatatu_events_jsonld_to_candidate( $ev, $url ) : null;
	}
	return $cache[ $url ];
}

/**
 * An RSS item's publish date as {start,end,all_day}. A date with no time
 * zone (Eventfinda's "2026-10-31T19:00:00") is site time, not UTC.
 *
 * @param SimplePie_Item $item Item.
 * @return array|string '' when the item has no date.
 */
function teatatu_events_rss_pub_date( $item ) {
	$raw = trim( (string) $item->get_date( '' ) );
	if ( '' !== $raw && ! preg_match( '/(Z|[+-]\d{2}:?\d{2}|\b(?:GMT|UTC|UT|[A-Z]{3,4}))\s*$/i', $raw ) ) {
		$parsed = teatatu_events_parse_datetime( $raw );
		if ( $parsed ) {
			return $parsed;
		}
	}
	$ts = $item->get_date( 'U' );
	return $ts ? array( 'start' => (int) $ts, 'end' => 0, 'all_day' => false ) : '';
}

/**
 * Resolves one mapped field for RSS/HTML candidates.
 *
 * A field's optional Pattern is applied to the extracted value before it is
 * used: to the element's HTML for text fields, to the date text for
 * Start/End, and to the URL for link and image fields.
 *
 * @param string $field   Field key.
 * @param array  $mapping Mapping {source, tag, attr_type, attr_value, format, pattern}.
 * @param array  $ctx     {rss_item?: SimplePie_Item, item_html?: string, link: string, base_url: string}.
 * @return mixed String for most fields; array {start,end,all_day} for start/end; string[] for tags.
 */
function teatatu_events_resolve_mapped( $field, $mapping, $ctx ) {
	$source  = $mapping['source'];
	$pattern = (string) ( $mapping['pattern'] ?? '' );
	if ( 'none' === $source ) {
		return '';
	}
	if ( 'page_jsonld' === $source ) {
		$ld = teatatu_events_linked_jsonld( $ctx['link'] );
		if ( ! $ld ) {
			return '';
		}
		if ( 'start' === $field ) {
			return isset( $ld['start'] ) ? array( 'start' => $ld['start'], 'end' => $ld['end'] ?? 0, 'all_day' => $ld['all_day'] ) : '';
		}
		if ( 'end' === $field ) {
			return ! empty( $ld['end'] ) ? array( 'start' => $ld['end'], 'end' => 0, 'all_day' => false ) : '';
		}
		$value = $ld[ $field ] ?? '';
		return is_string( $value ) ? teatatu_events_apply_pattern( $value, $pattern ) : $value;
	}

	$item = $ctx['rss_item'] ?? null;
	// Whole-value RSS sources.
	if ( $item ) {
		if ( 'rss_link' === $source ) {
			return teatatu_events_apply_pattern( $ctx['link'], $pattern );
		}
		if ( 'rss_image' === $source ) {
			$enclosure = $item->get_enclosure();
			$url       = $enclosure ? ( $enclosure->get_link() ? $enclosure->get_link() : $enclosure->get_thumbnail() ) : '';
			return $url ? teatatu_events_apply_pattern( (string) $url, $pattern ) : '';
		}
		if ( 'rss_categories' === $source ) {
			$cats = $item->get_categories();
			return $cats ? array_map( function ( $c ) { return $c->get_label(); }, $cats ) : array();
		}
		if ( 'rss_pub_date' === $source ) {
			if ( '' === $pattern ) {
				return teatatu_events_rss_pub_date( $item );
			}
			$parsed = teatatu_events_parse_datetime( teatatu_events_apply_pattern( (string) $item->get_date( '' ), $pattern ), $mapping['format'] );
			return $parsed ? $parsed : '';
		}
	}

	if ( 'page' === $source ) {
		$html = teatatu_events_cached_page( $ctx['link'] );
	} elseif ( 'item' === $source ) {
		$html = $ctx['item_html'] ?? '';
	} elseif ( $item ) {
		$html = 'rss_title' === $source ? (string) $item->get_title() : ( 'rss_content' === $source ? (string) ( $item->get_content() ? $item->get_content() : $item->get_description() ) : (string) $item->get_description() );
	} else {
		$html = '';
	}
	if ( '' === trim( (string) $html ) ) {
		return '';
	}
	$has_selector = $mapping['tag'] || $mapping['attr_value'];
	// Text fields on a scraped page/item need a selector (never "the whole page").
	if ( ! $has_selector && ( 'page' === $source || ( 'item' === $source && ! in_array( $field, array( 'image', 'read_more_url', 'ticket_url' ), true ) ) ) ) {
		return '';
	}
	$node = teatatu_events_dom_select( $html, $mapping['tag'], $mapping['attr_type'], $mapping['attr_value'] );
	if ( ! $node ) {
		return '';
	}
	switch ( $field ) {
		case 'image':
			return teatatu_events_resolve_relative_url( teatatu_events_apply_pattern( teatatu_events_dom_image_url( $node ), $pattern ), $ctx['link'] ? $ctx['link'] : $ctx['base_url'] );
		case 'read_more_url':
		case 'ticket_url':
			return teatatu_events_resolve_relative_url( teatatu_events_apply_pattern( teatatu_events_dom_link_url( $node ), $pattern ), $ctx['base_url'] );
		case 'start':
		case 'end':
			$parsed = teatatu_events_parse_datetime( teatatu_events_apply_pattern( teatatu_events_dom_datetime_text( $node ), $pattern ), $mapping['format'] );
			return $parsed ? $parsed : '';
	}
	$inner = teatatu_events_apply_pattern( teatatu_events_dom_inner_html( $node ), $pattern );
	if ( '' === $inner ) {
		return '';
	}
	switch ( $field ) {
		case 'excerpt':
			return teatatu_events_clean_excerpt( $inner );
		case 'description':
			return teatatu_events_clean_description( $inner );
		case 'tags':
			return array_filter( array_map( 'trim', explode( ',', teatatu_events_clean_text( $inner ) ) ) );
		case 'status':
			return teatatu_events_parse_status_text( teatatu_events_clean_text( $inner ) );
		default:
			return teatatu_events_clean_text( $inner );
	}
}

/**
 * Every dated session on a linked page, sorted by start, one per start.
 *
 * @param string $url      Linked page URL.
 * @param array  $sessions Session settings.
 * @return array[] {start, end, all_day}
 */
function teatatu_events_page_sessions( $url, $sessions ) {
	$html = teatatu_events_cached_page( $url );
	if ( '' === trim( $html ) ) {
		return array();
	}
	$out = array();
	if ( 'selector' === $sessions['source'] ) {
		foreach ( teatatu_events_dom_select_all( $html, $sessions['tag'], $sessions['attr_type'], $sessions['attr_value'], 400 ) as $node ) {
			$parsed = teatatu_events_parse_datetime( teatatu_events_dom_datetime_text( $node ) );
			if ( $parsed ) {
				$out[ $parsed['start'] ] = $parsed;
			}
		}
	} else {
		foreach ( teatatu_events_extract_jsonld_events( $html ) as $ev ) {
			$start = teatatu_events_parse_datetime( is_string( $ev['startDate'] ?? null ) ? $ev['startDate'] : '' );
			if ( ! $start ) {
				continue;
			}
			$end = teatatu_events_parse_datetime( is_string( $ev['endDate'] ?? null ) ? $ev['endDate'] : '' );
			if ( $end ) {
				$start['end'] = $end['all_day'] ? $end['start'] + DAY_IN_SECONDS - 1 : $end['start'];
			}
			$out[ $start['start'] ] = $start;
		}
	}
	ksort( $out );
	return array_values( $out );
}

/**
 * Applies the feed's Sessions setting to one mapped candidate: keeps it as
 * is ('off'), moves it to the next upcoming session ('next'), or turns it
 * into one candidate per upcoming session ('each', up to the max). Pages
 * with no readable sessions keep the mapped Start/End.
 *
 * Each session's identity is the page link plus its start, so a session
 * stays the same event from run to run, and every site importing the same
 * page gets the same external key per session.
 *
 * @param array  $c      Candidate.
 * @param array  $config Feed config.
 * @param string $link   Linked page URL.
 * @return array[] Candidates.
 */
function teatatu_events_apply_sessions( $c, $config, $link ) {
	$settings = array_merge( teatatu_events_sessions_defaults(), (array) ( $config['sessions'] ?? array() ) );
	if ( 'off' === $settings['mode'] || ! $link ) {
		return array( $c );
	}
	$sessions = teatatu_events_page_sessions( $link, $settings );
	if ( ! $sessions ) {
		return array( $c );
	}
	$default  = (int) teatatu_events_setting( 'default_duration' ) * MINUTE_IN_SECONDS;
	$now      = time();
	$upcoming = array_values(
		array_filter(
			$sessions,
			function ( $x ) use ( $now, $default ) {
				return ( $x['end'] ? $x['end'] : $x['start'] + $default ) >= $now;
			}
		)
	);
	$set = function ( $cand, $x ) {
		$cand['start']   = (int) $x['start'];
		$cand['end']     = (int) $x['end'];
		$cand['all_day'] = (bool) $x['all_day'];
		return $cand;
	};
	if ( ! $upcoming ) {
		// Everything has passed: use the last session (it will be skipped as ended).
		return array( $set( $c, $sessions[ count( $sessions ) - 1 ] ) );
	}
	if ( 'next' === $settings['mode'] ) {
		return array( $set( $c, $upcoming[0] ) );
	}
	$out = array();
	foreach ( array_slice( $upcoming, 0, max( 1, min( 12, (int) $settings['max'] ) ) ) as $x ) {
		$stamp              = gmdate( 'Ymd\THi', (int) $x['start'] );
		$one                = $set( $c, $x );
		$one['identity']     = $c['identity'] . '#' . $stamp;
		$one['ext_kind']     = 'session';
		$one['ext_identity'] = teatatu_events_normalize_url( $link ) . '|' . $stamp;
		$one['group']        = $c['identity'];
		$out[]               = $one;
	}
	return $out;
}

/**
 * Builds a candidate from a mapped RSS/HTML item.
 *
 * @param array  $map      Field map.
 * @param array  $ctx      Resolution context.
 * @param string $identity Identity.
 * @return array
 */
function teatatu_events_candidate_from_map( $map, $ctx, $identity ) {
	$c = teatatu_events_candidate_defaults();
	$c['identity']      = $identity;
	$c['ext_kind']      = 'url';
	$c['ext_identity']  = $ctx['link'];
	$c['read_more_url'] = $ctx['link'];
	foreach ( array_keys( teatatu_events_mappable_fields() ) as $field ) {
		$value = teatatu_events_resolve_mapped( $field, $map[ $field ], $ctx );
		if ( '' === $value || array() === $value ) {
			continue;
		}
		switch ( $field ) {
			case 'start':
				$c['start']   = (int) $value['start'];
				$c['all_day'] = (bool) $value['all_day'];
				if ( ! empty( $value['end'] ) && ! $c['end'] ) {
					$c['end'] = (int) $value['end'];
				}
				break;
			case 'end':
				$c['end'] = (int) ( ! empty( $value['all_day'] ) ? $value['start'] + DAY_IN_SECONDS - 1 : $value['start'] );
				break;
			case 'image':
				$c['image'] = $value;
				break;
			case 'title':
				$c['title'] = teatatu_events_clean_text( $value );
				break;
			default:
				$c[ $field ] = $value;
		}
	}
	if ( 'free' === strtolower( trim( (string) $c['price'] ) ) ) {
		$c['is_free'] = true;
	}
	if ( '' === $c['excerpt'] && '' !== $c['description'] ) {
		$c['excerpt'] = wp_trim_words( teatatu_events_clean_text( $c['description'] ), 40 );
	}
	return $c;
}

// ---------------------------------------------------------------------------
// Pending updates: Apply / Dismiss; Removed-at-source: Confirm / Restore;
// Needs venue: Assign / Keep address; Check Now.
// ---------------------------------------------------------------------------

add_action( 'admin_post_teatatu_events_apply_update', 'teatatu_events_handle_apply_update' );

/**
 * Applies a pending feed update.
 */
function teatatu_events_handle_apply_update() {
	$id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;
	check_admin_referer( 'teatatu_events_apply_update_' . $id );
	if ( ! $id || 'teatatu_event' !== get_post_type( $id ) || ! current_user_can( 'edit_post', $id ) ) {
		wp_die( esc_html__( 'You do not have permission to do that.', 'teatatu-events' ), 403 );
	}
	teatatu_events_apply_pending_update( $id );
	delete_transient( 'teatatu_events_attention_count' );
	teatatu_events_admin_notice( __( 'Feed update applied.', 'teatatu-events' ) );
	wp_safe_redirect( wp_get_referer() ? wp_get_referer() : add_query_arg( array( 'page' => 'teatatu-events', 'tab' => 'pending' ), admin_url( 'admin.php' ) ) );
	exit;
}

add_action( 'admin_post_teatatu_events_dismiss_update', 'teatatu_events_handle_dismiss_update' );

/**
 * Dismisses a pending feed update.
 */
function teatatu_events_handle_dismiss_update() {
	$id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;
	check_admin_referer( 'teatatu_events_dismiss_update_' . $id );
	if ( ! $id || 'teatatu_event' !== get_post_type( $id ) || ! current_user_can( 'edit_post', $id ) ) {
		wp_die( esc_html__( 'You do not have permission to do that.', 'teatatu-events' ), 403 );
	}
	delete_post_meta( $id, teatatu_events_mk( 'pending_update' ) );
	delete_transient( 'teatatu_events_attention_count' );
	teatatu_events_bump_cache_version();
	teatatu_events_admin_notice( __( 'Feed update dismissed.', 'teatatu-events' ) );
	wp_safe_redirect( wp_get_referer() ? wp_get_referer() : add_query_arg( array( 'page' => 'teatatu-events', 'tab' => 'pending' ), admin_url( 'admin.php' ) ) );
	exit;
}

add_action( 'admin_post_teatatu_events_attention', 'teatatu_events_handle_attention' );

/**
 * Handles the Needs attention actions: confirm/restore a removed-at-source
 * event, keep an address without a venue, dismiss a neighbourhood flag.
 */
function teatatu_events_handle_attention() {
	$id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;
	$do = isset( $_GET['do'] ) ? sanitize_key( $_GET['do'] ) : '';
	check_admin_referer( 'teatatu_events_attention_' . $do . '_' . $id );
	if ( ! $id || 'teatatu_event' !== get_post_type( $id ) || ! current_user_can( 'edit_post', $id ) ) {
		wp_die( esc_html__( 'You do not have permission to do that.', 'teatatu-events' ), 403 );
	}
	switch ( $do ) {
		case 'confirm_removed':
			update_post_meta( $id, teatatu_events_mk( 'removal_confirmed' ), 1 );
			delete_post_meta( $id, teatatu_events_mk( 'removed_at_source' ) );
			$msg = __( 'Removal confirmed: the event stays cancelled.', 'teatatu-events' );
			break;
		case 'restore_removed':
			$previous = get_post_meta( $id, teatatu_events_mk( 'status_before_removal' ), true );
			delete_post_meta( $id, teatatu_events_mk( 'removed_at_source' ) );
			delete_post_meta( $id, teatatu_events_mk( 'status_before_removal' ) );
			update_post_meta( $id, teatatu_events_mk( 'missing_count' ), 0 );
			teatatu_events_save_fields( $id, array( 'status' => $previous ? $previous : 'scheduled' ) );
			$msg = __( 'Event restored to its previous status.', 'teatatu-events' );
			break;
		case 'keep_address':
			delete_post_meta( $id, teatatu_events_mk( 'needs_venue' ) );
			$msg = __( 'Keeping the address without a venue.', 'teatatu-events' );
			break;
		case 'dismiss_nbhd':
			delete_post_meta( $id, teatatu_events_mk( 'needs_nbhd' ) );
			$msg = __( 'Neighbourhood flag dismissed.', 'teatatu-events' );
			break;
		default:
			$msg = '';
	}
	teatatu_events_refresh_derived( $id );
	if ( 'dismiss_nbhd' === $do ) {
		delete_post_meta( $id, teatatu_events_mk( 'needs_nbhd' ) );
	}
	teatatu_events_admin_notice( $msg );
	wp_safe_redirect( add_query_arg( array( 'page' => 'teatatu-events', 'tab' => 'events', 'view' => 'attention' ), admin_url( 'admin.php' ) ) );
	exit;
}

add_action( 'admin_post_teatatu_events_check_feed_now', 'teatatu_events_handle_check_feed_now' );

/**
 * Runs one feed's check immediately.
 */
function teatatu_events_handle_check_feed_now() {
	$id = isset( $_GET['feed_id'] ) ? absint( $_GET['feed_id'] ) : 0;
	check_admin_referer( 'teatatu_events_check_feed_now_' . $id );
	$type = teatatu_events_feed_type_of( $id );
	if ( ! $id || ! $type || ! current_user_can( 'manage_teatatu_events_feeds' ) ) {
		wp_die( esc_html__( 'You do not have permission to do that.', 'teatatu-events' ), 403 );
	}
	$stats = teatatu_events_process_feed( $id );
	if ( isset( $stats['error'] ) ) {
		teatatu_events_admin_error( $stats['error'] );
	} else {
		teatatu_events_admin_notice( teatatu_events_feed_stats_line( $stats ) );
	}
	wp_safe_redirect( add_query_arg( array( 'page' => 'teatatu-events', 'tab' => 'feed_' . $type ), admin_url( 'admin.php' ) ) );
	exit;
}

/**
 * One-line summary of a feed run.
 *
 * @param array $stats Stats.
 * @return string
 */
function teatatu_events_feed_stats_line( $stats ) {
	$bits = array(
		/* translators: %d: count. */
		sprintf( __( '%d imported', 'teatatu-events' ), $stats['imported'] ?? 0 ),
		/* translators: %d: count. */
		sprintf( __( '%d updated', 'teatatu-events' ), $stats['updated'] ?? 0 ),
	);
	if ( ! empty( $stats['pending'] ) ) {
		/* translators: %d: count. */
		$bits[] = sprintf( __( '%d waiting for review', 'teatatu-events' ), $stats['pending'] );
	}
	if ( ! empty( $stats['skipped_filter'] ) ) {
		/* translators: %d: count. */
		$bits[] = sprintf( __( '%d skipped — not a recognised venue/neighbourhood', 'teatatu-events' ), $stats['skipped_filter'] );
	}
	if ( ! empty( $stats['skipped_ended'] ) ) {
		/* translators: %d: count. */
		$bits[] = sprintf( __( '%d skipped — already ended', 'teatatu-events' ), $stats['skipped_ended'] );
	}
	if ( ! empty( $stats['skipped_nodate'] ) ) {
		/* translators: %d: count. */
		$bits[] = sprintf( __( '%d skipped — no readable date', 'teatatu-events' ), $stats['skipped_nodate'] );
	}
	if ( ! empty( $stats['removed'] ) ) {
		/* translators: %d: count. */
		$bits[] = sprintf( __( '%d removed at source (cancelled)', 'teatatu-events' ), $stats['removed'] );
	}
	if ( ! empty( $stats['restored'] ) ) {
		/* translators: %d: count. */
		$bits[] = sprintf( __( '%d back at source (restored)', 'teatatu-events' ), $stats['restored'] );
	}
	return implode( ', ', $bits ) . '.';
}

add_action( 'wp_ajax_teatatu_events_preview_feed', 'teatatu_events_ajax_preview_feed' );

/**
 * "Preview": builds candidates from the (possibly unsaved) form config using
 * exactly the same code as a real import, and reports what each item would
 * become — or why it would be skipped. Nothing is saved.
 */
function teatatu_events_ajax_preview_feed() {
	check_ajax_referer( 'teatatu_events_preview_feed', 'nonce' );
	if ( ! current_user_can( 'manage_teatatu_events_feeds' ) ) {
		wp_send_json_error( array( 'message' => __( 'You do not have permission to do that.', 'teatatu-events' ) ) );
	}
	$type = isset( $_POST['feed_type'] ) ? sanitize_key( $_POST['feed_type'] ) : '';
	if ( ! isset( teatatu_events_feed_types()[ $type ] ) ) {
		wp_send_json_error( array( 'message' => __( 'Unknown feed type.', 'teatatu-events' ) ) );
	}
	$config         = teatatu_events_sanitize_feed_config_from_request( $type );
	$config['type'] = $type;
	$config['id']   = 0;
	if ( ! $config['url'] ) {
		wp_send_json_error( array( 'message' => __( 'Enter a URL first.', 'teatatu-events' ) ) );
	}
	$built = teatatu_events_build_candidates( $config, 8 );
	if ( $built['error'] ) {
		wp_send_json_error( array( 'message' => $built['error'] ) );
	}
	$rows = array();
	$seen = array();
	foreach ( $built['candidates'] as $c ) {
		$c      = array_merge( teatatu_events_candidate_defaults(), $c );
		$place  = teatatu_events_candidate_place( $c, $config );
		$reason = '';
		if ( '' !== $c['identity'] && isset( $seen[ $c['identity'] ] ) ) {
			$reason = __( 'repeats an earlier item', 'teatatu-events' );
		} elseif ( '' === trim( $c['title'] ) ) {
			$reason = __( 'no title', 'teatatu-events' );
		} elseif ( ! $c['start'] ) {
			$reason = __( 'start date not readable', 'teatatu-events' );
		} elseif ( $config['skip_ended'] && ( $c['end'] ? $c['end'] : $c['start'] ) < time() ) {
			$reason = __( 'already ended', 'teatatu-events' );
		} elseif ( ! $place['pass'] ) {
			$reason = $place['reason'];
		}
		$seen[ $c['identity'] ] = true;
		$tags   = teatatu_events_match_tags( $c['tags'] );
		$rows[] = array(
			'title'   => $c['title'],
			'when'    => $c['start'] ? teatatu_events_format_when( $c['start'], $c['end'] ? $c['end'] : $c['start'], $c['all_day'] ) : '—',
			'place'   => $place['venue_id'] ? sprintf( /* translators: 1: venue, 2: how */ __( 'Venue: %1$s (matched by %2$s)', 'teatatu-events' ), get_term( $place['venue_id'] )->name, $place['via'] ? $place['via'] : __( 'feed default', 'teatatu-events' ) ) : ( trim( $c['location'] . ' ' . $c['address'] ) ? __( 'Address only (needs venue): ', 'teatatu-events' ) . trim( $c['location'] . ', ' . $c['address'], ', ' ) : '—' ),
			'nbhd'    => $place['nbhd_slug'],
			'tags'    => implode( ', ', array_map( function ( $id ) { return get_term( $id )->name; }, $tags ) ),
			'status'  => $c['status'],
			'link'    => $c['read_more_url'],
			'image'   => $c['image'],
			'excerpt' => wp_trim_words( teatatu_events_clean_text( $c['excerpt'] ), 30 ),
			'skip'    => $reason,
		);
	}
	wp_send_json_success( array( 'rows' => $rows, 'complete' => $built['complete'] ) );
}
