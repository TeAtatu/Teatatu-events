<?php
/**
 * Venues and Neighbourhoods tabs.
 *
 * Venues: Location (name) + structured address (NZ Post segments), aliases,
 * map/website links, default image, coordinates, and the derived
 * neighbourhood (with an editor override). A "Paste address" box splits a
 * one-line address into the fields using the same parser as imports.
 *
 * Neighbourhoods: rename the three areas, edit their descriptions, add
 * the street rules (loaded from the bundled street list, then editable), and
 * test an address.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the Venues tab.
 */
function teatatu_events_render_venues_tab() {
	if ( ! current_user_can( 'manage_teatatu_events_venues' ) ) {
		echo '<p>' . esc_html__( 'You do not have permission to manage venues.', 'teatatu-events' ) . '</p>';
		return;
	}
	// phpcs:disable WordPress.Security.NonceVerification.Recommended
	$editing_id = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0;
	$from_event = isset( $_GET['from_event'] ) ? absint( $_GET['from_event'] ) : 0;
	$paste      = isset( $_GET['paste'] ) ? sanitize_textarea_field( rawurldecode( wp_unslash( $_GET['paste'] ) ) ) : '';
	// phpcs:enable
	$editing = $editing_id ? get_term( $editing_id, 'teatatu_events_venue' ) : null;
	$editing = ( $editing && ! is_wp_error( $editing ) ) ? $editing : null;
	$a       = $editing ? teatatu_events_get_venue_address( $editing->term_id ) : array_fill_keys( array_keys( teatatu_events_venue_address_keys() ), '' );
	$name    = $editing ? $editing->name : '';
	if ( ! $editing && $paste ) {
		$parsed = teatatu_events_parse_address_text( $paste, teatatu_events_setting( 'default_country' ) );
		$name   = $parsed['name'];
		foreach ( $a as $k => $v ) {
			if ( isset( $parsed[ $k ] ) ) {
				$a[ $k ] = $parsed[ $k ];
			}
		}
	}
	if ( '' === $a['country'] ) {
		$a['country'] = (string) teatatu_events_setting( 'default_country' );
	}
	$meta = function ( $key ) use ( $editing ) {
		return $editing ? (string) get_term_meta( $editing->term_id, $key, true ) : '';
	};
	$labels = array(
		'unit'     => __( 'Unit / level', 'teatatu-events' ),
		'number'   => __( 'Street number', 'teatatu-events' ),
		'street'   => __( 'Street name', 'teatatu-events' ),
		'suburb'   => __( 'Suburb', 'teatatu-events' ),
		'city'     => __( 'Town / city', 'teatatu-events' ),
		'region'   => __( 'Region', 'teatatu-events' ),
		'postcode' => __( 'Postcode', 'teatatu-events' ),
		'country'  => __( 'Country (2 letters)', 'teatatu-events' ),
		'lat'      => __( 'Latitude', 'teatatu-events' ),
		'lng'      => __( 'Longitude', 'teatatu-events' ),
	);
	?>
	<h2><?php echo $editing ? esc_html__( 'Edit Venue', 'teatatu-events' ) : esc_html__( 'Add Venue', 'teatatu-events' ); ?></h2>
	<?php if ( $from_event ) : ?>
		<div class="notice notice-info inline"><p><?php esc_html_e( 'Creating a venue from an imported address. Check the fields below; the event will be assigned to the new venue when you save.', 'teatatu-events' ); ?></p></div>
	<?php endif; ?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'teatatu_events_save_venue', 'teatatu_events_venue_nonce' ); ?>
		<input type="hidden" name="action" value="teatatu_events_save_venue" />
		<input type="hidden" name="term_id" value="<?php echo esc_attr( $editing ? $editing->term_id : 0 ); ?>" />
		<input type="hidden" name="from_event" value="<?php echo esc_attr( $from_event ); ?>" />
		<table class="form-table">
			<tr>
				<th><label for="tte_venue_name"><?php esc_html_e( 'Location (venue name)', 'teatatu-events' ); ?> *</label></th>
				<td><input type="text" id="tte_venue_name" name="name" class="regular-text" required value="<?php echo esc_attr( $name ); ?>" placeholder="<?php esc_attr_e( 'e.g. Te Atatū Community Centre', 'teatatu-events' ); ?>" /></td>
			</tr>
			<tr>
				<th><label for="tte_paste"><?php esc_html_e( 'Paste address', 'teatatu-events' ); ?></label></th>
				<td>
					<input type="text" id="tte_paste" class="large-text" placeholder="<?php esc_attr_e( 'e.g. 595 Te Atatū Road, Te Atatū Peninsula, Auckland 0610', 'teatatu-events' ); ?>" />
					<button type="button" class="button" id="tte_paste_btn"><?php esc_html_e( 'Split into fields', 'teatatu-events' ); ?></button>
					<p class="description"><?php esc_html_e( 'Fills the fields below for you to check before saving.', 'teatatu-events' ); ?></p>
				</td>
			</tr>
			<?php foreach ( $labels as $field => $label ) : ?>
				<tr>
					<th><label for="tte_addr_<?php echo esc_attr( $field ); ?>"><?php echo esc_html( $label ); ?></label></th>
					<td><input type="text" id="tte_addr_<?php echo esc_attr( $field ); ?>" name="addr[<?php echo esc_attr( $field ); ?>]" class="large-text" value="<?php echo esc_attr( $a[ $field ] ); ?>" /></td>
				</tr>
			<?php endforeach; ?>
			<tr>
				<th><?php esc_html_e( 'Neighbourhood', 'teatatu-events' ); ?></th>
				<td>
					<?php
					if ( $editing ) {
						$slug = $meta( 'teatatu_events_addr_neighbourhood' );
						$via  = $meta( 'teatatu_events_addr_neighbourhood_via' );
						$term = $slug ? get_term( teatatu_events_neighbourhood_term_id( $slug ) ) : null;
						echo '<p><strong>' . esc_html( $term && ! is_wp_error( $term ) ? $term->name : __( 'None', 'teatatu-events' ) ) . '</strong>' . ( $via ? ' — ' . esc_html( teatatu_events_neighbourhood_via_label( $via ) ) : '' ) . '</p>';
						if ( $meta( 'teatatu_events_needs_neighbourhood' ) ) {
							echo '<p style="color:#b32d2e;">' . esc_html__( 'This looks like a Te Atatū Peninsula address but no rule placed it. Check the street name and number, or choose below.', 'teatatu-events' ) . '</p>';
						}
					}
					$override = $meta( 'teatatu_events_addr_neighbourhood_override' );
					?>
					<select name="nbhd_override">
						<option value=""><?php esc_html_e( 'Work it out from the address', 'teatatu-events' ); ?></option>
						<?php foreach ( teatatu_events_neighbourhood_slugs() as $slug ) : ?>
							<?php $t = get_term( teatatu_events_neighbourhood_term_id( $slug ) ); ?>
							<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $override, $slug ); ?>><?php echo esc_html( $t && ! is_wp_error( $t ) ? $t->name : $slug ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="tte_aliases"><?php esc_html_e( 'Other names (aliases)', 'teatatu-events' ); ?></label></th>
				<td><input type="text" id="tte_aliases" name="aliases" class="large-text" value="<?php echo esc_attr( $meta( 'teatatu_events_venue_aliases' ) ); ?>" placeholder="<?php esc_attr_e( 'Comma separated, e.g. TA Community Centre, Community Hall', 'teatatu-events' ); ?>" />
				<p class="description"><?php esc_html_e( 'Imports that use any of these names are matched to this venue.', 'teatatu-events' ); ?></p></td>
			</tr>
			<tr>
				<th><label for="tte_map_url"><?php esc_html_e( 'Map link', 'teatatu-events' ); ?></label></th>
				<td><input type="url" id="tte_map_url" name="map_url" class="large-text" value="<?php echo esc_attr( $meta( 'teatatu_events_venue_map_url' ) ); ?>" placeholder="<?php esc_attr_e( 'Leave blank for a Google Maps search of the address', 'teatatu-events' ); ?>" /></td>
			</tr>
			<tr>
				<th><label for="tte_website_url"><?php esc_html_e( 'Website', 'teatatu-events' ); ?></label></th>
				<td><input type="url" id="tte_website_url" name="website_url" class="large-text" value="<?php echo esc_attr( $meta( 'teatatu_events_venue_website_url' ) ); ?>" /></td>
			</tr>
			<tr>
				<th><label for="tte_default_image"><?php esc_html_e( 'Default image URL', 'teatatu-events' ); ?></label></th>
				<td><input type="url" id="tte_default_image" name="default_image" class="large-text" value="<?php echo esc_attr( $meta( 'teatatu_events_venue_default_image' ) ); ?>" /></td>
			</tr>
		</table>
		<?php submit_button( $editing ? __( 'Update Venue', 'teatatu-events' ) : __( 'Add Venue', 'teatatu-events' ) ); ?>
	</form>
	<script>
	( function () {
		var btn = document.getElementById( 'tte_paste_btn' );
		if ( ! btn ) { return; }
		btn.addEventListener( 'click', function () {
			var data = new FormData();
			data.append( 'action', 'teatatu_events_parse_address' );
			data.append( 'nonce', <?php echo wp_json_encode( wp_create_nonce( 'teatatu_events_parse_address' ) ); ?> );
			data.append( 'text', document.getElementById( 'tte_paste' ).value );
			fetch( <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>, { method: 'POST', credentials: 'same-origin', body: data } )
				.then( function ( r ) { return r.json(); } )
				.then( function ( json ) {
					if ( ! json || ! json.success ) { return; }
					Object.keys( json.data ).forEach( function ( k ) {
						var el = 'name' === k ? document.getElementById( 'tte_venue_name' ) : document.getElementById( 'tte_addr_' + k );
						if ( el && json.data[ k ] && ( 'name' !== k || ! el.value ) ) { el.value = json.data[ k ]; }
					} );
				} );
		} );
	} )();
	</script>
	<hr />
	<h2><?php esc_html_e( 'Venues', 'teatatu-events' ); ?></h2>
	<?php $venues = get_terms( array( 'taxonomy' => 'teatatu_events_venue', 'hide_empty' => false ) ); ?>
	<table class="wp-list-table widefat fixed striped">
		<thead><tr><th><?php esc_html_e( 'Location', 'teatatu-events' ); ?></th><th><?php esc_html_e( 'Address', 'teatatu-events' ); ?></th><th><?php esc_html_e( 'Neighbourhood', 'teatatu-events' ); ?></th><th><?php esc_html_e( 'Events', 'teatatu-events' ); ?></th><th><?php esc_html_e( 'Actions', 'teatatu-events' ); ?></th></tr></thead>
		<tbody>
		<?php if ( ! $venues || is_wp_error( $venues ) ) : ?>
			<tr><td colspan="5"><?php esc_html_e( 'No venues yet.', 'teatatu-events' ); ?></td></tr>
		<?php else : ?>
			<?php foreach ( $venues as $venue ) : ?>
				<?php
				$slug = (string) get_term_meta( $venue->term_id, 'teatatu_events_addr_neighbourhood', true );
				$term = $slug ? get_term( teatatu_events_neighbourhood_term_id( $slug ) ) : null;
				?>
				<tr>
					<td><strong><?php echo esc_html( $venue->name ); ?></strong></td>
					<td><?php echo esc_html( teatatu_events_format_address( $venue->term_id ) ); ?></td>
					<td><?php echo esc_html( $term && ! is_wp_error( $term ) ? $term->name : '—' ); ?><?php echo get_term_meta( $venue->term_id, 'teatatu_events_needs_neighbourhood', true ) ? '<br /><span style="color:#b32d2e;">' . esc_html__( 'Needs neighbourhood', 'teatatu-events' ) . '</span>' : ''; ?></td>
					<td><?php echo esc_html( $venue->count ); ?></td>
					<td>
						<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'teatatu-events', 'tab' => 'venues', 'edit' => $venue->term_id ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Edit', 'teatatu-events' ); ?></a>
						| <a href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'teatatu_events_delete_venue', 'term_id' => $venue->term_id ), admin_url( 'admin-post.php' ) ), 'teatatu_events_delete_venue_' . $venue->term_id ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Delete this venue? Its events keep their details but lose the venue.', 'teatatu-events' ) ); ?>');"><?php esc_html_e( 'Delete', 'teatatu-events' ); ?></a>
					</td>
				</tr>
			<?php endforeach; ?>
		<?php endif; ?>
		</tbody>
	</table>
	<?php
}

add_action( 'wp_ajax_teatatu_events_parse_address', 'teatatu_events_ajax_parse_address' );

/**
 * AJAX: splits a pasted address into segments.
 */
function teatatu_events_ajax_parse_address() {
	check_ajax_referer( 'teatatu_events_parse_address', 'nonce' );
	if ( ! current_user_can( 'manage_teatatu_events_venues' ) ) {
		wp_send_json_error();
	}
	wp_send_json_success( teatatu_events_parse_address_text( sanitize_text_field( wp_unslash( $_POST['text'] ?? '' ) ), teatatu_events_setting( 'default_country' ) ) );
}

add_action( 'admin_post_teatatu_events_save_venue', 'teatatu_events_handle_save_venue' );

/**
 * Saves a venue.
 */
function teatatu_events_handle_save_venue() {
	if ( ! isset( $_POST['teatatu_events_venue_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['teatatu_events_venue_nonce'] ) ), 'teatatu_events_save_venue' ) ) {
		wp_die( esc_html__( 'Security check failed.', 'teatatu-events' ) );
	}
	if ( ! current_user_can( 'manage_teatatu_events_venues' ) ) {
		wp_die( esc_html__( 'You do not have permission to do that.', 'teatatu-events' ), 403 );
	}
	$back    = add_query_arg( array( 'page' => 'teatatu-events', 'tab' => 'venues' ), admin_url( 'admin.php' ) );
	$term_id = absint( $_POST['term_id'] ?? 0 );
	$name    = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
	if ( '' === trim( $name ) ) {
		teatatu_events_admin_error( __( 'The Location name is required.', 'teatatu-events' ) );
		wp_safe_redirect( $back );
		exit;
	}
	$result = $term_id ? wp_update_term( $term_id, 'teatatu_events_venue', array( 'name' => $name ) ) : wp_insert_term( $name, 'teatatu_events_venue' );
	if ( is_wp_error( $result ) ) {
		teatatu_events_admin_error( teatatu_events_format_wp_error( $result ) );
		wp_safe_redirect( $back );
		exit;
	}
	$term_id = (int) $result['term_id'];
	$addr    = isset( $_POST['addr'] ) && is_array( $_POST['addr'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['addr'] ) ) : array();
	teatatu_events_save_venue(
		$term_id,
		array_intersect_key( $addr, teatatu_events_venue_address_keys() ),
		array(
			'aliases'       => wp_unslash( $_POST['aliases'] ?? '' ),
			'map_url'       => wp_unslash( $_POST['map_url'] ?? '' ),
			'website_url'   => wp_unslash( $_POST['website_url'] ?? '' ),
			'default_image' => wp_unslash( $_POST['default_image'] ?? '' ),
			'nbhd_override' => wp_unslash( $_POST['nbhd_override'] ?? '' ),
		)
	);
	$from_event = absint( $_POST['from_event'] ?? 0 );
	if ( $from_event && 'teatatu_event' === get_post_type( $from_event ) && current_user_can( 'edit_post', $from_event ) ) {
		wp_set_object_terms( $from_event, array( $term_id ), 'teatatu_events_venue' );
		delete_post_meta( $from_event, teatatu_events_mk( 'needs_venue' ) );
		update_post_meta( $from_event, teatatu_events_mk( 'address' ), '' );
		teatatu_events_refresh_derived( $from_event );
	}
	teatatu_events_admin_notice( __( 'Venue saved.', 'teatatu-events' ) );
	wp_safe_redirect( $back );
	exit;
}

add_action( 'admin_post_teatatu_events_delete_venue', 'teatatu_events_handle_delete_venue' );

/**
 * Deletes a venue.
 */
function teatatu_events_handle_delete_venue() {
	$term_id = isset( $_GET['term_id'] ) ? absint( $_GET['term_id'] ) : 0;
	check_admin_referer( 'teatatu_events_delete_venue_' . $term_id );
	if ( ! $term_id || ! current_user_can( 'manage_teatatu_events_venues' ) ) {
		wp_die( esc_html__( 'You do not have permission to do that.', 'teatatu-events' ), 403 );
	}
	$event_ids = get_objects_in_term( $term_id, 'teatatu_events_venue' );
	wp_delete_term( $term_id, 'teatatu_events_venue' );
	foreach ( is_wp_error( $event_ids ) ? array() : $event_ids as $event_id ) {
		teatatu_events_refresh_derived( $event_id );
	}
	teatatu_events_admin_notice( __( 'Venue deleted.', 'teatatu-events' ) );
	wp_safe_redirect( add_query_arg( array( 'page' => 'teatatu-events', 'tab' => 'venues' ), admin_url( 'admin.php' ) ) );
	exit;
}

// ---------------------------------------------------------------------------
// Neighbourhoods
// ---------------------------------------------------------------------------

/**
 * Renders the Neighbourhoods tab.
 */
function teatatu_events_render_neighbourhoods_tab() {
	if ( ! current_user_can( 'manage_teatatu_events_neighbourhoods' ) ) {
		echo '<p>' . esc_html__( 'You do not have permission to manage neighbourhoods.', 'teatatu-events' ) . '</p>';
		return;
	}
	$test         = isset( $_GET['test_address'] ) ? sanitize_text_field( wp_unslash( $_GET['test_address'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$can_rules    = is_multisite() ? current_user_can( 'manage_network_options' ) : current_user_can( 'manage_teatatu_events_neighbourhoods' );
	$rules        = teatatu_events_street_rules();
	$by_street    = array();
	foreach ( $rules as $row ) {
		$by_street[ teatatu_events_normalize_street( $row['street'] ) ][] = $row;
	}
	$counts = array_merge( array_fill_keys( teatatu_events_neighbourhood_slugs(), 0 ), array( 'outside' => 0, 'split' => 0 ) );
	foreach ( $by_street as $rows_for_street ) {
		$areas = array_values( array_unique( wp_list_pluck( $rows_for_street, 'area' ) ) );
		if ( 1 === count( $areas ) && isset( $counts[ $areas[0] ] ) ) {
			$counts[ $areas[0] ]++;
		} else {
			$counts['split']++;
		}
	}
	$can_slugs = teatatu_events_can_rename_neighbourhood_slugs();
	?>
	<h2><?php esc_html_e( 'Neighbourhoods', 'teatatu-events' ); ?></h2>
	<p><?php esc_html_e( 'Every Te Atatū Peninsula address falls into exactly one of three neighbourhoods, split along Te Atatū Road, Taikata Road and Harbour View Road. Events get their neighbourhood automatically from their venue or address.', 'teatatu-events' ); ?></p>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="tte-nbhd-form">
		<?php wp_nonce_field( 'teatatu_events_save_neighbourhoods', 'teatatu_events_nbhd_nonce' ); ?>
		<input type="hidden" name="action" value="teatatu_events_save_neighbourhoods" />
		<table class="wp-list-table widefat striped">
			<thead><tr><th><?php esc_html_e( 'Name', 'teatatu-events' ); ?></th><th><?php esc_html_e( 'Description', 'teatatu-events' ); ?></th><th><?php esc_html_e( 'Slug', 'teatatu-events' ); ?></th><th><?php esc_html_e( 'Events', 'teatatu-events' ); ?></th></tr></thead>
			<tbody>
			<?php foreach ( teatatu_events_neighbourhood_defs() as $slug => $def ) : ?>
				<?php
				$key  = $def[2];
				$term = get_term( teatatu_events_neighbourhood_term_id( $slug ) );
				if ( ! $term || is_wp_error( $term ) ) {
					continue;
				}
				?>
				<tr>
					<td><input type="text" name="nbhd[<?php echo esc_attr( $key ); ?>][name]" value="<?php echo esc_attr( $term->name ); ?>" /></td>
					<td><textarea name="nbhd[<?php echo esc_attr( $key ); ?>][description]" rows="2" class="large-text"><?php echo esc_textarea( $term->description ); ?></textarea></td>
					<td>
						<?php if ( $can_slugs ) : ?>
							<input type="text" class="tte-nbhd-slug" name="nbhd[<?php echo esc_attr( $key ); ?>][slug]" value="<?php echo esc_attr( $slug ); ?>" data-current="<?php echo esc_attr( $slug ); ?>" pattern="[a-z0-9\-]+" title="<?php esc_attr_e( 'Lower-case letters, numbers and hyphens', 'teatatu-events' ); ?>" required style="width:12em;" />
						<?php else : ?>
							<code><?php echo esc_html( $slug ); ?></code>
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( $term->count ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<p class="description">
			<?php
			if ( $can_slugs ) {
				if ( is_multisite() ) {
					esc_html_e( 'Changing a slug applies to every site on the network. It updates everywhere the slug is used: the neighbourhood on each site, venues, events, series, street rules, feed filters, linked events, and [teatatu_events_…] shortcodes in pages, posts and widgets. The old slug keeps working as an alias, so other links and shortcodes still find the neighbourhood, and old neighbourhood page addresses redirect.', 'teatatu-events' );
				} else {
					esc_html_e( 'Changing a slug updates everywhere it is used: venues, events, series, street rules, feed filters, and [teatatu_events_…] shortcodes in pages, posts and widgets. The old slug keeps working as an alias, so other links and shortcodes still find the neighbourhood, and old neighbourhood page addresses redirect.', 'teatatu-events' );
				}
			} else {
				esc_html_e( 'Slugs are shared by every site on the network, so only network administrators can change them.', 'teatatu-events' );
			}
			?>
		</p>
		<?php submit_button( $can_slugs ? __( 'Save neighbourhoods', 'teatatu-events' ) : __( 'Save names and descriptions', 'teatatu-events' ) ); ?>
	</form>
	<?php if ( $can_slugs ) : ?>
		<script>
		document.getElementById( 'tte-nbhd-form' ).addEventListener( 'submit', function ( e ) {
			var changed = Array.prototype.filter.call( this.querySelectorAll( '.tte-nbhd-slug' ), function ( input ) {
				return input.value.trim() !== input.getAttribute( 'data-current' );
			} );
			if ( changed.length && ! window.confirm( <?php echo wp_json_encode( __( 'Change the neighbourhood slug everywhere it is used? This updates venues, events, street rules, feeds and shortcodes on every site.', 'teatatu-events' ) ); ?> ) ) {
				e.preventDefault();
			}
		} );
		</script>
	<?php endif; ?>

	<h3><?php esc_html_e( 'Test an address', 'teatatu-events' ); ?></h3>
	<form method="get" action="">
		<input type="hidden" name="page" value="teatatu-events" />
		<input type="hidden" name="tab" value="neighbourhoods" />
		<input type="text" name="test_address" class="large-text" style="max-width:520px;" value="<?php echo esc_attr( $test ); ?>" placeholder="<?php esc_attr_e( 'e.g. 12 Taikata Road, Te Atatū Peninsula', 'teatatu-events' ); ?>" />
		<button class="button"><?php esc_html_e( 'Test', 'teatatu-events' ); ?></button>
	</form>
	<?php
	if ( $test ) {
		$parts  = teatatu_events_parse_address_text( $test, teatatu_events_setting( 'default_country' ) );
		$result = teatatu_events_resolve_neighbourhood( $parts );
		$term   = $result['slug'] ? get_term( teatatu_events_neighbourhood_term_id( $result['slug'] ) ) : null;
		echo '<div class="notice notice-info inline"><p>';
		if ( $term && ! is_wp_error( $term ) ) {
			/* translators: 1: neighbourhood, 2: how it was decided. */
			echo esc_html( sprintf( __( '%1$s — %2$s.', 'teatatu-events' ), $term->name, teatatu_events_neighbourhood_via_label( $result['via'] ) ) );
		} elseif ( $result['needs'] ) {
			esc_html_e( 'Unresolved: this looks like a peninsula address but no rule covers it (an event here would be flagged "Needs neighbourhood").', 'teatatu-events' );
		} else {
			esc_html_e( 'Not on Te Atatū Peninsula (no neighbourhood).', 'teatatu-events' );
		}
		echo '<br /><span class="description">' . esc_html( sprintf( /* translators: parsed parts */ __( 'Read as: number "%1$s", street "%2$s", suburb "%3$s".', 'teatatu-events' ), $parts['number'], $parts['street'], $parts['suburb'] ) ) . '</span>';
		echo '</p></div>';
	}
	?>

	<h3><?php esc_html_e( 'Street rules', 'teatatu-events' ); ?></h3>
	<p class="description">
		<?php
		echo esc_html(
			sprintf(
				/* translators: 1: rule rows, 2: streets, 3: e.g. "25 in Matipo, 40 in Beach, 46 in Harbourview", 4: streets split by house number. */
				__( 'These rules decide which neighbourhood each address is in: %1$d rules covering %2$d streets — %3$s, and %4$d that cross a boundary and are split by house number and side of the road. They were loaded from the street list bundled with the plugin (built from OpenStreetMap) and can be changed here — for example add a new subdivision\'s streets, or correct a house-number range.', 'teatatu-events' ),
				count( $rules ),
				count( $by_street ),
				implode(
					', ',
					array_map(
						function ( $slug ) use ( $counts ) {
							$term = get_term( teatatu_events_neighbourhood_term_id( $slug ) );
							/* translators: 1: count, 2: neighbourhood name. */
							return sprintf( __( '%1$d in %2$s', 'teatatu-events' ), $counts[ $slug ], $term && ! is_wp_error( $term ) ? $term->name : $slug );
						},
						teatatu_events_neighbourhood_slugs()
					)
				),
				$counts['split']
			)
		);
		?>
	</p>
	<p class="description"><?php esc_html_e( 'A street\'s rows are checked in order and the first row that matches the house number wins. Leave From/To blank for the whole street. Use Add below / Remove to change the rows, then save.', 'teatatu-events' ); ?></p>
	<?php if ( ! $can_rules ) : ?>
		<p><?php esc_html_e( 'Street rules apply to the whole network, so only network administrators can change them.', 'teatatu-events' ); ?></p>
	<?php endif; ?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'teatatu_events_save_street_rules', 'teatatu_events_rules_nonce' ); ?>
		<input type="hidden" name="action" value="teatatu_events_save_street_rules" />
		<table class="wp-list-table widefat striped tte-street-rules">
			<thead><tr><th><?php esc_html_e( 'Street', 'teatatu-events' ); ?></th><th><?php esc_html_e( 'Side', 'teatatu-events' ); ?></th><th><?php esc_html_e( 'From no.', 'teatatu-events' ); ?></th><th><?php esc_html_e( 'To no.', 'teatatu-events' ); ?></th><th><?php esc_html_e( 'Neighbourhood', 'teatatu-events' ); ?></th><?php if ( $can_rules ) : ?><th><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'teatatu-events' ); ?></span></th><?php endif; ?></tr></thead>
			<tbody id="tte-street-rules-body">
			<?php
			foreach ( $rules as $i => $row ) {
				teatatu_events_render_street_rule_row( (string) $i, $row, $can_rules );
			}
			?>
			</tbody>
		</table>
		<?php if ( $can_rules ) : ?>
			<p id="tte-street-rules-empty"<?php echo $rules ? ' hidden' : ''; ?>><?php esc_html_e( 'No street rules yet.', 'teatatu-events' ); ?></p>
			<p><button type="button" class="button" id="tte-add-street-rule"><?php esc_html_e( 'Add rule', 'teatatu-events' ); ?></button></p>
			<template id="tte-street-rule-template">
				<?php teatatu_events_render_street_rule_row( '__i__', array( 'street' => '', 'parity' => 'any', 'min' => '', 'max' => '', 'area' => '' ), true ); ?>
			</template>
			<script>
			( function () {
				var body  = document.getElementById( 'tte-street-rules-body' );
				var tpl   = document.getElementById( 'tte-street-rule-template' );
				var empty = document.getElementById( 'tte-street-rules-empty' );
				var next  = <?php echo (int) count( $rules ); ?>;
				function newRow( street ) {
					var html = tpl.innerHTML.replace( /__i__/g, String( next++ ) );
					var holder = document.createElement( 'tbody' );
					holder.innerHTML = html.trim();
					var row = holder.firstElementChild;
					if ( street ) {
						row.querySelector( '.tte-rule-street' ).value = street;
					}
					return row;
				}
				function refresh() {
					empty.hidden = body.children.length > 0;
				}
				function focusRow( row ) {
					var field = row.querySelector( row.querySelector( '.tte-rule-street' ).value ? '.tte-rule-min' : '.tte-rule-street' );
					if ( field ) {
						field.focus();
					}
				}
				document.getElementById( 'tte-add-street-rule' ).addEventListener( 'click', function () {
					var row = newRow( '' );
					body.appendChild( row );
					refresh();
					focusRow( row );
				} );
				body.addEventListener( 'click', function ( e ) {
					var btn = e.target.closest( 'button' );
					if ( ! btn ) {
						return;
					}
					var row = btn.closest( 'tr' );
					if ( btn.classList.contains( 'tte-rule-remove' ) ) {
						row.parentNode.removeChild( row );
						refresh();
					} else if ( btn.classList.contains( 'tte-rule-add' ) ) {
						// A new row for the same street, e.g. to split it by house number.
						var added = newRow( row.querySelector( '.tte-rule-street' ).value );
						row.parentNode.insertBefore( added, row.nextSibling );
						refresh();
						focusRow( added );
					}
				} );
			} )();
			</script>
		<?php endif; ?>
		<?php
		if ( $can_rules ) {
			submit_button( __( 'Save street rules', 'teatatu-events' ) );
		}
		?>
	</form>
	<?php if ( $can_rules ) : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'teatatu_events_reload_street_rules', 'teatatu_events_reload_nonce' ); ?>
			<input type="hidden" name="action" value="teatatu_events_reload_street_rules" />
			<p>
				<button class="button"><?php esc_html_e( 'Add missing streets from the bundled list', 'teatatu-events' ); ?></button>
				<span class="description"><?php esc_html_e( 'Adds rules for any bundled street that has no rows above (for example after removing one by mistake, or after an update ships new streets). Streets already listed are left exactly as they are.', 'teatatu-events' ); ?></span>
			</p>
		</form>
	<?php endif; ?>
	<?php
}

/**
 * Outputs one street-rule row.
 *
 * @param string $i        Row index ('__i__' for the template row).
 * @param array  $row      {street, parity, min, max, area}.
 * @param bool   $editable Whether the current user can change rules.
 */
function teatatu_events_render_street_rule_row( $i, $row, $editable ) {
	$name = 'rules[' . $i . ']';
	?>
	<tr>
		<td><input type="text" class="tte-rule-street" style="width:100%;min-width:10em;" name="<?php echo esc_attr( $name ); ?>[street]" value="<?php echo esc_attr( $row['street'] ); ?>" placeholder="<?php esc_attr_e( 'e.g. Neil Avenue', 'teatatu-events' ); ?>" required <?php disabled( ! $editable ); ?> /></td>
		<td><select name="<?php echo esc_attr( $name ); ?>[parity]" <?php disabled( ! $editable ); ?>>
			<option value="any" <?php selected( $row['parity'], 'any' ); ?>><?php esc_html_e( 'Both sides', 'teatatu-events' ); ?></option>
			<option value="odd" <?php selected( $row['parity'], 'odd' ); ?>><?php esc_html_e( 'Odd numbers', 'teatatu-events' ); ?></option>
			<option value="even" <?php selected( $row['parity'], 'even' ); ?>><?php esc_html_e( 'Even numbers', 'teatatu-events' ); ?></option>
		</select></td>
		<td><input type="number" min="0" class="small-text tte-rule-min" name="<?php echo esc_attr( $name ); ?>[min]" value="<?php echo esc_attr( $row['min'] ); ?>" <?php disabled( ! $editable ); ?> /></td>
		<td><input type="number" min="0" class="small-text" name="<?php echo esc_attr( $name ); ?>[max]" value="<?php echo esc_attr( $row['max'] ); ?>" <?php disabled( ! $editable ); ?> /></td>
		<td><select name="<?php echo esc_attr( $name ); ?>[area]" required <?php disabled( ! $editable ); ?>>
			<option value=""><?php esc_html_e( '— Choose —', 'teatatu-events' ); ?></option>
			<?php foreach ( teatatu_events_neighbourhood_defs() as $slug => $def ) : ?>
				<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $row['area'], $slug ); ?>><?php echo esc_html( $def[0] ); ?></option>
			<?php endforeach; ?>
			<option value="outside" <?php selected( $row['area'], 'outside' ); ?>><?php esc_html_e( 'Not on the peninsula', 'teatatu-events' ); ?></option>
		</select></td>
		<?php if ( $editable ) : ?>
			<td style="white-space:nowrap;">
				<button type="button" class="button button-small tte-rule-add"><?php esc_html_e( 'Add below', 'teatatu-events' ); ?></button>
				<button type="button" class="button button-small button-link-delete tte-rule-remove"><?php esc_html_e( 'Remove', 'teatatu-events' ); ?></button>
			</td>
		<?php endif; ?>
	</tr>
	<?php
}

add_action( 'admin_post_teatatu_events_save_neighbourhoods', 'teatatu_events_handle_save_neighbourhoods' );

/**
 * Renames neighbourhoods / edits descriptions (this site's terms).
 */
function teatatu_events_handle_save_neighbourhoods() {
	if ( ! isset( $_POST['teatatu_events_nbhd_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['teatatu_events_nbhd_nonce'] ) ), 'teatatu_events_save_neighbourhoods' ) ) {
		wp_die( esc_html__( 'Security check failed.', 'teatatu-events' ) );
	}
	if ( ! current_user_can( 'manage_teatatu_events_neighbourhoods' ) ) {
		wp_die( esc_html__( 'You do not have permission to do that.', 'teatatu-events' ), 403 );
	}
	$input   = isset( $_POST['nbhd'] ) && is_array( $_POST['nbhd'] ) ? wp_unslash( $_POST['nbhd'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	$renamed = array();
	// Slugs first (network-wide, network admins only), so names save against the new slug.
	if ( teatatu_events_can_rename_neighbourhood_slugs() ) {
		foreach ( teatatu_events_neighbourhood_keys() as $key ) {
			if ( ! isset( $input[ $key ]['slug'] ) || sanitize_title( $input[ $key ]['slug'] ) === teatatu_events_neighbourhood_slug_for_key( $key ) ) {
				continue;
			}
			$old    = teatatu_events_neighbourhood_slug_for_key( $key );
			$result = teatatu_events_rename_neighbourhood_slug( $key, $input[ $key ]['slug'] );
			if ( is_wp_error( $result ) ) {
				teatatu_events_admin_error( $result->get_error_message() );
			} elseif ( $result ) {
				/* translators: 1: old slug, 2: new slug, 3: sites, 4: street rules, 5: venue settings, 6: event/series overrides, 7: feeds, 8: pages/widgets. */
				$renamed[] = sprintf( __( '"%1$s" is now "%2$s" on %3$d site(s); events in it move with it. References updated: %4$d street rules, %5$d venue settings, %6$d event/series overrides, %7$d feed filters, %8$d pages/widgets.', 'teatatu-events' ), $old, teatatu_events_neighbourhood_slug_for_key( $key ), $result['sites'], $result['street_rules'], $result['venues'], $result['events'] + $result['series'], $result['feeds'], $result['content'] );
			}
		}
	}
	foreach ( teatatu_events_neighbourhood_keys() as $key ) {
		$slug = teatatu_events_neighbourhood_slug_for_key( $key );
		$id   = teatatu_events_neighbourhood_term_id( $slug );
		if ( $id && isset( $input[ $key ]['name'] ) && '' !== trim( $input[ $key ]['name'] ) ) {
			wp_update_term(
				$id,
				'teatatu_events_neighbourhood',
				array(
					'name'        => sanitize_text_field( $input[ $key ]['name'] ),
					'description' => sanitize_textarea_field( $input[ $key ]['description'] ?? '' ),
				)
			);
		}
	}
	if ( $renamed ) {
		teatatu_events_bump_cache_version();
		teatatu_events_admin_notice( __( 'Neighbourhoods saved.', 'teatatu-events' ) . ' ' . implode( ' ', $renamed ) );
		wp_safe_redirect( add_query_arg( array( 'page' => 'teatatu-events', 'tab' => 'neighbourhoods' ), admin_url( 'admin.php' ) ) );
		exit;
	}
	teatatu_events_bump_cache_version();
	teatatu_events_admin_notice( __( 'Neighbourhoods saved.', 'teatatu-events' ) );
	wp_safe_redirect( add_query_arg( array( 'page' => 'teatatu-events', 'tab' => 'neighbourhoods' ), admin_url( 'admin.php' ) ) );
	exit;
}

add_action( 'admin_post_teatatu_events_save_street_rules', 'teatatu_events_handle_save_street_rules' );

/**
 * Saves the (network-wide) street rules and re-resolves addresses.
 */
function teatatu_events_handle_save_street_rules() {
	if ( ! isset( $_POST['teatatu_events_rules_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['teatatu_events_rules_nonce'] ) ), 'teatatu_events_save_street_rules' ) ) {
		wp_die( esc_html__( 'Security check failed.', 'teatatu-events' ) );
	}
	$can = is_multisite() ? current_user_can( 'manage_network_options' ) : current_user_can( 'manage_teatatu_events_neighbourhoods' );
	if ( ! $can ) {
		wp_die( esc_html__( 'You do not have permission to do that.', 'teatatu-events' ), 403 );
	}
	$rows  = isset( $_POST['rules'] ) && is_array( $_POST['rules'] ) ? wp_unslash( $_POST['rules'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	$clean = array();
	$areas = array_merge( teatatu_events_neighbourhood_slugs(), array( 'outside' ) );
	foreach ( $rows as $row ) {
		$street = sanitize_text_field( $row['street'] ?? '' );
		$area   = sanitize_key( $row['area'] ?? '' );
		if ( '' === $street || ! in_array( $area, $areas, true ) ) {
			continue;
		}
		$parity  = in_array( $row['parity'] ?? 'any', array( 'any', 'odd', 'even' ), true ) ? $row['parity'] : 'any';
		$clean[] = array(
			'street' => $street,
			'parity' => $parity,
			'min'    => '' === (string) ( $row['min'] ?? '' ) ? '' : absint( $row['min'] ),
			'max'    => '' === (string) ( $row['max'] ?? '' ) ? '' : absint( $row['max'] ),
			'area'   => $area,
		);
	}
	teatatu_events_update_option( 'teatatu_events_nbhd_street_rules', teatatu_events_sort_street_rules( $clean ) );
	teatatu_events_update_option( 'teatatu_events_nbhd_rules_seeded', TEATATU_EVENTS_VERSION );
	teatatu_events_schedule_neighbourhood_reresolve();
	teatatu_events_admin_notice( __( 'Street rules saved. Venues and events are being re-checked in the background.', 'teatatu-events' ) );
	wp_safe_redirect( add_query_arg( array( 'page' => 'teatatu-events', 'tab' => 'neighbourhoods' ), admin_url( 'admin.php' ) ) );
	exit;
}

add_action( 'admin_post_teatatu_events_reload_street_rules', 'teatatu_events_handle_reload_street_rules' );

/**
 * Adds bundled street rules for streets the stored rules don't cover.
 */
function teatatu_events_handle_reload_street_rules() {
	if ( ! isset( $_POST['teatatu_events_reload_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['teatatu_events_reload_nonce'] ) ), 'teatatu_events_reload_street_rules' ) ) {
		wp_die( esc_html__( 'Security check failed.', 'teatatu-events' ) );
	}
	$can = is_multisite() ? current_user_can( 'manage_network_options' ) : current_user_can( 'manage_teatatu_events_neighbourhoods' );
	if ( ! $can ) {
		wp_die( esc_html__( 'You do not have permission to do that.', 'teatatu-events' ), 403 );
	}
	$added = teatatu_events_seed_street_rules( true );
	if ( $added ) {
		teatatu_events_schedule_neighbourhood_reresolve();
		/* translators: %d: number of rule rows. */
		teatatu_events_admin_notice( sprintf( _n( 'Added %d street rule from the bundled list. Venues and events are being re-checked in the background.', 'Added %d street rules from the bundled list. Venues and events are being re-checked in the background.', $added, 'teatatu-events' ), $added ) );
	} else {
		teatatu_events_admin_notice( __( 'Every street in the bundled list already has rules. Nothing was changed.', 'teatatu-events' ) );
	}
	wp_safe_redirect( add_query_arg( array( 'page' => 'teatatu-events', 'tab' => 'neighbourhoods' ), admin_url( 'admin.php' ) ) );
	exit;
}
