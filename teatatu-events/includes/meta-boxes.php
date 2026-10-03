<?php
/**
 * The "Event Details" fields: one renderer shared by the native edit screen
 * meta box and the plugin's own maintenance page, plus the meta box's save
 * handler and its status panel (neighbourhood, flags, series, draft copies,
 * linked-event usage, pending updates).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the event detail inputs (dates, place, status, tickets, links,
 * image). Names are prefixed `tte_` and read back by
 * teatatu_events_fields_from_form().
 *
 * @param int   $post_id Event ID (0 for a new event).
 * @param array $stashed Previously submitted values to refill after an error.
 */
function teatatu_events_render_event_fields( $post_id, $stashed = array() ) {
	$f       = $post_id ? teatatu_events_get_fields( $post_id ) : teatatu_events_field_defaults();
	$val     = function ( $key, $default ) use ( $stashed ) {
		return array_key_exists( $key, $stashed ) ? $stashed[ $key ] : $default;
	};
	$start   = (int) $f['start'];
	$end     = (int) $f['end'];
	$all_day = (bool) $val( 'tte_all_day', $f['all_day'] );
	$venue   = $post_id ? teatatu_events_first_term_id( $post_id, 'teatatu_events_venue' ) : 0;
	$venue   = (int) $val( 'tte_venue', $venue );
	$override = $post_id ? (string) get_post_meta( $post_id, teatatu_events_mk( 'nbhd_override' ), true ) : '';
	$image   = $val( 'tte_image_url', $f['image_url'] );
	?>
	<table class="form-table tte-event-fields" role="presentation">
		<tr>
			<th><label for="tte_start_date"><?php esc_html_e( 'Starts', 'teatatu-events' ); ?> *</label></th>
			<td>
				<input type="date" id="tte_start_date" name="tte_start_date" value="<?php echo esc_attr( $val( 'tte_start_date', $start ? wp_date( 'Y-m-d', $start ) : '' ) ); ?>" required />
				<input type="time" id="tte_start_time" name="tte_start_time" class="tte-time" value="<?php echo esc_attr( $val( 'tte_start_time', $start && ! $f['all_day'] ? wp_date( 'H:i', $start ) : '' ) ); ?>" />
				<label style="margin-left:12px;"><input type="checkbox" id="tte_all_day" name="tte_all_day" value="1" <?php checked( $all_day ); ?> /> <?php esc_html_e( 'All day', 'teatatu-events' ); ?></label>
			</td>
		</tr>
		<tr>
			<th><label for="tte_end_date"><?php esc_html_e( 'Ends', 'teatatu-events' ); ?></label></th>
			<td>
				<input type="date" id="tte_end_date" name="tte_end_date" value="<?php echo esc_attr( $val( 'tte_end_date', $end ? wp_date( 'Y-m-d', $end ) : '' ) ); ?>" />
				<input type="time" id="tte_end_time" name="tte_end_time" class="tte-time" value="<?php echo esc_attr( $val( 'tte_end_time', $end && ! $f['all_day'] ? wp_date( 'H:i', $end ) : '' ) ); ?>" />
				<p class="description"><?php printf( esc_html__( 'Leave blank to use the default duration (%d minutes). For multi-day events set the last day.', 'teatatu-events' ), (int) teatatu_events_setting( 'default_duration' ) ); ?></p>
			</td>
		</tr>
		<tr>
			<th><label for="tte_venue"><?php esc_html_e( 'Location (venue)', 'teatatu-events' ); ?></label></th>
			<td>
				<select id="tte_venue" name="tte_venue">
					<option value="0"><?php esc_html_e( '— No venue: use the address below —', 'teatatu-events' ); ?></option>
					<?php foreach ( teatatu_events_venue_choices() as $choice ) : ?>
						<option value="<?php echo esc_attr( $choice['id'] ); ?>" data-address="<?php echo esc_attr( $choice['address'] ); ?>" <?php selected( $venue, $choice['id'] ); ?>><?php echo esc_html( $choice['name'] ); ?></option>
					<?php endforeach; ?>
				</select>
				<p class="description tte-venue-address"><?php echo $venue ? esc_html( teatatu_events_format_address( $venue ) ) : ''; ?></p>
			</td>
		</tr>
		<tr>
			<th><label for="tte_room"><?php esc_html_e( 'Room', 'teatatu-events' ); ?></label></th>
			<td><input type="text" id="tte_room" name="tte_room" class="regular-text" value="<?php echo esc_attr( $val( 'tte_room', $f['room'] ) ); ?>" placeholder="<?php esc_attr_e( 'e.g. Hall B', 'teatatu-events' ); ?>" /></td>
		</tr>
		<tr class="tte-address-row"<?php echo $venue ? ' style="display:none"' : ''; ?>>
			<th><label for="tte_address"><?php esc_html_e( 'Address', 'teatatu-events' ); ?></label></th>
			<td>
				<textarea id="tte_address" name="tte_address" class="large-text" rows="2"><?php echo esc_textarea( $val( 'tte_address', $f['address'] ) ); ?></textarea>
				<p class="description"><?php esc_html_e( 'Only used when no venue is chosen.', 'teatatu-events' ); ?></p>
				<p>
					<label for="tte_nbhd_override"><?php esc_html_e( 'Neighbourhood', 'teatatu-events' ); ?></label>
					<select id="tte_nbhd_override" name="tte_nbhd_override">
						<option value=""><?php esc_html_e( 'Work it out from the address', 'teatatu-events' ); ?></option>
						<?php foreach ( teatatu_events_neighbourhood_defs() as $slug => $def ) : ?>
							<?php $term = get_term( teatatu_events_neighbourhood_term_id( $slug ) ); ?>
							<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $val( 'tte_nbhd_override', $override ), $slug ); ?>><?php echo esc_html( $term && ! is_wp_error( $term ) ? $term->name : $def[0] ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
			</td>
		</tr>
		<tr>
			<th><label for="tte_status"><?php esc_html_e( 'Event status', 'teatatu-events' ); ?></label></th>
			<td>
				<select id="tte_status" name="tte_status">
					<?php foreach ( teatatu_events_statuses() as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $val( 'tte_status', $f['status'] ), $key ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
		</tr>
		<tr>
			<th><label for="tte_ticket_url"><?php esc_html_e( 'Tickets / registration URL', 'teatatu-events' ); ?></label></th>
			<td><input type="url" id="tte_ticket_url" name="tte_ticket_url" class="large-text" value="<?php echo esc_attr( $val( 'tte_ticket_url', $f['ticket_url'] ) ); ?>" /></td>
		</tr>
		<tr>
			<th><label for="tte_price"><?php esc_html_e( 'Price', 'teatatu-events' ); ?></label></th>
			<td>
				<input type="text" id="tte_price" name="tte_price" class="regular-text" value="<?php echo esc_attr( $val( 'tte_price', $f['price'] ) ); ?>" placeholder="<?php esc_attr_e( 'e.g. $20–$35, Koha', 'teatatu-events' ); ?>" />
				<label style="margin-left:12px;"><input type="checkbox" name="tte_is_free" value="1" <?php checked( (bool) $val( 'tte_is_free', $f['is_free'] ) ); ?> /> <?php esc_html_e( 'Free', 'teatatu-events' ); ?></label>
			</td>
		</tr>
		<tr>
			<th><label for="tte_read_more_url"><?php esc_html_e( 'Read More URL', 'teatatu-events' ); ?></label></th>
			<td><input type="url" id="tte_read_more_url" name="tte_read_more_url" class="large-text" value="<?php echo esc_attr( $val( 'tte_read_more_url', $f['read_more_url'] ) ); ?>" placeholder="https://organiser.example/event" /></td>
		</tr>
		<tr>
			<th><label for="tte_link_mode"><?php esc_html_e( 'Cards link to', 'teatatu-events' ); ?></label></th>
			<td>
				<select id="tte_link_mode" name="tte_link_mode">
					<?php foreach ( teatatu_events_link_modes() as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $val( 'tte_link_mode', $f['link_mode'] ), $key ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
		</tr>
		<tr>
			<th><label for="tte_image_url"><?php esc_html_e( 'Image URL', 'teatatu-events' ); ?></label></th>
			<td>
				<input type="url" id="tte_image_url" name="tte_image_url" class="large-text" value="<?php echo esc_attr( $image ); ?>" placeholder="<?php esc_attr_e( "Leave blank to use the Source's or Venue's default image", 'teatatu-events' ); ?>" />
				<?php $preview = $image ? $image : ( $post_id ? teatatu_events_get_image_url( $post_id ) : '' ); ?>
				<img class="tte-image-preview" src="<?php echo esc_url( $preview ); ?>" alt="" style="display:<?php echo $preview ? 'block' : 'none'; ?>;max-width:200px;height:auto;margin-top:6px;" />
			</td>
		</tr>
		<?php if ( is_multisite() ) : ?>
			<tr>
				<th><?php esc_html_e( 'Other sites', 'teatatu-events' ); ?></th>
				<td><label><input type="checkbox" name="tte_no_linking" value="1" <?php checked( (bool) $val( 'tte_no_linking', $f['no_linking'] ) ); ?> /> <?php esc_html_e( "Don't allow other sites to show this event", 'teatatu-events' ); ?></label></td>
			</tr>
		<?php endif; ?>
	</table>
	<script>
	( function () {
		var allDay = document.getElementById( 'tte_all_day' );
		var venue  = document.getElementById( 'tte_venue' );
		var image  = document.getElementById( 'tte_image_url' );
		function syncAllDay() {
			document.querySelectorAll( '.tte-time' ).forEach( function ( el ) { el.style.display = allDay.checked ? 'none' : ''; } );
		}
		function syncVenue() {
			var opt = venue.options[ venue.selectedIndex ];
			document.querySelectorAll( '.tte-address-row' ).forEach( function ( row ) { row.style.display = '0' === venue.value ? '' : 'none'; } );
			document.querySelectorAll( '.tte-venue-address' ).forEach( function ( p ) { p.textContent = opt && opt.dataset.address ? opt.dataset.address : ''; } );
		}
		if ( allDay ) { allDay.addEventListener( 'change', syncAllDay ); syncAllDay(); }
		if ( venue ) { venue.addEventListener( 'change', syncVenue ); }
		if ( image ) {
			image.addEventListener( 'input', function () {
				var p = document.querySelector( '.tte-image-preview' );
				if ( p ) { p.src = image.value.trim(); p.style.display = image.value.trim() ? 'block' : 'none'; }
			} );
		}
	} )();
	</script>
	<?php
}

/**
 * Saves the shared event inputs (fields, venue, neighbourhood override).
 *
 * @param int   $post_id Event ID.
 * @param array $src     Unslashed request data.
 * @return true|WP_Error
 */
function teatatu_events_save_event_form( $post_id, $src ) {
	$result = teatatu_events_save_fields( $post_id, teatatu_events_fields_from_form( $src ) );
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	if ( isset( $src['tte_venue'] ) && current_user_can( 'assign_teatatu_events_venues' ) ) {
		$venue = absint( $src['tte_venue'] );
		wp_set_object_terms( $post_id, $venue ? array( $venue ) : array(), 'teatatu_events_venue' );
	}
	if ( isset( $src['tte_nbhd_override'] ) ) {
		$slug = sanitize_key( $src['tte_nbhd_override'] );
		if ( $slug && in_array( $slug, teatatu_events_neighbourhood_slugs(), true ) ) {
			update_post_meta( $post_id, teatatu_events_mk( 'nbhd_override' ), $slug );
		} else {
			delete_post_meta( $post_id, teatatu_events_mk( 'nbhd_override' ) );
		}
	}
	return true;
}

add_action( 'add_meta_boxes', 'teatatu_events_add_meta_boxes' );

/**
 * Registers the meta boxes on the native event edit screen.
 */
function teatatu_events_add_meta_boxes() {
	add_meta_box( 'teatatu_events_details', __( 'Event Details', 'teatatu-events' ), 'teatatu_events_render_meta_box', 'teatatu_event', 'normal', 'high' );
	add_meta_box( 'teatatu_events_status_panel', __( 'Event Status & Tools', 'teatatu-events' ), 'teatatu_events_render_status_panel', 'teatatu_event', 'side', 'high' );
}

/**
 * Renders the "Event Details" meta box.
 *
 * @param WP_Post $post Current post.
 */
function teatatu_events_render_meta_box( $post ) {
	wp_nonce_field( 'teatatu_events_save_meta_box', 'teatatu_events_meta_box_nonce' );
	teatatu_events_render_event_fields( 'auto-draft' === $post->post_status ? 0 : $post->ID );
}

/**
 * Renders the side panel: neighbourhood, flags, series, draft copy and
 * duplicate tools, linked-event usage and pending updates.
 *
 * @param WP_Post $post Current post.
 */
function teatatu_events_render_status_panel( $post ) {
	if ( 'auto-draft' === $post->post_status ) {
		echo '<p>' . esc_html__( 'Save the event to see its status and tools.', 'teatatu-events' ) . '</p>';
		return;
	}
	teatatu_events_render_event_status_summary( $post->ID );
}

/**
 * Status summary + tools for one event (used by the meta box and the
 * maintenance page's edit form).
 *
 * @param int $post_id Event ID.
 */
function teatatu_events_render_event_status_summary( $post_id ) {
	$nbhd = teatatu_events_first_term( $post_id, 'teatatu_events_neighbourhood' );
	$via  = (string) get_post_meta( $post_id, teatatu_events_mk( 'nbhd_via' ), true );
	echo '<p><strong>' . esc_html__( 'Neighbourhood:', 'teatatu-events' ) . '</strong> ';
	if ( $nbhd ) {
		echo esc_html( $nbhd->name . ( $via ? ' — ' . teatatu_events_neighbourhood_via_label( $via ) : '' ) );
	} else {
		esc_html_e( 'none', 'teatatu-events' );
	}
	echo '</p>';

	$flags = array();
	if ( get_post_meta( $post_id, teatatu_events_mk( 'needs_venue' ), true ) ) {
		$flags[] = __( 'Needs venue: imported address did not match a venue.', 'teatatu-events' );
	}
	if ( get_post_meta( $post_id, teatatu_events_mk( 'needs_nbhd' ), true ) ) {
		$flags[] = __( 'Needs neighbourhood: the address could not be placed.', 'teatatu-events' );
	}
	if ( get_post_meta( $post_id, teatatu_events_mk( 'removed_at_source' ), true ) ) {
		$flags[] = __( 'Removed at source: this event disappeared from its feed and was marked Cancelled.', 'teatatu-events' );
	}
	if ( is_array( get_post_meta( $post_id, teatatu_events_mk( 'pending_update' ), true ) ) ) {
		$flags[] = __( 'A feed update is waiting for review (Pending Updates tab).', 'teatatu-events' );
	}
	foreach ( $flags as $flag ) {
		echo '<p class="tte-flag" style="color:#b32d2e;">' . esc_html( $flag ) . '</p>';
	}

	$series_id = (int) get_post_meta( $post_id, teatatu_events_mk( 'series_id' ), true );
	if ( $series_id ) {
		$detached = (bool) get_post_meta( $post_id, teatatu_events_mk( 'detached' ), true );
		echo '<p>' . esc_html( sprintf( /* translators: %s: series title */ __( 'Part of the series "%s".', 'teatatu-events' ), get_the_title( $series_id ) ) );
		if ( $detached ) {
			echo ' ' . esc_html__( 'Edited separately from series.', 'teatatu-events' );
			if ( current_user_can( 'manage_teatatu_events_series' ) ) {
				$url = wp_nonce_url( add_query_arg( array( 'action' => 'teatatu_events_reset_occurrence', 'id' => $post_id ), admin_url( 'admin-post.php' ) ), 'teatatu_events_reset_occurrence_' . $post_id );
				echo ' <a href="' . esc_url( $url ) . '">' . esc_html__( 'Reset to series', 'teatatu-events' ) . '</a>';
			}
		}
		echo '</p>';
	}

	$draft_of = (int) get_post_meta( $post_id, teatatu_events_mk( 'draft_of' ), true );
	if ( $draft_of ) {
		echo '<p><strong>' . esc_html( sprintf( /* translators: %s: original title */ __( 'Draft changes to: %s', 'teatatu-events' ), get_the_title( $draft_of ) ) ) . '</strong><br />';
		esc_html_e( 'Publishing this draft merges its changes into the live event.', 'teatatu-events' );
		$discard = wp_nonce_url( add_query_arg( array( 'action' => 'teatatu_events_discard_draft', 'id' => $draft_of ), admin_url( 'admin-post.php' ) ), 'teatatu_events_discard_draft_' . $draft_of );
		echo '<br /><a href="' . esc_url( $discard ) . '">' . esc_html__( 'Discard draft', 'teatatu-events' ) . '</a></p>';
		return;
	}

	$tools = teatatu_events_row_actions( $post_id, false );
	if ( $tools ) {
		echo '<p>' . implode( ' | ', $tools ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
	}

	if ( is_multisite() ) {
		$linking = teatatu_events_sites_linking_to( get_current_blog_id(), $post_id );
		if ( $linking ) {
			$names = array_map(
				function ( $site_id ) {
					$info = teatatu_events_site_info( $site_id );
					return $info['name'];
				},
				$linking
			);
			echo '<p>' . esc_html( sprintf( /* translators: %s: site names */ __( 'Shown on: %s', 'teatatu-events' ), implode( ', ', $names ) ) ) . '</p>';
		}
	}
}

add_action( 'save_post_teatatu_event', 'teatatu_events_save_meta_box', 10, 2 );

/**
 * Saves the "Event Details" meta box.
 *
 * @param int     $post_id Post ID.
 * @param WP_Post $post    Post object.
 */
function teatatu_events_save_meta_box( $post_id, $post ) {
	if ( ! isset( $_POST['teatatu_events_meta_box_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['teatatu_events_meta_box_nonce'] ) ), 'teatatu_events_save_meta_box' ) ) {
		return;
	}
	if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	if ( '' === trim( (string) wp_unslash( $_POST['tte_start_date'] ?? '' ) ) ) {
		return; // Nothing entered yet (e.g. first autosave of a new event).
	}
	$src    = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised field by field in teatatu_events_save_fields().
	$result = teatatu_events_batch_write(
		$post_id,
		function ( $id ) use ( $src ) {
			$r = teatatu_events_save_event_form( $id, $src );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
			// A manual edit to a series occurrence detaches it from the series.
			if ( get_post_meta( $id, teatatu_events_mk( 'series_id' ), true ) && empty( $GLOBALS['teatatu_events_series_sync'] ) ) {
				update_post_meta( $id, teatatu_events_mk( 'detached' ), 1 );
			}
			return $id;
		}
	);
	if ( is_wp_error( $result ) ) {
		set_transient( 'teatatu_events_notice_' . get_current_user_id(), $result->get_error_message(), 45 );
	}
}

add_action( 'admin_notices', 'teatatu_events_show_meta_box_notice' );

/**
 * Displays a validation error stashed by the meta box saver.
 */
function teatatu_events_show_meta_box_notice() {
	$key    = 'teatatu_events_notice_' . get_current_user_id();
	$notice = get_transient( $key );
	if ( ! $notice ) {
		return;
	}
	delete_transient( $key );
	printf( '<div class="notice notice-error is-dismissible"><p>%s</p></div>', esc_html( $notice ) );
}
