<?php
/**
 * Linked events: a site can list an event that lives on another site in the
 * network — by reference, never a copy. There is still one event with one
 * page; its own site's editors still own it.
 *
 * A link is a `teatatu_evt_link` post on the linking site holding the
 * source site/event IDs, an optional note, and a snapshot of the source
 * event under the same start/end/status meta keys real events use — so one
 * WP_Query sorts and filters local and linked events together. The same
 * taxonomies are registered on links; snapshot term slugs are assigned
 * where the linking site has a term with that slug.
 *
 * Linking a whole series creates an anchor link plus one link per current
 * upcoming occurrence; new occurrences are linked as the series rolls on.
 *
 * Snapshots are refreshed whenever the source event changes (via a
 * network-wide registry of who links to what), and by a daily
 * reconciliation job.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether the current site shows linked events.
 *
 * @return bool
 */
function teatatu_events_linked_enabled() {
	return is_multisite() && teatatu_events_setting( 'allow_linked' ) && (bool) get_option( 'teatatu_events_show_linked', false );
}

/**
 * Registry key for an event or series.
 *
 * @param int    $site_id Source site.
 * @param int    $id      Event or series ID.
 * @param string $kind    'event' or 'series'.
 * @return string
 */
function teatatu_events_link_registry_key( $site_id, $id, $kind = 'event' ) {
	return (int) $site_id . ':' . ( 'series' === $kind ? 'series:' : '' ) . (int) $id;
}

/**
 * Adds or removes a linking site in the network-wide registry.
 *
 * @param string $key     Registry key.
 * @param int    $site_id Linking site.
 * @param bool   $add     Add (true) or remove (false).
 */
function teatatu_events_link_registry_update( $key, $site_id, $add ) {
	$registry = get_site_option( 'teatatu_events_link_registry', array() );
	$sites    = isset( $registry[ $key ] ) ? array_map( 'intval', $registry[ $key ] ) : array();
	$sites    = $add ? array_values( array_unique( array_merge( $sites, array( (int) $site_id ) ) ) ) : array_values( array_diff( $sites, array( (int) $site_id ) ) );
	if ( $sites ) {
		$registry[ $key ] = $sites;
	} else {
		unset( $registry[ $key ] );
	}
	update_site_option( 'teatatu_events_link_registry', $registry );
}

/**
 * Sites currently linking to an event.
 *
 * @param int $site_id  Source site.
 * @param int $event_id Event ID.
 * @return int[]
 */
function teatatu_events_sites_linking_to( $site_id, $event_id ) {
	if ( ! is_multisite() ) {
		return array();
	}
	$registry = get_site_option( 'teatatu_events_link_registry', array() );
	$key      = teatatu_events_link_registry_key( $site_id, $event_id );
	$sites    = isset( $registry[ $key ] ) ? $registry[ $key ] : array();
	$series   = (int) get_post_meta( $event_id, teatatu_events_mk( 'series_id' ), true );
	if ( $series ) {
		$skey  = teatatu_events_link_registry_key( $site_id, $series, 'series' );
		$sites = array_merge( $sites, isset( $registry[ $skey ] ) ? $registry[ $skey ] : array() );
	}
	return array_values( array_unique( array_map( 'intval', $sites ) ) );
}

/**
 * Whether an event on the *current* site may be linked by other sites.
 *
 * @param int $event_id Event ID.
 * @return bool
 */
function teatatu_events_is_linkable( $event_id ) {
	return 'teatatu_event' === get_post_type( $event_id )
		&& 'publish' === get_post_status( $event_id )
		&& ! get_post_meta( $event_id, teatatu_events_mk( 'no_linking' ), true )
		&& ! get_post_meta( $event_id, teatatu_events_mk( 'draft_of' ), true );
}

/**
 * Creates a link on the current site to another site's event (or, with
 * $whole_series, to the series the event belongs to).
 *
 * @param int    $source_site  Source site ID.
 * @param int    $event_id     Source event ID.
 * @param string $note         Optional note shown on this site's card.
 * @param bool   $whole_series Link the event's whole series.
 * @return int|WP_Error Link post ID.
 */
function teatatu_events_create_link( $source_site, $event_id, $note = '', $whole_series = false ) {
	if ( ! teatatu_events_linked_enabled() ) {
		return new WP_Error( 'teatatu_events_linking_off', __( 'Linked events are turned off for this site.', 'teatatu-events' ), array( 'status' => 403 ) );
	}
	if ( ! current_user_can( 'manage_teatatu_events_links' ) ) {
		return new WP_Error( 'rest_forbidden', __( 'You cannot manage linked events.', 'teatatu-events' ), array( 'status' => 403 ) );
	}
	$source_site = (int) $source_site;
	$here        = get_current_blog_id();
	if ( $source_site === $here || ! get_blog_details( $source_site ) ) {
		return new WP_Error( 'rest_invalid_param', __( 'Choose an event from another site.', 'teatatu-events' ), array( 'status' => 400 ) );
	}

	switch_to_blog( $source_site );
	$ok        = teatatu_events_is_linkable( $event_id );
	$series_id = (int) get_post_meta( $event_id, teatatu_events_mk( 'series_id' ), true );
	$title     = get_the_title( $event_id );
	restore_current_blog();
	if ( ! $ok ) {
		return new WP_Error( 'teatatu_events_not_linkable', __( 'That event is not available to other sites.', 'teatatu-events' ), array( 'status' => 400 ) );
	}

	if ( $whole_series && $series_id ) {
		$anchor = teatatu_events_find_links( $source_site, 0, $series_id, true );
		if ( $anchor ) {
			return $anchor[0];
		}
		$anchor_id = wp_insert_post(
			array(
				'post_type'   => 'teatatu_evt_link',
				'post_status' => 'publish',
				'post_title'  => $title,
			),
			true
		);
		if ( is_wp_error( $anchor_id ) ) {
			return $anchor_id;
		}
		update_post_meta( $anchor_id, '_teatatu_events_link_site_id', $source_site );
		update_post_meta( $anchor_id, '_teatatu_events_link_series_id', $series_id );
		update_post_meta( $anchor_id, '_teatatu_events_link_note', sanitize_text_field( $note ) );
		teatatu_events_link_registry_update( teatatu_events_link_registry_key( $source_site, $series_id, 'series' ), $here, true );
		teatatu_events_sync_series_link( $anchor_id );
		return $anchor_id;
	}

	$existing = teatatu_events_find_links( $source_site, $event_id );
	if ( $existing ) {
		return $existing[0];
	}
	$link_id = wp_insert_post(
		array(
			'post_type'   => 'teatatu_evt_link',
			'post_status' => 'publish',
			'post_title'  => $title,
		),
		true
	);
	if ( is_wp_error( $link_id ) ) {
		return $link_id;
	}
	update_post_meta( $link_id, '_teatatu_events_link_site_id', $source_site );
	update_post_meta( $link_id, '_teatatu_events_link_event_id', (int) $event_id );
	update_post_meta( $link_id, '_teatatu_events_link_note', sanitize_text_field( $note ) );
	teatatu_events_link_registry_update( teatatu_events_link_registry_key( $source_site, $event_id ), $here, true );
	teatatu_events_refresh_link( $link_id );
	return $link_id;
}

/**
 * Link posts on the current site for a source event or series.
 *
 * @param int  $source_site Source site.
 * @param int  $event_id    Source event ID (0 to ignore).
 * @param int  $series_id   Source series ID (0 to ignore).
 * @param bool $anchor_only Only series anchors.
 * @return int[]
 */
function teatatu_events_find_links( $source_site, $event_id = 0, $series_id = 0, $anchor_only = false ) {
	$meta = array( array( 'key' => '_teatatu_events_link_site_id', 'value' => (int) $source_site ) );
	if ( $event_id ) {
		$meta[] = array( 'key' => '_teatatu_events_link_event_id', 'value' => (int) $event_id );
	}
	if ( $series_id ) {
		$meta[] = array( 'key' => '_teatatu_events_link_series_id', 'value' => (int) $series_id );
	}
	if ( $anchor_only ) {
		$meta[] = array( 'key' => '_teatatu_events_link_event_id', 'compare' => 'NOT EXISTS' );
	}
	return array_map(
		'intval',
		get_posts(
			array(
				'post_type'        => 'teatatu_evt_link',
				'post_status'      => 'any',
				'posts_per_page'   => -1,
				'fields'           => 'ids',
				'suppress_filters' => true,
				'meta_query'       => $meta, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			)
		)
	);
}

/**
 * Removes a link (and, for a series anchor, its occurrence links) and
 * updates the registry.
 *
 * @param int $link_id Link post ID.
 */
function teatatu_events_delete_link( $link_id ) {
	if ( 'teatatu_evt_link' !== get_post_type( $link_id ) ) {
		return;
	}
	$site     = (int) get_post_meta( $link_id, '_teatatu_events_link_site_id', true );
	$event_id = (int) get_post_meta( $link_id, '_teatatu_events_link_event_id', true );
	$series   = (int) get_post_meta( $link_id, '_teatatu_events_link_series_id', true );
	$here     = get_current_blog_id();
	if ( ! $event_id && $series ) {
		foreach ( get_children( array( 'post_parent' => $link_id, 'post_type' => 'teatatu_evt_link', 'fields' => 'ids' ) ) as $child ) {
			wp_delete_post( $child, true );
		}
		teatatu_events_link_registry_update( teatatu_events_link_registry_key( $site, $series, 'series' ), $here, false );
	} elseif ( $event_id ) {
		$others = array_diff( teatatu_events_find_links( $site, $event_id ), array( (int) $link_id ) );
		if ( ! $others ) {
			teatatu_events_link_registry_update( teatatu_events_link_registry_key( $site, $event_id ), $here, false );
		}
	}
	wp_delete_post( $link_id, true );
	teatatu_events_bump_cache_version();
}

/**
 * Refreshes one link's snapshot from its source event. Hides the link when
 * the source is unpublished, trashed or opted out; removes it when the
 * source is permanently deleted.
 *
 * @param int $link_id Link post ID (on the current site).
 */
function teatatu_events_refresh_link( $link_id ) {
	$site     = (int) get_post_meta( $link_id, '_teatatu_events_link_site_id', true );
	$event_id = (int) get_post_meta( $link_id, '_teatatu_events_link_event_id', true );
	if ( ! $site || ! $event_id ) {
		return;
	}
	switch_to_blog( $site );
	$post     = get_post( $event_id );
	$exists   = $post && 'teatatu_event' === $post->post_type;
	$linkable = $exists && teatatu_events_is_linkable( $event_id );
	$snapshot = $linkable ? teatatu_events_normalize_item( $post ) : null;
	restore_current_blog();

	if ( ! $exists ) {
		teatatu_events_delete_link( $link_id );
		return;
	}
	if ( ! $snapshot ) {
		update_post_meta( $link_id, '_teatatu_events_link_hidden', 1 );
		teatatu_events_bump_cache_version();
		return;
	}
	delete_post_meta( $link_id, '_teatatu_events_link_hidden' );
	wp_update_post(
		array(
			'ID'         => $link_id,
			'post_title' => $snapshot['title'],
		)
	);
	update_post_meta( $link_id, '_teatatu_events_link_snapshot', $snapshot );
	update_post_meta( $link_id, teatatu_events_mk( 'start' ), (int) $snapshot['start'] );
	update_post_meta( $link_id, teatatu_events_mk( 'end' ), (int) $snapshot['end'] );
	update_post_meta( $link_id, teatatu_events_mk( 'all_day' ), $snapshot['all_day'] ? 1 : 0 );
	update_post_meta( $link_id, teatatu_events_mk( 'status' ), $snapshot['status'] );
	update_post_meta( $link_id, teatatu_events_mk( 'external_key' ), $snapshot['external_key'] );
	update_post_meta( $link_id, teatatu_events_mk( 'fuzzy_key' ), $snapshot['fuzzy_key'] );

	$slugs = array(
		'teatatu_events_tag'           => wp_list_pluck( $snapshot['tags'], 'slug' ),
		'teatatu_events_category'      => wp_list_pluck( $snapshot['categories'], 'slug' ),
		'teatatu_events_source'        => $snapshot['source_slug'] ? array( $snapshot['source_slug'] ) : array(),
		'teatatu_events_venue'         => $snapshot['place']['venue_slug'] ? array( $snapshot['place']['venue_slug'] ) : array(),
		'teatatu_events_neighbourhood' => $snapshot['neighbourhood'] ? array( $snapshot['neighbourhood']['slug'] ) : array(),
	);
	foreach ( $slugs as $taxonomy => $list ) {
		$ids = array();
		foreach ( $list as $slug ) {
			$term = get_term_by( 'slug', $slug, $taxonomy );
			if ( $term ) {
				$ids[] = (int) $term->term_id;
			}
		}
		wp_set_object_terms( $link_id, $ids, $taxonomy );
	}
	teatatu_events_update_link_shadow( $link_id );
	teatatu_events_bump_cache_version();
}

/**
 * Marks a link "shadowed" when this site already has its own copy of the
 * same event (same external key, or same fuzzy key when fuzzy matching is
 * on) — the site's own copy is then shown instead.
 *
 * @param int $link_id Link post ID.
 */
function teatatu_events_update_link_shadow( $link_id ) {
	$ext   = (string) get_post_meta( $link_id, teatatu_events_mk( 'external_key' ), true );
	$fuzzy = (string) get_post_meta( $link_id, teatatu_events_mk( 'fuzzy_key' ), true );
	$or    = array( 'relation' => 'OR' );
	if ( $ext ) {
		$or[] = array( 'key' => teatatu_events_mk( 'external_key' ), 'value' => $ext );
	}
	if ( $fuzzy && teatatu_events_setting( 'dedupe_fuzzy' ) ) {
		$or[] = array( 'key' => teatatu_events_mk( 'fuzzy_key' ), 'value' => $fuzzy );
	}
	$shadowed = false;
	if ( count( $or ) > 1 ) {
		$shadowed = (bool) get_posts(
			array(
				'post_type'        => 'teatatu_event',
				'post_status'      => 'publish',
				'posts_per_page'   => 1,
				'fields'           => 'ids',
				'suppress_filters' => true,
				'meta_query'       => array( $or, array( 'key' => teatatu_events_mk( 'draft_of' ), 'compare' => 'NOT EXISTS' ) ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			)
		);
	}
	if ( $shadowed ) {
		update_post_meta( $link_id, '_teatatu_events_link_shadowed', 1 );
	} else {
		delete_post_meta( $link_id, '_teatatu_events_link_shadowed' );
	}
}

/**
 * Creates/removes occurrence links under a series anchor so they match the
 * series' current upcoming occurrences.
 *
 * @param int $anchor_id Anchor link ID.
 */
function teatatu_events_sync_series_link( $anchor_id ) {
	$site   = (int) get_post_meta( $anchor_id, '_teatatu_events_link_site_id', true );
	$series = (int) get_post_meta( $anchor_id, '_teatatu_events_link_series_id', true );
	if ( ! $site || ! $series ) {
		return;
	}
	switch_to_blog( $site );
	$exists = 'teatatu_evt_series' === get_post_type( $series );
	$ids    = array();
	if ( $exists ) {
		foreach ( teatatu_events_series_occurrences( $series ) as $id ) {
			if ( teatatu_events_is_linkable( $id ) && ! teatatu_events_is_past( $id ) ) {
				$ids[] = $id;
			}
		}
	}
	restore_current_blog();
	if ( ! $exists ) {
		teatatu_events_delete_link( $anchor_id );
		return;
	}
	$children = array();
	foreach ( get_children( array( 'post_parent' => $anchor_id, 'post_type' => 'teatatu_evt_link', 'fields' => 'ids' ) ) as $child ) {
		$children[ (int) get_post_meta( $child, '_teatatu_events_link_event_id', true ) ] = $child;
	}
	$note = (string) get_post_meta( $anchor_id, '_teatatu_events_link_note', true );
	foreach ( $ids as $id ) {
		if ( isset( $children[ $id ] ) ) {
			teatatu_events_refresh_link( $children[ $id ] );
			unset( $children[ $id ] );
			continue;
		}
		$child = wp_insert_post(
			array(
				'post_type'   => 'teatatu_evt_link',
				'post_status' => 'publish',
				'post_parent' => $anchor_id,
				'post_title'  => get_the_title( $anchor_id ),
			),
			true
		);
		if ( ! is_wp_error( $child ) ) {
			update_post_meta( $child, '_teatatu_events_link_site_id', $site );
			update_post_meta( $child, '_teatatu_events_link_event_id', $id );
			update_post_meta( $child, '_teatatu_events_link_series_id', $series );
			update_post_meta( $child, '_teatatu_events_link_note', $note );
			teatatu_events_refresh_link( $child );
		}
	}
	// Past occurrences keep their links (for the archive); others no longer linkable are refreshed (hidden or removed).
	foreach ( $children as $child ) {
		teatatu_events_refresh_link( $child );
	}
}

add_action( 'teatatu_events_event_changed', 'teatatu_events_propagate_event_change' );

/**
 * When an event changes on this site: refresh every other site's links to
 * it (and to its series), and re-check this site's own links for shadowing.
 *
 * @param int $event_id Event ID.
 */
function teatatu_events_propagate_event_change( $event_id ) {
	if ( ! is_multisite() || ! empty( $GLOBALS['teatatu_events_propagating'] ) ) {
		return;
	}
	$GLOBALS['teatatu_events_propagating'] = true;
	$here     = get_current_blog_id();
	$registry = get_site_option( 'teatatu_events_link_registry', array() );
	$ekey     = teatatu_events_link_registry_key( $here, $event_id );
	$series   = (int) get_post_meta( $event_id, teatatu_events_mk( 'series_id' ), true );
	$skey     = $series ? teatatu_events_link_registry_key( $here, $series, 'series' ) : '';

	$targets = array();
	foreach ( isset( $registry[ $ekey ] ) ? $registry[ $ekey ] : array() as $site ) {
		$targets[ (int) $site ]['event'] = true;
	}
	if ( $skey ) {
		foreach ( isset( $registry[ $skey ] ) ? $registry[ $skey ] : array() as $site ) {
			$targets[ (int) $site ]['series'] = true;
		}
	}
	foreach ( $targets as $site => $what ) {
		if ( $site === $here ) {
			continue;
		}
		switch_to_blog( $site );
		if ( ! empty( $what['event'] ) ) {
			foreach ( teatatu_events_find_links( $here, $event_id ) as $link_id ) {
				teatatu_events_refresh_link( $link_id );
			}
		}
		if ( ! empty( $what['series'] ) ) {
			foreach ( teatatu_events_find_links( $here, 0, $series, true ) as $anchor ) {
				teatatu_events_sync_series_link( $anchor );
			}
		}
		restore_current_blog();
	}

	// This site's own links may now be shadowed (or no longer shadowed) by this event.
	if ( teatatu_events_linked_enabled() ) {
		$keys = array_filter( array( get_post_meta( $event_id, teatatu_events_mk( 'external_key' ), true ), get_post_meta( $event_id, teatatu_events_mk( 'fuzzy_key' ), true ) ) );
		if ( $keys ) {
			$links = get_posts(
				array(
					'post_type'        => 'teatatu_evt_link',
					'post_status'      => 'any',
					'posts_per_page'   => -1,
					'fields'           => 'ids',
					'suppress_filters' => true,
					'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						'relation' => 'OR',
						array( 'key' => teatatu_events_mk( 'external_key' ), 'value' => array_values( $keys ), 'compare' => 'IN' ),
						array( 'key' => teatatu_events_mk( 'fuzzy_key' ), 'value' => array_values( $keys ), 'compare' => 'IN' ),
					),
				)
			);
			foreach ( $links as $link_id ) {
				teatatu_events_update_link_shadow( $link_id );
			}
		}
	}
	$GLOBALS['teatatu_events_propagating'] = false;
}

add_action( 'transition_post_status', 'teatatu_events_propagate_status_change', 20, 3 );
add_action( 'before_delete_post', 'teatatu_events_propagate_delete', 20 );

/**
 * Unpublishing/trashing a linked event hides it everywhere it is linked.
 *
 * @param string  $new_status New status.
 * @param string  $old_status Old status.
 * @param WP_Post $post       Post.
 */
function teatatu_events_propagate_status_change( $new_status, $old_status, $post ) {
	if ( 'teatatu_event' === $post->post_type && $new_status !== $old_status && 'publish' === $old_status ) {
		teatatu_events_propagate_event_change( $post->ID );
	}
}

/**
 * Permanently deleting a linked event removes its links everywhere. The
 * propagation runs at shutdown, after the post is actually gone.
 *
 * @param int $post_id Post ID.
 */
function teatatu_events_propagate_delete( $post_id ) {
	if ( 'teatatu_event' !== get_post_type( $post_id ) || ! is_multisite() ) {
		return;
	}
	$site   = get_current_blog_id();
	$series = (int) get_post_meta( $post_id, teatatu_events_mk( 'series_id' ), true );
	add_action(
		'shutdown',
		function () use ( $site, $post_id, $series ) {
			$registry = get_site_option( 'teatatu_events_link_registry', array() );
			$key      = teatatu_events_link_registry_key( $site, $post_id );
			$sites    = isset( $registry[ $key ] ) ? $registry[ $key ] : array();
			if ( $series ) {
				$skey  = teatatu_events_link_registry_key( $site, $series, 'series' );
				$sites = array_merge( $sites, isset( $registry[ $skey ] ) ? $registry[ $skey ] : array() );
			}
			foreach ( array_unique( $sites ) as $target ) {
				switch_to_blog( (int) $target );
				foreach ( teatatu_events_find_links( $site, $post_id ) as $link_id ) {
					teatatu_events_delete_link( $link_id );
				}
				restore_current_blog();
			}
		}
	);
}

add_action( 'teatatu_events_links_cron_tick', 'teatatu_events_run_links_cron' );

/**
 * Daily reconciliation: refreshes every link snapshot on this site and
 * repairs anything the change hooks missed.
 */
function teatatu_events_run_links_cron() {
	if ( ! is_multisite() ) {
		return;
	}
	$anchors = get_posts(
		array(
			'post_type'      => 'teatatu_evt_link',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'post_parent'    => 0,
		)
	);
	foreach ( $anchors as $id ) {
		if ( get_post_meta( $id, '_teatatu_events_link_event_id', true ) ) {
			teatatu_events_refresh_link( $id );
		} else {
			teatatu_events_sync_series_link( $id );
		}
	}
}

/**
 * Normalised item for a link (from its snapshot).
 *
 * @param WP_Post $post Link post.
 * @return array
 */
function teatatu_events_normalize_link_item( $post ) {
	$item = get_post_meta( $post->ID, '_teatatu_events_link_snapshot', true );
	if ( ! is_array( $item ) ) {
		return array();
	}
	$item['type']      = 'link';
	$item['linked']    = true;
	$item['link_id']   = (int) $post->ID;
	$item['link_note'] = (string) get_post_meta( $post->ID, '_teatatu_events_link_note', true );
	$item['is_past']   = $item['end'] && $item['end'] < time();
	$item['display_when'] = teatatu_events_format_when( $item['start'], $item['end'], $item['all_day'] );
	return $item;
}

/**
 * Upcoming events other sites can link to (for the Linked Events browser).
 *
 * @param array $args {search, from, to, neighbourhood, page}.
 * @return array[] Normalised items, each with 'linked_here' (link ID or 0).
 */
function teatatu_events_linkable_items( $args = array() ) {
	$here  = get_current_blog_id();
	$items = array();
	foreach ( teatatu_events_network_site_ids( array( $here ) ) as $site ) {
		switch_to_blog( $site );
		$query_args = teatatu_events_query_args( 'upcoming', teatatu_events_parse_bound( $args['from'] ?? '' ), teatatu_events_parse_bound( $args['to'] ?? '', true ) );
		$query_args['meta_query'][] = array(
			'relation' => 'OR',
			array( 'key' => teatatu_events_mk( 'no_linking' ), 'compare' => 'NOT EXISTS' ),
			array( 'key' => teatatu_events_mk( 'no_linking' ), 'value' => '1', 'compare' => '!=' ),
		);
		$query_args['meta_query'][] = array( 'key' => teatatu_events_mk( 'draft_of' ), 'compare' => 'NOT EXISTS' );
		$q = new WP_Query(
			array_merge(
				$query_args,
				array(
					'post_type'      => 'teatatu_event',
					'post_status'    => 'publish',
					'posts_per_page' => 50,
					's'              => $args['search'] ?? '',
					'tax_query'      => ! empty( $args['neighbourhood'] ) ? array( array( 'taxonomy' => 'teatatu_events_neighbourhood', 'field' => 'slug', 'terms' => $args['neighbourhood'] ) ) : array(), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				)
			)
		);
		foreach ( $q->posts as $p ) {
			$items[] = teatatu_events_normalize_item( $p );
		}
		restore_current_blog();
	}
	usort(
		$items,
		function ( $a, $b ) {
			return $a['start'] <=> $b['start'];
		}
	);
	foreach ( $items as &$item ) {
		$links               = teatatu_events_find_links( $item['site_id'], $item['id'] );
		$item['linked_here'] = $links ? $links[0] : 0;
		$item['has_own_copy'] = false;
		if ( $item['external_key'] ) {
			$item['has_own_copy'] = (bool) get_posts(
				array(
					'post_type'      => 'teatatu_event',
					'post_status'    => 'any',
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'meta_query'     => array( array( 'key' => teatatu_events_mk( 'external_key' ), 'value' => $item['external_key'] ) ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				)
			);
		}
	}
	unset( $item );
	return array_slice( $items, 0, 100 );
}
