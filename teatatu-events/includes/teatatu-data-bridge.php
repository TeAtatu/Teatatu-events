<?php
/**
 * Bridge to Teatatu Data (optional). When Teatatu Data is active and a
 * steward has switched a list on in its Connections tab (Venues,
 * Neighbourhoods and street rules, Sources, Event Tags, Categories),
 * Teatatu Data keeps that list's terms, term meta and options here up to
 * date, linked by the `teatatu_data_ref` term meta. While it does:
 *
 * - the list is read-only here: creating, editing or deleting its terms is
 *   refused on every path (these screens, WordPress term screens, REST,
 *   agents, imports), except for Teatatu Data's own sync;
 * - this plugin's tabs show the list read-only with a link to Teatatu Data.
 *
 * Nothing else changes: events still use these terms, so queries, URLs,
 * shortcodes and REST work as before. Without Teatatu Data (or with a list
 * switched off) everything is editable here exactly as before, and the last
 * synced data stays.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether Teatatu Data manages a taxonomy now.
 *
 * @param string $taxonomy Taxonomy.
 * @return bool
 */
function teatatu_events_data_manages( $taxonomy ) {
	return function_exists( 'teatatu_data_manages_taxonomy' ) && teatatu_data_manages_taxonomy( $taxonomy );
}

/**
 * Whether Teatatu Data is writing right now (its sync is let through).
 *
 * @return bool
 */
function teatatu_events_data_syncing() {
	return function_exists( 'teatatu_data_is_syncing' ) && teatatu_data_is_syncing();
}

/**
 * Message shown when a managed list is changed here.
 *
 * @param string $taxonomy Taxonomy.
 * @return string
 */
function teatatu_events_data_managed_message( $taxonomy ) {
	$tax   = get_taxonomy( $taxonomy );
	$label = $tax ? $tax->labels->name : $taxonomy;
	/* translators: %s: list name, e.g. Venues */
	return sprintf( __( '%s are managed in Teatatu Data. Make the change there and it will be copied here.', 'teatatu-events' ), $label );
}

add_filter( 'pre_insert_term', 'teatatu_events_data_guard_insert', 5, 2 );

/**
 * Refuses new terms in a managed list (except from the sync).
 *
 * @param string|WP_Error $term     Term name.
 * @param string          $taxonomy Taxonomy.
 * @return string|WP_Error
 */
function teatatu_events_data_guard_insert( $term, $taxonomy ) {
	if ( is_wp_error( $term ) || 0 !== strpos( (string) $taxonomy, 'teatatu_events_' ) || teatatu_events_data_syncing() || ! teatatu_events_data_manages( $taxonomy ) ) {
		return $term;
	}
	return new WP_Error( 'teatatu_events_managed_by_data', teatatu_events_data_managed_message( $taxonomy ), array( 'status' => 403 ) );
}

add_filter( 'map_meta_cap', 'teatatu_events_data_guard_term_caps', 10, 4 );

/**
 * Refuses editing or deleting a term in a managed list (except from the sync).
 *
 * @param string[] $caps    Primitive caps.
 * @param string   $cap     Meta cap.
 * @param int      $user_id User.
 * @param array    $args    Args (term ID first).
 * @return string[]
 */
function teatatu_events_data_guard_term_caps( $caps, $cap, $user_id, $args ) {
	if ( ! in_array( $cap, array( 'edit_term', 'delete_term' ), true ) || empty( $args[0] ) || teatatu_events_data_syncing() ) {
		return $caps;
	}
	$term = get_term( (int) $args[0] );
	if ( $term && ! is_wp_error( $term ) && 0 === strpos( $term->taxonomy, 'teatatu_events_' ) && teatatu_events_data_manages( $term->taxonomy ) ) {
		return array( 'do_not_allow' );
	}
	return $caps;
}

add_action( 'admin_init', 'teatatu_events_data_guard_handlers', 1 );

/**
 * Refuses this plugin's own save/delete handlers for managed lists, before
 * they run.
 */
function teatatu_events_data_guard_handlers() {
	// phpcs:disable WordPress.Security.NonceVerification -- only decides whether to refuse; the handlers verify nonces.
	if ( ! isset( $GLOBALS['pagenow'] ) || 'admin-post.php' !== $GLOBALS['pagenow'] || empty( $_REQUEST['action'] ) ) {
		return;
	}
	$action   = sanitize_key( wp_unslash( $_REQUEST['action'] ) );
	$taxonomy = '';
	if ( in_array( $action, array( 'teatatu_events_save_venue', 'teatatu_events_delete_venue' ), true ) ) {
		$taxonomy = 'teatatu_events_venue';
	} elseif ( in_array( $action, array( 'teatatu_events_save_neighbourhoods', 'teatatu_events_save_street_rules', 'teatatu_events_reload_street_rules' ), true ) ) {
		$taxonomy = 'teatatu_events_neighbourhood';
	} elseif ( in_array( $action, array( 'teatatu_events_save_term', 'teatatu_events_delete_term' ), true ) && isset( $_REQUEST['taxonomy'] ) ) {
		$taxonomy = sanitize_key( wp_unslash( $_REQUEST['taxonomy'] ) );
	}
	// phpcs:enable
	if ( $taxonomy && teatatu_events_data_manages( $taxonomy ) ) {
		wp_die( esc_html( teatatu_events_data_managed_message( $taxonomy ) ), 403 );
	}
}

/**
 * Renders a managed list read-only (in place of its tab). Returns false
 * when the list isn't managed, so the normal tab renders.
 *
 * @param string $taxonomy Taxonomy.
 * @return bool Whether it rendered.
 */
function teatatu_events_render_managed_list( $taxonomy ) {
	if ( ! teatatu_events_data_manages( $taxonomy ) ) {
		return false;
	}
	$dataset = function_exists( 'teatatu_data_dataset_for_taxonomy' ) ? teatatu_data_dataset_for_taxonomy( $taxonomy ) : '';
	$url     = function_exists( 'teatatu_data_manage_url' ) ? teatatu_data_manage_url( $dataset ) : '';
	$terms   = get_terms(
		array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
			'orderby'    => 'name',
		)
	);
	?>
	<div class="notice notice-info inline"><p>
		<?php echo esc_html( teatatu_events_data_managed_message( $taxonomy ) ); ?>
		<?php if ( $url ) : ?>
			<a href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'Open in Teatatu Data', 'teatatu-events' ); ?></a>
		<?php endif; ?>
	</p></div>
	<?php if ( 'teatatu_events_neighbourhood' === $taxonomy ) : ?>
		<p><?php esc_html_e( 'The street rules and neighbourhood slugs are managed there too; venues and events are placed with the rules copied from Teatatu Data.', 'teatatu-events' ); ?></p>
	<?php endif; ?>
	<table class="widefat striped" style="max-width:1100px;">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Name', 'teatatu-events' ); ?></th>
				<th><?php esc_html_e( 'Slug', 'teatatu-events' ); ?></th>
				<?php if ( 'teatatu_events_venue' === $taxonomy ) : ?><th><?php esc_html_e( 'Address', 'teatatu-events' ); ?></th><?php endif; ?>
				<th><?php esc_html_e( 'Events', 'teatatu-events' ); ?></th>
				<th><?php esc_html_e( 'From Teatatu Data', 'teatatu-events' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( is_wp_error( $terms ) ? array() : $terms as $term ) : ?>
				<?php $linked = '' !== (string) get_term_meta( $term->term_id, 'teatatu_data_ref', true ); ?>
				<tr>
					<td><?php echo esc_html( ( $term->parent ? '— ' : '' ) . $term->name ); ?></td>
					<td><code><?php echo esc_html( $term->slug ); ?></code></td>
					<?php if ( 'teatatu_events_venue' === $taxonomy ) : ?><td><?php echo esc_html( teatatu_events_format_address( $term->term_id ) ); ?></td><?php endif; ?>
					<td><?php echo (int) $term->count; ?></td>
					<td><?php echo $linked ? esc_html__( 'Yes', 'teatatu-events' ) : esc_html__( 'No — not linked yet (kept as it is)', 'teatatu-events' ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php
	return true;
}
