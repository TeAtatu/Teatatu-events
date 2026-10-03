<?php
/**
 * Registers the Events taxonomies and their term meta:
 *
 *  - teatatu_events_source        Source (organiser/publisher; default image).
 *  - teatatu_events_category      Category.
 *  - teatatu_events_venue         Venue (Location name + structured address).
 *  - teatatu_events_tag           Event Tags (curated list, several per event).
 *  - teatatu_events_neighbourhood Neighbourhood (three fixed, derived terms).
 *
 * All are attached to `teatatu_event` and to `teatatu_evt_link`, so linked
 * events filter exactly like local ones. Venues and Neighbourhoods are
 * managed on the plugin's own screens (they need fields the native term
 * screens don't have), so their native UI is hidden.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'init', 'teatatu_events_register_taxonomies' );

/**
 * Registers every Events taxonomy with explicit, narrowly-scoped capabilities.
 */
function teatatu_events_register_taxonomies() {
	$objects = array( 'teatatu_event', 'teatatu_evt_link' );
	$defs    = array(
		'teatatu_events_source'        => array( 'sources', 'event-sources', __( 'Sources', 'teatatu-events' ), __( 'Source', 'teatatu-events' ), true, true ),
		'teatatu_events_category'      => array( 'categories', 'event-categories', __( 'Categories', 'teatatu-events' ), __( 'Category', 'teatatu-events' ), true, true ),
		'teatatu_events_venue'         => array( 'venues', 'event-venues', __( 'Venues', 'teatatu-events' ), __( 'Venue', 'teatatu-events' ), false, false ),
		'teatatu_events_tag'           => array( 'tags', 'event-tags', __( 'Event Tags', 'teatatu-events' ), __( 'Event Tag', 'teatatu-events' ), false, true ),
		'teatatu_events_neighbourhood' => array( 'neighbourhoods', 'event-neighbourhoods', __( 'Neighbourhoods', 'teatatu-events' ), __( 'Neighbourhood', 'teatatu-events' ), false, false ),
	);

	foreach ( $defs as $taxonomy => $def ) {
		list( $cap_slug, $rest_base, $plural, $singular, $hierarchical, $show_ui ) = $def;
		register_taxonomy(
			$taxonomy,
			$objects,
			array(
				'labels'            => array(
					'name'          => $plural,
					'singular_name' => $singular,
					'menu_name'     => $plural,
					/* translators: %s: taxonomy singular name. */
					'add_new_item'  => sprintf( __( 'Add New %s', 'teatatu-events' ), $singular ),
					/* translators: %s: taxonomy singular name. */
					'edit_item'     => sprintf( __( 'Edit %s', 'teatatu-events' ), $singular ),
					/* translators: %s: taxonomy plural name. */
					'search_items'  => sprintf( __( 'Search %s', 'teatatu-events' ), $plural ),
				),
				'hierarchical'      => $hierarchical,
				'public'            => true,
				'show_ui'           => $show_ui,
				'show_in_menu'      => $show_ui,
				'show_in_rest'      => true,
				'rest_base'         => $rest_base,
				'show_admin_column' => in_array( $taxonomy, array( 'teatatu_events_tag', 'teatatu_events_neighbourhood', 'teatatu_events_venue' ), true ),
				// Event Tags are curated: Quick Edit offers them as checkboxes
				// (includes/admin-columns.php), not core's free-text box.
				'show_in_quick_edit' => $show_ui && 'teatatu_events_tag' !== $taxonomy,
				'rewrite'           => array( 'slug' => $rest_base ),
				'capabilities'      => array(
					'manage_terms' => 'manage_teatatu_events_' . $cap_slug,
					'edit_terms'   => 'edit_teatatu_events_' . $cap_slug,
					'delete_terms' => 'delete_teatatu_events_' . $cap_slug,
					'assign_terms' => 'assign_teatatu_events_' . $cap_slug,
				),
			)
		);
	}
}

/**
 * Venue term meta keys holding the structured address, in display order.
 *
 * @return string[] field => meta key
 */
function teatatu_events_venue_address_keys() {
	return array(
		'unit'     => 'teatatu_events_addr_unit',
		'number'   => 'teatatu_events_addr_number',
		'street'   => 'teatatu_events_addr_street',
		'suburb'   => 'teatatu_events_addr_suburb',
		'city'     => 'teatatu_events_addr_city',
		'region'   => 'teatatu_events_addr_region',
		'postcode' => 'teatatu_events_addr_postcode',
		'country'  => 'teatatu_events_addr_country',
		'lat'      => 'teatatu_events_addr_lat',
		'lng'      => 'teatatu_events_addr_lng',
	);
}

add_action( 'init', 'teatatu_events_register_term_meta' );

/**
 * Registers term meta (REST-exposed; writes need the taxonomy's manage cap).
 */
function teatatu_events_register_term_meta() {
	$string = function ( $taxonomy, $key, $sanitize, $cap, $readonly = false ) {
		register_term_meta(
			$taxonomy,
			$key,
			array(
				'type'              => 'string',
				'single'            => true,
				'sanitize_callback' => $sanitize,
				'show_in_rest'      => true,
				'auth_callback'     => function () use ( $cap, $readonly ) {
					return ! $readonly && current_user_can( $cap );
				},
			)
		);
	};

	$string( 'teatatu_events_source', 'teatatu_events_source_default_image', 'esc_url_raw', 'manage_teatatu_events_sources' );

	foreach ( teatatu_events_venue_address_keys() as $field => $key ) {
		$string( 'teatatu_events_venue', $key, 'sanitize_text_field', 'manage_teatatu_events_venues' );
	}
	$string( 'teatatu_events_venue', 'teatatu_events_venue_map_url', 'esc_url_raw', 'manage_teatatu_events_venues' );
	$string( 'teatatu_events_venue', 'teatatu_events_venue_website_url', 'esc_url_raw', 'manage_teatatu_events_venues' );
	$string( 'teatatu_events_venue', 'teatatu_events_venue_aliases', 'sanitize_text_field', 'manage_teatatu_events_venues' );
	$string( 'teatatu_events_venue', 'teatatu_events_venue_default_image', 'esc_url_raw', 'manage_teatatu_events_venues' );
	// Derived values: readable over REST, never writable directly.
	$string( 'teatatu_events_venue', 'teatatu_events_addr_neighbourhood', 'sanitize_key', 'manage_teatatu_events_venues', true );
	$string( 'teatatu_events_venue', 'teatatu_events_addr_neighbourhood_via', 'sanitize_key', 'manage_teatatu_events_venues', true );
	$string( 'teatatu_events_venue', 'teatatu_events_addr_normalized', 'sanitize_text_field', 'manage_teatatu_events_venues', true );

	$string( 'teatatu_events_tag', 'teatatu_events_tag_color', 'sanitize_hex_color', 'manage_teatatu_events_tags' );
}

add_action( 'teatatu_events_source_add_form_fields', 'teatatu_events_source_add_form_field' );
add_action( 'teatatu_events_source_edit_form_fields', 'teatatu_events_source_edit_form_field' );

/**
 * Default Image URL field on the native "Add New Source" screen.
 */
function teatatu_events_source_add_form_field() {
	?>
	<div class="form-field">
		<label for="teatatu_events_source_default_image"><?php esc_html_e( 'Default Image URL', 'teatatu-events' ); ?></label>
		<input type="url" name="teatatu_events_source_default_image" id="teatatu_events_source_default_image" value="" class="regular-text" placeholder="https://example.com/logo.jpg" />
		<p><?php esc_html_e( 'Fallback image for events from this Source that have no image of their own.', 'teatatu-events' ); ?></p>
	</div>
	<?php
}

/**
 * Default Image URL field on the native "Edit Source" screen.
 *
 * @param WP_Term $term Term being edited.
 */
function teatatu_events_source_edit_form_field( $term ) {
	$value = get_term_meta( $term->term_id, 'teatatu_events_source_default_image', true );
	?>
	<tr class="form-field">
		<th scope="row"><label for="teatatu_events_source_default_image"><?php esc_html_e( 'Default Image URL', 'teatatu-events' ); ?></label></th>
		<td>
			<input type="url" name="teatatu_events_source_default_image" id="teatatu_events_source_default_image" value="<?php echo esc_attr( $value ); ?>" class="regular-text" placeholder="https://example.com/logo.jpg" />
			<p class="description"><?php esc_html_e( 'Fallback image for events from this Source that have no image of their own.', 'teatatu-events' ); ?></p>
			<?php if ( $value ) : ?>
				<p><img src="<?php echo esc_url( $value ); ?>" alt="" style="max-width:150px;height:auto;" /></p>
			<?php endif; ?>
		</td>
	</tr>
	<?php
}

add_action( 'saved_teatatu_events_source', 'teatatu_events_save_source_default_image' );

/**
 * Saves the Default Image URL field from either native term screen.
 *
 * @param int $term_id Term ID.
 */
function teatatu_events_save_source_default_image( $term_id ) {
	if ( ! isset( $_POST['teatatu_events_source_default_image'] ) ) {
		return;
	}
	$nonce_ok = false;
	if ( isset( $_POST['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'update-tag_' . $term_id ) ) {
		$nonce_ok = true;
	} elseif ( isset( $_POST['_wpnonce_add-tag'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce_add-tag'] ) ), 'add-tag' ) ) {
		$nonce_ok = true;
	}
	if ( ! $nonce_ok || ! current_user_can( 'manage_teatatu_events_sources' ) ) {
		return;
	}
	update_term_meta( $term_id, 'teatatu_events_source_default_image', esc_url_raw( wp_unslash( $_POST['teatatu_events_source_default_image'] ) ) );
}

/**
 * First term of a taxonomy assigned to a post (or null).
 *
 * @param int    $post_id  Post ID.
 * @param string $taxonomy Taxonomy.
 * @return WP_Term|null
 */
function teatatu_events_first_term( $post_id, $taxonomy ) {
	$terms = get_the_terms( $post_id, $taxonomy );
	if ( empty( $terms ) || is_wp_error( $terms ) ) {
		return null;
	}
	return $terms[0];
}

/**
 * ID of the first term of a taxonomy assigned to a post.
 *
 * @param int    $post_id  Post ID.
 * @param string $taxonomy Taxonomy.
 * @return int 0 if none.
 */
function teatatu_events_first_term_id( $post_id, $taxonomy ) {
	$term = teatatu_events_first_term( $post_id, $taxonomy );
	return $term ? (int) $term->term_id : 0;
}

/**
 * Comma-separated term names of a post for a taxonomy.
 *
 * @param int    $post_id  Post ID.
 * @param string $taxonomy Taxonomy.
 * @return string
 */
function teatatu_events_term_names( $post_id, $taxonomy ) {
	$terms = get_the_terms( $post_id, $taxonomy );
	if ( empty( $terms ) || is_wp_error( $terms ) ) {
		return '—';
	}
	return implode( ', ', wp_list_pluck( $terms, 'name' ) );
}
