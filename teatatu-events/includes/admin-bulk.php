<?php
/**
 * Bulk work on events, shared by the native Events list (edit.php) and the
 * plugin's Events / Pending Updates tabs:
 *
 *  - Publish selected events, or "Publish all upcoming drafts" in one go;
 *  - Apply or Dismiss pending feed updates (selected, or all at once).
 *
 * Every event goes through the same checks as publishing it by hand: the
 * user needs edit_post and the publish capability; draft copies (which
 * publish their changes onto the original through the merge review) and
 * duplicates still waiting for their date confirmation are left alone.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bulk actions offered for events.
 *
 * @return string[] action => label
 */
function teatatu_events_bulk_actions() {
	$actions = array();
	if ( current_user_can( 'publish_teatatu_events_items' ) ) {
		$actions['tte_publish'] = __( 'Publish', 'teatatu-events' );
	}
	$actions['tte_apply_updates']   = __( 'Apply feed updates', 'teatatu-events' );
	$actions['tte_dismiss_updates'] = __( 'Dismiss feed updates', 'teatatu-events' );
	return $actions;
}

/**
 * Applies an event's pending feed update. Shared by Apply links and bulk
 * actions.
 *
 * @param int $id Event ID.
 * @return bool Whether there was an update to apply.
 */
function teatatu_events_apply_pending_update( $id ) {
	$pending = get_post_meta( $id, teatatu_events_mk( 'pending_update' ), true );
	if ( ! is_array( $pending ) || ! isset( $pending['values'] ) ) {
		return false;
	}
	if ( ! empty( $pending['restore_status'] ) ) {
		teatatu_events_save_fields( $id, array( 'status' => $pending['restore_status'] ) );
		teatatu_events_refresh_derived( $id );
	} else {
		teatatu_events_apply_import_values( $id, $pending['values'], null );
	}
	delete_post_meta( $id, teatatu_events_mk( 'pending_update' ) );
	return true;
}

/**
 * Why an event can't be published in bulk ('' when it can).
 *
 * @param int $id Event ID.
 * @return string
 */
function teatatu_events_bulk_publish_blocker( $id ) {
	if ( 'teatatu_event' !== get_post_type( $id ) ) {
		return __( 'not an event', 'teatatu-events' );
	}
	if ( 'publish' === get_post_status( $id ) ) {
		return __( 'already published', 'teatatu-events' );
	}
	if ( ! current_user_can( 'edit_post', $id ) || ! current_user_can( 'publish_teatatu_events_items' ) ) {
		return __( 'you cannot publish it', 'teatatu-events' );
	}
	if ( get_post_meta( $id, teatatu_events_mk( 'draft_of' ), true ) ) {
		return __( 'draft changes to a published event (publish them from the copy)', 'teatatu-events' );
	}
	if ( get_post_meta( $id, teatatu_events_mk( 'copy_needs_date' ), true ) ) {
		return __( 'a duplicate whose date has not been confirmed', 'teatatu-events' );
	}
	if ( ! teatatu_events_get_start( $id ) ) {
		return __( 'no start date', 'teatatu-events' );
	}
	return '';
}

/**
 * Runs a bulk action over events.
 *
 * @param string $action tte_publish | tte_apply_updates | tte_dismiss_updates.
 * @param int[]  $ids    Event IDs.
 * @return array {done: int, skipped: array reason => count}
 */
function teatatu_events_bulk_process( $action, $ids ) {
	$result = array( 'done' => 0, 'skipped' => array() );
	$skip   = function ( $reason ) use ( &$result ) {
		$result['skipped'][ $reason ] = ( $result['skipped'][ $reason ] ?? 0 ) + 1;
	};
	foreach ( array_unique( array_map( 'absint', (array) $ids ) ) as $id ) {
		if ( ! $id ) {
			continue;
		}
		if ( 'tte_publish' === $action ) {
			$blocker = teatatu_events_bulk_publish_blocker( $id );
			if ( '' !== $blocker ) {
				$skip( $blocker );
				continue;
			}
			$r = wp_update_post( array( 'ID' => $id, 'post_status' => 'publish' ), true );
			if ( is_wp_error( $r ) ) {
				$skip( $r->get_error_message() );
				continue;
			}
			$result['done']++;
			continue;
		}
		if ( 'teatatu_event' !== get_post_type( $id ) || ! current_user_can( 'edit_post', $id ) ) {
			$skip( __( 'you cannot edit it', 'teatatu-events' ) );
			continue;
		}
		if ( ! is_array( get_post_meta( $id, teatatu_events_mk( 'pending_update' ), true ) ) ) {
			$skip( __( 'no feed update waiting', 'teatatu-events' ) );
			continue;
		}
		if ( 'tte_apply_updates' === $action ) {
			teatatu_events_apply_pending_update( $id );
		} else {
			delete_post_meta( $id, teatatu_events_mk( 'pending_update' ) );
			teatatu_events_bump_cache_version();
		}
		$result['done']++;
	}
	delete_transient( 'teatatu_events_attention_count' );
	return $result;
}

/**
 * A one-line summary of a bulk result.
 *
 * @param string $action Action.
 * @param array  $result Result.
 * @return string
 */
function teatatu_events_bulk_summary( $action, $result ) {
	$done = array(
		/* translators: %d: count. */
		'tte_publish'         => _n( '%d event published.', '%d events published.', $result['done'], 'teatatu-events' ),
		/* translators: %d: count. */
		'tte_apply_updates'   => _n( '%d feed update applied.', '%d feed updates applied.', $result['done'], 'teatatu-events' ),
		/* translators: %d: count. */
		'tte_dismiss_updates' => _n( '%d feed update dismissed.', '%d feed updates dismissed.', $result['done'], 'teatatu-events' ),
	);
	$out = sprintf( $done[ $action ] ?? '%d', $result['done'] );
	if ( $result['skipped'] ) {
		$parts = array();
		foreach ( $result['skipped'] as $reason => $count ) {
			$parts[] = $count . ' ' . $reason;
		}
		/* translators: %s: list of "count reason". */
		$out .= ' ' . sprintf( __( 'Skipped: %s.', 'teatatu-events' ), implode( '; ', $parts ) );
	}
	return $out;
}

/**
 * Drafts that "Publish all upcoming drafts" would publish: draft or pending
 * events that haven't finished, that the user may publish, excluding draft
 * copies and unconfirmed duplicates.
 *
 * @return int[]
 */
function teatatu_events_publishable_draft_ids() {
	if ( ! current_user_can( 'publish_teatatu_events_items' ) ) {
		return array();
	}
	$args = teatatu_events_query_args( 'upcoming' );
	$args['meta_query'][] = array( 'key' => teatatu_events_mk( 'draft_of' ), 'compare' => 'NOT EXISTS' );
	$args['meta_query'][] = array( 'key' => teatatu_events_mk( 'copy_needs_date' ), 'compare' => 'NOT EXISTS' );
	$query = array_merge(
		$args,
		array(
			'post_type'        => 'teatatu_event',
			'post_status'      => array( 'draft', 'pending' ),
			'posts_per_page'   => 500,
			'fields'           => 'ids',
			'suppress_filters' => true,
		)
	);
	if ( ! current_user_can( 'edit_others_teatatu_events_items' ) ) {
		$query['author'] = get_current_user_id();
	}
	return array_values( array_filter( get_posts( $query ), function ( $id ) { return '' === teatatu_events_bulk_publish_blocker( $id ); } ) );
}

/**
 * IDs of events with a pending feed update the user may edit.
 *
 * @return int[]
 */
function teatatu_events_pending_update_ids() {
	return array_values(
		array_filter(
			get_posts(
				array(
					'post_type'        => 'teatatu_event',
					'post_status'      => 'any',
					'posts_per_page'   => 500,
					'fields'           => 'ids',
					'suppress_filters' => true,
					'meta_query'       => array( array( 'key' => teatatu_events_mk( 'pending_update' ), 'compare' => 'EXISTS' ) ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				)
			),
			function ( $id ) {
				return current_user_can( 'edit_post', $id );
			}
		)
	);
}

/**
 * "Publish all upcoming drafts (N)" link (with a confirmation).
 *
 * @return string HTML ('' when there's nothing to publish or no permission).
 */
function teatatu_events_publish_all_button() {
	$ids = teatatu_events_publishable_draft_ids();
	if ( ! $ids ) {
		return '';
	}
	$url = wp_nonce_url( add_query_arg( array( 'action' => 'teatatu_events_publish_all' ), admin_url( 'admin-post.php' ) ), 'teatatu_events_publish_all' );
	/* translators: %d: count. */
	$label = sprintf( _n( 'Publish %d upcoming draft', 'Publish all %d upcoming drafts', count( $ids ), 'teatatu-events' ), count( $ids ) );
	/* translators: %d: count. */
	$confirm = sprintf( _n( 'Publish %d draft event that has not finished yet?', 'Publish all %d draft events that have not finished yet?', count( $ids ), 'teatatu-events' ), count( $ids ) );
	return '<a class="button" href="' . esc_url( $url ) . '" onclick="return confirm(' . esc_attr( wp_json_encode( $confirm ) ) . ');">' . esc_html( $label ) . '</a>';
}

add_action( 'admin_post_teatatu_events_publish_all', 'teatatu_events_handle_publish_all' );

/**
 * Publishes every publishable upcoming draft.
 */
function teatatu_events_handle_publish_all() {
	check_admin_referer( 'teatatu_events_publish_all' );
	if ( ! current_user_can( 'publish_teatatu_events_items' ) ) {
		wp_die( esc_html__( 'You do not have permission to do that.', 'teatatu-events' ), 403 );
	}
	teatatu_events_admin_notice( teatatu_events_bulk_summary( 'tte_publish', teatatu_events_bulk_process( 'tte_publish', teatatu_events_publishable_draft_ids() ) ) );
	wp_safe_redirect( wp_get_referer() ? wp_get_referer() : add_query_arg( array( 'page' => 'teatatu-events', 'tab' => 'events' ), admin_url( 'admin.php' ) ) );
	exit;
}

add_action( 'admin_post_teatatu_events_bulk_events', 'teatatu_events_handle_bulk_events' );

/**
 * Bulk form on the plugin's Events and Pending Updates tabs.
 */
function teatatu_events_handle_bulk_events() {
	check_admin_referer( 'teatatu_events_bulk_events', 'teatatu_events_bulk_nonce' );
	$action = isset( $_POST['bulk_action'] ) ? sanitize_key( wp_unslash( $_POST['bulk_action'] ) ) : '';
	$ids    = isset( $_POST['event_ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['event_ids'] ) ) : array();
	if ( ! empty( $_POST['all_updates'] ) ) {
		$action = sanitize_key( wp_unslash( $_POST['all_updates'] ) );
		$ids    = teatatu_events_pending_update_ids();
	}
	if ( ! isset( teatatu_events_bulk_actions()[ $action ] ) ) {
		teatatu_events_admin_error( __( 'Choose a bulk action.', 'teatatu-events' ) );
	} elseif ( ! $ids ) {
		teatatu_events_admin_error( __( 'Select at least one event.', 'teatatu-events' ) );
	} else {
		teatatu_events_admin_notice( teatatu_events_bulk_summary( $action, teatatu_events_bulk_process( $action, $ids ) ) );
	}
	wp_safe_redirect( wp_get_referer() ? wp_get_referer() : add_query_arg( array( 'page' => 'teatatu-events', 'tab' => 'events' ), admin_url( 'admin.php' ) ) );
	exit;
}

/**
 * Bulk-action controls (select + Apply) for the plugin's lists.
 *
 * @param string[] $only Limit to these actions (empty = all).
 */
function teatatu_events_render_bulk_controls( $only = array() ) {
	$actions = teatatu_events_bulk_actions();
	if ( $only ) {
		$actions = array_intersect_key( $actions, array_flip( $only ) );
	}
	?>
	<div class="alignleft actions bulkactions">
		<label for="tte-bulk-action" class="screen-reader-text"><?php esc_html_e( 'Bulk action', 'teatatu-events' ); ?></label>
		<select name="bulk_action" id="tte-bulk-action">
			<option value=""><?php esc_html_e( 'Bulk actions', 'teatatu-events' ); ?></option>
			<?php foreach ( $actions as $value => $label ) : ?>
				<option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<button type="submit" class="button action"><?php esc_html_e( 'Apply', 'teatatu-events' ); ?></button>
	</div>
	<?php
}

// ---------------------------------------------------------------------------
// The native Events list (edit.php?post_type=teatatu_event)
// ---------------------------------------------------------------------------

add_filter( 'bulk_actions-edit-teatatu_event', 'teatatu_events_native_bulk_actions' );

/**
 * Adds Publish / Apply / Dismiss feed updates to the native bulk actions.
 *
 * @param string[] $actions Actions.
 * @return string[]
 */
function teatatu_events_native_bulk_actions( $actions ) {
	return array_merge( $actions, teatatu_events_bulk_actions() );
}

add_filter( 'handle_bulk_actions-edit-teatatu_event', 'teatatu_events_handle_native_bulk_action', 10, 3 );

/**
 * Runs one of our bulk actions from the native list.
 *
 * @param string $redirect Redirect URL.
 * @param string $action   Action.
 * @param int[]  $ids      Post IDs.
 * @return string
 */
function teatatu_events_handle_native_bulk_action( $redirect, $action, $ids ) {
	if ( ! isset( teatatu_events_bulk_actions()[ $action ] ) ) {
		return $redirect;
	}
	teatatu_events_admin_notice( teatatu_events_bulk_summary( $action, teatatu_events_bulk_process( $action, $ids ) ) );
	return $redirect;
}

add_action( 'manage_posts_extra_tablenav', 'teatatu_events_native_publish_all_button' );

/**
 * "Publish all upcoming drafts" above the native list.
 *
 * @param string $which top|bottom.
 */
function teatatu_events_native_publish_all_button( $which ) {
	$screen = get_current_screen();
	if ( 'top' !== $which || ! $screen || 'edit-teatatu_event' !== $screen->id ) {
		return;
	}
	$button = teatatu_events_publish_all_button();
	if ( $button ) {
		echo '<div class="alignleft actions">' . $button . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
	}
}

add_action( 'admin_notices', 'teatatu_events_native_list_notices' );

/**
 * Shows stashed plugin notices (bulk results) on the native Events list.
 */
function teatatu_events_native_list_notices() {
	$screen = get_current_screen();
	if ( $screen && 'edit-teatatu_event' === $screen->id ) {
		teatatu_events_render_admin_notices();
	}
}

add_filter( 'post_row_actions', 'teatatu_events_pending_update_row_actions', 20, 2 );

/**
 * "Apply feed update" / "Dismiss" row actions for events with a pending
 * feed update.
 *
 * @param string[] $actions Actions.
 * @param WP_Post  $post    Post.
 * @return string[]
 */
function teatatu_events_pending_update_row_actions( $actions, $post ) {
	if ( 'teatatu_event' !== $post->post_type || ! current_user_can( 'edit_post', $post->ID ) || ! is_array( get_post_meta( $post->ID, teatatu_events_mk( 'pending_update' ), true ) ) ) {
		return $actions;
	}
	$actions['tte_apply_update']   = '<a href="' . esc_url( teatatu_events_pending_update_url( $post->ID, 'apply' ) ) . '">' . esc_html__( 'Apply feed update', 'teatatu-events' ) . '</a>';
	$actions['tte_dismiss_update'] = '<a href="' . esc_url( teatatu_events_pending_update_url( $post->ID, 'dismiss' ) ) . '">' . esc_html__( 'Dismiss feed update', 'teatatu-events' ) . '</a>';
	return $actions;
}

/**
 * Apply / Dismiss URL for one pending update.
 *
 * @param int    $id Event ID.
 * @param string $do 'apply' or 'dismiss'.
 * @return string
 */
function teatatu_events_pending_update_url( $id, $do ) {
	$action = 'apply' === $do ? 'teatatu_events_apply_update' : 'teatatu_events_dismiss_update';
	return wp_nonce_url( add_query_arg( array( 'action' => $action, 'post_id' => (int) $id ), admin_url( 'admin-post.php' ) ), $action . '_' . (int) $id );
}

/**
 * Plain-text summary of a pending update's changes (for tooltips).
 *
 * @param int $id Event ID.
 * @return string
 */
function teatatu_events_pending_update_summary( $id ) {
	$pending = get_post_meta( $id, teatatu_events_mk( 'pending_update' ), true );
	if ( ! is_array( $pending ) ) {
		return '';
	}
	$labels = array_merge( teatatu_events_merge_field_labels(), array( 'room' => __( 'Room', 'teatatu-events' ) ) );
	$lines  = array();
	foreach ( (array) ( $pending['diff'] ?? array() ) as $key => $pair ) {
		$lines[] = ( $labels[ $key ] ?? ucfirst( str_replace( '_', ' ', $key ) ) ) . ': ' . wp_trim_words( (string) $pair[0], 12 ) . ' → ' . wp_trim_words( (string) $pair[1], 12 );
	}
	return implode( "\n", $lines );
}
