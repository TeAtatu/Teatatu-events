<?php
/**
 * Columns for the native Events list table (edit.php?post_type=teatatu_event):
 * When (sortable, default start ascending), Status, Image and Origin. Venue,
 * Neighbourhood and Event Tags columns come from show_admin_column.
 *
 * Also Quick Edit's "Event details": dates, event status, venue, room,
 * neighbourhood, price, ticket URL and Event Tags, saved through the same
 * single writer as the full edit screen.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'manage_teatatu_event_posts_columns', 'teatatu_events_admin_columns' );

/**
 * Inserts custom columns after the title column.
 *
 * @param array $columns Existing columns.
 * @return array
 */
function teatatu_events_admin_columns( $columns ) {
	$new = array();
	foreach ( $columns as $key => $label ) {
		if ( 'date' === $key ) {
			continue;
		}
		$new[ $key ] = $label;
		if ( 'title' === $key ) {
			$new['tte_when']   = __( 'When', 'teatatu-events' );
			$new['tte_status'] = __( 'Status', 'teatatu-events' );
			$new['tte_image']  = __( 'Image', 'teatatu-events' );
			$new['tte_origin'] = __( 'Origin', 'teatatu-events' );
		}
	}
	return $new;
}

add_action( 'manage_teatatu_event_posts_custom_column', 'teatatu_events_render_admin_column', 10, 2 );

/**
 * Renders a custom column cell.
 *
 * @param string $column  Column key.
 * @param int    $post_id Post ID.
 */
function teatatu_events_render_admin_column( $column, $post_id ) {
	switch ( $column ) {
		case 'tte_when':
			echo esc_html( teatatu_events_format_when( teatatu_events_get_start( $post_id ), teatatu_events_get_end( $post_id ), teatatu_events_is_all_day( $post_id ) ) );
			if ( teatatu_events_is_past( $post_id ) ) {
				echo '<br /><span class="description">' . esc_html__( 'Finished', 'teatatu-events' ) . '</span>';
			}
			echo '<div class="hidden tte-qe-data" data-qe="' . esc_attr( wp_json_encode( teatatu_events_quick_edit_values( $post_id ) ) ) . '"></div>';
			break;
		case 'tte_status':
			$statuses = teatatu_events_statuses();
			echo esc_html( $statuses[ teatatu_events_get_status( $post_id ) ] );
			echo teatatu_events_flag_badges( $post_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
			break;
		case 'tte_image':
			$url = teatatu_events_get_image_url( $post_id );
			echo $url ? '<img src="' . esc_url( $url ) . '" alt="" style="width:60px;height:auto;" />' : '&#8212;';
			break;
		case 'tte_origin':
			echo esc_html( teatatu_events_origin_label( $post_id ) );
			break;
	}
}

/**
 * Where an event came from: Local, a feed's label, or a series.
 *
 * @param int $post_id Event ID.
 * @return string
 */
function teatatu_events_origin_label( $post_id ) {
	$series = (int) get_post_meta( $post_id, teatatu_events_mk( 'series_id' ), true );
	if ( $series ) {
		/* translators: %s: series title. */
		return sprintf( __( 'Series: %s', 'teatatu-events' ), get_the_title( $series ) );
	}
	$feed = (int) get_post_meta( $post_id, teatatu_events_mk( 'feed_id' ), true );
	if ( $feed ) {
		$title = get_the_title( $feed );
		/* translators: %s: feed label. */
		return sprintf( __( 'Feed: %s', 'teatatu-events' ), $title ? $title : '#' . $feed );
	}
	return __( 'Local', 'teatatu-events' );
}

/**
 * Small inline badges for an event's attention flags.
 *
 * @param int $post_id Event ID.
 * @return string HTML.
 */
function teatatu_events_flag_badges( $post_id ) {
	$badges = array();
	if ( get_post_meta( $post_id, teatatu_events_mk( 'needs_venue' ), true ) ) {
		$badges[] = __( 'Needs venue', 'teatatu-events' );
	}
	if ( get_post_meta( $post_id, teatatu_events_mk( 'needs_nbhd' ), true ) ) {
		$badges[] = __( 'Needs neighbourhood', 'teatatu-events' );
	}
	if ( get_post_meta( $post_id, teatatu_events_mk( 'removed_at_source' ), true ) ) {
		$badges[] = __( 'Removed at source', 'teatatu-events' );
	}
	$pending_link = '';
	if ( is_array( get_post_meta( $post_id, teatatu_events_mk( 'pending_update' ), true ) ) ) {
		// Links to the change on the Pending Updates tab (hover shows what changed).
		$pending_link = '<br /><a style="color:#b32d2e;" title="' . esc_attr( teatatu_events_pending_update_summary( $post_id ) ) . '" href="' . esc_url( add_query_arg( array( 'page' => 'teatatu-events', 'tab' => 'pending' ), admin_url( 'admin.php' ) ) . '#tte-pending-' . (int) $post_id ) . '">' . esc_html__( 'Feed update pending — review', 'teatatu-events' ) . '</a>';
	}
	if ( teatatu_events_get_draft_copy_id( $post_id ) ) {
		$badges[] = __( 'Draft changes pending', 'teatatu-events' );
	}
	$out = '';
	foreach ( $badges as $badge ) {
		$out .= '<br /><span style="color:#b32d2e;">' . esc_html( $badge ) . '</span>';
	}
	return $out . $pending_link;
}

add_filter( 'manage_edit-teatatu_event_sortable_columns', 'teatatu_events_sortable_columns' );

/**
 * Registers sortable columns.
 *
 * @param array $columns Existing sortable columns.
 * @return array
 */
function teatatu_events_sortable_columns( $columns ) {
	$columns['tte_when'] = 'tte_when';
	return $columns;
}

add_action( 'pre_get_posts', 'teatatu_events_admin_list_sorting' );

/**
 * Sorts the native list by start (ascending by default), keeping events
 * with no start date in the list.
 *
 * @param WP_Query $query Current query.
 */
function teatatu_events_admin_list_sorting( $query ) {
	if ( ! is_admin() || ! $query->is_main_query() || 'teatatu_event' !== $query->get( 'post_type' ) ) {
		return;
	}
	$orderby = $query->get( 'orderby' );
	if ( $orderby && 'tte_when' !== $orderby ) {
		return;
	}
	$meta_query          = (array) $query->get( 'meta_query' );
	$meta_query[]        = array(
		'relation' => 'OR',
		'tte_when' => array(
			'key'     => teatatu_events_mk( 'start' ),
			'type'    => 'NUMERIC',
			'compare' => 'EXISTS',
		),
		array(
			'key'     => teatatu_events_mk( 'start' ),
			'compare' => 'NOT EXISTS',
		),
	);
	$query->set( 'meta_query', $meta_query );
	$query->set( 'orderby', array( 'tte_when' => $query->get( 'order' ) ? $query->get( 'order' ) : 'ASC' ) );
}

// ---------------------------------------------------------------------------
// Quick Edit: event details
// ---------------------------------------------------------------------------

/**
 * Current values for Quick Edit, embedded (hidden) in each row.
 *
 * @param int $post_id Event ID.
 * @return array
 */
function teatatu_events_quick_edit_values( $post_id ) {
	$f       = teatatu_events_get_fields( $post_id );
	$start   = (int) $f['start'];
	$end     = (int) $f['end'];
	$all_day = (bool) $f['all_day'];
	return array(
		'start_date' => $start ? wp_date( 'Y-m-d', $start ) : '',
		'start_time' => $start && ! $all_day ? wp_date( 'H:i', $start ) : '',
		'end_date'   => $end ? wp_date( 'Y-m-d', $end ) : '',
		'end_time'   => $end && ! $all_day ? wp_date( 'H:i', $end ) : '',
		'all_day'    => $all_day,
		'status'     => $f['status'],
		'venue'      => teatatu_events_first_term_id( $post_id, 'teatatu_events_venue' ),
		'room'       => $f['room'],
		'price'      => $f['price'],
		'ticket_url' => $f['ticket_url'],
		'nbhd'       => (string) get_post_meta( $post_id, teatatu_events_mk( 'nbhd_override' ), true ),
		'tags'       => array_map( 'intval', wp_get_object_terms( $post_id, 'teatatu_events_tag', array( 'fields' => 'ids' ) ) ),
	);
}

add_action( 'quick_edit_custom_box', 'teatatu_events_quick_edit_box', 10, 2 );

/**
 * Renders the "Event details" part of Quick Edit (once, with the When column).
 *
 * @param string $column    Column key.
 * @param string $post_type Post type.
 */
function teatatu_events_quick_edit_box( $column, $post_type ) {
	if ( 'teatatu_event' !== $post_type || 'tte_when' !== $column ) {
		return;
	}
	$tags = get_terms( array( 'taxonomy' => 'teatatu_events_tag', 'hide_empty' => false ) );
	?>
	<fieldset class="inline-edit-col-left tte-qe" style="clear:both;width:100%;border-top:1px solid #dcdcde;margin-top:8px;padding-top:4px;">
		<?php wp_nonce_field( 'teatatu_events_quick_edit', 'teatatu_events_qe_nonce' ); ?>
		<style>
			.inline-edit-row fieldset.tte-qe label span.title, .inline-edit-row fieldset.tte-qe .inline-edit-group span.title { width: 7.5em; }
			.inline-edit-row fieldset.tte-qe label span.input-text-wrap { margin-left: 7.5em; }
			.inline-edit-row fieldset.tte-qe input[type="url"] { width: 100%; }
		</style>
		<legend class="inline-edit-legend"><?php esc_html_e( 'Event details', 'teatatu-events' ); ?></legend>
		<div class="inline-edit-col" style="display:flex;flex-wrap:wrap;gap:4px 32px;">
			<div style="flex:1 1 360px;">
				<div class="inline-edit-group" style="margin:.2em 0 .5em;">
					<span class="title"><?php esc_html_e( 'Starts', 'teatatu-events' ); ?></span>
					<input type="date" name="tte_start_date" required aria-label="<?php esc_attr_e( 'Start date', 'teatatu-events' ); ?>" style="width:auto;" />
					<input type="time" name="tte_start_time" class="tte-qe-time" aria-label="<?php esc_attr_e( 'Start time', 'teatatu-events' ); ?>" style="width:auto;" />
					<label style="display:inline;margin-left:8px;"><input type="checkbox" name="tte_all_day" value="1" /> <?php esc_html_e( 'All day', 'teatatu-events' ); ?></label>
				</div>
				<div class="inline-edit-group" style="margin:.2em 0 .5em;">
					<span class="title"><?php esc_html_e( 'Ends', 'teatatu-events' ); ?></span>
					<input type="date" name="tte_end_date" aria-label="<?php esc_attr_e( 'End date', 'teatatu-events' ); ?>" style="width:auto;" />
					<input type="time" name="tte_end_time" class="tte-qe-time" aria-label="<?php esc_attr_e( 'End time', 'teatatu-events' ); ?>" style="width:auto;" />
				</div>
				<label>
					<span class="title"><?php esc_html_e( 'Event status', 'teatatu-events' ); ?></span>
					<select name="tte_status">
						<?php foreach ( teatatu_events_statuses() as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label>
					<span class="title"><?php esc_html_e( 'Price', 'teatatu-events' ); ?></span>
					<span class="input-text-wrap"><input type="text" name="tte_price" placeholder="<?php esc_attr_e( 'e.g. $20–$35, Koha', 'teatatu-events' ); ?>" /></span>
				</label>
				<label>
					<span class="title"><?php esc_html_e( 'Tickets', 'teatatu-events' ); ?></span>
					<span class="input-text-wrap"><input type="url" name="tte_ticket_url" placeholder="https://" /></span>
				</label>
			</div>
			<div style="flex:1 1 360px;">
				<?php if ( current_user_can( 'assign_teatatu_events_venues' ) ) : ?>
					<label>
						<span class="title"><?php esc_html_e( 'Venue', 'teatatu-events' ); ?></span>
						<select name="tte_venue" class="tte-qe-venue" style="max-width:100%;">
							<option value="0"><?php esc_html_e( '— No venue: use the event address —', 'teatatu-events' ); ?></option>
							<?php foreach ( teatatu_events_venue_choices() as $choice ) : ?>
								<option value="<?php echo esc_attr( $choice['id'] ); ?>"><?php echo esc_html( $choice['name'] ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
				<?php endif; ?>
				<label>
					<span class="title"><?php esc_html_e( 'Room', 'teatatu-events' ); ?></span>
					<span class="input-text-wrap"><input type="text" name="tte_room" placeholder="<?php esc_attr_e( 'e.g. Hall B', 'teatatu-events' ); ?>" /></span>
				</label>
				<label class="tte-qe-nbhd-row">
					<span class="title"><?php esc_html_e( 'Neighbourhood', 'teatatu-events' ); ?></span>
					<select name="tte_nbhd_override">
						<option value=""><?php esc_html_e( 'Work it out from the address', 'teatatu-events' ); ?></option>
						<?php foreach ( teatatu_events_neighbourhood_defs() as $slug => $def ) : ?>
							<?php $term = get_term( teatatu_events_neighbourhood_term_id( $slug ) ); ?>
							<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $term && ! is_wp_error( $term ) ? $term->name : $def[0] ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<p class="description tte-qe-nbhd-note" style="margin:0 0 6px;"><?php esc_html_e( 'With a venue, the neighbourhood comes from the venue.', 'teatatu-events' ); ?></p>
				<?php if ( $tags && ! is_wp_error( $tags ) && current_user_can( 'assign_teatatu_events_tags' ) ) : ?>
					<span class="title inline-edit-categories-label"><?php esc_html_e( 'Event Tags', 'teatatu-events' ); ?></span>
					<input type="hidden" name="tte_qe_tags_sent" value="1" />
					<ul class="cat-checklist tte-qe-tags" style="height:auto;max-height:9em;">
						<?php foreach ( $tags as $tag ) : ?>
							<li><label class="selectit"><input type="checkbox" name="tte_qe_tags[]" value="<?php echo esc_attr( $tag->term_id ); ?>" /> <?php echo esc_html( $tag->name ); ?></label></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>
		</div>
	</fieldset>
	<?php
}

add_action( 'admin_footer-edit.php', 'teatatu_events_quick_edit_script' );

/**
 * Fills Quick Edit's event details from the row's embedded values, and
 * relabels core's "Date" (the publish date) so it isn't mistaken for the
 * event's date.
 */
function teatatu_events_quick_edit_script() {
	if ( 'teatatu_event' !== get_current_screen()->post_type ) {
		return;
	}
	?>
	<script>
	( function ( $ ) {
		if ( ! window.inlineEditPost ) {
			return;
		}
		var original = inlineEditPost.edit;
		inlineEditPost.edit = function ( id ) {
			original.apply( this, arguments );
			var postId = typeof id === 'object' ? parseInt( this.getId( id ), 10 ) : parseInt( id, 10 );
			var row    = $( '#edit-' + postId );
			var data   = $( '#post-' + postId ).find( '.tte-qe-data' ).data( 'qe' );
			row.find( '.inline-edit-date legend' ).text( <?php echo wp_json_encode( __( 'Published', 'teatatu-events' ) ); ?> );
			if ( ! data ) {
				return;
			}
			row.find( '[name="tte_start_date"]' ).val( data.start_date );
			row.find( '[name="tte_start_time"]' ).val( data.start_time );
			row.find( '[name="tte_end_date"]' ).val( data.end_date );
			row.find( '[name="tte_end_time"]' ).val( data.end_time );
			row.find( '[name="tte_all_day"]' ).prop( 'checked', !! data.all_day );
			row.find( '[name="tte_status"]' ).val( data.status );
			row.find( '[name="tte_venue"]' ).val( String( data.venue || 0 ) );
			row.find( '[name="tte_room"]' ).val( data.room );
			row.find( '[name="tte_price"]' ).val( data.price );
			row.find( '[name="tte_ticket_url"]' ).val( data.ticket_url );
			row.find( '[name="tte_nbhd_override"]' ).val( data.nbhd || '' );
			row.find( '.tte-qe-tags input' ).each( function () {
				this.checked = data.tags.indexOf( parseInt( this.value, 10 ) ) !== -1;
			} );
			var sync = function () {
				var allDay   = row.find( '[name="tte_all_day"]' ).is( ':checked' );
				var hasVenue = row.find( '[name="tte_venue"]' ).length && '0' !== row.find( '[name="tte_venue"]' ).val();
				row.find( '.tte-qe-time' ).prop( 'disabled', allDay );
				row.find( '[name="tte_nbhd_override"]' ).prop( 'disabled', hasVenue );
				row.find( '.tte-qe-nbhd-note' ).toggle( hasVenue );
			};
			row.find( '[name="tte_all_day"], [name="tte_venue"]' ).off( 'change.tte' ).on( 'change.tte', sync );
			sync();
		};
	} )( jQuery );
	</script>
	<?php
}

add_action( 'save_post_teatatu_event', 'teatatu_events_save_quick_edit', 10, 2 );

/**
 * Saves Quick Edit's event details. Only the fields Quick Edit shows are
 * written (tickets links, images etc. are left alone); a validation error
 * is shown in the Quick Edit row and nothing is saved.
 *
 * @param int     $post_id Post ID.
 * @param WP_Post $post    Post.
 */
function teatatu_events_save_quick_edit( $post_id, $post ) {
	if ( ! wp_doing_ajax() || ! isset( $_POST['teatatu_events_qe_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['teatatu_events_qe_nonce'] ) ), 'teatatu_events_quick_edit' ) ) {
		return;
	}
	if ( wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	$src    = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised field by field in teatatu_events_save_fields().
	$fields = array_intersect_key( teatatu_events_fields_from_form( $src ), array_flip( array( 'start', 'end', 'all_day', 'status', 'room', 'price', 'ticket_url' ) ) );
	$result = teatatu_events_batch_write(
		$post_id,
		function ( $id ) use ( $src, $fields ) {
			$r = teatatu_events_save_fields( $id, $fields );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
			$venue = null;
			if ( isset( $src['tte_venue'] ) && current_user_can( 'assign_teatatu_events_venues' ) ) {
				$venue = absint( $src['tte_venue'] );
				wp_set_object_terms( $id, $venue ? array( $venue ) : array(), 'teatatu_events_venue' );
			}
			if ( ! $venue && isset( $src['tte_nbhd_override'] ) ) {
				$slug = sanitize_key( $src['tte_nbhd_override'] );
				if ( $slug && in_array( $slug, teatatu_events_neighbourhood_slugs(), true ) ) {
					update_post_meta( $id, teatatu_events_mk( 'nbhd_override' ), $slug );
				} else {
					delete_post_meta( $id, teatatu_events_mk( 'nbhd_override' ) );
				}
			}
			if ( ! empty( $src['tte_qe_tags_sent'] ) && current_user_can( 'assign_teatatu_events_tags' ) ) {
				$tags = array_values( array_filter( array_map( 'absint', (array) ( $src['tte_qe_tags'] ?? array() ) ) ) );
				wp_set_object_terms( $id, $tags, 'teatatu_events_tag' );
			}
			// A manual edit to a series occurrence detaches it from the series.
			if ( get_post_meta( $id, teatatu_events_mk( 'series_id' ), true ) && empty( $GLOBALS['teatatu_events_series_sync'] ) ) {
				update_post_meta( $id, teatatu_events_mk( 'detached' ), 1 );
			}
			return $id;
		}
	);
	if ( is_wp_error( $result ) ) {
		// Quick Edit shows any non-row response as its error message.
		wp_die( esc_html( $result->get_error_message() ) );
	}
}

