<?php
/**
 * Duplicate and Edit as draft.
 *
 * Duplicate: a brand-new, independent draft copy of any event. Import and
 * series identity, flags and pending updates are not copied.
 *
 * Edit as draft: a hidden working copy of a *published* event
 * (`_teatatu_events_draft_of`). Publishing it merges its fields, content and
 * terms into the original — which keeps its ID, URL, author, publish date
 * and import identity — and then deletes the copy. If the original changed
 * after the copy was made, the editor reviews a field-by-field diff first.
 * One open draft copy per event. Agents can create and edit draft copies
 * (they own them) but never merge: merging needs edit-published rights on
 * the original.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Taxonomies copied between an event and its copies (Neighbourhood is
 * derived, so it is recomputed rather than copied).
 *
 * @return string[]
 */
function teatatu_events_copyable_taxonomies() {
	return array( 'teatatu_events_source', 'teatatu_events_category', 'teatatu_events_venue', 'teatatu_events_tag' );
}

/**
 * The open draft copy of an event, if any.
 *
 * @param int $post_id Original event ID.
 * @return int 0 if none.
 */
function teatatu_events_get_draft_copy_id( $post_id ) {
	$ids = get_posts(
		array(
			'post_type'        => 'teatatu_event',
			'post_status'      => array( 'draft', 'pending', 'future', 'private' ),
			'posts_per_page'   => 1,
			'fields'           => 'ids',
			'suppress_filters' => true,
			'meta_query'       => array( array( 'key' => teatatu_events_mk( 'draft_of' ), 'value' => (int) $post_id ) ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		)
	);
	return $ids ? (int) $ids[0] : 0;
}

/**
 * Copies an event. The one function behind Duplicate and Edit as draft.
 *
 * @param int    $post_id Source event ID.
 * @param string $mode    'duplicate' or 'draft_of'.
 * @return int|WP_Error New (or existing, for draft_of) copy ID.
 */
function teatatu_events_copy_event( $post_id, $mode = 'duplicate' ) {
	$post = get_post( $post_id );
	if ( ! $post || 'teatatu_event' !== $post->post_type ) {
		return new WP_Error( 'rest_post_invalid_id', __( 'That is not an event.', 'teatatu-events' ), array( 'status' => 404 ) );
	}
	if ( ! current_user_can( 'edit_teatatu_events_items' ) ) {
		return new WP_Error( 'rest_cannot_create', __( 'You cannot create events.', 'teatatu-events' ), array( 'status' => 403 ) );
	}
	if ( get_post_meta( $post_id, teatatu_events_mk( 'draft_of' ), true ) ) {
		return new WP_Error( 'teatatu_events_is_draft_copy', __( 'That is already a draft copy.', 'teatatu-events' ), array( 'status' => 400 ) );
	}

	if ( 'draft_of' === $mode ) {
		if ( 'publish' !== $post->post_status ) {
			return new WP_Error( 'teatatu_events_not_published', __( 'Only published events need a draft copy. Edit this one directly.', 'teatatu-events' ), array( 'status' => 400 ) );
		}
		$existing = teatatu_events_get_draft_copy_id( $post_id );
		if ( $existing ) {
			if ( ! current_user_can( 'edit_post', $existing ) ) {
				return new WP_Error( 'teatatu_events_draft_locked', __( 'Someone else already has draft changes for this event.', 'teatatu-events' ), array( 'status' => 403, 'draft_id' => $existing ) );
			}
			return $existing;
		}
	} elseif ( 'publish' !== $post->post_status && ! current_user_can( 'edit_post', $post_id ) ) {
		return new WP_Error( 'rest_forbidden', __( 'You cannot copy that event.', 'teatatu-events' ), array( 'status' => 403 ) );
	}

	$title = get_post_field( 'post_title', $post_id, 'raw' );
	$new   = array(
		'post_type'    => 'teatatu_event',
		'post_status'  => 'draft',
		'post_title'   => 'duplicate' === $mode ? sprintf( /* translators: %s: title */ __( '%s (copy)', 'teatatu-events' ), $title ) : $title,
		'post_content' => get_post_field( 'post_content', $post_id, 'raw' ),
		'post_excerpt' => get_post_field( 'post_excerpt', $post_id, 'raw' ),
		'post_author'  => get_current_user_id(),
	);

	return teatatu_events_batch_write(
		0,
		function () use ( $new, $post_id, $mode ) {
			$copy_id = wp_insert_post( wp_slash( $new ), true );
			if ( is_wp_error( $copy_id ) ) {
				return $copy_id;
			}
			$fields = teatatu_events_get_fields( $post_id );
			if ( 'duplicate' === $mode ) {
				$fields['status'] = 'scheduled';
			}
			foreach ( $fields as $field => $value ) {
				update_post_meta( $copy_id, teatatu_events_mk( $field ), is_bool( $value ) ? (int) $value : $value );
			}
			$override = get_post_meta( $post_id, teatatu_events_mk( 'nbhd_override' ), true );
			if ( $override ) {
				update_post_meta( $copy_id, teatatu_events_mk( 'nbhd_override' ), $override );
			}
			foreach ( teatatu_events_copyable_taxonomies() as $taxonomy ) {
				$ids = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'ids' ) );
				wp_set_object_terms( $copy_id, is_wp_error( $ids ) ? array() : array_map( 'intval', $ids ), $taxonomy );
			}
			if ( 'duplicate' === $mode ) {
				update_post_meta( $copy_id, teatatu_events_mk( 'copied_from' ), $post_id );
				update_post_meta( $copy_id, teatatu_events_mk( 'copy_needs_date' ), 1 );
			} else {
				update_post_meta( $copy_id, teatatu_events_mk( 'draft_of' ), $post_id );
				update_post_meta( $copy_id, teatatu_events_mk( 'draft_base' ), get_post_field( 'post_modified_gmt', $post_id ) );
				$series = get_post_meta( $post_id, teatatu_events_mk( 'series_id' ), true );
				if ( $series ) {
					update_post_meta( $copy_id, teatatu_events_mk( 'draft_series_id' ), $series );
				}
			}
			return $copy_id;
		}
	);
}

/**
 * Fields compared between a draft copy and its original.
 *
 * @return string[] key => label
 */
function teatatu_events_merge_field_labels() {
	return array(
		'title'         => __( 'Title', 'teatatu-events' ),
		'excerpt'       => __( 'Excerpt', 'teatatu-events' ),
		'content'       => __( 'Description', 'teatatu-events' ),
		'when'          => __( 'Date and time', 'teatatu-events' ),
		'status'        => __( 'Event status', 'teatatu-events' ),
		'place'         => __( 'Place', 'teatatu-events' ),
		'ticket_url'    => __( 'Tickets URL', 'teatatu-events' ),
		'price'         => __( 'Price', 'teatatu-events' ),
		'read_more_url' => __( 'Read More URL', 'teatatu-events' ),
		'link_mode'     => __( 'Cards link to', 'teatatu-events' ),
		'image_url'     => __( 'Image URL', 'teatatu-events' ),
		'terms'         => __( 'Source, Category and Event Tags', 'teatatu-events' ),
	);
}

/**
 * Comparable snapshot of an event for the merge diff.
 *
 * @param int $post_id Event ID.
 * @return array
 */
function teatatu_events_merge_snapshot( $post_id ) {
	$f     = teatatu_events_get_fields( $post_id );
	$place = teatatu_events_get_place( $post_id );
	$terms = array();
	foreach ( array( 'teatatu_events_source', 'teatatu_events_category', 'teatatu_events_tag' ) as $taxonomy ) {
		$names = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'names' ) );
		if ( ! is_wp_error( $names ) && $names ) {
			sort( $names );
			$terms[] = implode( ', ', $names );
		}
	}
	$statuses = teatatu_events_statuses();
	return array(
		'title'         => get_post_field( 'post_title', $post_id, 'raw' ),
		'excerpt'       => get_post_field( 'post_excerpt', $post_id, 'raw' ),
		'content'       => get_post_field( 'post_content', $post_id, 'raw' ),
		'when'          => teatatu_events_format_when( $f['start'], $f['end'], $f['all_day'] ),
		'status'        => $statuses[ $f['status'] ],
		'place'         => $place['line'],
		'ticket_url'    => $f['ticket_url'],
		'price'         => $f['price'] . ( $f['is_free'] ? ' (' . __( 'Free', 'teatatu-events' ) . ')' : '' ),
		'read_more_url' => $f['read_more_url'],
		'link_mode'     => $f['link_mode'],
		'image_url'     => $f['image_url'],
		'terms'         => implode( ' | ', $terms ),
	);
}

/**
 * The fields where original and draft differ, and whether the original has
 * changed since the copy was made.
 *
 * @param int $draft_id Draft copy ID.
 * @return array {original_id, conflict: bool, diff: [field => [original, draft]]}
 */
function teatatu_events_merge_diff( $draft_id ) {
	$original_id = (int) get_post_meta( $draft_id, teatatu_events_mk( 'draft_of' ), true );
	$base        = (string) get_post_meta( $draft_id, teatatu_events_mk( 'draft_base' ), true );
	$a           = teatatu_events_merge_snapshot( $original_id );
	$b           = teatatu_events_merge_snapshot( $draft_id );
	$diff        = array();
	foreach ( $a as $key => $value ) {
		if ( (string) $value !== (string) $b[ $key ] ) {
			$diff[ $key ] = array( $value, $b[ $key ] );
		}
	}
	return array(
		'original_id' => $original_id,
		'conflict'    => $base !== (string) get_post_field( 'post_modified_gmt', $original_id ),
		'diff'        => $diff,
	);
}

/**
 * Merges a draft copy into its original and deletes the copy.
 *
 * @param int        $draft_id     Draft copy ID.
 * @param array|null $take         When the original changed since the copy was
 *                                 made: the fields (merge_field_labels() keys)
 *                                 to take from the draft; others keep the
 *                                 original's current value. null = no conflict
 *                                 resolution given (returns a conflict error if needed).
 * @param bool       $defer_delete Delete the copy at the end of the request
 *                                 (when an editor's own save response still
 *                                 needs to read it).
 * @return int|WP_Error Original event ID.
 */
function teatatu_events_merge_draft( $draft_id, $take = null, $defer_delete = false ) {
	$original_id = (int) get_post_meta( $draft_id, teatatu_events_mk( 'draft_of' ), true );
	if ( ! $original_id || 'teatatu_event' !== get_post_type( $original_id ) ) {
		return new WP_Error( 'teatatu_events_not_draft_copy', __( 'That is not a draft copy of an event.', 'teatatu-events' ), array( 'status' => 400 ) );
	}
	if ( ! current_user_can( 'edit_post', $original_id ) || ! current_user_can( 'edit_published_teatatu_events_items' ) ) {
		return new WP_Error( 'rest_cannot_edit', __( 'You cannot publish changes to this event.', 'teatatu-events' ), array( 'status' => 403 ) );
	}
	$check = teatatu_events_merge_diff( $draft_id );
	if ( $check['conflict'] && null === $take ) {
		return new WP_Error(
			'teatatu_events_merge_conflict',
			__( 'The live event changed after this draft was made. Review the differences before publishing.', 'teatatu-events' ),
			array( 'status' => 409, 'diff' => $check['diff'] )
		);
	}
	$fields = null === $take ? array_keys( teatatu_events_merge_field_labels() ) : array_intersect( (array) $take, array_keys( teatatu_events_merge_field_labels() ) );

	$result = teatatu_events_batch_write(
		$original_id,
		function ( $id ) use ( $draft_id, $fields ) {
			$postarr = array( 'ID' => $id );
			if ( in_array( 'title', $fields, true ) ) {
				$postarr['post_title'] = get_post_field( 'post_title', $draft_id, 'raw' );
			}
			if ( in_array( 'excerpt', $fields, true ) ) {
				$postarr['post_excerpt'] = get_post_field( 'post_excerpt', $draft_id, 'raw' );
			}
			if ( in_array( 'content', $fields, true ) ) {
				$postarr['post_content'] = get_post_field( 'post_content', $draft_id, 'raw' );
			}
			if ( count( $postarr ) > 1 ) {
				$r = wp_update_post( wp_slash( $postarr ), true );
				if ( is_wp_error( $r ) ) {
					return $r;
				}
			}
			$draft = teatatu_events_get_fields( $draft_id );
			$map   = array(
				'when'          => array( 'start', 'end', 'all_day' ),
				'status'        => array( 'status' ),
				'place'         => array( 'room', 'address' ),
				'ticket_url'    => array( 'ticket_url' ),
				'price'         => array( 'price', 'is_free' ),
				'read_more_url' => array( 'read_more_url' ),
				'link_mode'     => array( 'link_mode' ),
				'image_url'     => array( 'image_url' ),
			);
			$save  = array();
			foreach ( $map as $group => $keys ) {
				if ( in_array( $group, $fields, true ) ) {
					foreach ( $keys as $key ) {
						$save[ $key ] = $draft[ $key ];
					}
				}
			}
			$save['no_linking'] = $draft['no_linking'];
			$r = teatatu_events_save_fields( $id, $save );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
			if ( in_array( 'place', $fields, true ) ) {
				$venue = wp_get_object_terms( $draft_id, 'teatatu_events_venue', array( 'fields' => 'ids' ) );
				wp_set_object_terms( $id, is_wp_error( $venue ) ? array() : array_map( 'intval', $venue ), 'teatatu_events_venue' );
				$override = get_post_meta( $draft_id, teatatu_events_mk( 'nbhd_override' ), true );
				if ( $override ) {
					update_post_meta( $id, teatatu_events_mk( 'nbhd_override' ), $override );
				} else {
					delete_post_meta( $id, teatatu_events_mk( 'nbhd_override' ) );
				}
			}
			if ( in_array( 'terms', $fields, true ) ) {
				foreach ( array( 'teatatu_events_source', 'teatatu_events_category', 'teatatu_events_tag' ) as $taxonomy ) {
					$ids = wp_get_object_terms( $draft_id, $taxonomy, array( 'fields' => 'ids' ) );
					wp_set_object_terms( $id, is_wp_error( $ids ) ? array() : array_map( 'intval', $ids ), $taxonomy );
				}
			}
			if ( in_array( 'status', $fields, true ) && 'scheduled' === $draft['status'] && get_post_meta( $id, teatatu_events_mk( 'removed_at_source' ), true ) ) {
				// Publishing a "back to scheduled" change confirms the event is back.
				delete_post_meta( $id, teatatu_events_mk( 'removed_at_source' ) );
			}
			if ( get_post_meta( $id, teatatu_events_mk( 'series_id' ), true ) ) {
				update_post_meta( $id, teatatu_events_mk( 'detached' ), 1 );
			}
			return $id;
		}
	);
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	$GLOBALS['teatatu_events_merged_drafts'][ $draft_id ] = $original_id;
	if ( $defer_delete ) {
		update_post_meta( $draft_id, teatatu_events_mk( 'merged_into' ), $original_id );
		add_action(
			'shutdown',
			function () use ( $draft_id ) {
				wp_delete_post( $draft_id, true );
			}
		);
	} else {
		wp_delete_post( $draft_id, true );
	}
	return $original_id;
}

/**
 * Discards a draft copy, leaving the original untouched.
 *
 * @param int $original_id Original event ID.
 * @return true|WP_Error
 */
function teatatu_events_discard_draft( $original_id ) {
	$draft_id = teatatu_events_get_draft_copy_id( $original_id );
	if ( ! $draft_id ) {
		return new WP_Error( 'rest_post_invalid_id', __( 'There is no draft copy to discard.', 'teatatu-events' ), array( 'status' => 404 ) );
	}
	if ( ! current_user_can( 'delete_post', $draft_id ) ) {
		return new WP_Error( 'teatatu_events_draft_locked', __( 'You cannot discard someone else\'s draft.', 'teatatu-events' ), array( 'status' => 403 ) );
	}
	wp_delete_post( $draft_id, true );
	return true;
}

add_filter( 'wp_insert_post_data', 'teatatu_events_guard_draft_copy_publish', 10, 2 );

/**
 * Publishing a draft copy from an editor screen merges it — but only when
 * the original hasn't changed meanwhile (otherwise the copy stays a draft
 * and the editor is sent to the review screen) and the user may publish.
 *
 * @param array $data    Sanitised post data.
 * @param array $postarr Raw post array.
 * @return array
 */
function teatatu_events_guard_draft_copy_publish( $data, $postarr ) {
	if ( 'teatatu_event' !== $data['post_type'] || 'publish' !== $data['post_status'] || empty( $postarr['ID'] ) ) {
		return $data;
	}
	$draft_id    = (int) $postarr['ID'];
	$original_id = (int) get_post_meta( $draft_id, teatatu_events_mk( 'draft_of' ), true );
	if ( ! $original_id ) {
		return $data;
	}
	$base = (string) get_post_meta( $draft_id, teatatu_events_mk( 'draft_base' ), true );
	if ( $base !== (string) get_post_field( 'post_modified_gmt', $original_id ) || ! current_user_can( 'edit_post', $original_id ) ) {
		$data['post_status'] = 'draft';
		set_transient( 'teatatu_events_notice_' . get_current_user_id(), __( 'The live event changed after this draft was made, so the changes were not published. Use "Publish changes" to review the differences.', 'teatatu-events' ), 60 );
	}
	return $data;
}

add_action( 'transition_post_status', 'teatatu_events_merge_on_publish', 10, 3 );

/**
 * Merges a draft copy when it transitions to published. The copy itself is
 * deleted at the end of the request, after the editor's response is built.
 *
 * @param string  $new_status New status.
 * @param string  $old_status Old status.
 * @param WP_Post $post       Post.
 */
function teatatu_events_merge_on_publish( $new_status, $old_status, $post ) {
	if ( 'publish' !== $new_status || 'publish' === $old_status || 'teatatu_event' !== $post->post_type ) {
		return;
	}
	if ( ! get_post_meta( $post->ID, teatatu_events_mk( 'draft_of' ), true ) ) {
		return;
	}
	$draft_id = (int) $post->ID;
	// Meta and terms are saved after the status transition; merge once the save is complete.
	add_action(
		'wp_after_insert_post',
		function ( $id ) use ( $draft_id ) {
			if ( (int) $id === $draft_id && get_post( $draft_id ) ) {
				teatatu_events_merge_draft( $draft_id, array_keys( teatatu_events_merge_field_labels() ), true );
			}
		},
		99
	);
}

add_filter( 'redirect_post_location', 'teatatu_events_redirect_after_merge', 10, 2 );

/**
 * After a draft copy is merged from the classic editor, return to the
 * original event instead of the (now deleted) copy.
 *
 * @param string $location Redirect location.
 * @param int    $post_id  Post ID.
 * @return string
 */
function teatatu_events_redirect_after_merge( $location, $post_id ) {
	if ( ! empty( $GLOBALS['teatatu_events_merged_drafts'][ $post_id ] ) ) {
		return add_query_arg( 'message', 1, get_edit_post_link( $GLOBALS['teatatu_events_merged_drafts'][ $post_id ], 'url' ) );
	}
	return $location;
}

/**
 * Row action links for an event (Duplicate / Edit as draft / draft badge).
 *
 * @param int  $post_id   Event ID.
 * @param bool $in_list   Whether for the plugin's list (true) or the native editor panel.
 * @return string[] HTML links.
 */
function teatatu_events_row_actions( $post_id, $in_list = true ) {
	$links = array();
	if ( get_post_meta( $post_id, teatatu_events_mk( 'draft_of' ), true ) || ! current_user_can( 'edit_teatatu_events_items' ) ) {
		return $links;
	}
	$dup = wp_nonce_url( add_query_arg( array( 'action' => 'teatatu_events_duplicate', 'id' => $post_id ), admin_url( 'admin-post.php' ) ), 'teatatu_events_duplicate_' . $post_id );
	$links[] = '<a href="' . esc_url( $dup ) . '">' . esc_html__( 'Duplicate', 'teatatu-events' ) . '</a>';
	if ( 'publish' === get_post_status( $post_id ) ) {
		$copy = teatatu_events_get_draft_copy_id( $post_id );
		if ( $copy ) {
			$links[] = '<a href="' . esc_url( teatatu_events_edit_url( $copy ) ) . '">' . esc_html__( 'Draft changes pending', 'teatatu-events' ) . '</a>';
		} else {
			$draft = wp_nonce_url( add_query_arg( array( 'action' => 'teatatu_events_edit_as_draft', 'id' => $post_id ), admin_url( 'admin-post.php' ) ), 'teatatu_events_edit_as_draft_' . $post_id );
			$links[] = '<a href="' . esc_url( $draft ) . '">' . esc_html__( 'Edit as draft', 'teatatu-events' ) . '</a>';
		}
	}
	return $links;
}

/**
 * Edit URL for an event on the plugin's maintenance page.
 *
 * @param int $post_id Event ID.
 * @return string
 */
function teatatu_events_edit_url( $post_id ) {
	return add_query_arg( array( 'page' => 'teatatu-events', 'tab' => 'events', 'edit' => (int) $post_id ), admin_url( 'admin.php' ) );
}

add_filter( 'post_row_actions', 'teatatu_events_native_row_actions', 10, 2 );

/**
 * Adds Duplicate / Edit as draft to the native Events list.
 *
 * @param string[] $actions Actions.
 * @param WP_Post  $post    Post.
 * @return string[]
 */
function teatatu_events_native_row_actions( $actions, $post ) {
	if ( 'teatatu_event' !== $post->post_type ) {
		return $actions;
	}
	foreach ( teatatu_events_row_actions( $post->ID ) as $i => $link ) {
		$actions[ 'tte_' . $i ] = $link;
	}
	return $actions;
}

add_action( 'admin_post_teatatu_events_duplicate', 'teatatu_events_handle_duplicate' );

/**
 * Handles "Duplicate".
 */
function teatatu_events_handle_duplicate() {
	$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
	check_admin_referer( 'teatatu_events_duplicate_' . $id );
	$copy = teatatu_events_copy_event( $id, 'duplicate' );
	if ( is_wp_error( $copy ) ) {
		wp_die( esc_html( $copy->get_error_message() ), 403 );
	}
	teatatu_events_admin_notice( __( 'Event duplicated as a draft. Set the date for this copy.', 'teatatu-events' ) );
	wp_safe_redirect( add_query_arg( 'focus', 'date', teatatu_events_edit_url( $copy ) ) );
	exit;
}

add_action( 'admin_post_teatatu_events_edit_as_draft', 'teatatu_events_handle_edit_as_draft' );

/**
 * Handles "Edit as draft".
 */
function teatatu_events_handle_edit_as_draft() {
	$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
	check_admin_referer( 'teatatu_events_edit_as_draft_' . $id );
	$copy = teatatu_events_copy_event( $id, 'draft_of' );
	if ( is_wp_error( $copy ) ) {
		wp_die( esc_html( $copy->get_error_message() ), 403 );
	}
	wp_safe_redirect( teatatu_events_edit_url( $copy ) );
	exit;
}

add_action( 'admin_post_teatatu_events_publish_changes', 'teatatu_events_handle_publish_changes' );

/**
 * Handles "Publish changes" for a draft copy, including the field-by-field
 * conflict choices posted from the review screen.
 */
function teatatu_events_handle_publish_changes() {
	$draft_id = isset( $_REQUEST['id'] ) ? absint( $_REQUEST['id'] ) : 0;
	check_admin_referer( 'teatatu_events_publish_changes_' . $draft_id );
	$take = null;
	if ( isset( $_POST['tte_take'] ) ) {
		$take = array_map( 'sanitize_key', (array) wp_unslash( $_POST['tte_take'] ) );
	} elseif ( isset( $_POST['tte_resolved'] ) ) {
		$take = array();
	}
	$result = teatatu_events_merge_draft( $draft_id, $take );
	if ( is_wp_error( $result ) ) {
		if ( 'teatatu_events_merge_conflict' === $result->get_error_code() ) {
			wp_safe_redirect( add_query_arg( array( 'page' => 'teatatu-events', 'tab' => 'events', 'merge' => $draft_id ), admin_url( 'admin.php' ) ) );
			exit;
		}
		wp_die( esc_html( $result->get_error_message() ), 403 );
	}
	teatatu_events_admin_notice( __( 'Changes published to the live event.', 'teatatu-events' ) );
	wp_safe_redirect( teatatu_events_edit_url( $result ) );
	exit;
}

add_action( 'admin_post_teatatu_events_discard_draft', 'teatatu_events_handle_discard_draft' );

/**
 * Handles "Discard draft".
 */
function teatatu_events_handle_discard_draft() {
	$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
	check_admin_referer( 'teatatu_events_discard_draft_' . $id );
	$result = teatatu_events_discard_draft( $id );
	if ( is_wp_error( $result ) ) {
		wp_die( esc_html( $result->get_error_message() ), 403 );
	}
	teatatu_events_admin_notice( __( 'Draft changes discarded.', 'teatatu-events' ) );
	wp_safe_redirect( teatatu_events_edit_url( $id ) );
	exit;
}

add_action( 'transition_post_status', 'teatatu_events_confirm_copy_date', 10, 3 );

/**
 * Clears the "set the date for this copy" marker once the copy is published
 * (the confirmation itself happens in the admin form).
 *
 * @param string  $new_status New status.
 * @param string  $old_status Old status.
 * @param WP_Post $post       Post.
 */
function teatatu_events_confirm_copy_date( $new_status, $old_status, $post ) {
	if ( 'publish' === $new_status && 'teatatu_event' === $post->post_type ) {
		delete_post_meta( $post->ID, teatatu_events_mk( 'copy_needs_date' ) );
	}
}
