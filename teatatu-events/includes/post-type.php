<?php
/**
 * Registers the public `teatatu_event` post type and the plugin's internal
 * post types (series, linked events, and the four feed configurations), and
 * renders the event details block, the external-event redirect and the
 * Schema.org Event JSON-LD on single event pages.
 *
 * WordPress limits post_type to 20 characters, so the internal types use the
 * short `teatatu_evt_` prefix — the only exception to the teatatu_events_
 * prefix rule. No 'thumbnail' support anywhere: images are link-only.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'init', 'teatatu_events_register_post_types' );

/**
 * Registers every post type the plugin uses.
 */
function teatatu_events_register_post_types() {
	$labels = array(
		'name'               => __( 'Events', 'teatatu-events' ),
		'singular_name'      => __( 'Event', 'teatatu-events' ),
		'menu_name'          => __( 'Events', 'teatatu-events' ),
		'add_new'            => __( 'Add New', 'teatatu-events' ),
		'add_new_item'       => __( 'Add New Event', 'teatatu-events' ),
		'edit_item'          => __( 'Edit Event', 'teatatu-events' ),
		'new_item'           => __( 'New Event', 'teatatu-events' ),
		'view_item'          => __( 'View Event', 'teatatu-events' ),
		'view_items'         => __( 'View Events', 'teatatu-events' ),
		'search_items'       => __( 'Search Events', 'teatatu-events' ),
		'not_found'          => __( 'No events found', 'teatatu-events' ),
		'not_found_in_trash' => __( 'No events found in Trash', 'teatatu-events' ),
		'all_items'          => __( 'All Events', 'teatatu-events' ),
		'archives'           => __( 'Event Archives', 'teatatu-events' ),
	);

	register_post_type(
		'teatatu_event',
		array(
			'labels'              => $labels,
			'public'              => true,
			'show_in_rest'        => true,
			'rest_base'           => 'events',
			'supports'            => array( 'title', 'excerpt', 'editor', 'author', 'custom-fields' ),
			'menu_icon'           => 'dashicons-calendar-alt',
			'has_archive'         => true,
			'rewrite'             => array( 'slug' => 'events' ),
			'capability_type'     => array( 'teatatu_event', 'teatatu_events_items' ),
			'map_meta_cap'        => true,
			'show_ui'             => true,
			'show_in_menu'        => true,
			'menu_position'       => 20,
			'hierarchical'        => false,
			'exclude_from_search' => false,
		)
	);

	$internal = array(
		'teatatu_evt_series'   => __( 'Event Series', 'teatatu-events' ),
		'teatatu_evt_link'     => __( 'Linked Events', 'teatatu-events' ),
		'teatatu_evt_rssfeed'  => __( 'RSS Feeds', 'teatatu-events' ),
		'teatatu_evt_webfeed'  => __( 'HTML Feeds', 'teatatu-events' ),
		'teatatu_evt_icalfeed' => __( 'iCal Feeds', 'teatatu-events' ),
		'teatatu_evt_ldfeed'   => __( 'Structured Data Feeds', 'teatatu-events' ),
	);
	foreach ( $internal as $type => $name ) {
		register_post_type(
			$type,
			array(
				'labels'       => array(
					'name'          => $name,
					'singular_name' => $name,
				),
				'public'       => false,
				'show_ui'      => false,
				'show_in_rest' => false,
				'supports'     => array( 'title', 'editor', 'excerpt' ),
				'rewrite'      => false,
				'query_var'    => false,
			)
		);
	}
}

add_filter( 'the_content', 'teatatu_events_filter_the_content', 20 );

/**
 * Adds the details block (when, place, tags, status, price, tickets,
 * add-to-calendar, other dates in the series) above a local event's
 * description on its own page.
 *
 * @param string $content Original post content.
 * @return string
 */
function teatatu_events_filter_the_content( $content ) {
	if ( ! is_singular( 'teatatu_event' ) || ! in_the_loop() || ! is_main_query() ) {
		return $content;
	}
	teatatu_events_enqueue_style();
	$post_id = get_the_ID();
	$item    = teatatu_events_normalize_item( get_post( $post_id ) );

	ob_start();
	?>
	<div class="tte-details">
		<?php if ( $item['is_past'] ) : ?>
			<p class="tte-notice tte-notice-past"><?php esc_html_e( 'This event has finished.', 'teatatu-events' ); ?></p>
		<?php endif; ?>
		<?php if ( 'scheduled' !== $item['status'] ) : ?>
			<p class="tte-badge tte-status tte-status-<?php echo esc_attr( $item['status'] ); ?>"><?php echo esc_html( $item['status_label'] ); ?></p>
		<?php endif; ?>
		<dl class="tte-details-list">
			<dt><?php esc_html_e( 'When', 'teatatu-events' ); ?></dt>
			<dd class="tte-when"><?php echo esc_html( $item['display_when'] ); ?></dd>
			<?php if ( $item['place']['line'] ) : ?>
				<dt><?php esc_html_e( 'Where', 'teatatu-events' ); ?></dt>
				<dd class="tte-where">
					<?php echo esc_html( $item['place']['line'] ); ?>
					<?php if ( $item['place']['map_url'] ) : ?>
						<a class="tte-map-link" href="<?php echo esc_url( $item['place']['map_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Map', 'teatatu-events' ); ?></a>
					<?php endif; ?>
					<?php if ( ! empty( $item['neighbourhood'] ) ) : ?>
						<span class="tte-badge tte-neighbourhood"><?php echo esc_html( $item['neighbourhood']['name'] ); ?></span>
					<?php endif; ?>
				</dd>
			<?php endif; ?>
			<?php if ( $item['price'] || $item['is_free'] ) : ?>
				<dt><?php esc_html_e( 'Price', 'teatatu-events' ); ?></dt>
				<dd class="tte-price"><?php echo esc_html( $item['price'] ? $item['price'] : __( 'Free', 'teatatu-events' ) ); ?></dd>
			<?php endif; ?>
		</dl>
		<?php if ( ! empty( $item['tags'] ) ) : ?>
			<p class="tte-tags">
				<?php foreach ( $item['tags'] as $tag ) : ?>
					<span class="tte-badge tte-tag"<?php echo $tag['color'] ? ' style="--tte-tag-color:' . esc_attr( $tag['color'] ) . '"' : ''; ?>><?php echo esc_html( $tag['name'] ); ?></span>
				<?php endforeach; ?>
			</p>
		<?php endif; ?>
		<p class="tte-actions">
			<?php if ( $item['ticket_url'] && ! $item['is_past'] && 'cancelled' !== $item['status'] ) : ?>
				<a class="tte-button tte-tickets" href="<?php echo esc_url( $item['ticket_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo 'sold_out' === $item['status'] ? esc_html__( 'Sold out — join waitlist', 'teatatu-events' ) : esc_html__( 'Tickets / register', 'teatatu-events' ); ?></a>
			<?php endif; ?>
			<?php echo teatatu_events_render_calendar_links( $item ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>
		</p>
		<?php
		$others = teatatu_events_series_other_dates( $post_id );
		if ( ! empty( $others ) ) :
			?>
			<div class="tte-series-dates">
				<h3><?php echo get_post_meta( $post_id, teatatu_events_mk( 'series_id' ), true ) ? esc_html__( 'Other dates in this series', 'teatatu-events' ) : esc_html__( 'Other dates', 'teatatu-events' ); ?></h3>
				<ul>
					<?php foreach ( $others as $other ) : ?>
						<li><a href="<?php echo esc_url( get_permalink( $other ) ); ?>"><?php echo esc_html( teatatu_events_format_when( teatatu_events_get_start( $other ), teatatu_events_get_end( $other ), teatatu_events_is_all_day( $other ) ) ); ?></a></li>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php endif; ?>
	</div>
	<?php
	$details = ob_get_clean();

	if ( '' === trim( wp_strip_all_tags( $content ) ) ) {
		$excerpt = get_the_excerpt( $post_id );
		if ( $excerpt ) {
			$content = wpautop( esc_html( $excerpt ) );
		}
	}
	if ( $item['read_more_url'] ) {
		$content .= sprintf(
			'<p class="tte-read-more"><a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a></p>',
			esc_url( $item['read_more_url'] ),
			esc_html__( 'More information', 'teatatu-events' )
		);
	}
	return $details . $content;
}

add_action( 'template_redirect', 'teatatu_events_maybe_redirect_external' );

/**
 * External events have no page of their own: if one is reached directly,
 * visitors are sent to its Read More URL. Editors can still view the page.
 */
function teatatu_events_maybe_redirect_external() {
	if ( ! is_singular( 'teatatu_event' ) ) {
		return;
	}
	$post_id = get_queried_object_id();
	if ( current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	$link = teatatu_events_resolve_link( $post_id );
	if ( 'external' === $link['mode'] && $link['url'] ) {
		wp_redirect( $link['url'], 302 ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- deliberate redirect to the event's own external page.
		exit;
	}
}

add_action( 'wp_head', 'teatatu_events_output_json_ld' );

/**
 * Outputs Schema.org Event JSON-LD on a local event's page.
 */
function teatatu_events_output_json_ld() {
	if ( ! is_singular( 'teatatu_event' ) ) {
		return;
	}
	$post = get_queried_object();
	if ( ! $post || 'publish' !== $post->post_status ) {
		return;
	}
	$item = teatatu_events_normalize_item( $post );
	$tz   = wp_timezone();

	$status_map = array(
		'scheduled'   => 'https://schema.org/EventScheduled',
		'cancelled'   => 'https://schema.org/EventCancelled',
		'postponed'   => 'https://schema.org/EventPostponed',
		'rescheduled' => 'https://schema.org/EventRescheduled',
		'sold_out'    => 'https://schema.org/EventScheduled',
	);
	$fmt  = $item['all_day'] ? 'Y-m-d' : DATE_ATOM;
	$data = array(
		'@context'            => 'https://schema.org',
		'@type'               => 'Event',
		'name'                => $item['title'],
		'description'         => wp_strip_all_tags( $item['excerpt'] ),
		'startDate'           => wp_date( $fmt, $item['start'], $tz ),
		'endDate'             => wp_date( $fmt, $item['end'], $tz ),
		'eventStatus'         => $status_map[ $item['status'] ],
		'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
		'url'                 => get_permalink( $post ),
	);
	if ( $item['image_url'] ) {
		$data['image'] = array( $item['image_url'] );
	}
	if ( $item['place']['line'] ) {
		$location = array(
			'@type' => 'Place',
			'name'  => $item['place']['location'] ? $item['place']['location'] : $item['place']['line'],
		);
		if ( ! empty( $item['place']['postal'] ) ) {
			$location['address'] = array_merge( array( '@type' => 'PostalAddress' ), $item['place']['postal'] );
		} elseif ( $item['place']['address'] ) {
			$location['address'] = $item['place']['address'];
		}
		if ( ! empty( $item['place']['lat'] ) && ! empty( $item['place']['lng'] ) ) {
			$location['geo'] = array(
				'@type'     => 'GeoCoordinates',
				'latitude'  => (float) $item['place']['lat'],
				'longitude' => (float) $item['place']['lng'],
			);
		}
		$data['location'] = $location;
	}
	if ( $item['ticket_url'] || $item['price'] || $item['is_free'] ) {
		$offer = array( '@type' => 'Offer' );
		if ( $item['ticket_url'] ) {
			$offer['url'] = $item['ticket_url'];
		}
		if ( $item['is_free'] ) {
			$offer['price']         = 0;
			$data['isAccessibleForFree'] = true;
		} elseif ( preg_match( '/(\d+(?:\.\d+)?)/', $item['price'], $m ) ) {
			$offer['price'] = (float) $m[1];
		}
		$offer['priceCurrency'] = 'NZD';
		$offer['availability']  = 'sold_out' === $item['status'] ? 'https://schema.org/SoldOut' : 'https://schema.org/InStock';
		$data['offers']         = $offer;
	}
	if ( ! empty( $item['source'] ) ) {
		$data['organizer'] = array(
			'@type' => 'Organization',
			'name'  => $item['source'],
		);
	}

	echo '<script type="application/ld+json">' . wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "</script>\n";
}

add_action( 'pre_get_posts', 'teatatu_events_exclude_draft_copies_front_end' );

/**
 * Keeps "Edit as draft" working copies out of every front-end query.
 *
 * @param WP_Query $query Query.
 */
function teatatu_events_exclude_draft_copies_front_end( $query ) {
	// Only the main front-end query (theme archives, search, single views);
	// the plugin's own queries add their own exclusion where needed.
	if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ! $query->is_main_query() || $query->is_preview() ) {
		return;
	}
	$type = $query->get( 'post_type' );
	if ( 'teatatu_event' !== $type && ! ( is_array( $type ) && in_array( 'teatatu_event', $type, true ) ) ) {
		return;
	}
	$meta_query   = (array) $query->get( 'meta_query' );
	$meta_query[] = array(
		'key'     => '_teatatu_events_draft_of',
		'compare' => 'NOT EXISTS',
	);
	$query->set( 'meta_query', $meta_query );
}
