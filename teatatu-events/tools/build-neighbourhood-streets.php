<?php
/**
 * Builds the neighbourhood data shipped with the plugin, from OpenStreetMap:
 *
 *   assets/data/neighbourhood-streets.json  street → neighbourhood rules, loaded
 *                                           once into the editable street rules
 *   assets/data/neighbourhoods.geojson      peninsula outline + boundary lines
 *
 * Usage (from the plugin folder, no WordPress needed):
 *
 *   php tools/build-neighbourhood-streets.php [--endpoint=URL] [--dry-run]
 *
 * How it works: every Te Atatū Peninsula address point (addr:suburb) is
 * classified by where it sits relative to the boundary roads — south of the
 * Taikata Road / Harbour View Road line is Harbourview; north of it, west of
 * Te Atatū Road is Matipo and east is Beach. Each street then becomes either
 * one neighbourhood, or a set of house-number ranges by side of the road
 * (odd/even) where the street crosses a boundary.
 *
 * The build FAILS if a street can't be described by clean number ranges
 * (its neighbourhoods interleave along the street). That keeps every
 * address resolvable by a rule a person can read and check.
 *
 * Data © OpenStreetMap contributors, ODbL.
 */

define( 'TEATATU_EVENTS_CLI', true );
require __DIR__ . '/../includes/address-core.php';

$opts      = getopt( '', array( 'endpoint::', 'dry-run' ) );
$endpoints = ! empty( $opts['endpoint'] ) ? array( $opts['endpoint'] ) : array(
	'https://maps.mail.ru/osm/tools/overpass/api/interpreter',
	'https://overpass-api.de/api/interpreter',
	'https://overpass.kumi.systems/api/interpreter',
);
$bbox      = '-36.875,174.60,-36.80,174.70';

/**
 * Runs an Overpass query against the first endpoint that answers.
 *
 * @param string   $query     Overpass QL.
 * @param string[] $endpoints Endpoints to try.
 * @return array Decoded elements.
 */
function tte_overpass( $query, $endpoints ) {
	foreach ( $endpoints as $endpoint ) {
		for ( $attempt = 1; $attempt <= 3; $attempt++ ) {
			fwrite( STDERR, "Querying {$endpoint} (attempt {$attempt}) …\n" );
			$ctx  = stream_context_create(
				array(
					'http' => array(
						'method'  => 'POST',
						'header'  => "Content-Type: application/x-www-form-urlencoded\r\nAccept: application/json\r\nUser-Agent: teatatu-events-build/1.0\r\n",
						'content' => http_build_query( array( 'data' => $query ) ),
						'timeout' => 120,
					),
				)
			);
			$body = @file_get_contents( $endpoint, false, $ctx ); // phpcs:ignore
			$json = $body ? json_decode( $body, true ) : null;
			if ( is_array( $json ) && isset( $json['elements'] ) ) {
				return $json['elements'];
			}
			fwrite( STDERR, "  … no usable answer.\n" );
			sleep( 15 * $attempt );
		}
	}
	fwrite( STDERR, "All Overpass endpoints failed.\n" );
	exit( 1 );
}

// One request (two outputs) so a rate-limited endpoint isn't hit twice.
$elements  = tte_overpass( "[out:json][timeout:110];(node[\"addr:street\"][\"addr:housenumber\"]({$bbox});way[\"addr:street\"][\"addr:housenumber\"]({$bbox}););out center tags;way[\"highway\"][\"name\"~\"^(Te Atat(u|ū) Road|Taikata Road|Harbour View Road)$\"]({$bbox});out geom tags;", $endpoints );
$addresses = array();
$roads     = array();
foreach ( $elements as $el ) {
	if ( isset( $el['geometry'] ) && isset( $el['tags']['highway'] ) ) {
		$roads[] = $el;
	} elseif ( isset( $el['tags']['addr:street'] ) ) {
		$addresses[] = $el;
	}
}

// ---------------------------------------------------------------------
// Boundary geometry.
// ---------------------------------------------------------------------
$east_west = array();
$tar       = array();
foreach ( $roads as $way ) {
	$name = teatatu_events_normalize_street( $way['tags']['name'] );
	foreach ( $way['geometry'] as $p ) {
		$pt = array( round( $p['lon'], 7 ), round( $p['lat'], 7 ) );
		if ( 'taikata road' === $name || 'harbour view road' === $name ) {
			$east_west[ implode( ',', $pt ) ] = $pt;
		} elseif ( 'te atatu road' === $name ) {
			$tar[ implode( ',', $pt ) ] = $pt;
		}
	}
}
$east_west = array_values( $east_west );
usort( $east_west, function ( $a, $b ) { return $a[0] <=> $b[0]; } );
$junction_lat = min( array_column( $east_west, 1 ) ) - 0.001;
$tar          = array_values( array_filter( $tar, function ( $p ) use ( $junction_lat ) { return $p[1] > $junction_lat; } ) );
usort( $tar, function ( $a, $b ) { return $a[1] <=> $b[1]; } );
// Te Atatū Road ends just short of the northern tip: continue the line due
// north to the coast so the tip is split the same way.
$top   = end( $tar );
$tar[] = array( $top[0], $top[1] + 0.02 );

if ( count( $east_west ) < 4 || count( $tar ) < 4 ) {
	fwrite( STDERR, "Boundary road geometry is incomplete.\n" );
	exit( 1 );
}

// ---------------------------------------------------------------------
// Addresses.
// ---------------------------------------------------------------------
$peninsula = array();
$outside   = array();
$ambiguous = array();
foreach ( $addresses as $el ) {
	$t   = $el['tags'];
	$lat = isset( $el['lat'] ) ? $el['lat'] : ( isset( $el['center']['lat'] ) ? $el['center']['lat'] : null );
	$lon = isset( $el['lon'] ) ? $el['lon'] : ( isset( $el['center']['lon'] ) ? $el['center']['lon'] : null );
	if ( null === $lat ) {
		continue;
	}
	$hn  = teatatu_events_parse_house_number( $t['addr:housenumber'] );
	$row = array(
		'street' => teatatu_events_normalize_street( $t['addr:street'] ),
		'label'  => $t['addr:street'],
		'number' => $hn['int'],
		'lat'    => $lat,
		'lon'    => $lon,
	);
	$suburb = teatatu_events_fold_text( isset( $t['addr:suburb'] ) ? $t['addr:suburb'] : '' );
	if ( false !== strpos( $suburb, 'te atatu peninsula' ) ) {
		$peninsula[] = $row;
	} elseif ( in_array( $suburb, array( '', 'te atatu', 'te atatu north' ), true ) ) {
		// Blank or ambiguous suburb: decided by the peninsula outline below.
		$ambiguous[] = $row;
	} else {
		// Only addresses explicitly tagged with another suburb count as "off the peninsula".
		$outside[] = $row;
	}
}

// Peninsula outline: convex hull of every peninsula address, pushed ~60 m
// outward so properties on the edge are safely inside.
$points = array_map( function ( $r ) { return array( $r['lon'], $r['lat'] ); }, $peninsula );
usort( $points, function ( $a, $b ) { return $a[0] === $b[0] ? $a[1] <=> $b[1] : $a[0] <=> $b[0]; } );
$cross = function ( $o, $a, $b ) { return ( $a[0] - $o[0] ) * ( $b[1] - $o[1] ) - ( $a[1] - $o[1] ) * ( $b[0] - $o[0] ); };
$lower = array();
foreach ( $points as $p ) {
	while ( count( $lower ) >= 2 && $cross( $lower[ count( $lower ) - 2 ], $lower[ count( $lower ) - 1 ], $p ) <= 0 ) {
		array_pop( $lower );
	}
	$lower[] = $p;
}
$upper = array();
foreach ( array_reverse( $points ) as $p ) {
	while ( count( $upper ) >= 2 && $cross( $upper[ count( $upper ) - 2 ], $upper[ count( $upper ) - 1 ], $p ) <= 0 ) {
		array_pop( $upper );
	}
	$upper[] = $p;
}
array_pop( $lower );
array_pop( $upper );
$hull = array_merge( $lower, $upper );
$cx   = array_sum( array_column( $hull, 0 ) ) / count( $hull );
$cy   = array_sum( array_column( $hull, 1 ) ) / count( $hull );
$hull = array_map(
	function ( $p ) use ( $cx, $cy ) {
		$dx  = $p[0] - $cx;
		$dy  = $p[1] - $cy;
		$len = sqrt( $dx * $dx + $dy * $dy ) ?: 1;
		$pad = 0.0006; // ≈ 60 m.
		return array( round( $p[0] + $dx / $len * $pad, 6 ), round( $p[1] + $dy / $len * $pad, 6 ) );
	},
	$hull
);

// Ambiguous-suburb addresses inside the outline are peninsula addresses too
// (e.g. town-centre properties tagged just "Te Atatu").
foreach ( $ambiguous as $row ) {
	if ( teatatu_events_point_in_polygon( $row['lon'], $row['lat'], $hull ) ) {
		$peninsula[] = $row;
	}
}

$geometry = array(
	'peninsula'     => $hull,
	'east_west'     => $east_west,
	'te_atatu_road' => $tar,
);

// ---------------------------------------------------------------------
// Classify and derive per-street rules.
// ---------------------------------------------------------------------
$by_street = array();
$labels    = array();
foreach ( $peninsula as $r ) {
	$area = teatatu_events_classify_point( $r['lon'], $r['lat'], array( 'east_west' => $east_west, 'te_atatu_road' => $tar ) );
	if ( null === $r['number'] ) {
		continue;
	}
	$by_street[ $r['street'] ][ $r['number'] ][] = $area;
	$labels[ $r['street'] ]                       = $r['label'];
}
ksort( $by_street );

$outside_numbers = array();
foreach ( $outside as $r ) {
	if ( isset( $by_street[ $r['street'] ] ) && null !== $r['number'] ) {
		$outside_numbers[ $r['street'] ][] = $r['number'];
	}
}

$streets  = array();
$errors   = array();
$summary  = array();
foreach ( $by_street as $street => $numbers ) {
	ksort( $numbers );
	$majority = array();
	foreach ( $numbers as $n => $areas ) {
		$counts = array_count_values( $areas );
		arsort( $counts );
		$majority[ $n ] = key( $counts );
	}
	$areas = array_unique( array_values( $majority ) );
	$pmin  = min( array_keys( $majority ) );

	$below = null;
	if ( ! empty( $outside_numbers[ $street ] ) ) {
		$omax = max( $outside_numbers[ $street ] );
		if ( $omax < $pmin ) {
			$below = 'outside';
		} else {
			$summary[] = sprintf( 'note: "%s" also has non-peninsula addresses that overlap its numbering (a different street of the same name nearby?) — ignored.', $labels[ $street ] );
		}
	}

	if ( 1 === count( $areas ) ) {
		$area = reset( $areas );
		if ( $below ) {
			$streets[ $street ] = array(
				'rules' => array( array( 'parity' => 'any', 'min' => $pmin, 'max' => null, 'area' => $area ) ),
				'below' => $below,
			);
		} else {
			$streets[ $street ] = $area;
		}
		continue;
	}

	$rules = array();
	foreach ( array( 'odd' => 1, 'even' => 0 ) as $parity => $mod ) {
		$side = array_filter( $majority, function ( $n ) use ( $mod ) { return $n % 2 === $mod; }, ARRAY_FILTER_USE_KEY );
		if ( empty( $side ) ) {
			continue;
		}
		$blocks = array();
		foreach ( $side as $n => $area ) {
			$last = count( $blocks ) - 1;
			if ( $last >= 0 && $blocks[ $last ]['area'] === $area ) {
				$blocks[ $last ]['max'] = $n;
			} else {
				$blocks[] = array( 'area' => $area, 'min' => $n, 'max' => $n );
			}
		}
		// Absorb a single-number blip between two blocks of the same area.
		for ( $i = 1; $i < count( $blocks ) - 1; $i++ ) {
			if ( $blocks[ $i ]['min'] === $blocks[ $i ]['max'] && $blocks[ $i - 1 ]['area'] === $blocks[ $i + 1 ]['area'] ) {
				$blocks[ $i - 1 ]['max'] = $blocks[ $i + 1 ]['max'];
				array_splice( $blocks, $i, 2 );
				$i--;
			}
		}
		$seen = array();
		foreach ( $blocks as $b ) {
			if ( isset( $seen[ $b['area'] ] ) ) {
				$errors[] = sprintf( '"%s" (%s side): neighbourhoods interleave along the street: %s', $labels[ $street ], $parity, json_encode( $blocks ) );
				continue 3;
			}
			$seen[ $b['area'] ] = true;
		}
		$count = count( $blocks );
		foreach ( $blocks as $i => $b ) {
			$rules[] = array(
				'parity' => $parity,
				'min'    => 0 === $i ? ( $below ? $b['min'] : 0 ) : $b['min'],
				'max'    => $i === $count - 1 ? null : $b['max'],
				'area'   => $b['area'],
			);
		}
	}
	$entry = array( 'rules' => $rules );
	if ( $below ) {
		$entry['below'] = $below;
	}
	$streets[ $street ] = $entry;
	$summary[]          = sprintf( 'split: "%s" → %s', $labels[ $street ], json_encode( $rules ) );
}

$inside_hull_outside = 0;
foreach ( $outside as $r ) {
	if ( teatatu_events_point_in_polygon( $r['lon'], $r['lat'], $hull ) ) {
		$inside_hull_outside++;
	}
}

fwrite( STDERR, sprintf( "%d peninsula addresses on %d streets (%d non-peninsula addresses fall inside the outline).\n", count( $peninsula ), count( $by_street ), $inside_hull_outside ) );
foreach ( $summary as $line ) {
	fwrite( STDERR, $line . "\n" );
}
if ( $errors ) {
	foreach ( $errors as $line ) {
		fwrite( STDERR, 'ERROR: ' . $line . "\n" );
	}
	fwrite( STDERR, "Build failed: fix the boundary rules or add overrides before rebuilding.\n" );
	exit( 2 );
}

$generated = gmdate( 'Y-m-d' );
$streets_json = array(
	'generated' => $generated,
	'source'    => 'OpenStreetMap contributors (ODbL), addr:suburb = Te Atatū Peninsula',
	'note'      => 'Generated by tools/build-neighbourhood-streets.php. A string value means the whole street is in that neighbourhood; otherwise rules give house-number ranges by side of the road. Numbers matching no rule are unresolved and flagged for an editor; "below" covers numbers lower than every rule (the street continues off the peninsula).',
	'streets'   => $streets,
);
$geojson = array(
	'type'     => 'FeatureCollection',
	'metadata' => array(
		'generated' => $generated,
		'source'    => 'OpenStreetMap contributors (ODbL)',
		'note'      => 'peninsula: outline used to decide whether a coordinate is on Te Atatū Peninsula. east_west: Taikata Road + Harbour View Road (south of it = Harbourview). te_atatu_road: Te Atatū Road north of the town centre, extended due north to the coast (west = Matipo, east = Beach).',
	),
	'features' => array(
		array( 'type' => 'Feature', 'properties' => array( 'id' => 'peninsula' ), 'geometry' => array( 'type' => 'Polygon', 'coordinates' => array( array_merge( $hull, array( $hull[0] ) ) ) ) ),
		array( 'type' => 'Feature', 'properties' => array( 'id' => 'east_west' ), 'geometry' => array( 'type' => 'LineString', 'coordinates' => $east_west ) ),
		array( 'type' => 'Feature', 'properties' => array( 'id' => 'te_atatu_road' ), 'geometry' => array( 'type' => 'LineString', 'coordinates' => $tar ) ),
	),
);

if ( isset( $opts['dry-run'] ) ) {
	fwrite( STDERR, "Dry run: nothing written.\n" );
	exit( 0 );
}

$dir = __DIR__ . '/../assets/data';
if ( ! is_dir( $dir ) ) {
	mkdir( $dir, 0755, true );
}
file_put_contents( $dir . '/neighbourhood-streets.json', json_encode( $streets_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n" );
file_put_contents( $dir . '/neighbourhoods.geojson', json_encode( $geojson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n" );
fwrite( STDERR, "Wrote assets/data/neighbourhood-streets.json and assets/data/neighbourhoods.geojson.\n" );
