<?php
/**
 * Neighbourhoods: every Te Atatū Peninsula address falls into exactly one of
 * three areas — Matipo, Beach or Harbourview — split along Te Atatū Road,
 * Taikata Road and Harbour View Road.
 *
 * Resolution order (first rule that applies wins):
 *  1. Manual override on the venue (or on an address-only event).
 *  2. Street rules (network-wide option, listed and edited on the
 *     Neighbourhoods tab): each street is one neighbourhood, or house-number
 *     ranges by side of the road where it crosses a boundary (Te Atatū,
 *     Taikata, Harbour View, Matipo and Wharf Roads). The rules are loaded
 *     once from assets/data/neighbourhood-streets.json — built from
 *     OpenStreetMap by tools/build-neighbourhood-streets.php — the same way
 *     the three neighbourhood terms are seeded, and from then on live only
 *     in the database, so editors see and change every rule.
 *  3. Coordinates, classified against the boundary lines in
 *     assets/data/neighbourhoods.geojson.
 *  4. Off the peninsula → no neighbourhood (normal, not an error).
 *  5. A peninsula address nothing resolved → no neighbourhood, flagged
 *     "Needs neighbourhood" for an editor.
 *
 * No external geocoding service is ever called.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fixed internal keys of the three neighbourhoods. They never change: the
 * boundary classifier and the bundled street data speak in these keys.
 * Everything stored and shown uses the current *slug* instead, which a
 * network admin can rename (see teatatu_events_rename_neighbourhood_slug()).
 *
 * @return string[]
 */
function teatatu_events_neighbourhood_keys() {
	return array( 'matipo', 'beach', 'harbourview' );
}

/**
 * Current slug for each key (network-wide on multisite).
 *
 * @return array key => slug
 */
function teatatu_events_neighbourhood_slug_map() {
	$stored = teatatu_events_get_option( 'teatatu_events_nbhd_slugs', array() );
	$map    = array();
	foreach ( teatatu_events_neighbourhood_keys() as $key ) {
		$map[ $key ] = ! empty( $stored[ $key ] ) ? (string) $stored[ $key ] : $key;
	}
	return $map;
}

/**
 * Current slug for a key ('outside' and unknown values pass through).
 *
 * @param string $key Key.
 * @return string
 */
function teatatu_events_neighbourhood_slug_for_key( $key ) {
	$map = teatatu_events_neighbourhood_slug_map();
	return isset( $map[ $key ] ) ? $map[ $key ] : (string) $key;
}

/**
 * Key for a slug — the current slug, or any slug it had before (renames
 * keep the old slug as an alias, so old links and shortcodes still work).
 *
 * @param string $slug Slug.
 * @return string '' if not a neighbourhood.
 */
function teatatu_events_neighbourhood_key_for_slug( $slug ) {
	$slug = (string) $slug;
	$key  = array_search( $slug, teatatu_events_neighbourhood_slug_map(), true );
	if ( false !== $key ) {
		return $key;
	}
	$aliases = teatatu_events_get_option( 'teatatu_events_nbhd_slug_aliases', array() );
	return isset( $aliases[ $slug ] ) ? (string) $aliases[ $slug ] : '';
}

/**
 * Brings a possibly-old neighbourhood slug up to date (unknown values are
 * returned unchanged).
 *
 * @param string $slug Slug.
 * @return string
 */
function teatatu_events_current_neighbourhood_slug( $slug ) {
	$key = teatatu_events_neighbourhood_key_for_slug( $slug );
	return '' !== $key ? teatatu_events_neighbourhood_slug_for_key( $key ) : (string) $slug;
}

/**
 * The three neighbourhoods: current slug => [default name, description, key].
 *
 * @return array
 */
function teatatu_events_neighbourhood_defs() {
	$base = array(
		'matipo'      => array( __( 'Matipo', 'teatatu-events' ), __( 'North of Taikata Road and west of Te Atatū Road. Named after Matipo Road.', 'teatatu-events' ) ),
		'beach'       => array( __( 'Beach', 'teatatu-events' ), __( 'North of Harbour View Road and east of Te Atatū Road. Named after Beach Road.', 'teatatu-events' ) ),
		'harbourview' => array( __( 'Harbourview', 'teatatu-events' ), __( 'South of Taikata Road and Harbour View Road, down to the North-Western Motorway (SH16). Named after Harbourview–Orangihina Park near SH16 — not Harbour View Road (even-numbered houses on Harbour View Road are in Beach).', 'teatatu-events' ) ),
	);
	$defs = array();
	foreach ( teatatu_events_neighbourhood_slug_map() as $key => $slug ) {
		$defs[ $slug ] = array( $base[ $key ][0], $base[ $key ][1], $key );
	}
	return $defs;
}

/**
 * Current neighbourhood slugs.
 *
 * @return string[]
 */
function teatatu_events_neighbourhood_slugs() {
	return array_keys( teatatu_events_neighbourhood_defs() );
}

/**
 * Creates the three neighbourhood terms on the current site if missing, and
 * records their IDs (by key). Existing terms keep their (possibly renamed)
 * names; a term whose slug is out of date (e.g. a site skipped by a rename)
 * is brought up to the current slug.
 */
function teatatu_events_seed_neighbourhood_terms() {
	if ( ! taxonomy_exists( 'teatatu_events_neighbourhood' ) ) {
		teatatu_events_register_taxonomies();
	}
	$stored  = get_option( 'teatatu_events_nbhd_term_ids', array() );
	$aliases = array_keys( (array) teatatu_events_get_option( 'teatatu_events_nbhd_slug_aliases', array() ) );
	$ids     = array();
	$GLOBALS['teatatu_events_seeding_neighbourhoods'] = true;
	foreach ( teatatu_events_neighbourhood_defs() as $slug => $def ) {
		$key  = $def[2];
		$term = isset( $stored[ $key ] ) ? get_term( (int) $stored[ $key ], 'teatatu_events_neighbourhood' ) : null;
		if ( ! $term || is_wp_error( $term ) ) {
			$term = null;
			foreach ( array_unique( array_merge( array( $slug, $key ), $aliases ) ) as $candidate ) {
				$found = get_term_by( 'slug', $candidate, 'teatatu_events_neighbourhood' );
				if ( $found && ( $candidate === $slug || teatatu_events_neighbourhood_key_for_slug( $candidate ) === $key || $candidate === $key ) ) {
					$term = $found;
					break;
				}
			}
		}
		if ( ! $term ) {
			$created = wp_insert_term( $def[0], 'teatatu_events_neighbourhood', array( 'slug' => $slug, 'description' => $def[1] ) );
			if ( ! is_wp_error( $created ) ) {
				$ids[ $key ] = (int) $created['term_id'];
			}
			continue;
		}
		if ( $term->slug !== $slug ) {
			$GLOBALS['teatatu_events_renaming_neighbourhood'] = true;
			wp_update_term( $term->term_id, 'teatatu_events_neighbourhood', array( 'slug' => $slug ) );
			$GLOBALS['teatatu_events_renaming_neighbourhood'] = false;
		}
		$ids[ $key ] = (int) $term->term_id;
	}
	$GLOBALS['teatatu_events_seeding_neighbourhoods'] = false;
	update_option( 'teatatu_events_nbhd_term_ids', $ids );
}

/**
 * Local term ID for a neighbourhood slug (current or old; only the three
 * seeded terms).
 *
 * @param string $slug Slug.
 * @return int 0 if unknown.
 */
function teatatu_events_neighbourhood_term_id( $slug ) {
	$key = teatatu_events_neighbourhood_key_for_slug( $slug );
	if ( '' === $key ) {
		return 0;
	}
	$ids = get_option( 'teatatu_events_nbhd_term_ids', array() );
	if ( isset( $ids[ $key ] ) && term_exists( (int) $ids[ $key ], 'teatatu_events_neighbourhood' ) ) {
		return (int) $ids[ $key ];
	}
	$term = get_term_by( 'slug', teatatu_events_neighbourhood_slug_for_key( $key ), 'teatatu_events_neighbourhood' );
	return $term ? (int) $term->term_id : 0;
}

/**
 * Street rules from the bundled data file, as editable rows (sorted by
 * street). Only used to seed or top up the stored rules.
 *
 * @return array[] Rows of {street, parity, min, max, area}.
 */
function teatatu_events_bundled_street_rules() {
	$file = TEATATU_EVENTS_DIR . 'assets/data/neighbourhood-streets.json';
	$json = is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	if ( ! isset( $json['streets'] ) || ! is_array( $json['streets'] ) ) {
		return array();
	}
	$rows = array();
	foreach ( $json['streets'] as $key => $rule ) {
		$street = teatatu_events_street_display_name( $key );
		if ( is_string( $rule ) ) {
			$rows[] = array( 'street' => $street, 'parity' => 'any', 'min' => '', 'max' => '', 'area' => teatatu_events_neighbourhood_slug_for_key( $rule ) );
			continue;
		}
		$lowest = PHP_INT_MAX;
		foreach ( $rule['rules'] as $range ) {
			$min    = isset( $range['min'] ) ? (int) $range['min'] : 0;
			$lowest = min( $lowest, $min );
			$rows[] = array(
				'street' => $street,
				'parity' => $range['parity'] ?? 'any',
				'min'    => $min > 0 ? $min : '',
				'max'    => isset( $range['max'] ) && null !== $range['max'] ? (int) $range['max'] : '',
				'area'   => teatatu_events_neighbourhood_slug_for_key( $range['area'] ),
			);
		}
		// "below": numbers lower than every range (the street continues off the peninsula).
		if ( ! empty( $rule['below'] ) && $lowest > 0 && PHP_INT_MAX !== $lowest ) {
			$rows[] = array( 'street' => $street, 'parity' => 'any', 'min' => '', 'max' => $lowest - 1, 'area' => teatatu_events_neighbourhood_slug_for_key( $rule['below'] ) );
		}
	}
	return teatatu_events_sort_street_rules( $rows );
}

/**
 * Display name for a normalised street key ("te atatu road" → "Te Atatū Road").
 *
 * @param string $key Normalised street.
 * @return string
 */
function teatatu_events_street_display_name( $key ) {
	$name = ucwords( (string) $key );
	return preg_replace( '/\bTe Atatu\b/', 'Te Atatū', $name );
}

/**
 * Sorts rule rows by street, keeping each street's rows in their order.
 *
 * @param array[] $rows Rows.
 * @return array[]
 */
function teatatu_events_sort_street_rules( $rows ) {
	$keyed = array();
	foreach ( array_values( $rows ) as $i => $row ) {
		$keyed[] = array( teatatu_events_normalize_street( $row['street'] ), $i, $row );
	}
	usort(
		$keyed,
		function ( $a, $b ) {
			return strcmp( $a[0], $b[0] ) ?: $a[1] <=> $b[1];
		}
	);
	return array_column( $keyed, 2 );
}

/**
 * Loads the bundled street rules into the stored rules, once per install
 * (network-wide on multisite) — the street equivalent of seeding the
 * neighbourhood terms. Rules an editor added before this (older versions
 * kept them separately) are kept and win for their streets.
 *
 * @param bool $top_up Add bundled rules for streets the stored rules don't
 *                     cover yet, even if seeding already happened.
 * @return int Number of rule rows added.
 */
function teatatu_events_seed_street_rules( $top_up = false ) {
	if ( ! $top_up && teatatu_events_get_option( 'teatatu_events_nbhd_rules_seeded', false ) ) {
		return 0;
	}
	$rows = teatatu_events_get_option( 'teatatu_events_nbhd_street_rules', null );
	if ( ! is_array( $rows ) ) {
		$legacy = teatatu_events_get_option( 'teatatu_events_nbhd_overrides', array() );
		$rows   = is_array( $legacy ) ? $legacy : array();
	}
	$have = array();
	foreach ( $rows as $row ) {
		$have[ teatatu_events_normalize_street( $row['street'] ) ] = true;
	}
	$added = 0;
	foreach ( teatatu_events_bundled_street_rules() as $row ) {
		if ( ! isset( $have[ teatatu_events_normalize_street( $row['street'] ) ] ) ) {
			$rows[] = $row;
			$added++;
		}
	}
	teatatu_events_update_option( 'teatatu_events_nbhd_street_rules', teatatu_events_sort_street_rules( $rows ) );
	teatatu_events_update_option( 'teatatu_events_nbhd_rules_seeded', TEATATU_EVENTS_VERSION );
	teatatu_events_delete_option( 'teatatu_events_nbhd_overrides' );
	return $added;
}

/**
 * The shipped boundary geometry.
 *
 * @return array {peninsula, east_west, te_atatu_road}
 */
function teatatu_events_neighbourhood_geometry() {
	static $geometry = null;
	if ( null === $geometry ) {
		$geometry = array();
		$file     = TEATATU_EVENTS_DIR . 'assets/data/neighbourhoods.geojson';
		if ( is_readable( $file ) ) {
			$json = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			foreach ( isset( $json['features'] ) ? $json['features'] : array() as $feature ) {
				$id = isset( $feature['properties']['id'] ) ? $feature['properties']['id'] : '';
				if ( 'peninsula' === $id ) {
					$geometry['peninsula'] = $feature['geometry']['coordinates'][0];
				} elseif ( in_array( $id, array( 'east_west', 'te_atatu_road' ), true ) ) {
					$geometry[ $id ] = $feature['geometry']['coordinates'];
				}
			}
		}
	}
	return $geometry;
}

/**
 * The stored street rules (network-wide on multisite): rows of
 * {street, parity: any|odd|even, min, max, area}. Seeds them from the
 * bundled data on first use.
 *
 * @return array[]
 */
function teatatu_events_street_rules() {
	if ( ! teatatu_events_get_option( 'teatatu_events_nbhd_rules_seeded', false ) ) {
		teatatu_events_seed_street_rules();
	}
	$rows = teatatu_events_get_option( 'teatatu_events_nbhd_street_rules', array() );
	return is_array( $rows ) ? $rows : array();
}

/**
 * Street rules grouped by normalised street name.
 *
 * @return array normalised street => rows
 */
function teatatu_events_street_rules_index() {
	static $source = null;
	static $index  = array();
	$rows = teatatu_events_street_rules();
	if ( $rows !== $source ) {
		$source = $rows;
		$index  = array();
		foreach ( $rows as $row ) {
			$index[ teatatu_events_normalize_street( $row['street'] ?? '' ) ][] = $row;
		}
	}
	return $index;
}

/**
 * Whether a folded suburb name means Te Atatū Peninsula: true, false
 * (another suburb) or null (unknown/blank).
 *
 * @param string $suburb Suburb.
 * @return bool|null
 */
function teatatu_events_suburb_is_peninsula( $suburb ) {
	$folded = teatatu_events_fold_text( $suburb );
	if ( '' === $folded ) {
		return null;
	}
	if ( false !== strpos( $folded, 'te atatu peninsula' ) || in_array( $folded, array( 'te atatu', 'te atatu north', 'tat pen' ), true ) ) {
		return true;
	}
	return false;
}

/**
 * Resolves the neighbourhood of an address.
 *
 * @param array $parts Address segments (number, street, suburb, postcode, lat, lng…).
 * @return array {slug: string ('' if none), via: ''|'override'|'road'|'street'|'coords', needs: bool}
 */
function teatatu_events_resolve_neighbourhood( $parts ) {
	$none    = array( 'slug' => '', 'via' => '', 'needs' => false );
	$on      = teatatu_events_suburb_is_peninsula( $parts['suburb'] ?? '' );
	if ( false === $on ) {
		return $none;
	}
	$street  = teatatu_events_normalize_street( $parts['street'] ?? '' );
	$number  = teatatu_events_parse_house_number( $parts['number'] ?? '' );
	$number  = $number['int'];
	$known   = false;

	if ( '' !== $street ) {
		$index = teatatu_events_street_rules_index();
		if ( ! empty( $index[ $street ] ) ) {
			$known = true;
			$rows  = $index[ $street ];
			$rule  = array( 'rules' => array() );
			foreach ( $rows as $row ) {
				$rule['rules'][] = array(
					'parity' => $row['parity'] ?? 'any',
					'min'    => isset( $row['min'] ) && '' !== $row['min'] ? (int) $row['min'] : 0,
					'max'    => isset( $row['max'] ) && '' !== $row['max'] ? (int) $row['max'] : null,
					'area'   => $row['area'],
				);
			}
			$whole = function ( $r ) {
				return 'any' === ( $r['parity'] ?? 'any' ) && '' === (string) ( $r['min'] ?? '' ) && '' === (string) ( $r['max'] ?? '' );
			};
			$all_whole = count( $rows ) === count( array_filter( $rows, $whole ) );
			$area      = teatatu_events_apply_street_rule( $rule, null === $number ? ( $all_whole ? 0 : null ) : $number );
			if ( 'outside' === $area ) {
				return $none;
			}
			if ( $area ) {
				return array( 'slug' => $area, 'via' => $all_whole ? 'street' : 'road', 'needs' => false );
			}
		}
	}

	// Coordinates.
	$lat = $parts['lat'] ?? '';
	$lng = $parts['lng'] ?? '';
	if ( '' !== $lat && '' !== $lng && is_numeric( $lat ) && is_numeric( $lng ) ) {
		$area = teatatu_events_classify_point( (float) $lng, (float) $lat, teatatu_events_neighbourhood_geometry() );
		if ( 'outside' === $area ) {
			return $none;
		}
		if ( $area ) {
			return array( 'slug' => teatatu_events_neighbourhood_slug_for_key( $area ), 'via' => 'coords', 'needs' => false );
		}
	}

	$on_peninsula = true === $on || $known;
	return array( 'slug' => '', 'via' => '', 'needs' => $on_peninsula );
}

/**
 * Assigns an event's neighbourhood term: its venue's neighbourhood, else an
 * editor override (address-only events), else one resolved from the event's
 * own Address. Flags address-only peninsula events that can't be resolved.
 *
 * @param int $post_id Event ID.
 */
function teatatu_events_assign_event_neighbourhood( $post_id ) {
	$slug  = '';
	$via   = '';
	$needs = false;
	$venue = teatatu_events_first_term( $post_id, 'teatatu_events_venue' );
	if ( $venue ) {
		$slug = (string) get_term_meta( $venue->term_id, 'teatatu_events_addr_neighbourhood', true );
		$via  = 'venue';
	} else {
		$override = (string) get_post_meta( $post_id, teatatu_events_mk( 'nbhd_override' ), true );
		if ( $override && in_array( $override, teatatu_events_neighbourhood_slugs(), true ) ) {
			$slug = $override;
			$via  = 'override';
		} else {
			$address = (string) get_post_meta( $post_id, teatatu_events_mk( 'address' ), true );
			if ( '' !== trim( $address ) ) {
				$parts  = teatatu_events_parse_address_text( $address, teatatu_events_setting( 'default_country' ) );
				$result = teatatu_events_resolve_neighbourhood( $parts );
				$slug   = $result['slug'];
				$via    = $result['via'];
				$needs  = $result['needs'];
			}
		}
	}
	$term_id = $slug ? teatatu_events_neighbourhood_term_id( $slug ) : 0;
	wp_set_object_terms( $post_id, $term_id ? array( $term_id ) : array(), 'teatatu_events_neighbourhood' );
	update_post_meta( $post_id, teatatu_events_mk( 'nbhd_via' ), $via );
	if ( $needs ) {
		update_post_meta( $post_id, teatatu_events_mk( 'needs_nbhd' ), 1 );
	} else {
		delete_post_meta( $post_id, teatatu_events_mk( 'needs_nbhd' ) );
	}
}

/**
 * Human label for how a neighbourhood was decided.
 *
 * @param string $via Via code.
 * @return string
 */
function teatatu_events_neighbourhood_via_label( $via ) {
	$labels = array(
		'override' => __( 'set by an editor', 'teatatu-events' ),
		'road'     => __( 'from the side of the road / house number', 'teatatu-events' ),
		'street'   => __( 'from the street rules', 'teatatu-events' ),
		'coords'   => __( 'from coordinates', 'teatatu-events' ),
		'venue'    => __( "from the venue's address", 'teatatu-events' ),
	);
	return $labels[ $via ] ?? '';
}

// ---------------------------------------------------------------------------
// Re-resolving after a rule change (one-off background job, in batches).
// ---------------------------------------------------------------------------

/**
 * Marks the rules as changed (network-wide) and schedules re-resolution on
 * the current site. Other sites notice the new version on their next admin
 * page load (see teatatu_events_maybe_reresolve_neighbourhoods()).
 */
function teatatu_events_schedule_neighbourhood_reresolve() {
	teatatu_events_update_option( 'teatatu_events_nbhd_rules_ver', time() );
	teatatu_events_queue_neighbourhood_reresolve();
}

/**
 * Schedules the batch job on the current site.
 */
function teatatu_events_queue_neighbourhood_reresolve() {
	update_option( 'teatatu_events_nbhd_rules_seen', (int) teatatu_events_get_option( 'teatatu_events_nbhd_rules_ver', 0 ) );
	if ( ! wp_next_scheduled( 'teatatu_events_nbhd_reresolve', array( 'venues', 0 ) ) ) {
		wp_schedule_single_event( time() + 5, 'teatatu_events_nbhd_reresolve', array( 'venues', 0 ) );
	}
}

add_action( 'admin_init', 'teatatu_events_maybe_reresolve_neighbourhoods' );

/**
 * Queues re-resolution on this site if the network-wide rules changed since
 * this site last ran it.
 */
function teatatu_events_maybe_reresolve_neighbourhoods() {
	$ver  = (int) teatatu_events_get_option( 'teatatu_events_nbhd_rules_ver', 0 );
	$seen = (int) get_option( 'teatatu_events_nbhd_rules_seen', 0 );
	if ( $ver && $ver !== $seen ) {
		teatatu_events_queue_neighbourhood_reresolve();
	}
}

add_action( 'teatatu_events_nbhd_reresolve', 'teatatu_events_run_neighbourhood_reresolve', 10, 2 );

/**
 * Processes one batch: venues first, then address-only events. Reschedules
 * itself until done.
 *
 * @param string $phase  'venues' or 'events'.
 * @param int    $offset Offset.
 */
function teatatu_events_run_neighbourhood_reresolve( $phase, $offset ) {
	$batch = 50;
	if ( 'venues' === $phase ) {
		$ids = get_terms(
			array(
				'taxonomy'   => 'teatatu_events_venue',
				'hide_empty' => false,
				'fields'     => 'ids',
				'number'     => $batch,
				'offset'     => (int) $offset,
				'orderby'    => 'term_id',
			)
		);
		$ids = is_wp_error( $ids ) ? array() : $ids;
		foreach ( $ids as $id ) {
			teatatu_events_refresh_venue( $id );
		}
		$next = count( $ids ) < $batch ? array( 'events', 0 ) : array( 'venues', $offset + $batch );
	} else {
		$ids = get_posts(
			array(
				'post_type'      => 'teatatu_event',
				'post_status'    => 'any',
				'posts_per_page' => $batch,
				'offset'         => (int) $offset,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'fields'         => 'ids',
				'tax_query'      => array( array( 'taxonomy' => 'teatatu_events_venue', 'operator' => 'NOT EXISTS' ) ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
			)
		);
		foreach ( $ids as $id ) {
			teatatu_events_refresh_derived( $id );
		}
		$next = count( $ids ) < $batch ? null : array( 'events', $offset + $batch );
	}
	if ( $next ) {
		wp_schedule_single_event( time() + 5, 'teatatu_events_nbhd_reresolve', $next );
	}
}

// ---------------------------------------------------------------------------
// Renaming a neighbourhood's slug
// ---------------------------------------------------------------------------

/**
 * Who may rename neighbourhood slugs: slugs are shared by every site (network
 * listings and linked events match on them), so network admins on multisite.
 *
 * @return bool
 */
function teatatu_events_can_rename_neighbourhood_slugs() {
	return is_multisite() ? current_user_can( 'manage_network_options' ) : current_user_can( 'manage_teatatu_events_neighbourhoods' );
}

/**
 * Renames a neighbourhood's slug everywhere it is used, on every site:
 * the neighbourhood term, venue neighbourhoods and overrides, event and
 * series-template overrides, the street rules, feed neighbourhood filters,
 * [teatatu_events_*] shortcodes in post content and widgets, and linked-event
 * snapshots. The old slug is kept as an alias, so links and shortcodes
 * elsewhere keep working and old archive URLs redirect.
 *
 * @param string $key      Neighbourhood key (matipo, beach, harbourview).
 * @param string $new_slug New slug.
 * @return array|WP_Error Counts of what changed.
 */
function teatatu_events_rename_neighbourhood_slug( $key, $new_slug ) {
	if ( ! in_array( $key, teatatu_events_neighbourhood_keys(), true ) ) {
		return new WP_Error( 'teatatu_events_nbhd_key', __( 'Unknown neighbourhood.', 'teatatu-events' ) );
	}
	$map  = teatatu_events_neighbourhood_slug_map();
	$old  = $map[ $key ];
	$new  = sanitize_title( $new_slug );
	if ( '' === $new || 'outside' === $new ) {
		return new WP_Error( 'teatatu_events_nbhd_slug', __( 'Enter a slug (letters, numbers and hyphens).', 'teatatu-events' ) );
	}
	if ( $new === $old ) {
		return array();
	}
	if ( in_array( $new, $map, true ) ) {
		/* translators: %s: slug. */
		return new WP_Error( 'teatatu_events_nbhd_slug_taken', sprintf( __( 'Another neighbourhood already uses the slug "%s".', 'teatatu-events' ), $new ) );
	}
	$sites = is_multisite() ? get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) : array( get_current_blog_id() );
	foreach ( $sites as $site_id ) {
		if ( is_multisite() ) {
			switch_to_blog( $site_id );
		}
		if ( ! taxonomy_exists( 'teatatu_events_neighbourhood' ) ) {
			teatatu_events_register_taxonomies();
		}
		$clash = get_term_by( 'slug', $new, 'teatatu_events_neighbourhood' );
		if ( is_multisite() ) {
			restore_current_blog();
		}
		if ( $clash ) {
			/* translators: %s: slug. */
			return new WP_Error( 'teatatu_events_nbhd_slug_taken', sprintf( __( 'The slug "%s" is already used by a term on another site.', 'teatatu-events' ), $new ) );
		}
	}

	// Network-wide settings first: the slug map, the alias, the street rules.
	$map[ $key ] = $new;
	teatatu_events_update_option( 'teatatu_events_nbhd_slugs', $map );
	$aliases = (array) teatatu_events_get_option( 'teatatu_events_nbhd_slug_aliases', array() );
	unset( $aliases[ $new ] );
	$aliases[ $old ] = $key;
	teatatu_events_update_option( 'teatatu_events_nbhd_slug_aliases', $aliases );

	$counts = array( 'sites' => 0, 'street_rules' => 0, 'venues' => 0, 'events' => 0, 'series' => 0, 'feeds' => 0, 'content' => 0 );
	$rules  = teatatu_events_get_option( 'teatatu_events_nbhd_street_rules', array() );
	if ( is_array( $rules ) ) {
		foreach ( $rules as $i => $row ) {
			if ( ( $row['area'] ?? '' ) === $old ) {
				$rules[ $i ]['area'] = $new;
				$counts['street_rules']++;
			}
		}
		teatatu_events_update_option( 'teatatu_events_nbhd_street_rules', $rules );
	}

	foreach ( $sites as $site_id ) {
		if ( is_multisite() ) {
			switch_to_blog( $site_id );
		}
		foreach ( teatatu_events_rename_neighbourhood_slug_on_site( $key, $old, $new ) as $what => $n ) {
			$counts[ $what ] += $n;
		}
		$counts['sites']++;
		if ( is_multisite() ) {
			restore_current_blog();
		}
	}
	// Linked-event snapshots carry the source event's neighbourhood slug:
	// refresh them once every site has the new slug.
	if ( is_multisite() ) {
		foreach ( $sites as $site_id ) {
			switch_to_blog( $site_id );
			teatatu_events_run_links_cron();
			restore_current_blog();
		}
	}
	return $counts;
}

/**
 * The per-site part of a slug rename (runs on the current site).
 *
 * @param string $key Key.
 * @param string $old Old slug.
 * @param string $new New slug.
 * @return array Counts.
 */
function teatatu_events_rename_neighbourhood_slug_on_site( $key, $old, $new ) {
	$counts = array( 'venues' => 0, 'events' => 0, 'series' => 0, 'feeds' => 0, 'content' => 0 );
	if ( ! taxonomy_exists( 'teatatu_events_neighbourhood' ) ) {
		teatatu_events_register_taxonomies();
		teatatu_events_register_post_types();
	}

	// The term (seeding finds it by stored ID or old slug, and updates the slug).
	teatatu_events_seed_neighbourhood_terms();

	// Venues: resolved neighbourhood and manual override.
	foreach ( array( 'teatatu_events_addr_neighbourhood', 'teatatu_events_addr_neighbourhood_override' ) as $meta_key ) {
		$venues = get_terms(
			array(
				'taxonomy'   => 'teatatu_events_venue',
				'hide_empty' => false,
				'fields'     => 'ids',
				'meta_query' => array( array( 'key' => $meta_key, 'value' => $old ) ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			)
		);
		foreach ( is_wp_error( $venues ) ? array() : $venues as $venue_id ) {
			update_term_meta( $venue_id, $meta_key, $new );
			$counts['venues']++;
		}
	}

	// Events: manual override (every status, incl. drafts and trash).
	$events = get_posts(
		array(
			'post_type'        => array( 'teatatu_event' ),
			'post_status'      => array_keys( get_post_stati() ),
			'posts_per_page'   => -1,
			'fields'           => 'ids',
			'suppress_filters' => true,
			'meta_query'       => array( array( 'key' => teatatu_events_mk( 'nbhd_override' ), 'value' => $old ) ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		)
	);
	foreach ( $events as $id ) {
		update_post_meta( $id, teatatu_events_mk( 'nbhd_override' ), $new );
		$counts['events']++;
	}

	// Series templates.
	$series = get_posts( array( 'post_type' => 'teatatu_evt_series', 'post_status' => array_keys( get_post_stati() ), 'posts_per_page' => -1, 'fields' => 'ids', 'suppress_filters' => true ) );
	foreach ( $series as $id ) {
		$template = get_post_meta( $id, '_teatatu_events_series_template', true );
		if ( is_array( $template ) && ( $template['nbhd_override'] ?? '' ) === $old ) {
			$template['nbhd_override'] = $new;
			update_post_meta( $id, '_teatatu_events_series_template', $template );
			$counts['series']++;
		}
	}

	// Feed neighbourhood filters.
	$feed_types = wp_list_pluck( teatatu_events_feed_types(), 0 );
	$feeds      = get_posts( array( 'post_type' => array_values( $feed_types ), 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'suppress_filters' => true ) );
	foreach ( $feeds as $id ) {
		$config = get_post_meta( $id, '_teatatu_events_feed_config', true );
		if ( is_array( $config ) && in_array( $old, (array) ( $config['neighbourhood_ids'] ?? array() ), true ) ) {
			$config['neighbourhood_ids'] = array_values( array_unique( array_map( function ( $s ) use ( $old, $new ) { return $s === $old ? $new : $s; }, $config['neighbourhood_ids'] ) ) );
			update_post_meta( $id, '_teatatu_events_feed_config', $config );
			$counts['feeds']++;
		}
	}

	// Shortcodes in post content and widgets.
	global $wpdb;
	$rows = $wpdb->get_results( "SELECT ID, post_content FROM {$wpdb->posts} WHERE post_type <> 'revision' AND post_content LIKE '%[teatatu\\_events%' AND post_content LIKE '%neighbourhood%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	foreach ( $rows as $row ) {
		$updated = teatatu_events_rename_neighbourhood_in_shortcodes( $row->post_content, $old, $new );
		if ( $updated !== $row->post_content ) {
			$wpdb->update( $wpdb->posts, array( 'post_content' => $updated ), array( 'ID' => $row->ID ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			clean_post_cache( (int) $row->ID );
			$counts['content']++;
		}
	}
	foreach ( array( 'widget_text' => 'text', 'widget_custom_html' => 'content', 'widget_block' => 'content' ) as $option => $field ) {
		$widgets = get_option( $option );
		if ( ! is_array( $widgets ) ) {
			continue;
		}
		$changed = false;
		foreach ( $widgets as $i => $widget ) {
			if ( is_array( $widget ) && isset( $widget[ $field ] ) && is_string( $widget[ $field ] ) ) {
				$updated = teatatu_events_rename_neighbourhood_in_shortcodes( $widget[ $field ], $old, $new );
				if ( $updated !== $widget[ $field ] ) {
					$widgets[ $i ][ $field ] = $updated;
					$changed                 = true;
					$counts['content']++;
				}
			}
		}
		if ( $changed ) {
			update_option( $option, $widgets );
		}
	}

	teatatu_events_bump_cache_version();
	return $counts;
}

/**
 * Replaces a neighbourhood slug in the neighbourhood="" attribute of every
 * [teatatu_events_*] shortcode in some content (comma lists included).
 *
 * @param string $content Content.
 * @param string $old     Old slug.
 * @param string $new     New slug.
 * @return string
 */
function teatatu_events_rename_neighbourhood_in_shortcodes( $content, $old, $new ) {
	return preg_replace_callback(
		'/\[teatatu_events_[a-z_]+\b[^\]]*\]/',
		function ( $tag ) use ( $old, $new ) {
			return preg_replace_callback(
				'/(\bneighbourhood\s*=\s*)(["\']?)([^"\'\s\]]*)\2/',
				function ( $m ) use ( $old, $new ) {
					$values = array_map(
						function ( $v ) use ( $old, $new ) {
							return trim( $v ) === $old ? $new : $v;
						},
						explode( ',', $m[3] )
					);
					return $m[1] . $m[2] . implode( ',', $values ) . $m[2];
				},
				$tag[0]
			);
		},
		(string) $content
	);
}

add_filter( 'wp_update_term_data', 'teatatu_events_guard_neighbourhood_slug', 10, 3 );

/**
 * Neighbourhood slugs only change through the rename above (which updates
 * every stored use); a slug edit made any other way — REST, a term screen —
 * is ignored so nothing is left pointing at a slug that no longer exists.
 *
 * @param array  $data     Term data to be saved.
 * @param int    $term_id  Term ID.
 * @param string $taxonomy Taxonomy.
 * @return array
 */
function teatatu_events_guard_neighbourhood_slug( $data, $term_id, $taxonomy ) {
	if ( 'teatatu_events_neighbourhood' !== $taxonomy || ! empty( $GLOBALS['teatatu_events_renaming_neighbourhood'] ) ) {
		return $data;
	}
	$term = get_term( $term_id, $taxonomy );
	if ( $term && ! is_wp_error( $term ) ) {
		$data['slug'] = $term->slug;
	}
	return $data;
}

add_action( 'template_redirect', 'teatatu_events_redirect_old_neighbourhood_slug' );

/**
 * Sends a neighbourhood archive URL that uses an old slug to the current one.
 */
function teatatu_events_redirect_old_neighbourhood_slug() {
	if ( ! is_404() ) {
		return;
	}
	$slug = (string) get_query_var( 'teatatu_events_neighbourhood' );
	if ( '' === $slug ) {
		return;
	}
	$current = teatatu_events_current_neighbourhood_slug( $slug );
	if ( $current !== $slug ) {
		$link = get_term_link( $current, 'teatatu_events_neighbourhood' );
		if ( ! is_wp_error( $link ) ) {
			wp_safe_redirect( $link, 301 );
			exit;
		}
	}
}
