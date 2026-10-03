<?php
/**
 * The in-admin Shortcodes reference tab — a second, independent copy of the
 * shortcode reference in readme.txt, so editors don't have to leave
 * wp-admin. Keep both in step when shortcodes or attributes change.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the Shortcodes tab.
 */
function teatatu_events_render_shortcodes_tab() {
	$pairs = array(
		'list'      => __( 'Vertical list', 'teatatu-events' ),
		'grid'      => __( 'Card grid (use columns="")', 'teatatu-events' ),
		'latest'    => __( 'Compact "next few events" widget (default 3)', 'teatatu-events' ),
		'calendar'  => __( 'Month calendar with previous/next (an agenda on phones)', 'teatatu-events' ),
		'archive'   => __( 'Past events, newest first, with paging', 'teatatu-events' ),
		'subscribe' => __( 'Calendar subscribe links (Apple, Google, Outlook)', 'teatatu-events' ),
	);
	$atts = array(
		array( 'count', __( 'How many events (per page for archive).', 'teatatu-events' ), '10 (latest: 3, archive: 20)' ),
		array( 'columns', __( 'Grid only: cards per row (1–6).', 'teatatu-events' ), '3' ),
		array( 'when', __( 'upcoming (not yet ended), past or all.', 'teatatu-events' ), 'upcoming' ),
		array( 'from / to', __( 'Date range: YYYY-MM-DD or relative like "+30 days".', 'teatatu-events' ), '—' ),
		array( 'neighbourhood', __( 'Only these neighbourhoods (slug, or comma list).', 'teatatu-events' ), '—' ),
		array( 'tag', __( 'Only these Event Tags (slug or comma list).', 'teatatu-events' ), '—' ),
		array( 'category / venue / source', __( 'Only these Categories / Venues / Sources (slug or comma list).', 'teatatu-events' ), '—' ),
		array( 'order', __( 'ASC or DESC by start time.', 'teatatu-events' ), __( 'ASC (past: DESC)', 'teatatu-events' ) ),
		array( 'group_by', __( 'none, day or month headings.', 'teatatu-events' ), 'none' ),
		array( 'show_filters', __( 'Show a filter form for visitors (neighbourhood, category, type, venue, organiser, dates, search).', 'teatatu-events' ), 'false' ),
		array( 'show_image / show_excerpt / show_tags', __( 'Show the image, excerpt, Event Tags.', 'teatatu-events' ), 'true' ),
		array( 'show_venue / show_room / show_time', __( 'Show the place, the room, the time.', 'teatatu-events' ), 'true' ),
		array( 'show_neighbourhood', __( 'Show a neighbourhood badge.', 'teatatu-events' ), 'false' ),
		array( 'show_source / show_category', __( 'Show the Source (organiser) / Category.', 'teatatu-events' ), 'false' ),
		array( 'show_status / show_tickets', __( 'Status badge (cancelled, postponed…) / Tickets button.', 'teatatu-events' ), 'true' ),
		array( 'show_calendar_links', __( 'Add-to-calendar links on each event.', 'teatatu-events' ), 'false' ),
		array( 'hide_cancelled', __( 'Leave cancelled events out (otherwise they show with a badge).', 'teatatu-events' ), __( 'setting', 'teatatu-events' ) ),
		array( 'linked', __( 'Site versions only: include, exclude or only events linked from other sites.', 'teatatu-events' ), 'include' ),
		array( 'show_linked_badge', __( 'Show "From Site name" on linked events.', 'teatatu-events' ), 'true' ),
		array( 'link_target', __( '_self or _blank.', 'teatatu-events' ), '_self' ),
		array( 'display', __( 'summary for a minimal card.', 'teatatu-events' ), 'full' ),
		array( 'month', __( 'Calendar only: starting month YYYY-MM.', 'teatatu-events' ), __( 'this month', 'teatatu-events' ) ),
		array( 'start_of_week', __( 'Calendar only: 0 = Sunday … 6 = Saturday.', 'teatatu-events' ), __( 'WordPress setting', 'teatatu-events' ) ),
	);
	?>
	<h2><?php esc_html_e( 'Shortcodes', 'teatatu-events' ); ?></h2>
	<p><?php esc_html_e( 'Shortcodes come in matching pairs: one for this site\'s events (plus any events linked from other sites), and a network version showing events from every site (Master Site only). Both versions take the same attributes and look the same.', 'teatatu-events' ); ?></p>
	<table class="wp-list-table widefat fixed striped">
		<thead><tr><th><?php esc_html_e( 'Display', 'teatatu-events' ); ?></th><th><?php esc_html_e( 'This site', 'teatatu-events' ); ?></th><th><?php esc_html_e( 'All sites on the network', 'teatatu-events' ); ?></th></tr></thead>
		<tbody>
		<?php foreach ( $pairs as $display => $label ) : ?>
			<tr>
				<td><?php echo esc_html( $label ); ?></td>
				<td><code>[teatatu_events_<?php echo esc_html( $display ); ?>]</code></td>
				<td><code>[teatatu_events_network_<?php echo esc_html( $display ); ?>]</code></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>

	<h3><?php esc_html_e( 'Attributes (all optional, both versions)', 'teatatu-events' ); ?></h3>
	<table class="wp-list-table widefat fixed striped">
		<thead><tr><th style="width:240px;"><?php esc_html_e( 'Attribute', 'teatatu-events' ); ?></th><th><?php esc_html_e( 'What it does', 'teatatu-events' ); ?></th><th style="width:160px;"><?php esc_html_e( 'Default', 'teatatu-events' ); ?></th></tr></thead>
		<tbody>
		<?php foreach ( $atts as $att ) : ?>
			<tr><td><code><?php echo esc_html( $att[0] ); ?></code></td><td><?php echo esc_html( $att[1] ); ?></td><td><?php echo esc_html( $att[2] ); ?></td></tr>
		<?php endforeach; ?>
		<tr><td><code>exclude_sites</code> <em><?php esc_html_e( '(network only)', 'teatatu-events' ); ?></em></td><td><?php esc_html_e( 'Comma list of site IDs to leave out.', 'teatatu-events' ); ?></td><td>—</td></tr>
		<tr><td><code>site_label</code> <em><?php esc_html_e( '(network only)', 'teatatu-events' ); ?></em></td><td><?php esc_html_e( 'Show which site each event is from ("From A · also on B").', 'teatatu-events' ); ?></td><td>false</td></tr>
		</tbody>
	</table>

	<h3><?php esc_html_e( 'Slugs on this site', 'teatatu-events' ); ?></h3>
	<table class="wp-list-table widefat fixed striped">
		<tbody>
		<?php foreach ( array( 'teatatu_events_neighbourhood' => __( 'Neighbourhoods', 'teatatu-events' ), 'teatatu_events_tag' => __( 'Event Tags', 'teatatu-events' ), 'teatatu_events_category' => __( 'Categories', 'teatatu-events' ), 'teatatu_events_venue' => __( 'Venues', 'teatatu-events' ), 'teatatu_events_source' => __( 'Sources', 'teatatu-events' ) ) as $taxonomy => $label ) : ?>
			<?php $terms = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false ) ); ?>
			<tr>
				<th style="width:160px;"><?php echo esc_html( $label ); ?></th>
				<td><?php echo $terms && ! is_wp_error( $terms ) ? wp_kses_post( implode( ', ', array_map( function ( $t ) { return '<code>' . esc_html( $t->slug ) . '</code> (' . esc_html( $t->name ) . ')'; }, $terms ) ) ) : esc_html__( 'None yet.', 'teatatu-events' ); ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>

	<h3><?php esc_html_e( 'Examples', 'teatatu-events' ); ?></h3>
	<p><code>[teatatu_events_grid count="6" columns="3" neighbourhood="beach" show_filters="true"]</code> — <?php esc_html_e( 'six upcoming Beach events as cards, with visitor filters.', 'teatatu-events' ); ?></p>
	<p><code>[teatatu_events_calendar tag="community-event"]</code> — <?php esc_html_e( 'a month calendar of community events.', 'teatatu-events' ); ?></p>
	<p><code>[teatatu_events_network_list site_label="true" group_by="day"]</code> — <?php esc_html_e( 'every site\'s upcoming events, grouped by day, duplicates merged.', 'teatatu-events' ); ?></p>
	<p><code>[teatatu_events_subscribe neighbourhood="matipo"]</code> — <?php esc_html_e( 'calendar subscription links for Matipo events only.', 'teatatu-events' ); ?></p>

	<h3><?php esc_html_e( 'Calendar feed addresses', 'teatatu-events' ); ?></h3>
	<p><?php esc_html_e( 'This site:', 'teatatu-events' ); ?> <code><?php echo esc_html( rest_url( 'teatatu-events/v1/calendar.ics' ) ); ?></code></p>
	<?php if ( is_multisite() && teatatu_events_is_master_site() ) : ?>
		<p><?php esc_html_e( 'Network:', 'teatatu-events' ); ?> <code><?php echo esc_html( rest_url( 'teatatu-events/v1/network-calendar.ics' ) ); ?></code></p>
	<?php endif; ?>
	<p class="description"><?php esc_html_e( 'Add ?neighbourhood=, ?tag=, ?category=, ?venue= or ?source= to filter a feed.', 'teatatu-events' ); ?></p>
	<?php
}
