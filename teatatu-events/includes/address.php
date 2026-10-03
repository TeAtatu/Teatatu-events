<?php
/**
 * Venue addresses (WordPress side): the single address formatter, the
 * schema.org PostalAddress mapping, saving a venue's structured address
 * (with its derived match key and neighbourhood), and matching an imported
 * or agent-supplied place to an existing Venue.
 *
 * The parsing/normalising logic itself lives in includes/address-core.php
 * (pure PHP, shared with the build tool).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/address-core.php';

/**
 * A venue's structured address as an array of segments.
 *
 * @param int $term_id Venue term ID.
 * @return array
 */
function teatatu_events_get_venue_address( $term_id ) {
	$out = array();
	foreach ( teatatu_events_venue_address_keys() as $field => $key ) {
		$out[ $field ] = (string) get_term_meta( $term_id, $key, true );
	}
	return $out;
}

/**
 * Formats address segments. The only place address fields are joined.
 *
 * @param array  $parts Segments (see teatatu_events_venue_address_keys()).
 * @param string $style 'single_line', 'multi_line' or 'ics'.
 * @return string
 */
function teatatu_events_format_address_parts( $parts, $style = 'single_line' ) {
	$street_line = trim(
		implode(
			' ',
			array_filter(
				array(
					! empty( $parts['unit'] ) ? ( ctype_digit( (string) $parts['unit'] ) ? $parts['unit'] . '/' : $parts['unit'] . ',' ) : '',
					$parts['number'] ?? '',
					$parts['street'] ?? '',
				)
			)
		)
	);
	$street_line = str_replace( '/ ', '/', $street_line );
	$city_line   = trim( ( $parts['city'] ?? '' ) . ' ' . ( $parts['postcode'] ?? '' ) );
	$lines       = array_filter( array( $street_line, $parts['suburb'] ?? '', $city_line ) );
	if ( 'multi_line' === $style ) {
		return implode( "\n", $lines );
	}
	return implode( ', ', $lines );
}

/**
 * Formats a venue's address.
 *
 * @param int    $term_id Venue term ID.
 * @param string $style   'single_line', 'multi_line' or 'ics'.
 * @return string
 */
function teatatu_events_format_address( $term_id, $style = 'single_line' ) {
	return teatatu_events_format_address_parts( teatatu_events_get_venue_address( $term_id ), $style );
}

/**
 * schema.org PostalAddress properties for a venue.
 *
 * @param int $term_id Venue term ID.
 * @return array
 */
function teatatu_events_venue_postal_address( $term_id ) {
	$a      = teatatu_events_get_venue_address( $term_id );
	$street = trim( teatatu_events_format_address_parts( array( 'unit' => $a['unit'], 'number' => $a['number'], 'street' => $a['street'] ) ) . ( $a['suburb'] ? ', ' . $a['suburb'] : '' ), ', ' );
	return array_filter(
		array(
			'streetAddress'   => $street,
			'addressLocality' => $a['city'],
			'addressRegion'   => $a['region'],
			'postalCode'      => $a['postcode'],
			'addressCountry'  => $a['country'],
		)
	);
}

/**
 * Saves a venue's structured address and recomputes its derived values
 * (match key, neighbourhood). Re-resolves the venue's events too.
 *
 * @param int   $term_id Venue term ID.
 * @param array $parts   Address segments.
 * @param array $extra   Other venue meta: map_url, website_url, aliases, default_image, nbhd_override.
 */
function teatatu_events_save_venue( $term_id, $parts, $extra = array() ) {
	if ( empty( $parts['country'] ) && ! get_term_meta( $term_id, 'teatatu_events_addr_country', true ) ) {
		$parts['country'] = (string) teatatu_events_setting( 'default_country' );
	}
	foreach ( teatatu_events_venue_address_keys() as $field => $key ) {
		if ( array_key_exists( $field, $parts ) ) {
			$value = sanitize_text_field( (string) $parts[ $field ] );
			if ( 'country' === $field ) {
				$value = strtoupper( substr( $value ? $value : (string) teatatu_events_setting( 'default_country' ), 0, 2 ) );
			}
			if ( in_array( $field, array( 'lat', 'lng' ), true ) && '' !== $value && ! is_numeric( $value ) ) {
				$value = '';
			}
			update_term_meta( $term_id, $key, $value );
		}
	}
	$map = array(
		'map_url'       => array( 'teatatu_events_venue_map_url', 'esc_url_raw' ),
		'website_url'   => array( 'teatatu_events_venue_website_url', 'esc_url_raw' ),
		'aliases'       => array( 'teatatu_events_venue_aliases', 'sanitize_text_field' ),
		'default_image' => array( 'teatatu_events_venue_default_image', 'esc_url_raw' ),
	);
	foreach ( $map as $field => $def ) {
		if ( array_key_exists( $field, $extra ) ) {
			update_term_meta( $term_id, $def[0], call_user_func( $def[1], (string) $extra[ $field ] ) );
		}
	}
	if ( array_key_exists( 'nbhd_override', $extra ) ) {
		$slug = sanitize_key( (string) $extra['nbhd_override'] );
		if ( $slug && in_array( $slug, teatatu_events_neighbourhood_slugs(), true ) ) {
			update_term_meta( $term_id, 'teatatu_events_addr_neighbourhood_override', $slug );
		} else {
			delete_term_meta( $term_id, 'teatatu_events_addr_neighbourhood_override' );
		}
	}
	teatatu_events_refresh_venue( $term_id );
}

/**
 * Recomputes a venue's match key and neighbourhood, then re-resolves every
 * event at the venue.
 *
 * @param int $term_id Venue term ID.
 */
function teatatu_events_refresh_venue( $term_id ) {
	$parts = teatatu_events_get_venue_address( $term_id );
	update_term_meta( $term_id, 'teatatu_events_addr_normalized', teatatu_events_address_match_key( $parts ) );

	$override = (string) get_term_meta( $term_id, 'teatatu_events_addr_neighbourhood_override', true );
	if ( $override ) {
		$result = array( 'slug' => $override, 'via' => 'override', 'needs' => false );
	} else {
		$result = teatatu_events_resolve_neighbourhood( $parts );
	}
	update_term_meta( $term_id, 'teatatu_events_addr_neighbourhood', $result['slug'] );
	update_term_meta( $term_id, 'teatatu_events_addr_neighbourhood_via', $result['via'] );
	if ( $result['needs'] ) {
		update_term_meta( $term_id, 'teatatu_events_needs_neighbourhood', 1 );
	} else {
		delete_term_meta( $term_id, 'teatatu_events_needs_neighbourhood' );
	}

	$event_ids = get_posts(
		array(
			'post_type'      => 'teatatu_event',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'tax_query'      => array( array( 'taxonomy' => 'teatatu_events_venue', 'terms' => array( (int) $term_id ) ) ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		)
	);
	foreach ( $event_ids as $event_id ) {
		teatatu_events_refresh_derived( $event_id );
	}
	teatatu_events_bump_cache_version();
}

/**
 * Matches a place to an existing Venue: by Location name or alias, then by
 * normalised street address (+ suburb/city/postcode), then by coordinates.
 *
 * @param string     $location Location name (may be '').
 * @param array|string $address Address segments, or free text.
 * @param float|null $lat      Latitude.
 * @param float|null $lng      Longitude.
 * @return array|null {term_id, name, via: 'name'|'alias'|'address'|'coords'} or null.
 */
function teatatu_events_match_venue( $location, $address = array(), $lat = null, $lng = null ) {
	$venues = get_terms(
		array(
			'taxonomy'   => 'teatatu_events_venue',
			'hide_empty' => false,
		)
	);
	if ( empty( $venues ) || is_wp_error( $venues ) ) {
		return null;
	}
	$parts = is_array( $address ) ? $address : teatatu_events_parse_address_text( (string) $address, teatatu_events_setting( 'default_country' ) );
	if ( '' === trim( (string) $location ) && ! empty( $parts['name'] ) ) {
		$location = $parts['name'];
	}

	// 1. Location name or alias.
	$wanted = teatatu_events_fold_text( $location );
	if ( '' !== $wanted ) {
		foreach ( $venues as $venue ) {
			if ( teatatu_events_fold_text( $venue->name ) === $wanted ) {
				return array( 'term_id' => (int) $venue->term_id, 'name' => $venue->name, 'via' => 'name' );
			}
		}
		foreach ( $venues as $venue ) {
			$aliases = array_filter( array_map( 'teatatu_events_fold_text', explode( ',', (string) get_term_meta( $venue->term_id, 'teatatu_events_venue_aliases', true ) ) ) );
			if ( in_array( $wanted, $aliases, true ) ) {
				return array( 'term_id' => (int) $venue->term_id, 'name' => $venue->name, 'via' => 'alias' );
			}
		}
	}

	// 2. Street address: number + street, and suburb or city or postcode.
	$key = teatatu_events_address_match_key( $parts );
	if ( $key ) {
		list( $num, $street, $suburb, $city, $postcode ) = explode( '|', $key );
		foreach ( $venues as $venue ) {
			$vkey = (string) get_term_meta( $venue->term_id, 'teatatu_events_addr_normalized', true );
			if ( ! $vkey ) {
				continue;
			}
			list( $vnum, $vstreet, $vsuburb, $vcity, $vpostcode ) = array_pad( explode( '|', $vkey ), 5, '' );
			if ( $vstreet !== $street ) {
				continue;
			}
			$locality = ( $suburb && $suburb === $vsuburb ) || ( $city && $city === $vcity ) || ( $postcode && $postcode === $vpostcode ) || ( ! $suburb && ! $city && ! $postcode );
			if ( ! $locality ) {
				continue;
			}
			if ( $num && $vnum && $num === $vnum ) {
				return array( 'term_id' => (int) $venue->term_id, 'name' => $venue->name, 'via' => 'address' );
			}
			if ( ! $vnum && $wanted && false !== strpos( teatatu_events_fold_text( $venue->name ), $wanted ) ) {
				return array( 'term_id' => (int) $venue->term_id, 'name' => $venue->name, 'via' => 'address' );
			}
		}
	}

	// 3. Coordinates.
	$distance = (float) teatatu_events_setting( 'venue_match_distance' );
	if ( null !== $lat && null !== $lng && '' !== $lat && '' !== $lng && $distance > 0 ) {
		$best = null;
		foreach ( $venues as $venue ) {
			$vlat = get_term_meta( $venue->term_id, 'teatatu_events_addr_lat', true );
			$vlng = get_term_meta( $venue->term_id, 'teatatu_events_addr_lng', true );
			if ( '' === $vlat || '' === $vlng ) {
				continue;
			}
			$d = teatatu_events_distance_m( (float) $lat, (float) $lng, (float) $vlat, (float) $vlng );
			if ( $d <= $distance && ( ! $best || $d < $best[1] ) ) {
				$best = array( $venue, $d );
			}
		}
		if ( $best ) {
			return array( 'term_id' => (int) $best[0]->term_id, 'name' => $best[0]->name, 'via' => 'coords' );
		}
	}
	return null;
}

/**
 * Venue IDs whose events should be flagged/filtered: all venues with their
 * names and formatted addresses (for admin pickers).
 *
 * @return array[] {id, name, address}
 */
function teatatu_events_venue_choices() {
	$venues = get_terms(
		array(
			'taxonomy'   => 'teatatu_events_venue',
			'hide_empty' => false,
			'orderby'    => 'name',
		)
	);
	$out = array();
	if ( empty( $venues ) || is_wp_error( $venues ) ) {
		return $out;
	}
	foreach ( $venues as $venue ) {
		$out[] = array(
			'id'      => (int) $venue->term_id,
			'name'    => $venue->name,
			'address' => teatatu_events_format_address( $venue->term_id ),
		);
	}
	return $out;
}
