<?php
/**
 * Shortcodes, in matching pairs: every display has a site version (this
 * site's events, plus linked events) and a network twin (every site's
 * events, Master Site only):
 *
 *   list · grid · latest · calendar · archive · subscribe
 *   [teatatu_events_{display}] / [teatatu_events_network_{display}]
 *
 * Each pair runs through ONE render function with a scope of 'site' or
 * 'network'. The scope only changes where events come from —
 * teatatu_events_query_site_items() or teatatu_events_get_network_feed_items()
 * — so filtering, sorting, grouping, markup and CSS can never drift apart.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_enqueue_scripts', 'teatatu_events_register_style' );

/**
 * Registers (without enqueuing) the plugin's structural stylesheet.
 */
function teatatu_events_register_style() {
	wp_register_style( 'teatatu-events', TEATATU_EVENTS_URL . 'assets/css/teatatu-events.css', array(), TEATATU_EVENTS_VERSION );
}

/**
 * Enqueues the stylesheet only on pages that render events.
 */
function teatatu_events_enqueue_style() {
	if ( ! wp_style_is( 'teatatu-events', 'registered' ) ) {
		teatatu_events_register_style();
	}
	wp_enqueue_style( 'teatatu-events' );
}

/**
 * The displays every scope supports.
 *
 * @return string[]
 */
function teatatu_events_shortcode_displays() {
	return array( 'list', 'grid', 'latest', 'calendar', 'archive', 'subscribe' );
}

add_action( 'init', 'teatatu_events_register_shortcodes' );

/**
 * Registers all twelve shortcodes.
 */
function teatatu_events_register_shortcodes() {
	foreach ( teatatu_events_shortcode_displays() as $display ) {
		add_shortcode(
			'teatatu_events_' . $display,
			function ( $atts ) use ( $display ) {
				return teatatu_events_render_shortcode( (array) $atts, $display, 'site' );
			}
		);
		add_shortcode(
			'teatatu_events_network_' . $display,
			function ( $atts ) use ( $display ) {
				return teatatu_events_render_shortcode( (array) $atts, $display, 'network' );
			}
		);
	}
}

/**
 * Merges attributes with the shared defaults and normalises types.
 *
 * @param array $atts           Raw attributes.
 * @param array $extra_defaults Display-specific defaults.
 * @return array
 */
function teatatu_events_shortcode_atts( $atts, $extra_defaults = array() ) {
	$defaults = array_merge(
		array(
			'count'               => 10,
			'columns'             => 3,
			'source'              => '',
			'category'            => '',
			'venue'               => '',
			'neighbourhood'       => '',
			'tag'                 => '',
			'when'                => 'upcoming',
			'from'                => '',
			'to'                  => '',
			'order'               => '',
			'group_by'            => 'none',
			'show_filters'        => 'false',
			'show_image'          => 'true',
			'show_excerpt'        => 'true',
			'show_source'         => 'false',
			'show_category'       => 'false',
			'show_venue'          => 'true',
			'show_neighbourhood'  => 'false',
			'show_room'           => 'true',
			'show_tags'           => 'true',
			'show_time'           => 'true',
			'show_status'         => 'true',
			'show_tickets'        => 'true',
			'show_calendar_links' => 'false',
			'hide_cancelled'      => teatatu_events_setting( 'hide_cancelled' ) ? 'true' : 'false',
			'linked'              => 'include',
			'show_linked_badge'   => 'true',
			'link_target'         => '_self',
			'exclude_sites'       => '',
			'site_label'          => 'false',
			'display'             => 'full',
			'month'               => '',
			'start_of_week'       => (string) get_option( 'start_of_week', 1 ),
			'page'                => 1,
			'search'              => '',
			'dedupe'              => 'true',
			'_variant'            => '',
		),
		$extra_defaults
	);
	$atts = shortcode_atts( $defaults, $atts );

	$bools = array( 'show_filters', 'show_image', 'show_excerpt', 'show_source', 'show_category', 'show_venue', 'show_neighbourhood', 'show_room', 'show_tags', 'show_time', 'show_status', 'show_tickets', 'show_calendar_links', 'hide_cancelled', 'show_linked_badge', 'site_label', 'dedupe' );
	foreach ( $bools as $key ) {
		$atts[ $key ] = filter_var( $atts[ $key ], FILTER_VALIDATE_BOOLEAN );
	}
	$atts['count']   = max( 1, min( 500, (int) $atts['count'] ) );
	$atts['columns'] = max( 1, min( 6, (int) $atts['columns'] ) );
	$atts['page']    = max( 1, (int) $atts['page'] );
	$atts['when']    = in_array( $atts['when'], array( 'upcoming', 'past', 'all' ), true ) ? $atts['when'] : 'upcoming';
	$order           = strtoupper( (string) $atts['order'] );
	$atts['order']   = in_array( $order, array( 'ASC', 'DESC' ), true ) ? $order : ( 'past' === $atts['when'] ? 'DESC' : 'ASC' );
	$atts['group_by'] = in_array( $atts['group_by'], array( 'none', 'day', 'month' ), true ) ? $atts['group_by'] : 'none';
	$atts['linked']  = in_array( $atts['linked'], array( 'include', 'exclude', 'only' ), true ) ? $atts['linked'] : 'include';
	$atts['link_target'] = '_blank' === $atts['link_target'] ? '_blank' : '_self';
	$atts['display'] = 'summary' === $atts['display'] ? 'summary' : 'full';
	$atts['start_of_week'] = max( 0, min( 6, (int) $atts['start_of_week'] ) );
	foreach ( array( 'source', 'category', 'venue', 'neighbourhood', 'tag' ) as $key ) {
		$atts[ $key ] = implode( ',', array_filter( array_map( 'sanitize_title', explode( ',', (string) $atts[ $key ] ) ) ) );
	}
	// A renamed neighbourhood's old slug still works (shortcodes, links, feeds).
	if ( '' !== $atts['neighbourhood'] ) {
		$atts['neighbourhood'] = implode( ',', array_unique( array_map( 'teatatu_events_current_neighbourhood_slug', explode( ',', $atts['neighbourhood'] ) ) ) );
	}
	$atts['exclude_sites'] = implode( ',', array_filter( array_map( 'absint', explode( ',', (string) $atts['exclude_sites'] ) ) ) );
	$atts['search']        = sanitize_text_field( (string) $atts['search'] );
	$atts['month']         = preg_match( '/^\d{4}-\d{2}$/', (string) $atts['month'] ) ? $atts['month'] : '';

	if ( 'summary' === $atts['display'] ) {
		foreach ( array( 'show_source', 'show_category', 'show_tags', 'show_neighbourhood', 'site_label', 'show_calendar_links' ) as $key ) {
			$atts[ $key ] = false;
		}
	}
	return $atts;
}

/**
 * Taxonomy filter attributes → taxonomy.
 *
 * @return string[]
 */
function teatatu_events_filter_taxonomies() {
	return array(
		'neighbourhood' => 'teatatu_events_neighbourhood',
		'category'      => 'teatatu_events_category',
		'tag'           => 'teatatu_events_tag',
		'venue'         => 'teatatu_events_venue',
		'source'        => 'teatatu_events_source',
	);
}

/**
 * Queries the current site's events (plus linked events) for a listing.
 *
 * @param array $atts Parsed attributes.
 * @return array {items: array[], total: int}
 */
function teatatu_events_query_site_items( $atts ) {
	$types = array( 'teatatu_event' );
	if ( 'exclude' !== $atts['linked'] && teatatu_events_linked_enabled() ) {
		$types = 'only' === $atts['linked'] ? array( 'teatatu_evt_link' ) : array( 'teatatu_event', 'teatatu_evt_link' );
	} elseif ( 'only' === $atts['linked'] ) {
		return array( 'items' => array(), 'total' => 0 );
	}

	$args = teatatu_events_query_args( $atts['when'], teatatu_events_parse_bound( $atts['from'] ), teatatu_events_parse_bound( $atts['to'], true ), $atts['order'] );
	$args['meta_query'][] = array( 'key' => teatatu_events_mk( 'draft_of' ), 'compare' => 'NOT EXISTS' );
	$args['meta_query'][] = array( 'key' => '_teatatu_events_link_hidden', 'compare' => 'NOT EXISTS' );
	$args['meta_query'][] = array( 'key' => '_teatatu_events_link_shadowed', 'compare' => 'NOT EXISTS' );
	if ( $atts['hide_cancelled'] ) {
		$args['meta_query'][] = array( 'key' => teatatu_events_mk( 'status' ), 'value' => 'cancelled', 'compare' => '!=' );
	}

	$tax_query = array();
	foreach ( teatatu_events_filter_taxonomies() as $att => $taxonomy ) {
		if ( '' !== $atts[ $att ] ) {
			$tax_query[] = array( 'taxonomy' => $taxonomy, 'field' => 'slug', 'terms' => explode( ',', $atts[ $att ] ) );
		}
	}
	if ( $tax_query ) {
		$args['tax_query'] = $tax_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
	}

	$query = new WP_Query(
		array_merge(
			$args,
			array(
				'post_type'           => $types,
				'post_status'         => 'publish',
				'posts_per_page'      => $atts['count'],
				'paged'               => $atts['page'],
				's'                   => $atts['search'],
				'ignore_sticky_posts' => true,
			)
		)
	);

	$items = array();
	foreach ( $query->posts as $post ) {
		$item = 'teatatu_evt_link' === $post->post_type ? teatatu_events_normalize_link_item( $post ) : teatatu_events_normalize_item( $post );
		if ( $item ) {
			$items[] = $item;
		}
	}
	return array(
		'items' => $items,
		'total' => (int) $query->found_posts,
	);
}

/**
 * Aggregates events from every site (no linked events — the network already
 * includes each source event once), removes cross-site duplicates, then
 * sorts and pages the merged list. Shared by all six network shortcodes,
 * the network REST endpoint and the network iCal feed.
 *
 * The cache key is built only from attributes that change which events are
 * fetched — never purely visual ones — so every network consumer shares
 * cache entries for identical data.
 *
 * @param array $atts Parsed attributes.
 * @return array {items: array[], total: int, has_more: bool}
 */
function teatatu_events_get_network_feed_items( $atts ) {
	$cache_atts = array_intersect_key(
		$atts,
		array_flip( array( 'count', 'source', 'category', 'venue', 'neighbourhood', 'tag', 'when', 'from', 'to', 'order', 'page', 'month', 'exclude_sites', 'hide_cancelled', 'search', 'dedupe' ) )
	);
	$key    = teatatu_events_cache_key( 'network', 'items', $cache_atts );
	$cached = teatatu_events_cache_get( 'network', $key );
	if ( false !== $cached ) {
		return $cached;
	}

	$need       = min( 1000, $atts['count'] * $atts['page'] * 2 );
	$site_atts  = array_merge( $atts, array( 'count' => $need, 'page' => 1, 'linked' => 'exclude' ) );
	$all        = array();
	$truncated  = false;
	foreach ( teatatu_events_network_site_ids( array_filter( explode( ',', $atts['exclude_sites'] ) ) ) as $site_id ) {
		switch_to_blog( $site_id );
		$result = teatatu_events_query_site_items( $site_atts );
		restore_current_blog();
		$all = array_merge( $all, $result['items'] );
		if ( $result['total'] > count( $result['items'] ) ) {
			$truncated = true;
		}
	}

	$all = teatatu_events_dedupe_items( $all, ! $atts['dedupe'] );
	usort(
		$all,
		function ( $a, $b ) use ( $atts ) {
			$cmp = $a['start'] <=> $b['start'];
			return 'DESC' === $atts['order'] ? -$cmp : $cmp;
		}
	);
	$total  = count( $all );
	$offset = ( $atts['page'] - 1 ) * $atts['count'];
	$result = array(
		'items'    => array_slice( $all, $offset, $atts['count'] ),
		'total'    => $total,
		'has_more' => $total > $offset + $atts['count'] || $truncated,
	);
	teatatu_events_cache_set( 'network', $key, $result, HOUR_IN_SECONDS );
	return $result;
}

/**
 * Gets events for a scope.
 *
 * @param array  $atts  Parsed attributes.
 * @param string $scope 'site' or 'network'.
 * @return array {items, total, has_more}
 */
function teatatu_events_get_items( $atts, $scope ) {
	if ( 'network' === $scope ) {
		return teatatu_events_get_network_feed_items( $atts );
	}
	$result             = teatatu_events_query_site_items( $atts );
	$result['has_more'] = $result['total'] > $atts['page'] * $atts['count'];
	return $result;
}

/**
 * The one render function behind all twelve shortcodes.
 *
 * @param array  $raw     Raw shortcode attributes.
 * @param string $display list|grid|latest|calendar|archive|subscribe.
 * @param string $scope   'site' or 'network'.
 * @return string
 */
function teatatu_events_render_shortcode( $raw, $display, $scope ) {
	// A stable per-shortcode ID (from its own attributes, not render order —
	// content can be rendered more than once per request), so filter and
	// calendar URLs keep working. Identical shortcodes on one page share it.
	ksort( $raw );
	$instance = substr( md5( $display . '|' . $scope . '|' . wp_json_encode( $raw ) ), 0, 6 );

	if ( 'network' === $scope ) {
		if ( ! is_multisite() ) {
			return '';
		}
		if ( ! teatatu_events_is_master_site() ) {
			if ( current_user_can( 'manage_options' ) ) {
				$message = teatatu_events_get_master_site_id()
					? __( "This shortcode only displays on the network's Master Site.", 'teatatu-events' )
					: __( 'No Master Site has been set yet — configure one in Network Admin > Settings > Teatatu Events.', 'teatatu-events' );
				return '<p class="tte-admin-notice">' . esc_html( $message ) . '</p>';
			}
			return '';
		}
	}
	teatatu_events_enqueue_style();

	$extra = array();
	if ( 'latest' === $display ) {
		$extra = array( 'count' => 3, 'show_excerpt' => 'false', 'show_tags' => 'false', 'show_image' => 'false' );
	} elseif ( 'archive' === $display ) {
		$extra = array( 'when' => 'past', 'count' => 20 );
	} elseif ( 'calendar' === $display ) {
		$extra = array( 'count' => 500, 'when' => 'all' );
	}
	$atts = teatatu_events_shortcode_atts( $raw, $extra );
	if ( 'archive' === $display ) {
		$atts['when'] = 'past';
		if ( empty( $raw['order'] ) ) {
			$atts['order'] = 'DESC';
		}
	}
	if ( 'network' !== $scope ) {
		$atts['exclude_sites'] = '';
		$atts['site_label']    = false;
	}

	// Visitor filters and paging come from per-instance query params.
	$prefix = 'tte' . $instance . '_';
	if ( $atts['show_filters'] ) {
		foreach ( array_keys( teatatu_events_filter_taxonomies() ) as $key ) {
			if ( isset( $_GET[ $prefix . $key ] ) && '' !== $_GET[ $prefix . $key ] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$atts[ $key ] = sanitize_title( wp_unslash( $_GET[ $prefix . $key ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			}
		}
		foreach ( array( 'from', 'to' ) as $key ) {
			if ( ! empty( $_GET[ $prefix . $key ] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', wp_unslash( $_GET[ $prefix . $key ] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				$atts[ $key ] = sanitize_text_field( wp_unslash( $_GET[ $prefix . $key ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			}
		}
		if ( ! empty( $_GET[ $prefix . 's' ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$atts['search'] = sanitize_text_field( wp_unslash( $_GET[ $prefix . 's' ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
	}
	if ( isset( $_GET[ $prefix . 'page' ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$atts['page'] = max( 1, absint( $_GET[ $prefix . 'page' ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}
	$cal_param = 'tte_cal_' . $instance;
	if ( isset( $_GET[ $cal_param ] ) && preg_match( '/^\d{4}-\d{2}$/', wp_unslash( $_GET[ $cal_param ] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$atts['month'] = sanitize_text_field( wp_unslash( $_GET[ $cal_param ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	if ( 'subscribe' === $display ) {
		return teatatu_events_render_subscribe_shortcode( $atts, $scope );
	}

	$out = '<div class="tte tte-' . esc_attr( $display ) . '-wrap tte-scope-' . esc_attr( $scope ) . '">';
	if ( $atts['show_filters'] ) {
		$out .= teatatu_events_render_filters( $atts, $scope, $prefix, 'calendar' === $display ? $cal_param : '' );
	}
	if ( 'calendar' === $display ) {
		$out .= teatatu_events_render_calendar( $atts, $scope, $cal_param );
	} else {
		$result = teatatu_events_get_items( $atts, $scope );
		$out   .= teatatu_events_render_items( $result['items'], $atts, $display );
		if ( 'archive' === $display || ( $atts['show_filters'] && $result['has_more'] ) ) {
			$out .= teatatu_events_render_pager( $atts['page'], $result['has_more'], $prefix . 'page' );
		}
	}
	return $out . '</div>';
}

/**
 * Renders a list of events as list/grid/latest/archive markup.
 *
 * @param array[] $items   Normalised items.
 * @param array   $atts    Parsed attributes.
 * @param string  $display Display.
 * @return string
 */
function teatatu_events_render_items( array $items, array $atts, $display ) {
	if ( empty( $items ) ) {
		$msg = 'past' === $atts['when'] ? __( 'No past events found.', 'teatatu-events' ) : __( 'No upcoming events found.', 'teatatu-events' );
		return '<p class="tte-empty">' . esc_html( $msg ) . '</p>';
	}
	$summary = 'summary' === $atts['display'] ? ' tte-summary' : '';

	$groups = array();
	foreach ( $items as $item ) {
		$label = '';
		if ( 'day' === $atts['group_by'] ) {
			$label = teatatu_events_format_date( $item['start'] );
		} elseif ( 'month' === $atts['group_by'] ) {
			$label = wp_date( 'F Y', $item['start'] );
		}
		$groups[ $label ][] = $item;
	}

	ob_start();
	foreach ( $groups as $label => $group ) {
		if ( '' !== $label ) {
			echo '<h3 class="tte-group-heading">' . esc_html( $label ) . '</h3>';
		}
		if ( 'grid' === $display ) {
			echo '<div class="tte-grid' . esc_attr( $summary ) . '" style="--tte-columns:' . esc_attr( $atts['columns'] ) . ';">';
			foreach ( $group as $item ) {
				echo '<article class="tte-card' . esc_attr( teatatu_events_item_classes( $item ) ) . '">' . teatatu_events_render_item_markup( $item, $atts ) . '</article>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped piece by piece.
			}
			echo '</div>';
		} else {
			$class = 'latest' === $display ? 'tte-latest' : 'tte-list';
			echo '<ul class="' . esc_attr( $class . $summary ) . '">';
			foreach ( $group as $item ) {
				echo '<li class="tte-list-item' . esc_attr( teatatu_events_item_classes( $item ) ) . '">' . teatatu_events_render_item_markup( $item, $atts ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped piece by piece.
			}
			echo '</ul>';
		}
	}
	return ob_get_clean();
}

/**
 * Extra CSS classes for an item.
 *
 * @param array $item Item.
 * @return string
 */
function teatatu_events_item_classes( $item ) {
	$classes = ' tte-status-' . sanitize_html_class( $item['status'] );
	if ( $item['is_past'] ) {
		$classes .= ' tte-is-past';
	}
	if ( ! empty( $item['linked'] ) ) {
		$classes .= ' tte-is-linked';
	}
	return $classes;
}

/**
 * Inner markup for one event, honouring the show_* toggles.
 *
 * @param array $item Normalised item.
 * @param array $atts Parsed attributes.
 * @return string
 */
function teatatu_events_render_item_markup( $item, $atts ) {
	$link   = $item['link'];
	$target = $atts['link_target'];
	$rel    = '_blank' === $target || 'external' === $item['link_mode'] ? 'noopener noreferrer' : '';
	ob_start();

	if ( $atts['show_image'] && ! empty( $item['image_url'] ) ) {
		printf(
			'<a class="tte-image-link" href="%1$s" target="%2$s" rel="%3$s"><img class="tte-image" src="%4$s" alt="" loading="lazy" /></a>',
			esc_url( $link ),
			esc_attr( $target ),
			esc_attr( $rel ),
			esc_url( $item['image_url'] )
		);
	}

	echo '<div class="tte-body">';
	$badges = array();
	if ( $atts['show_status'] && 'scheduled' !== $item['status'] ) {
		$badges[] = '<span class="tte-badge tte-status tte-status-' . esc_attr( $item['status'] ) . '">' . esc_html( $item['status_label'] ) . '</span>';
	}
	if ( ! empty( $item['linked'] ) && $atts['show_linked_badge'] ) {
		/* translators: %s: site name. */
		$badges[] = '<span class="tte-badge tte-linked">' . esc_html( sprintf( __( 'From %s', 'teatatu-events' ), $item['home_site']['name'] ) ) . '</span>';
	}
	if ( $atts['show_neighbourhood'] && ! empty( $item['neighbourhood'] ) ) {
		$badges[] = '<span class="tte-badge tte-neighbourhood">' . esc_html( $item['neighbourhood']['name'] ) . '</span>';
	}
	if ( $badges ) {
		echo '<div class="tte-badges">' . implode( ' ', $badges ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	printf(
		'<h3 class="tte-title"><a href="%1$s" target="%2$s" rel="%3$s">%4$s</a></h3>',
		esc_url( $link ),
		esc_attr( $target ),
		esc_attr( $rel ),
		esc_html( $item['title'] )
	);

	$when = $atts['show_time'] ? $item['display_when'] : ( $item['start'] ? teatatu_events_format_date( $item['start'] ) : '' );
	echo '<p class="tte-when"><time datetime="' . esc_attr( gmdate( 'c', (int) $item['start'] ) ) . '">' . esc_html( $when ) . '</time></p>';

	if ( $atts['show_venue'] ) {
		$place = $atts['show_room'] ? $item['place']['line'] : implode( ', ', array_filter( array( $item['place']['location'], $item['place']['address'] ) ) );
		if ( $place ) {
			echo '<p class="tte-where">' . esc_html( $place ) . '</p>';
		}
	}

	$meta = array();
	if ( $atts['show_source'] && $item['source'] ) {
		$meta[] = esc_html( $item['source'] );
	}
	if ( $atts['show_category'] && $item['category'] ) {
		$meta[] = esc_html( $item['category'] );
	}
	if ( $item['price'] || $item['is_free'] ) {
		$meta[] = esc_html( $item['price'] ? $item['price'] : __( 'Free', 'teatatu-events' ) );
	}
	if ( $atts['site_label'] ) {
		$label = sprintf( /* translators: %s: site name. */ __( 'From %s', 'teatatu-events' ), $item['home_site']['name'] );
		if ( ! empty( $item['also_on'] ) ) {
			$label .= ' · ' . sprintf( /* translators: %s: site names. */ __( 'also on %s', 'teatatu-events' ), implode( ', ', wp_list_pluck( $item['also_on'], 'name' ) ) );
		}
		$meta[] = esc_html( $label );
	}
	if ( $meta ) {
		echo '<p class="tte-meta">' . implode( ' &middot; ', $meta ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	if ( $atts['show_tags'] && ! empty( $item['tags'] ) ) {
		echo '<p class="tte-tags">';
		foreach ( $item['tags'] as $tag ) {
			echo '<span class="tte-badge tte-tag"' . ( $tag['color'] ? ' style="--tte-tag-color:' . esc_attr( $tag['color'] ) . '"' : '' ) . '>' . esc_html( $tag['name'] ) . '</span> ';
		}
		echo '</p>';
	}

	if ( ! empty( $item['link_note'] ) ) {
		echo '<p class="tte-link-note">' . esc_html( $item['link_note'] ) . '</p>';
	}
	if ( $atts['show_excerpt'] && ! empty( $item['excerpt'] ) ) {
		echo '<div class="tte-excerpt">' . wp_kses_post( wpautop( $item['excerpt'] ) ) . '</div>';
	}

	$actions = array();
	if ( $atts['show_tickets'] && $item['ticket_url'] && ! $item['is_past'] && 'cancelled' !== $item['status'] ) {
		$actions[] = '<a class="tte-button tte-tickets" href="' . esc_url( $item['ticket_url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Tickets', 'teatatu-events' ) . '</a>';
	}
	if ( $atts['show_calendar_links'] && ! $item['is_past'] ) {
		$actions[] = teatatu_events_render_calendar_links( $item );
	}
	if ( $actions ) {
		echo '<p class="tte-actions">' . implode( ' ', $actions ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
	echo '</div>';
	return ob_get_clean();
}

/**
 * Previous/next pager using a per-instance page parameter.
 *
 * @param int    $page     Current page.
 * @param bool   $has_more Whether there's a next page.
 * @param string $param    Query parameter name.
 * @return string
 */
function teatatu_events_render_pager( $page, $has_more, $param ) {
	if ( $page <= 1 && ! $has_more ) {
		return '';
	}
	$out = '<nav class="tte-pager">';
	if ( $page > 1 ) {
		$out .= '<a class="tte-pager-prev" href="' . esc_url( add_query_arg( $param, $page - 1 ) ) . '">' . esc_html__( '← Newer', 'teatatu-events' ) . '</a>';
	}
	if ( $has_more ) {
		$out .= '<a class="tte-pager-next" href="' . esc_url( add_query_arg( $param, $page + 1 ) ) . '">' . esc_html__( 'Older →', 'teatatu-events' ) . '</a>';
	}
	return $out . '</nav>';
}

/**
 * Terms offered in a visitor filter dropdown (only terms with events). On
 * the network, terms from every site are merged by slug.
 *
 * @param string $taxonomy Taxonomy.
 * @param string $scope    'site' or 'network'.
 * @return string[] slug => name
 */
function teatatu_events_filter_terms( $taxonomy, $scope ) {
	$key    = teatatu_events_cache_key( $scope, 'terms', array( $taxonomy ) );
	$cached = teatatu_events_cache_get( $scope, $key );
	if ( false !== $cached ) {
		return $cached;
	}
	$sites = 'network' === $scope ? teatatu_events_network_site_ids() : array( get_current_blog_id() );
	$out   = array();
	foreach ( $sites as $site_id ) {
		$switched = is_multisite() && get_current_blog_id() !== $site_id;
		if ( $switched ) {
			switch_to_blog( $site_id );
		}
		$terms = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => true ) );
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				if ( ! isset( $out[ $term->slug ] ) ) {
					$out[ $term->slug ] = $term->name;
				}
			}
		}
		if ( $switched ) {
			restore_current_blog();
		}
	}
	asort( $out );
	teatatu_events_cache_set( $scope, $key, $out, HOUR_IN_SECONDS );
	return $out;
}

/**
 * Visitor filter form (GET, no JavaScript, bookmarkable).
 *
 * @param array  $atts      Parsed attributes (current filter values).
 * @param string $scope     Scope.
 * @param string $prefix    Per-instance parameter prefix.
 * @param string $cal_param Calendar month parameter to preserve (calendar only).
 * @return string
 */
function teatatu_events_render_filters( $atts, $scope, $prefix, $cal_param = '' ) {
	$labels = array(
		'neighbourhood' => __( 'Neighbourhood', 'teatatu-events' ),
		'category'      => __( 'Category', 'teatatu-events' ),
		'tag'           => __( 'Type', 'teatatu-events' ),
		'venue'         => __( 'Venue', 'teatatu-events' ),
		'source'        => __( 'Organiser', 'teatatu-events' ),
	);
	ob_start();
	?>
	<form class="tte-filters" method="get" action="">
		<?php
		foreach ( $_GET as $key => $value ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( 0 !== strpos( (string) $key, $prefix ) && is_string( $value ) && ( ! $cal_param || $key !== $cal_param ) ) {
				echo '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( wp_unslash( $value ) ) . '" />';
			}
		}
		if ( $cal_param && $atts['month'] ) {
			echo '<input type="hidden" name="' . esc_attr( $cal_param ) . '" value="' . esc_attr( $atts['month'] ) . '" />';
		}
		foreach ( $labels as $key => $label ) :
			$terms = teatatu_events_filter_terms( teatatu_events_filter_taxonomies()[ $key ], $scope );
			if ( empty( $terms ) ) {
				continue;
			}
			?>
			<label class="tte-filter tte-filter-<?php echo esc_attr( $key ); ?>">
				<span><?php echo esc_html( $label ); ?></span>
				<select name="<?php echo esc_attr( $prefix . $key ); ?>">
					<option value=""><?php esc_html_e( 'Any', 'teatatu-events' ); ?></option>
					<?php foreach ( $terms as $slug => $name ) : ?>
						<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $atts[ $key ], $slug ); ?>><?php echo esc_html( $name ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
		<?php endforeach; ?>
		<label class="tte-filter"><span><?php esc_html_e( 'From', 'teatatu-events' ); ?></span><input type="date" name="<?php echo esc_attr( $prefix . 'from' ); ?>" value="<?php echo esc_attr( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $atts['from'] ) ? $atts['from'] : '' ); ?>" /></label>
		<label class="tte-filter"><span><?php esc_html_e( 'To', 'teatatu-events' ); ?></span><input type="date" name="<?php echo esc_attr( $prefix . 'to' ); ?>" value="<?php echo esc_attr( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $atts['to'] ) ? $atts['to'] : '' ); ?>" /></label>
		<label class="tte-filter tte-filter-search"><span><?php esc_html_e( 'Search', 'teatatu-events' ); ?></span><input type="search" name="<?php echo esc_attr( $prefix . 's' ); ?>" value="<?php echo esc_attr( $atts['search'] ); ?>" /></label>
		<button type="submit" class="tte-button"><?php esc_html_e( 'Filter', 'teatatu-events' ); ?></button>
		<a class="tte-filter-reset" href="<?php echo esc_url( remove_query_arg( array_map( function ( $k ) use ( $prefix ) { return $prefix . $k; }, array( 'neighbourhood', 'category', 'tag', 'venue', 'source', 'from', 'to', 's', 'page' ) ) ) ); ?>"><?php esc_html_e( 'Reset', 'teatatu-events' ); ?></a>
	</form>
	<?php
	return ob_get_clean();
}

/**
 * Month calendar (no JavaScript; collapses to an agenda on phones).
 *
 * @param array  $atts      Parsed attributes.
 * @param string $scope     Scope.
 * @param string $cal_param Month query parameter for this instance.
 * @return string
 */
function teatatu_events_render_calendar( $atts, $scope, $cal_param ) {
	$tz    = wp_timezone();
	$month = $atts['month'] ? $atts['month'] : wp_date( 'Y-m' );
	$first = DateTimeImmutable::createFromFormat( '!Y-m-d', $month . '-01', $tz );
	if ( ! $first ) {
		$first = new DateTimeImmutable( 'first day of this month 00:00', $tz );
	}
	$last       = $first->modify( 'last day of this month' )->setTime( 23, 59, 59 );
	$atts['from'] = $first->format( 'Y-m-d' );
	$atts['to']   = $last->format( 'Y-m-d' );
	$atts['when'] = 'all';
	$atts['order'] = 'ASC';
	$atts['page'] = 1;
	$result = teatatu_events_get_items( $atts, $scope );

	$by_day = array();
	foreach ( $result['items'] as $item ) {
		$day = max( $item['start'], $first->getTimestamp() );
		$end = min( $item['end'] ? $item['end'] : $item['start'], $last->getTimestamp() );
		for ( $guard = 0; $day <= $end && $guard < 62; $guard++ ) {
			$by_day[ wp_date( 'Y-m-d', $day, $tz ) ][] = $item;
			$day = ( new DateTimeImmutable( '@' . $day ) )->setTimezone( $tz )->modify( '+1 day' )->setTime( 0, 0 )->getTimestamp();
		}
	}

	$sow      = (int) $atts['start_of_week'];
	$offset   = ( (int) $first->format( 'w' ) - $sow + 7 ) % 7;
	$cursor   = $first->modify( '-' . $offset . ' days' );
	$today    = wp_date( 'Y-m-d' );
	$prev_url = add_query_arg( $cal_param, $first->modify( '-1 month' )->format( 'Y-m' ) );
	$next_url = add_query_arg( $cal_param, $first->modify( '+1 month' )->format( 'Y-m' ) );
	$weekdays = array();
	for ( $i = 0; $i < 7; $i++ ) {
		$weekdays[] = wp_date( 'D', $cursor->modify( '+' . $i . ' days' )->getTimestamp(), $tz );
	}

	ob_start();
	?>
	<div class="tte-calendar">
		<nav class="tte-calendar-nav">
			<a class="tte-calendar-prev" href="<?php echo esc_url( $prev_url ); ?>" aria-label="<?php esc_attr_e( 'Previous month', 'teatatu-events' ); ?>">&larr;</a>
			<h3 class="tte-calendar-title"><?php echo esc_html( wp_date( 'F Y', $first->getTimestamp(), $tz ) ); ?></h3>
			<a class="tte-calendar-next" href="<?php echo esc_url( $next_url ); ?>" aria-label="<?php esc_attr_e( 'Next month', 'teatatu-events' ); ?>">&rarr;</a>
		</nav>
		<div class="tte-calendar-grid" role="grid">
			<?php foreach ( $weekdays as $wd ) : ?>
				<div class="tte-calendar-weekday" role="columnheader"><?php echo esc_html( $wd ); ?></div>
			<?php endforeach; ?>
			<?php
			while ( $cursor <= $last || 0 !== ( (int) $cursor->format( 'w' ) - $sow + 7 ) % 7 ) :
				$key     = $cursor->format( 'Y-m-d' );
				$in      = $cursor->format( 'Y-m' ) === $first->format( 'Y-m' );
				$day_ev  = $in && isset( $by_day[ $key ] ) ? $by_day[ $key ] : array();
				$classes = 'tte-calendar-day' . ( $in ? '' : ' tte-outside' ) . ( $key === $today ? ' tte-today' : '' ) . ( $day_ev ? ' tte-has-events' : ' tte-no-events' );
				?>
				<div class="<?php echo esc_attr( $classes ); ?>" role="gridcell">
					<span class="tte-calendar-date"><span class="tte-calendar-dow"><?php echo esc_html( wp_date( 'D', $cursor->getTimestamp(), $tz ) ); ?> </span><?php echo esc_html( $cursor->format( 'j' ) ); ?></span>
					<?php if ( $day_ev ) : ?>
						<ul class="tte-calendar-events">
							<?php foreach ( $day_ev as $item ) : ?>
								<li class="<?php echo esc_attr( trim( teatatu_events_item_classes( $item ) ) ); ?>">
									<a href="<?php echo esc_url( $item['link'] ); ?>" target="<?php echo esc_attr( $atts['link_target'] ); ?>">
										<?php if ( ! $item['all_day'] && wp_date( 'Y-m-d', $item['start'], $tz ) === $key ) : ?>
											<span class="tte-calendar-time"><?php echo esc_html( teatatu_events_format_time( $item['start'] ) ); ?></span>
										<?php endif; ?>
										<?php echo esc_html( $item['title'] ); ?>
										<?php if ( 'scheduled' !== $item['status'] ) : ?>
											<span class="tte-badge tte-status tte-status-<?php echo esc_attr( $item['status'] ); ?>"><?php echo esc_html( $item['status_label'] ); ?></span>
										<?php endif; ?>
									</a>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</div>
				<?php
				$cursor = $cursor->modify( '+1 day' );
			endwhile;
			?>
		</div>
		<?php if ( empty( $result['items'] ) ) : ?>
			<p class="tte-empty"><?php esc_html_e( 'No events this month.', 'teatatu-events' ); ?></p>
		<?php endif; ?>
	</div>
	<?php
	return ob_get_clean();
}

/**
 * [teatatu_events_subscribe] / [teatatu_events_network_subscribe].
 *
 * @param array  $atts  Parsed attributes (filters are passed to the feed).
 * @param string $scope Scope.
 * @return string
 */
function teatatu_events_render_subscribe_shortcode( $atts, $scope ) {
	$params = array();
	foreach ( array( 'neighbourhood', 'category', 'tag', 'venue', 'source' ) as $key ) {
		if ( '' !== $atts[ $key ] ) {
			$params[ $key ] = $atts[ $key ];
		}
	}
	if ( 'network' === $scope && $atts['exclude_sites'] ) {
		$params['exclude_sites'] = $atts['exclude_sites'];
	}
	if ( 'site' === $scope && 'include' !== $atts['linked'] ) {
		$params['linked'] = $atts['linked'];
	}
	$path = 'network' === $scope ? 'teatatu-events/v1/network-calendar.ics' : 'teatatu-events/v1/calendar.ics';
	$url  = add_query_arg( $params, rest_url( $path ) );
	$name = 'network' === $scope && get_network() ? get_network()->site_name : get_bloginfo( 'name' );
	return '<div class="tte tte-subscribe-wrap tte-scope-' . esc_attr( $scope ) . '">' . teatatu_events_render_subscribe_links( $url, $name ) . '</div>';
}
