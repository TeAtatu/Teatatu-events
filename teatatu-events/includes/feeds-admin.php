<?php
/**
 * The feed admin tabs (RSS Feeds, HTML Feeds, iCal Feeds, Structured Data):
 * one renderer and one set of handlers for all four types, so they look and
 * behave the same. Type-specific parts: the Content Mapping table (RSS and
 * HTML), the Repeating Item selector (HTML) and "follow links" (HTML and
 * Structured Data).
 *
 * Feed configs are internal posts with no native UI and are never exposed
 * over REST; configuring imports needs manage_teatatu_events_feeds, which
 * the agent role never has.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders a feed tab.
 *
 * @param string $type Feed type.
 */
function teatatu_events_render_feed_tab( $type ) {
	if ( ! current_user_can( 'manage_teatatu_events_feeds' ) ) {
		echo '<p>' . esc_html__( 'You do not have permission to manage feeds.', 'teatatu-events' ) . '</p>';
		return;
	}
	$types     = teatatu_events_feed_types();
	$post_type = $types[ $type ][0];
	$editing_id = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$editing    = ( $editing_id && $post_type === get_post_type( $editing_id ) ) ? get_post( $editing_id ) : null;
	$config     = $editing ? teatatu_events_get_feed_config( $editing_id ) : array_merge( teatatu_events_feed_defaults( $type ), array( 'type' => $type ) );
	$url_labels = array(
		'rss'  => __( 'Feed URL', 'teatatu-events' ),
		'html' => __( 'Listing Page URL', 'teatatu-events' ),
		'ical' => __( 'Calendar URL (.ics or webcal://)', 'teatatu-events' ),
		'ld'   => __( 'Page URL', 'teatatu-events' ),
	);
	$intros = array(
		'rss'  => __( 'Imports events from an RSS or Atom feed. Most feeds keep the event date in the description or on the linked page, so map Start/End below — by default they come from the linked page\'s Schema.org data.', 'teatatu-events' ),
		'html' => __( 'For sites with an events listing page but no feed: the page is fetched and every element matching the Repeating Item becomes one event.', 'teatatu-events' ),
		'ical' => __( 'Imports a calendar feed (Google Calendar, Outlook, Eventbrite, Meetup and most event systems). Fields map automatically; repeating events are expanded to their next 12 dates.', 'teatatu-events' ),
		'ld'   => __( 'Reads Schema.org Event data that many event websites embed in their pages. More reliable than scraping.', 'teatatu-events' ),
	);
	?>
	<h2><?php echo esc_html( ( $editing ? __( 'Edit', 'teatatu-events' ) : __( 'Add', 'teatatu-events' ) ) . ' ' . $types[ $type ][2] ); ?></h2>
	<p><?php echo esc_html( $intros[ $type ] ); ?></p>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="tte-feed-form">
		<?php wp_nonce_field( 'teatatu_events_save_feed', 'teatatu_events_feed_nonce' ); ?>
		<input type="hidden" name="action" value="teatatu_events_save_feed" />
		<input type="hidden" name="feed_type" value="<?php echo esc_attr( $type ); ?>" />
		<input type="hidden" name="feed_id" value="<?php echo esc_attr( $editing_id ); ?>" />
		<table class="form-table">
			<tr>
				<th><label for="tte_feed_label"><?php esc_html_e( 'Label', 'teatatu-events' ); ?></label></th>
				<td><input type="text" id="tte_feed_label" name="label" class="regular-text" value="<?php echo esc_attr( $editing ? $editing->post_title : '' ); ?>" placeholder="<?php esc_attr_e( 'e.g. Council events', 'teatatu-events' ); ?>" /></td>
			</tr>
			<tr>
				<th><label for="tte_feed_url"><?php echo esc_html( $url_labels[ $type ] ); ?> *</label></th>
				<td><input type="url" id="tte_feed_url" name="feed_url" class="large-text" value="<?php echo esc_attr( $config['url'] ); ?>" required /></td>
			</tr>
			<tr>
				<th><label for="tte_feed_cadence"><?php esc_html_e( 'Check every (minutes)', 'teatatu-events' ); ?></label></th>
				<td>
					<input type="number" id="tte_feed_cadence" name="cadence" class="small-text" min="5" value="<?php echo esc_attr( $config['cadence'] ); ?>" />
					<label style="margin-left:12px;"><input type="checkbox" name="active" value="1" <?php checked( (bool) $config['active'] ); ?> /> <?php esc_html_e( 'Active', 'teatatu-events' ); ?></label>
					<p class="description"><?php esc_html_e( 'Minimum 5 minutes. WP-Cron runs on site traffic, so quiet sites should use a real server cron.', 'teatatu-events' ); ?></p>
				</td>
			</tr>
			<?php if ( current_user_can( 'publish_teatatu_events_items' ) || current_user_can( 'edit_published_teatatu_events_items' ) ) : ?>
				<tr>
					<th><?php esc_html_e( 'Publishing', 'teatatu-events' ); ?></th>
					<td>
						<?php if ( current_user_can( 'publish_teatatu_events_items' ) ) : ?>
							<label><input type="checkbox" name="auto_publish" value="1" <?php checked( (bool) $config['auto_publish'] ); ?> /> <?php esc_html_e( 'Auto-publish new events', 'teatatu-events' ); ?></label>
							<p class="description"><?php esc_html_e( 'Off by default: new events arrive as drafts for review.', 'teatatu-events' ); ?></p>
						<?php endif; ?>
						<?php if ( current_user_can( 'edit_published_teatatu_events_items' ) ) : ?>
							<label><input type="checkbox" name="auto_apply" value="1" <?php checked( (bool) $config['auto_apply'] ); ?> /> <?php esc_html_e( 'Auto-apply changes to published events', 'teatatu-events' ); ?></label>
							<p class="description"><?php esc_html_e( 'Off by default: changes wait in Pending Updates. (An event removed at its source is always cancelled straight away.)', 'teatatu-events' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
			<?php endif; ?>
			<tr>
				<th><?php esc_html_e( 'Defaults', 'teatatu-events' ); ?></th>
				<td>
					<?php
					foreach ( array( 'default_source' => array( 'teatatu_events_source', __( 'Source', 'teatatu-events' ) ), 'default_category' => array( 'teatatu_events_category', __( 'Category', 'teatatu-events' ) ), 'default_venue' => array( 'teatatu_events_venue', __( 'Venue (when an item has no place)', 'teatatu-events' ) ) ) as $name => $def ) {
						echo '<p><label>' . esc_html( $def[1] ) . ' ';
						wp_dropdown_categories(
							array(
								'taxonomy'          => $def[0],
								'name'              => $name,
								'hide_empty'        => false,
								'show_option_none'  => __( '— None —', 'teatatu-events' ),
								'option_none_value' => 0,
								'selected'          => (int) $config[ $name ],
							)
						);
						echo '</label></p>';
					}
					$tags = get_terms( array( 'taxonomy' => 'teatatu_events_tag', 'hide_empty' => false ) );
					if ( $tags && ! is_wp_error( $tags ) ) {
						echo '<p>' . esc_html__( 'Event Tags added to everything from this feed:', 'teatatu-events' ) . '<br />';
						foreach ( $tags as $tag ) {
							echo '<label style="margin-right:12px;"><input type="checkbox" name="default_tags[]" value="' . esc_attr( $tag->term_id ) . '" ' . checked( in_array( (int) $tag->term_id, array_map( 'intval', $config['default_tags'] ), true ), true, false ) . ' /> ' . esc_html( $tag->name ) . '</label>';
						}
						echo '</p>';
					}
					?>
					<p><label><input type="checkbox" name="skip_ended" value="1" <?php checked( (bool) $config['skip_ended'] ); ?> /> <?php esc_html_e( 'Skip events that have already ended', 'teatatu-events' ); ?></label></p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Only import events at', 'teatatu-events' ); ?></th>
				<td>
					<?php
					$filters = array(
						'off'            => __( 'Anywhere (flag addresses that match no venue)', 'teatatu-events' ),
						'any'            => __( 'Any recognised venue', 'teatatu-events' ),
						'selected'       => __( 'Selected venues only', 'teatatu-events' ),
						'neighbourhoods' => __( 'Selected neighbourhoods', 'teatatu-events' ),
					);
					foreach ( $filters as $value => $label ) {
						echo '<label style="display:block;"><input type="radio" name="venue_filter" value="' . esc_attr( $value ) . '" ' . checked( $config['venue_filter'], $value, false ) . ' /> ' . esc_html( $label ) . '</label>';
					}
					?>
					<p><select name="venue_ids[]" multiple size="5" style="min-width:280px;">
						<?php foreach ( teatatu_events_venue_choices() as $venue ) : ?>
							<option value="<?php echo esc_attr( $venue['id'] ); ?>" <?php selected( in_array( $venue['id'], array_map( 'intval', $config['venue_ids'] ), true ) ); ?>><?php echo esc_html( $venue['name'] ); ?></option>
						<?php endforeach; ?>
					</select><br /><span class="description"><?php esc_html_e( 'Venues for "Selected venues only" (Ctrl/Cmd-click for several).', 'teatatu-events' ); ?></span></p>
					<p>
						<?php foreach ( teatatu_events_neighbourhood_slugs() as $slug ) : ?>
							<?php $term = get_term( teatatu_events_neighbourhood_term_id( $slug ) ); ?>
							<label style="margin-right:12px;"><input type="checkbox" name="neighbourhood_ids[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $config['neighbourhood_ids'], true ) ); ?> /> <?php echo esc_html( $term && ! is_wp_error( $term ) ? $term->name : $slug ); ?></label>
						<?php endforeach; ?>
						<br /><span class="description"><?php esc_html_e( 'Neighbourhoods for "Selected neighbourhoods". Skipped items create nothing; the run summary counts them and Preview shows why.', 'teatatu-events' ); ?></span>
					</p>
				</td>
			</tr>
			<?php if ( 'html' === $type ) : ?>
				<tr>
					<th><?php esc_html_e( 'Repeating Item', 'teatatu-events' ); ?> *</th>
					<td>
						<input type="text" name="item_tag" value="<?php echo esc_attr( $config['item_selector']['tag'] ); ?>" placeholder="div" size="6" />
						<select name="item_attr_type">
							<option value="class" <?php selected( $config['item_selector']['attr_type'], 'class' ); ?>><?php esc_html_e( 'Class', 'teatatu-events' ); ?></option>
							<option value="id" <?php selected( $config['item_selector']['attr_type'], 'id' ); ?>><?php esc_html_e( 'ID', 'teatatu-events' ); ?></option>
						</select>
						<input type="text" name="item_attr_value" value="<?php echo esc_attr( $config['item_selector']['attr_value'] ); ?>" placeholder="event-card" />
						<p class="description"><?php esc_html_e( 'The element that repeats once per event on the listing page (one class name only).', 'teatatu-events' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Only inside', 'teatatu-events' ); ?></th>
					<td>
						<?php $within = array_merge( array( 'tag' => '', 'attr_type' => 'class', 'attr_value' => '' ), (array) $config['item_container'] ); ?>
						<input type="text" name="container_tag" value="<?php echo esc_attr( $within['tag'] ); ?>" placeholder="div" size="6" />
						<select name="container_attr_type">
							<option value="class" <?php selected( $within['attr_type'], 'class' ); ?>><?php esc_html_e( 'Class', 'teatatu-events' ); ?></option>
							<option value="id" <?php selected( $within['attr_type'], 'id' ); ?>><?php esc_html_e( 'ID', 'teatatu-events' ); ?></option>
						</select>
						<input type="text" name="container_attr_value" value="<?php echo esc_attr( $within['attr_value'] ); ?>" placeholder="listings-events" />
						<p class="description"><?php esc_html_e( 'Optional. Only items inside the first element matching this are used — e.g. the main listing, not a "Popular events" block that repeats some of them. Leave blank to use the whole page. An event linked more than once is imported once.', 'teatatu-events' ); ?></p>
					</td>
				</tr>
			<?php endif; ?>
			<?php if ( in_array( $type, array( 'html', 'ld' ), true ) ) : ?>
				<tr>
					<th><?php esc_html_e( 'Linked pages', 'teatatu-events' ); ?></th>
					<td><label><input type="checkbox" name="follow_links" value="1" <?php checked( (bool) $config['follow_links'] ); ?> /> <?php echo 'html' === $type ? esc_html__( "Follow each item's link (lets fields come from the event's own page)", 'teatatu-events' ) : esc_html__( 'Follow event URLs (when the page only links to its events)', 'teatatu-events' ); ?></label>
					<p class="description"><?php printf( esc_html__( 'Up to %d linked pages are fetched per check.', 'teatatu-events' ), (int) TEATATU_EVENTS_MAX_FOLLOWS ); ?></p></td>
				</tr>
			<?php endif; ?>
		</table>

		<?php if ( in_array( $type, array( 'rss', 'html' ), true ) ) : ?>
			<h3><?php esc_html_e( 'Content Mapping', 'teatatu-events' ); ?></h3>
			<p class="description"><?php esc_html_e( 'For each field choose where it comes from. Scraped sources need an Element and/or Class/ID. "Linked Page JSON-LD" reads the event page\'s Schema.org data — usually the most reliable source of dates and places; when the page lists several dates, the next upcoming one is used. Dates are read from a datetime="", content="" or hCalendar title="" attribute when present, else the text (NZ day-first, "7pm", "3–5 Oct"; "A and B" means from A to B); add a PHP date format if needed.', 'teatatu-events' ); ?></p>
			<p class="description"><?php esc_html_e( 'Pattern (optional) cuts the value out of what was found, using a regular expression: the first ( ) group is kept, or the whole match if there is no group; no match leaves the field empty. It is matched against the element\'s HTML for text fields, the date text for Start/End, and the URL for links and images. Example — the venue from "Saturday 31st Oct, 7.00pm, Mr Illingsworth<br/>Auckland": ,\s*([^,<]+)<br', 'teatatu-events' ); ?></p>
			<div style="max-width:100%;overflow-x:auto;">
				<table class="wp-list-table widefat striped" style="table-layout:auto;">
					<thead><tr>
						<th><?php esc_html_e( 'Field', 'teatatu-events' ); ?></th>
						<th><?php esc_html_e( 'Source', 'teatatu-events' ); ?></th>
						<th><?php esc_html_e( 'Element', 'teatatu-events' ); ?></th>
						<th><?php esc_html_e( 'Class or ID', 'teatatu-events' ); ?></th>
						<th><?php esc_html_e( 'Date format', 'teatatu-events' ); ?></th>
						<th><?php esc_html_e( 'Pattern', 'teatatu-events' ); ?></th>
					</tr></thead>
					<tbody>
						<?php foreach ( teatatu_events_mappable_fields() as $field => $label ) : ?>
							<?php $fm = $config['field_map'][ $field ]; ?>
							<tr>
								<td><strong><?php echo esc_html( $label ); ?></strong></td>
								<td><select name="map_<?php echo esc_attr( $field ); ?>_source">
									<?php foreach ( teatatu_events_field_source_options( $type, $field ) as $value => $opt ) : ?>
										<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $fm['source'], $value ); ?>><?php echo esc_html( $opt ); ?></option>
									<?php endforeach; ?>
								</select></td>
								<td><input type="text" name="map_<?php echo esc_attr( $field ); ?>_tag" value="<?php echo esc_attr( $fm['tag'] ); ?>" size="5" placeholder="div" /></td>
								<td>
									<select name="map_<?php echo esc_attr( $field ); ?>_attr_type">
										<option value="class" <?php selected( $fm['attr_type'], 'class' ); ?>><?php esc_html_e( 'Class', 'teatatu-events' ); ?></option>
										<option value="id" <?php selected( $fm['attr_type'], 'id' ); ?>><?php esc_html_e( 'ID', 'teatatu-events' ); ?></option>
									</select>
									<input type="text" name="map_<?php echo esc_attr( $field ); ?>_attr_value" value="<?php echo esc_attr( $fm['attr_value'] ); ?>" size="16" />
								</td>
								<td><?php if ( in_array( $field, array( 'start', 'end' ), true ) ) : ?><input type="text" name="map_<?php echo esc_attr( $field ); ?>_format" value="<?php echo esc_attr( $fm['format'] ); ?>" size="12" placeholder="D j M Y, g:ia" /><?php endif; ?></td>
								<td><?php if ( 'tags' !== $field ) : ?><input type="text" name="map_<?php echo esc_attr( $field ); ?>_pattern" value="<?php echo esc_attr( $fm['pattern'] ?? '' ); ?>" size="18" class="code" /><?php endif; ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<?php $ses = array_merge( teatatu_events_sessions_defaults(), (array) $config['sessions'] ); ?>
			<h3><?php esc_html_e( 'Sessions', 'teatatu-events' ); ?></h3>
			<p class="description"><?php esc_html_e( 'For event pages that list several dates (a weekly class, a monthly market). Sessions are read from the linked page and replace the mapped Start/End; a page with no readable sessions keeps the mapped dates.', 'teatatu-events' ); ?></p>
			<table class="form-table">
				<tr>
					<th><?php esc_html_e( 'Dates to import', 'teatatu-events' ); ?></th>
					<td>
						<?php
						$modes = array(
							'off'  => __( 'Use the mapped Start/End only', 'teatatu-events' ),
							'next' => __( 'The next upcoming session', 'teatatu-events' ),
							'each' => __( 'Each upcoming session as its own event (shown as "Other dates" on each other)', 'teatatu-events' ),
						);
						foreach ( $modes as $value => $label ) {
							echo '<label style="display:block;"><input type="radio" name="sessions_mode" value="' . esc_attr( $value ) . '" ' . checked( $ses['mode'], $value, false ) . ' /> ' . esc_html( $label ) . '</label>';
						}
						?>
						<p><label><?php esc_html_e( 'At most', 'teatatu-events' ); ?> <input type="number" name="sessions_max" min="1" max="12" class="small-text" value="<?php echo esc_attr( $ses['max'] ); ?>" /> <?php esc_html_e( 'upcoming sessions per event (each session mode)', 'teatatu-events' ); ?></label></p>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Read sessions from', 'teatatu-events' ); ?></th>
					<td>
						<label style="display:block;"><input type="radio" name="sessions_source" value="jsonld" <?php checked( $ses['source'], 'jsonld' ); ?> /> <?php esc_html_e( "The linked page's Schema.org data (one event per session)", 'teatatu-events' ); ?></label>
						<label style="display:block;"><input type="radio" name="sessions_source" value="selector" <?php checked( $ses['source'], 'selector' ); ?> /> <?php esc_html_e( 'Every element on the linked page matching:', 'teatatu-events' ); ?></label>
						<p>
							<input type="text" name="sessions_tag" value="<?php echo esc_attr( $ses['tag'] ); ?>" placeholder="time" size="6" />
							<select name="sessions_attr_type">
								<option value="class" <?php selected( $ses['attr_type'], 'class' ); ?>><?php esc_html_e( 'Class', 'teatatu-events' ); ?></option>
								<option value="id" <?php selected( $ses['attr_type'], 'id' ); ?>><?php esc_html_e( 'ID', 'teatatu-events' ); ?></option>
							</select>
							<input type="text" name="sessions_attr_value" value="<?php echo esc_attr( $ses['attr_value'] ); ?>" placeholder="dt-start" />
						</p>
						<p class="description"><?php esc_html_e( 'Each match is read as one session\'s date and time (its datetime="" attribute when present). Past sessions are ignored.', 'teatatu-events' ); ?></p>
					</td>
				</tr>
			</table>
		<?php endif; ?>

		<p style="margin-top:16px;">
			<button type="button" class="button" id="tte_feed_preview"><?php esc_html_e( 'Preview', 'teatatu-events' ); ?></button>
			<span id="tte_feed_preview_status" style="margin-left:8px;font-style:italic;"></span>
		</p>
		<div id="tte_feed_preview_out"></div>
		<?php submit_button( $editing ? __( 'Update Feed', 'teatatu-events' ) : __( 'Add Feed', 'teatatu-events' ) ); ?>
	</form>
	<script>
	( function () {
		var btn = document.getElementById( 'tte_feed_preview' );
		var status = document.getElementById( 'tte_feed_preview_status' );
		var out = document.getElementById( 'tte_feed_preview_out' );
		if ( ! btn ) { return; }
		btn.addEventListener( 'click', function () {
			var data = new FormData( document.getElementById( 'tte-feed-form' ) );
			data.set( 'action', 'teatatu_events_preview_feed' );
			data.set( 'nonce', <?php echo wp_json_encode( wp_create_nonce( 'teatatu_events_preview_feed' ) ); ?> );
			btn.disabled = true;
			status.textContent = <?php echo wp_json_encode( __( 'Fetching…', 'teatatu-events' ) ); ?>;
			out.innerHTML = '';
			fetch( <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>, { method: 'POST', credentials: 'same-origin', body: data } )
				.then( function ( r ) { return r.json(); } )
				.then( function ( json ) {
					btn.disabled = false;
					if ( ! json || ! json.success ) {
						status.textContent = json && json.data && json.data.message ? json.data.message : <?php echo wp_json_encode( __( 'Preview failed.', 'teatatu-events' ) ); ?>;
						return;
					}
					status.textContent = json.data.rows.length + ' ' + <?php echo wp_json_encode( __( 'items (nothing was saved).', 'teatatu-events' ) ); ?>;
					var table = document.createElement( 'table' );
					table.className = 'wp-list-table widefat striped';
					var head = '<thead><tr><th><?php echo esc_js( __( 'Title', 'teatatu-events' ) ); ?></th><th><?php echo esc_js( __( 'When', 'teatatu-events' ) ); ?></th><th><?php echo esc_js( __( 'Place', 'teatatu-events' ) ); ?></th><th><?php echo esc_js( __( 'Neighbourhood', 'teatatu-events' ) ); ?></th><th><?php echo esc_js( __( 'Tags', 'teatatu-events' ) ); ?></th><th><?php echo esc_js( __( 'Result', 'teatatu-events' ) ); ?></th></tr></thead>';
					table.innerHTML = head + '<tbody></tbody>';
					json.data.rows.forEach( function ( row ) {
						var tr = document.createElement( 'tr' );
						[ row.title, row.when, row.place, row.nbhd, row.tags, row.skip ? <?php echo wp_json_encode( __( 'Skipped: ', 'teatatu-events' ) ); ?> + row.skip : <?php echo wp_json_encode( __( 'Would import', 'teatatu-events' ) ); ?> ].forEach( function ( v ) {
							var td = document.createElement( 'td' );
							td.textContent = v || '—';
							tr.appendChild( td );
						} );
						table.querySelector( 'tbody' ).appendChild( tr );
					} );
					out.appendChild( table );
				} )
				.catch( function () { btn.disabled = false; status.textContent = <?php echo wp_json_encode( __( 'Preview failed.', 'teatatu-events' ) ); ?>; } );
		} );
	} )();
	</script>

	<hr />
	<h2><?php echo esc_html( $types[ $type ][1] ); ?></h2>
	<?php
	$feeds = get_posts( array( 'post_type' => $post_type, 'post_status' => 'publish', 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC' ) );
	?>
	<table class="wp-list-table widefat fixed striped">
		<thead><tr>
			<th><?php esc_html_e( 'Label', 'teatatu-events' ); ?></th>
			<th><?php esc_html_e( 'URL', 'teatatu-events' ); ?></th>
			<th><?php esc_html_e( 'Status', 'teatatu-events' ); ?></th>
			<th><?php esc_html_e( 'Last check', 'teatatu-events' ); ?></th>
			<th><?php esc_html_e( 'Actions', 'teatatu-events' ); ?></th>
		</tr></thead>
		<tbody>
		<?php if ( ! $feeds ) : ?>
			<tr><td colspan="5"><?php esc_html_e( 'None configured yet.', 'teatatu-events' ); ?></td></tr>
		<?php endif; ?>
		<?php foreach ( $feeds as $feed ) : ?>
			<?php
			$fc    = teatatu_events_get_feed_config( $feed->ID );
			$last  = (int) get_post_meta( $feed->ID, '_teatatu_events_feed_last_checked', true );
			$error = (string) get_post_meta( $feed->ID, '_teatatu_events_feed_last_error', true );
			$stats = get_post_meta( $feed->ID, '_teatatu_events_feed_last_stats', true );
			$base  = array( 'feed_id' => $feed->ID );
			?>
			<tr>
				<td><?php echo esc_html( $feed->post_title ); ?></td>
				<td style="word-break:break-all;"><?php echo esc_html( $fc['url'] ); ?></td>
				<td>
					<?php echo $fc['active'] ? esc_html__( 'Active', 'teatatu-events' ) : esc_html__( 'Paused', 'teatatu-events' ); ?>
					<?php /* translators: %d: minutes. */ echo esc_html( ' · ' . sprintf( __( 'every %d min', 'teatatu-events' ), $fc['cadence'] ) ); ?>
					<?php if ( $fc['auto_publish'] ) : ?><br /><span style="color:#b32d2e;"><?php esc_html_e( 'Auto-publish on', 'teatatu-events' ); ?></span><?php endif; ?>
					<?php if ( $fc['auto_apply'] ) : ?><br /><span style="color:#b32d2e;"><?php esc_html_e( 'Auto-apply on', 'teatatu-events' ); ?></span><?php endif; ?>
					<?php if ( 'off' !== $fc['venue_filter'] ) : ?><br /><?php esc_html_e( 'Filtered by venue/neighbourhood', 'teatatu-events' ); ?><?php endif; ?>
				</td>
				<td>
					<?php echo $last ? esc_html( human_time_diff( $last ) . ' ' . __( 'ago', 'teatatu-events' ) ) : esc_html__( 'Never', 'teatatu-events' ); ?>
					<?php if ( is_array( $stats ) ) : ?><br /><span class="description"><?php echo esc_html( teatatu_events_feed_stats_line( $stats ) ); ?></span><?php endif; ?>
					<?php if ( $error ) : ?><br /><span style="color:#b32d2e;"><?php echo esc_html( $error ); ?></span><?php endif; ?>
				</td>
				<td>
					<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'teatatu-events', 'tab' => 'feed_' . $type, 'edit' => $feed->ID ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Edit', 'teatatu-events' ); ?></a>
					| <a href="<?php echo esc_url( wp_nonce_url( add_query_arg( array_merge( $base, array( 'action' => 'teatatu_events_check_feed_now' ) ), admin_url( 'admin-post.php' ) ), 'teatatu_events_check_feed_now_' . $feed->ID ) ); ?>"><?php esc_html_e( 'Check now', 'teatatu-events' ); ?></a>
					| <a href="<?php echo esc_url( wp_nonce_url( add_query_arg( array_merge( $base, array( 'action' => 'teatatu_events_toggle_feed' ) ), admin_url( 'admin-post.php' ) ), 'teatatu_events_toggle_feed_' . $feed->ID ) ); ?>"><?php echo $fc['active'] ? esc_html__( 'Pause', 'teatatu-events' ) : esc_html__( 'Activate', 'teatatu-events' ); ?></a>
					| <a href="<?php echo esc_url( wp_nonce_url( add_query_arg( array_merge( $base, array( 'action' => 'teatatu_events_delete_feed' ) ), admin_url( 'admin-post.php' ) ), 'teatatu_events_delete_feed_' . $feed->ID ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Delete this feed configuration? Events it already imported are kept.', 'teatatu-events' ) ); ?>');"><?php esc_html_e( 'Delete', 'teatatu-events' ); ?></a>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<?php
}

add_action( 'admin_post_teatatu_events_save_feed', 'teatatu_events_handle_save_feed' );

/**
 * Saves a feed.
 */
function teatatu_events_handle_save_feed() {
	if ( ! isset( $_POST['teatatu_events_feed_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['teatatu_events_feed_nonce'] ) ), 'teatatu_events_save_feed' ) ) {
		wp_die( esc_html__( 'Security check failed.', 'teatatu-events' ) );
	}
	if ( ! current_user_can( 'manage_teatatu_events_feeds' ) ) {
		wp_die( esc_html__( 'You do not have permission to do that.', 'teatatu-events' ), 403 );
	}
	$type  = isset( $_POST['feed_type'] ) ? sanitize_key( $_POST['feed_type'] ) : '';
	$types = teatatu_events_feed_types();
	if ( ! isset( $types[ $type ] ) ) {
		wp_die( esc_html__( 'Unknown feed type.', 'teatatu-events' ) );
	}
	$feed_id = absint( $_POST['feed_id'] ?? 0 );
	if ( $feed_id && $types[ $type ][0] !== get_post_type( $feed_id ) ) {
		wp_die( esc_html__( 'That is not a feed of this type.', 'teatatu-events' ) );
	}
	$existing = $feed_id ? get_post_meta( $feed_id, '_teatatu_events_feed_config', true ) : array();
	$config   = teatatu_events_sanitize_feed_config_from_request( $type, is_array( $existing ) ? $existing : array() );
	$back     = add_query_arg( array( 'page' => 'teatatu-events', 'tab' => 'feed_' . $type ), admin_url( 'admin.php' ) );
	if ( ! $config['url'] ) {
		teatatu_events_admin_error( __( 'A URL is required.', 'teatatu-events' ) );
		wp_safe_redirect( $feed_id ? add_query_arg( 'edit', $feed_id, $back ) : $back );
		exit;
	}
	if ( 'html' === $type && ! $config['item_selector']['tag'] && ! $config['item_selector']['attr_value'] ) {
		teatatu_events_admin_error( __( 'HTML feeds need a Repeating Item selector.', 'teatatu-events' ) );
		wp_safe_redirect( $feed_id ? add_query_arg( 'edit', $feed_id, $back ) : $back );
		exit;
	}
	$label   = sanitize_text_field( wp_unslash( $_POST['label'] ?? '' ) );
	$postarr = array(
		'post_title'  => $label ? $label : $config['url'],
		'post_status' => 'publish',
	);
	if ( $feed_id ) {
		$postarr['ID'] = $feed_id;
		$result        = wp_update_post( $postarr, true );
	} else {
		$postarr['post_type'] = $types[ $type ][0];
		$result               = wp_insert_post( $postarr, true );
	}
	if ( is_wp_error( $result ) ) {
		teatatu_events_admin_error( teatatu_events_format_wp_error( $result ) );
		wp_safe_redirect( $back );
		exit;
	}
	update_post_meta( $result, '_teatatu_events_feed_config', $config );
	teatatu_events_admin_notice( __( 'Feed saved.', 'teatatu-events' ) );
	wp_safe_redirect( $back );
	exit;
}

add_action( 'admin_post_teatatu_events_toggle_feed', 'teatatu_events_handle_toggle_feed' );

/**
 * Pauses or activates a feed.
 */
function teatatu_events_handle_toggle_feed() {
	$id = isset( $_GET['feed_id'] ) ? absint( $_GET['feed_id'] ) : 0;
	check_admin_referer( 'teatatu_events_toggle_feed_' . $id );
	$type = teatatu_events_feed_type_of( $id );
	if ( ! $type || ! current_user_can( 'manage_teatatu_events_feeds' ) ) {
		wp_die( esc_html__( 'You do not have permission to do that.', 'teatatu-events' ), 403 );
	}
	$config           = get_post_meta( $id, '_teatatu_events_feed_config', true );
	$config           = is_array( $config ) ? $config : array();
	$config['active'] = empty( $config['active'] ) ? 1 : 0;
	update_post_meta( $id, '_teatatu_events_feed_config', $config );
	teatatu_events_admin_notice( __( 'Feed updated.', 'teatatu-events' ) );
	wp_safe_redirect( add_query_arg( array( 'page' => 'teatatu-events', 'tab' => 'feed_' . $type ), admin_url( 'admin.php' ) ) );
	exit;
}

add_action( 'admin_post_teatatu_events_delete_feed', 'teatatu_events_handle_delete_feed' );

/**
 * Deletes a feed config (imported events are kept).
 */
function teatatu_events_handle_delete_feed() {
	$id = isset( $_GET['feed_id'] ) ? absint( $_GET['feed_id'] ) : 0;
	check_admin_referer( 'teatatu_events_delete_feed_' . $id );
	$type = teatatu_events_feed_type_of( $id );
	if ( ! $type || ! current_user_can( 'manage_teatatu_events_feeds' ) ) {
		wp_die( esc_html__( 'You do not have permission to do that.', 'teatatu-events' ), 403 );
	}
	wp_delete_post( $id, true );
	teatatu_events_admin_notice( __( 'Feed deleted. Events it imported are kept.', 'teatatu-events' ) );
	wp_safe_redirect( add_query_arg( array( 'page' => 'teatatu-events', 'tab' => 'feed_' . $type ), admin_url( 'admin.php' ) ) );
	exit;
}
