<?php
/**
 * The "Teatatu Events" maintenance screen: one place for non-technical
 * editors to manage events, series, places, tags, feeds and linked events.
 *
 * Tabs: Events · Series · Venues · Neighbourhoods · Event Tags · Sources ·
 * Categories · Pending Updates · Linked Events (when enabled) · RSS Feeds ·
 * HTML Feeds · iCal Feeds · Structured Data · Shortcodes.
 *
 * Every form posts to admin_url( 'admin-post.php' ) with a nonce, and every
 * handler checks the narrowest capability and that IDs really are ours.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_menu', 'teatatu_events_register_admin_menu' );

/**
 * Registers the top-level menu, with a count badge for events needing attention.
 */
function teatatu_events_register_admin_menu() {
	$count = current_user_can( 'edit_others_teatatu_events_items' ) ? teatatu_events_attention_count() : 0;
	$title = __( 'Teatatu Events', 'teatatu-events' );
	if ( $count ) {
		$title .= ' <span class="awaiting-mod"><span class="pending-count">' . (int) $count . '</span></span>';
	}
	add_menu_page(
		__( 'Teatatu Events', 'teatatu-events' ),
		$title,
		'edit_teatatu_events_items',
		'teatatu-events',
		'teatatu_events_render_admin_page',
		'dashicons-calendar-alt',
		21
	);
}

/**
 * The tabs the current user can see.
 *
 * @return string[] slug => label
 */
function teatatu_events_admin_tabs() {
	$tabs = array( 'events' => __( 'Events', 'teatatu-events' ) );
	if ( current_user_can( 'manage_teatatu_events_series' ) ) {
		$tabs['series'] = __( 'Series', 'teatatu-events' );
	}
	if ( current_user_can( 'manage_teatatu_events_venues' ) ) {
		$tabs['venues'] = __( 'Venues', 'teatatu-events' );
	}
	if ( current_user_can( 'manage_teatatu_events_neighbourhoods' ) ) {
		$tabs['neighbourhoods'] = __( 'Neighbourhoods', 'teatatu-events' );
	}
	if ( current_user_can( 'manage_teatatu_events_tags' ) ) {
		$tabs['tags'] = __( 'Event Tags', 'teatatu-events' );
	}
	if ( current_user_can( 'manage_teatatu_events_sources' ) ) {
		$tabs['sources'] = __( 'Sources', 'teatatu-events' );
	}
	if ( current_user_can( 'manage_teatatu_events_categories' ) ) {
		$tabs['categories'] = __( 'Categories', 'teatatu-events' );
	}
	if ( current_user_can( 'edit_others_teatatu_events_items' ) ) {
		$tabs['pending'] = __( 'Pending Updates', 'teatatu-events' );
	}
	if ( is_multisite() && teatatu_events_setting( 'allow_linked' ) && current_user_can( 'manage_teatatu_events_links' ) ) {
		$tabs['linked'] = __( 'Linked Events', 'teatatu-events' );
	}
	if ( current_user_can( 'manage_teatatu_events_feeds' ) ) {
		$tabs['feed_rss']  = __( 'RSS Feeds', 'teatatu-events' );
		$tabs['feed_html'] = __( 'HTML Feeds', 'teatatu-events' );
		$tabs['feed_ical'] = __( 'iCal Feeds', 'teatatu-events' );
		$tabs['feed_ld']   = __( 'Structured Data', 'teatatu-events' );
	}
	$tabs['shortcodes'] = __( 'Shortcodes', 'teatatu-events' );
	return $tabs;
}

/**
 * Renders the maintenance page.
 */
function teatatu_events_render_admin_page() {
	if ( ! current_user_can( 'edit_teatatu_events_items' ) ) {
		wp_die( esc_html__( 'You do not have permission to access this page.', 'teatatu-events' ) );
	}
	$tabs = teatatu_events_admin_tabs();
	$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'events'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( ! isset( $tabs[ $tab ] ) ) {
		$tab = 'events';
	}
	?>
	<div class="wrap tte-admin">
		<h1><?php esc_html_e( 'Teatatu Events', 'teatatu-events' ); ?></h1>
		<nav class="nav-tab-wrapper">
			<?php foreach ( $tabs as $slug => $label ) : ?>
				<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'teatatu-events', 'tab' => $slug ), admin_url( 'admin.php' ) ) ); ?>" class="nav-tab <?php echo $slug === $tab ? 'nav-tab-active' : ''; ?>"><?php echo esc_html( $label ); ?></a>
			<?php endforeach; ?>
		</nav>
		<?php teatatu_events_render_admin_notices(); ?>
		<?php
		switch ( $tab ) {
			case 'series':
				teatatu_events_render_series_tab();
				break;
			case 'venues':
				teatatu_events_render_venues_tab();
				break;
			case 'neighbourhoods':
				teatatu_events_render_neighbourhoods_tab();
				break;
			case 'tags':
				teatatu_events_render_term_tab( 'teatatu_events_tag' );
				break;
			case 'sources':
				teatatu_events_render_term_tab( 'teatatu_events_source' );
				break;
			case 'categories':
				teatatu_events_render_term_tab( 'teatatu_events_category' );
				break;
			case 'pending':
				teatatu_events_render_pending_tab();
				break;
			case 'linked':
				teatatu_events_render_linked_tab();
				break;
			case 'feed_rss':
			case 'feed_html':
			case 'feed_ical':
			case 'feed_ld':
				teatatu_events_render_feed_tab( substr( $tab, 5 ) );
				break;
			case 'shortcodes':
				teatatu_events_render_shortcodes_tab();
				break;
			default:
				teatatu_events_render_events_tab();
		}
		?>
	</div>
	<?php
}

// ---------------------------------------------------------------------------
// Notices and small helpers
// ---------------------------------------------------------------------------

/**
 * Stashes a success notice for the next admin page load.
 *
 * @param string $message Message.
 */
function teatatu_events_admin_notice( $message ) {
	if ( $message ) {
		set_transient( 'teatatu_events_admin_notice_' . get_current_user_id(), $message, 60 );
	}
}

/**
 * Stashes an error notice for the next admin page load.
 *
 * @param string $message Message.
 */
function teatatu_events_admin_error( $message ) {
	if ( $message ) {
		set_transient( 'teatatu_events_admin_errors_' . get_current_user_id(), $message, 60 );
	}
}

/**
 * Prints stashed notices.
 */
function teatatu_events_render_admin_notices() {
	$uid = get_current_user_id();
	foreach ( array( 'teatatu_events_admin_notice_' => 'success', 'teatatu_events_admin_errors_' => 'error', 'teatatu_events_notice_' => 'warning' ) as $prefix => $class ) {
		$message = get_transient( $prefix . $uid );
		if ( $message ) {
			delete_transient( $prefix . $uid );
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $class ), esc_html( $message ) );
		}
	}
}

/**
 * Formats a WP_Error, including any extra string data (often the real cause).
 *
 * @param WP_Error $error Error.
 * @return string
 */
function teatatu_events_format_wp_error( $error ) {
	$message = $error->get_error_message();
	$data    = $error->get_error_data();
	if ( is_string( $data ) && '' !== trim( $data ) && false === strpos( $message, $data ) ) {
		$message .= ' (' . $data . ')';
	}
	return $message;
}

/**
 * Stashes submitted form values so a form can be refilled after an error.
 *
 * @param array $data Data.
 */
function teatatu_events_stash_formdata( $data ) {
	set_transient( 'teatatu_events_admin_formdata_' . get_current_user_id(), $data, 60 );
}

/**
 * Retrieves (and clears) stashed form values.
 *
 * @return array
 */
function teatatu_events_get_stashed_formdata() {
	$key  = 'teatatu_events_admin_formdata_' . get_current_user_id();
	$data = get_transient( $key );
	if ( $data ) {
		delete_transient( $key );
		return (array) $data;
	}
	return array();
}

/**
 * Number of events needing attention (needs venue / neighbourhood, removed
 * at source, feed update pending).
 *
 * @return int
 */
function teatatu_events_attention_count() {
	$cached = get_transient( 'teatatu_events_attention_count' );
	if ( false !== $cached ) {
		return (int) $cached;
	}
	$count = count( teatatu_events_attention_ids( 200 ) );
	set_transient( 'teatatu_events_attention_count', $count, 5 * MINUTE_IN_SECONDS );
	return $count;
}
add_action( 'teatatu_events_event_changed', function () {
	delete_transient( 'teatatu_events_attention_count' );
} );

/**
 * IDs of events needing attention.
 *
 * @param int $limit Max.
 * @return int[]
 */
function teatatu_events_attention_ids( $limit = 100 ) {
	return get_posts(
		array(
			'post_type'        => 'teatatu_event',
			'post_status'      => array( 'publish', 'draft', 'pending' ),
			'posts_per_page'   => $limit,
			'fields'           => 'ids',
			'suppress_filters' => true,
			'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'relation' => 'OR',
				array( 'key' => teatatu_events_mk( 'needs_venue' ), 'compare' => 'EXISTS' ),
				array( 'key' => teatatu_events_mk( 'needs_nbhd' ), 'compare' => 'EXISTS' ),
				array( 'key' => teatatu_events_mk( 'removed_at_source' ), 'compare' => 'EXISTS' ),
				array( 'key' => teatatu_events_mk( 'pending_update' ), 'compare' => 'EXISTS' ),
			),
		)
	);
}

/**
 * Source / Category / Event Tag pickers for the events and series forms.
 *
 * @param array $selected taxonomy => term IDs.
 */
function teatatu_events_render_term_pickers( $selected ) {
	?>
	<table class="form-table" role="presentation">
		<?php foreach ( array( 'teatatu_events_source' => __( 'Source', 'teatatu-events' ), 'teatatu_events_category' => __( 'Category', 'teatatu-events' ) ) as $taxonomy => $label ) : ?>
			<tr>
				<th><?php echo esc_html( $label ); ?></th>
				<td>
					<?php
					wp_dropdown_categories(
						array(
							'taxonomy'          => $taxonomy,
							'name'              => 'tte_terms[' . $taxonomy . ']',
							'hide_empty'        => false,
							'hierarchical'      => true,
							'show_option_none'  => __( '— None —', 'teatatu-events' ),
							'option_none_value' => 0,
							'selected'          => isset( $selected[ $taxonomy ][0] ) ? (int) $selected[ $taxonomy ][0] : 0,
						)
					);
					?>
				</td>
			</tr>
		<?php endforeach; ?>
		<tr>
			<th><?php esc_html_e( 'Event Tags', 'teatatu-events' ); ?></th>
			<td>
				<?php
				$tags = get_terms( array( 'taxonomy' => 'teatatu_events_tag', 'hide_empty' => false ) );
				if ( ! $tags || is_wp_error( $tags ) ) {
					esc_html_e( 'No Event Tags yet (add them on the Event Tags tab).', 'teatatu-events' );
				} else {
					foreach ( $tags as $tag ) {
						$checked = in_array( (int) $tag->term_id, array_map( 'intval', $selected['teatatu_events_tag'] ?? array() ), true );
						echo '<label style="margin-right:14px;display:inline-block;"><input type="checkbox" name="tte_terms[teatatu_events_tag][]" value="' . esc_attr( $tag->term_id ) . '" ' . checked( $checked, true, false ) . ' /> ' . esc_html( $tag->name ) . '</label>';
					}
				}
				?>
			</td>
		</tr>
	</table>
	<?php
}

/**
 * Reads term picker values from a request.
 *
 * @param array $src Request data.
 * @return array taxonomy => int[]
 */
function teatatu_events_terms_from_form( $src ) {
	$in  = isset( $src['tte_terms'] ) && is_array( $src['tte_terms'] ) ? $src['tte_terms'] : array();
	$out = array();
	foreach ( array( 'teatatu_events_source', 'teatatu_events_category', 'teatatu_events_tag' ) as $taxonomy ) {
		$out[ $taxonomy ] = array_values( array_filter( array_map( 'absint', (array) ( $in[ $taxonomy ] ?? array() ) ) ) );
	}
	return $out;
}

// ---------------------------------------------------------------------------
// Events tab
// ---------------------------------------------------------------------------

/**
 * Renders the Events tab: the merge review, the add/edit form, and the
 * filtered list.
 */
function teatatu_events_render_events_tab() {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended
	if ( isset( $_GET['merge'] ) ) {
		teatatu_events_render_merge_review( absint( $_GET['merge'] ) );
		return;
	}
	$editing_id = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0;
	$view       = isset( $_GET['view'] ) ? sanitize_key( $_GET['view'] ) : 'upcoming';
	// phpcs:enable
	$editing = ( $editing_id && 'teatatu_event' === get_post_type( $editing_id ) && current_user_can( 'edit_post', $editing_id ) ) ? get_post( $editing_id ) : null;
	$stashed = teatatu_events_get_stashed_formdata();

	$selected = array();
	if ( $editing ) {
		foreach ( array( 'teatatu_events_source', 'teatatu_events_category', 'teatatu_events_tag' ) as $taxonomy ) {
			$ids                   = wp_get_object_terms( $editing->ID, $taxonomy, array( 'fields' => 'ids' ) );
			$selected[ $taxonomy ] = is_wp_error( $ids ) ? array() : $ids;
		}
	}
	$draft_of = $editing ? (int) get_post_meta( $editing->ID, teatatu_events_mk( 'draft_of' ), true ) : 0;
	?>
	<h2>
		<?php
		if ( $draft_of ) {
			/* translators: %s: title. */
			echo esc_html( sprintf( __( 'Draft changes to: %s', 'teatatu-events' ), get_the_title( $draft_of ) ) );
		} else {
			echo $editing ? esc_html__( 'Edit Event', 'teatatu-events' ) : esc_html__( 'Add Event', 'teatatu-events' );
		}
		?>
	</h2>
	<?php if ( $editing && get_post_meta( $editing->ID, teatatu_events_mk( 'copy_needs_date' ), true ) ) : ?>
		<div class="notice notice-info inline"><p><?php esc_html_e( 'This is a copy: set the date for this copy before publishing.', 'teatatu-events' ); ?></p></div>
	<?php endif; ?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'teatatu_events_save_event', 'teatatu_events_event_nonce' ); ?>
		<input type="hidden" name="action" value="teatatu_events_save_event" />
		<input type="hidden" name="post_id" value="<?php echo esc_attr( $editing ? $editing->ID : 0 ); ?>" />
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="tte_title"><?php esc_html_e( 'Title', 'teatatu-events' ); ?> *</label></th>
				<td><input type="text" id="tte_title" name="title" class="regular-text" required value="<?php echo esc_attr( $stashed['title'] ?? ( $editing ? $editing->post_title : '' ) ); ?>" /></td>
			</tr>
			<tr>
				<th><label for="tte_excerpt"><?php esc_html_e( 'Excerpt', 'teatatu-events' ); ?></label></th>
				<td><textarea id="tte_excerpt" name="excerpt" class="large-text" rows="2"><?php echo esc_textarea( $stashed['excerpt'] ?? ( $editing ? $editing->post_excerpt : '' ) ); ?></textarea>
				<p class="description"><?php esc_html_e( 'One or two sentences shown on event cards.', 'teatatu-events' ); ?></p></td>
			</tr>
			<tr>
				<th><label for="tte_content"><?php esc_html_e( 'Description', 'teatatu-events' ); ?></label></th>
				<td><textarea id="tte_content" name="content" class="large-text" rows="6"><?php echo esc_textarea( $stashed['content'] ?? ( $editing ? $editing->post_content : '' ) ); ?></textarea>
				<p class="description"><?php esc_html_e( "Shown on the event's own page (for events without a Read More URL, or set to link locally).", 'teatatu-events' ); ?></p></td>
			</tr>
		</table>
		<?php teatatu_events_render_event_fields( $editing ? $editing->ID : 0, $stashed ); ?>
		<?php teatatu_events_render_term_pickers( $selected ); ?>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="tte_post_status"><?php esc_html_e( 'Publish status', 'teatatu-events' ); ?></label></th>
				<td>
					<?php $current = $stashed['post_status'] ?? ( $editing ? $editing->post_status : 'draft' ); ?>
					<select id="tte_post_status" name="post_status">
						<option value="draft" <?php selected( $current, 'draft' ); ?>><?php esc_html_e( 'Draft', 'teatatu-events' ); ?></option>
						<option value="pending" <?php selected( $current, 'pending' ); ?>><?php esc_html_e( 'Pending Review', 'teatatu-events' ); ?></option>
						<?php if ( current_user_can( 'publish_teatatu_events_items' ) && ! $draft_of ) : ?>
							<option value="publish" <?php selected( $current, 'publish' ); ?>><?php esc_html_e( 'Published', 'teatatu-events' ); ?></option>
						<?php endif; ?>
					</select>
					<?php if ( $editing && get_post_meta( $editing->ID, teatatu_events_mk( 'copy_needs_date' ), true ) ) : ?>
						<label style="margin-left:12px;"><input type="checkbox" name="confirm_same_date" value="1" /> <?php esc_html_e( 'Yes, publish this copy on the same date as the original', 'teatatu-events' ); ?></label>
					<?php endif; ?>
				</td>
			</tr>
		</table>
		<?php
		submit_button( $editing ? __( 'Save Event', 'teatatu-events' ) : __( 'Add Event', 'teatatu-events' ), 'primary', 'submit', false );
		if ( $draft_of && current_user_can( 'edit_post', $draft_of ) && current_user_can( 'edit_published_teatatu_events_items' ) ) {
			echo ' <button type="submit" class="button button-primary" name="publish_changes" value="1">' . esc_html__( 'Save & publish changes', 'teatatu-events' ) . '</button>';
		}
		?>
	</form>
	<?php if ( $editing ) : ?>
		<div class="tte-status-summary" style="margin-top:16px;padding:12px;background:#fff;border:1px solid #dcdcde;">
			<?php teatatu_events_render_event_status_summary( $editing->ID ); ?>
			<p><a href="<?php echo esc_url( get_edit_post_link( $editing->ID ) ); ?>"><?php esc_html_e( 'Open in the full editor', 'teatatu-events' ); ?></a>
			<?php if ( 'publish' === $editing->post_status ) : ?> | <a href="<?php echo esc_url( get_permalink( $editing ) ); ?>" target="_blank"><?php esc_html_e( 'View', 'teatatu-events' ); ?></a><?php endif; ?></p>
		</div>
	<?php endif; ?>
	<hr />
	<?php
	teatatu_events_render_events_list( $view );
}

/**
 * The event list with view filters.
 *
 * @param string $view upcoming|past|drafts|attention|all.
 */
function teatatu_events_render_events_list( $view ) {
	$views = array(
		'upcoming'  => __( 'Upcoming', 'teatatu-events' ),
		'past'      => __( 'Past', 'teatatu-events' ),
		'drafts'    => __( 'Drafts', 'teatatu-events' ),
		'attention' => __( 'Needs attention', 'teatatu-events' ),
		'all'       => __( 'All', 'teatatu-events' ),
	);
	if ( ! isset( $views[ $view ] ) ) {
		$view = 'upcoming';
	}
	$paged = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$args  = array(
		'post_type'      => 'teatatu_event',
		'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
		'posts_per_page' => 25,
		'paged'          => $paged,
	);
	switch ( $view ) {
		case 'upcoming':
			$args = array_merge( $args, teatatu_events_query_args( 'upcoming' ) );
			break;
		case 'past':
			$args = array_merge( $args, teatatu_events_query_args( 'past', null, null, 'DESC' ) );
			break;
		case 'drafts':
			$args['post_status'] = array( 'draft', 'pending' );
			$args['orderby']     = 'modified';
			break;
		case 'attention':
			$args['post__in'] = teatatu_events_attention_ids( 500 );
			$args['post__in'] = $args['post__in'] ? $args['post__in'] : array( 0 );
			break;
		default:
			$args = array_merge( $args, teatatu_events_query_args( 'all', null, null, 'DESC' ) );
	}
	if ( ! current_user_can( 'edit_others_teatatu_events_items' ) ) {
		$args['author'] = get_current_user_id();
	}
	$query = new WP_Query( $args );
	?>
	<ul class="subsubsub">
		<?php
		$links = array();
		foreach ( $views as $slug => $label ) {
			$count   = 'attention' === $slug ? ' (' . teatatu_events_attention_count() . ')' : '';
			$links[] = '<li><a href="' . esc_url( add_query_arg( array( 'page' => 'teatatu-events', 'tab' => 'events', 'view' => $slug ), admin_url( 'admin.php' ) ) ) . '" class="' . ( $slug === $view ? 'current' : '' ) . '">' . esc_html( $label . $count ) . '</a>';
		}
		echo implode( ' | </li>', $links ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		?>
	</ul>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="clear:both;">
	<?php wp_nonce_field( 'teatatu_events_bulk_events', 'teatatu_events_bulk_nonce' ); ?>
	<input type="hidden" name="action" value="teatatu_events_bulk_events" />
	<div class="tablenav top">
		<?php teatatu_events_render_bulk_controls(); ?>
		<div class="alignleft actions"><?php echo teatatu_events_publish_all_button(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?></div>
	</div>
	<table class="wp-list-table widefat fixed striped" style="clear:both;">
		<thead><tr>
			<td class="manage-column column-cb check-column"><input type="checkbox" class="tte-check-all" aria-label="<?php esc_attr_e( 'Select all', 'teatatu-events' ); ?>" /></td>
			<th style="width:18%;"><?php esc_html_e( 'When', 'teatatu-events' ); ?></th>
			<th><?php esc_html_e( 'Title', 'teatatu-events' ); ?></th>
			<th><?php esc_html_e( 'Place', 'teatatu-events' ); ?></th>
			<th><?php esc_html_e( 'Tags', 'teatatu-events' ); ?></th>
			<th><?php esc_html_e( 'Status', 'teatatu-events' ); ?></th>
			<th><?php esc_html_e( 'Origin', 'teatatu-events' ); ?></th>
			<th><?php esc_html_e( 'Actions', 'teatatu-events' ); ?></th>
		</tr></thead>
		<tbody>
		<?php if ( ! $query->have_posts() ) : ?>
			<tr><td colspan="8"><?php esc_html_e( 'No events here.', 'teatatu-events' ); ?></td></tr>
		<?php endif; ?>
		<?php foreach ( $query->posts as $p ) : ?>
			<?php
			$id       = $p->ID;
			$statuses = teatatu_events_statuses();
			$place    = teatatu_events_get_place( $id );
			$nbhd     = teatatu_events_first_term( $id, 'teatatu_events_neighbourhood' );
			$actions  = array();
			if ( current_user_can( 'edit_post', $id ) ) {
				$actions[] = '<a href="' . esc_url( teatatu_events_edit_url( $id ) ) . '">' . esc_html__( 'Edit', 'teatatu-events' ) . '</a>';
			}
			$actions = array_merge( $actions, teatatu_events_row_actions( $id ) );
			if ( current_user_can( 'delete_post', $id ) ) {
				$actions[] = '<a href="' . esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'teatatu_events_trash_event', 'id' => $id ), admin_url( 'admin-post.php' ) ), 'teatatu_events_trash_event_' . $id ) ) . '">' . esc_html__( 'Trash', 'teatatu-events' ) . '</a>';
			}
			?>
			<tr>
				<th scope="row" class="check-column"><input type="checkbox" name="event_ids[]" value="<?php echo esc_attr( $id ); ?>" aria-label="<?php echo esc_attr( get_the_title( $p ) ); ?>" /></th>
				<td><?php echo esc_html( teatatu_events_format_when( teatatu_events_get_start( $id ), teatatu_events_get_end( $id ), teatatu_events_is_all_day( $id ) ) ); ?></td>
				<td><strong><?php echo esc_html( get_the_title( $p ) ); ?></strong><br /><span class="description"><?php echo esc_html( get_post_status_object( $p->post_status )->label ); ?></span></td>
				<td><?php echo esc_html( $place['line'] ? $place['line'] : '—' ); ?><?php if ( $nbhd ) : ?><br /><span class="description"><?php echo esc_html( $nbhd->name ); ?></span><?php endif; ?></td>
				<td><?php echo esc_html( teatatu_events_term_names( $id, 'teatatu_events_tag' ) ); ?></td>
				<td><?php echo esc_html( $statuses[ teatatu_events_get_status( $id ) ] ); ?><?php echo teatatu_events_flag_badges( $id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
				<td><?php echo esc_html( teatatu_events_origin_label( $id ) ); ?></td>
				<td><?php echo implode( ' | ', $actions ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php if ( 'attention' === $view ) : ?>
						<br /><?php echo teatatu_events_attention_actions( $id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php endif; ?>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	</form>
	<?php
	teatatu_events_check_all_script();
	if ( $query->max_num_pages > 1 ) {
		echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( paginate_links( array( 'base' => add_query_arg( 'paged', '%#%' ), 'format' => '', 'current' => $paged, 'total' => $query->max_num_pages ) ) ) . '</div></div>';
	}
}

/**
 * Quick actions for an event in the Needs attention view.
 *
 * @param int $id Event ID.
 * @return string HTML.
 */
function teatatu_events_attention_actions( $id ) {
	$out = array();
	$url = function ( $do ) use ( $id ) {
		return wp_nonce_url( add_query_arg( array( 'action' => 'teatatu_events_attention', 'do' => $do, 'post_id' => $id ), admin_url( 'admin-post.php' ) ), 'teatatu_events_attention_' . $do . '_' . $id );
	};
	if ( get_post_meta( $id, teatatu_events_mk( 'removed_at_source' ), true ) ) {
		$out[] = '<a href="' . esc_url( $url( 'confirm_removed' ) ) . '">' . esc_html__( 'Confirm (keep cancelled)', 'teatatu-events' ) . '</a>';
		$out[] = '<a href="' . esc_url( $url( 'restore_removed' ) ) . '">' . esc_html__( 'Restore', 'teatatu-events' ) . '</a>';
	}
	if ( get_post_meta( $id, teatatu_events_mk( 'needs_venue' ), true ) && current_user_can( 'manage_teatatu_events_venues' ) ) {
		$address = (string) get_post_meta( $id, teatatu_events_mk( 'address' ), true );
		$create  = add_query_arg( array( 'page' => 'teatatu-events', 'tab' => 'venues', 'from_event' => $id, 'paste' => rawurlencode( $address ) ), admin_url( 'admin.php' ) );
		$out[]   = '<a href="' . esc_url( teatatu_events_edit_url( $id ) ) . '">' . esc_html__( 'Assign Location', 'teatatu-events' ) . '</a>';
		$out[]   = '<a href="' . esc_url( $create ) . '">' . esc_html__( 'Create venue from this address', 'teatatu-events' ) . '</a>';
		$out[]   = '<a href="' . esc_url( $url( 'keep_address' ) ) . '">' . esc_html__( 'Keep address only', 'teatatu-events' ) . '</a>';
	}
	if ( get_post_meta( $id, teatatu_events_mk( 'needs_nbhd' ), true ) ) {
		$out[] = '<a href="' . esc_url( $url( 'dismiss_nbhd' ) ) . '">' . esc_html__( 'Dismiss neighbourhood flag', 'teatatu-events' ) . '</a>';
	}
	if ( is_array( get_post_meta( $id, teatatu_events_mk( 'pending_update' ), true ) ) && current_user_can( 'edit_post', $id ) ) {
		$out[] = '<a href="' . esc_url( teatatu_events_pending_update_url( $id, 'apply' ) ) . '" title="' . esc_attr( teatatu_events_pending_update_summary( $id ) ) . '">' . esc_html__( 'Apply feed update', 'teatatu-events' ) . '</a>';
		$out[] = '<a href="' . esc_url( teatatu_events_pending_update_url( $id, 'dismiss' ) ) . '">' . esc_html__( 'Dismiss feed update', 'teatatu-events' ) . '</a>';
	}
	return implode( ' | ', $out );
}

/**
 * Field-by-field review when publishing a draft copy whose original changed.
 *
 * @param int $draft_id Draft copy ID.
 */
function teatatu_events_render_merge_review( $draft_id ) {
	$original = (int) get_post_meta( $draft_id, teatatu_events_mk( 'draft_of' ), true );
	if ( ! $original || ! current_user_can( 'edit_post', $original ) ) {
		echo '<p>' . esc_html__( 'Nothing to review.', 'teatatu-events' ) . '</p>';
		return;
	}
	$check  = teatatu_events_merge_diff( $draft_id );
	$labels = teatatu_events_merge_field_labels();
	?>
	<h2><?php esc_html_e( 'Review changes before publishing', 'teatatu-events' ); ?></h2>
	<p><?php esc_html_e( 'The live event changed after this draft was made (by an editor, a feed update or a removed-at-source cancellation). Choose which version of each field to keep.', 'teatatu-events' ); ?></p>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'teatatu_events_publish_changes_' . $draft_id ); ?>
		<input type="hidden" name="action" value="teatatu_events_publish_changes" />
		<input type="hidden" name="id" value="<?php echo esc_attr( $draft_id ); ?>" />
		<input type="hidden" name="tte_resolved" value="1" />
		<table class="wp-list-table widefat striped">
			<thead><tr><th><?php esc_html_e( 'Field', 'teatatu-events' ); ?></th><th><?php esc_html_e( 'Live event now', 'teatatu-events' ); ?></th><th><?php esc_html_e( 'Your draft', 'teatatu-events' ); ?></th><th><?php esc_html_e( 'Use draft?', 'teatatu-events' ); ?></th></tr></thead>
			<tbody>
			<?php if ( ! $check['diff'] ) : ?>
				<tr><td colspan="4"><?php esc_html_e( 'No differences.', 'teatatu-events' ); ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $check['diff'] as $key => $pair ) : ?>
				<tr>
					<td><strong><?php echo esc_html( $labels[ $key ] ?? $key ); ?></strong></td>
					<td><?php echo esc_html( wp_trim_words( wp_strip_all_tags( (string) $pair[0] ), 40 ) ); ?></td>
					<td><?php echo esc_html( wp_trim_words( wp_strip_all_tags( (string) $pair[1] ), 40 ) ); ?></td>
					<td><input type="checkbox" name="tte_take[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( 'status' !== $key ); ?> /></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php submit_button( __( 'Publish selected changes', 'teatatu-events' ) ); ?>
	</form>
	<?php
}

add_action( 'admin_post_teatatu_events_save_event', 'teatatu_events_handle_save_event' );

/**
 * Saves the maintenance page's event form.
 */
function teatatu_events_handle_save_event() {
	if ( ! isset( $_POST['teatatu_events_event_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['teatatu_events_event_nonce'] ) ), 'teatatu_events_save_event' ) ) {
		wp_die( esc_html__( 'Security check failed.', 'teatatu-events' ) );
	}
	if ( ! current_user_can( 'edit_teatatu_events_items' ) ) {
		wp_die( esc_html__( 'You do not have permission to do that.', 'teatatu-events' ), 403 );
	}
	$src     = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised per field below.
	$post_id = absint( $src['post_id'] ?? 0 );
	$title   = sanitize_text_field( $src['title'] ?? '' );
	$status  = sanitize_key( $src['post_status'] ?? 'draft' );
	$back    = function ( $id ) {
		return $id ? teatatu_events_edit_url( $id ) : add_query_arg( array( 'page' => 'teatatu-events', 'tab' => 'events' ), admin_url( 'admin.php' ) );
	};
	$fail    = function ( $message ) use ( $src, $post_id, $back ) {
		teatatu_events_admin_error( $message );
		teatatu_events_stash_formdata( $src );
		wp_safe_redirect( $back( $post_id ) );
		exit;
	};

	if ( '' === trim( $title ) ) {
		$fail( __( 'Title is required.', 'teatatu-events' ) );
	}
	if ( $post_id && ( 'teatatu_event' !== get_post_type( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) ) {
		$fail( __( 'You cannot edit that event.', 'teatatu-events' ) );
	}
	$allowed = array( 'draft', 'pending' );
	$draft_of = $post_id ? (int) get_post_meta( $post_id, teatatu_events_mk( 'draft_of' ), true ) : 0;
	if ( current_user_can( 'publish_teatatu_events_items' ) && ! $draft_of ) {
		$allowed[] = 'publish';
	}
	if ( ! in_array( $status, $allowed, true ) ) {
		$status = 'draft';
	}
	if ( 'publish' === $status && $post_id && get_post_meta( $post_id, teatatu_events_mk( 'copy_needs_date' ), true ) && empty( $src['confirm_same_date'] ) ) {
		$original = (int) get_post_meta( $post_id, teatatu_events_mk( 'copied_from' ), true );
		$fields   = teatatu_events_fields_from_form( $src );
		$new      = teatatu_events_parse_local_datetime( $fields['start'] );
		if ( $original && $new && wp_date( 'Y-m-d', $new ) === wp_date( 'Y-m-d', teatatu_events_get_start( $original ) ) ) {
			$fail( __( 'This copy still has the same date as the event it was copied from. Change the date, or tick the box to confirm.', 'teatatu-events' ) );
		}
	}

	$terms  = teatatu_events_terms_from_form( $src );
	$result = teatatu_events_batch_write(
		$post_id,
		function ( $id ) use ( $src, $title, $status, $terms ) {
			$postarr = array(
				'post_title'   => $title,
				'post_excerpt' => sanitize_textarea_field( $src['excerpt'] ?? '' ),
				'post_content' => wp_kses_post( $src['content'] ?? '' ),
				'post_status'  => $status,
			);
			if ( $id ) {
				$postarr['ID'] = $id; // post_type deliberately omitted: never retype a post.
				$r             = wp_update_post( wp_slash( $postarr ), true );
			} else {
				$postarr['post_type'] = 'teatatu_event';
				$r                    = wp_insert_post( wp_slash( $postarr ), true );
			}
			if ( is_wp_error( $r ) ) {
				return $r;
			}
			$id = (int) $r;
			$f  = teatatu_events_save_event_form( $id, $src );
			if ( is_wp_error( $f ) ) {
				return $f;
			}
			foreach ( $terms as $taxonomy => $ids ) {
				$cap = 'teatatu_events_source' === $taxonomy ? 'assign_teatatu_events_sources' : ( 'teatatu_events_category' === $taxonomy ? 'assign_teatatu_events_categories' : 'assign_teatatu_events_tags' );
				if ( current_user_can( $cap ) ) {
					wp_set_object_terms( $id, $ids, $taxonomy );
				}
			}
			if ( get_post_meta( $id, teatatu_events_mk( 'series_id' ), true ) && ! get_post_meta( $id, teatatu_events_mk( 'draft_of' ), true ) ) {
				update_post_meta( $id, teatatu_events_mk( 'detached' ), 1 );
			}
			return $id;
		}
	);
	if ( is_wp_error( $result ) ) {
		$fail( teatatu_events_format_wp_error( $result ) );
	}

	if ( ! empty( $src['publish_changes'] ) && $draft_of ) {
		$merged = teatatu_events_merge_draft( $result );
		if ( is_wp_error( $merged ) ) {
			if ( 'teatatu_events_merge_conflict' === $merged->get_error_code() ) {
				wp_safe_redirect( add_query_arg( array( 'page' => 'teatatu-events', 'tab' => 'events', 'merge' => $result ), admin_url( 'admin.php' ) ) );
				exit;
			}
			$fail( $merged->get_error_message() );
		}
		teatatu_events_admin_notice( __( 'Changes published to the live event.', 'teatatu-events' ) );
		wp_safe_redirect( $back( $merged ) );
		exit;
	}
	teatatu_events_admin_notice( __( 'Event saved.', 'teatatu-events' ) );
	wp_safe_redirect( $back( $result ) );
	exit;
}

add_action( 'admin_post_teatatu_events_trash_event', 'teatatu_events_handle_trash_event' );

/**
 * Moves an event to the trash.
 */
function teatatu_events_handle_trash_event() {
	$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
	check_admin_referer( 'teatatu_events_trash_event_' . $id );
	if ( ! $id || 'teatatu_event' !== get_post_type( $id ) || ! current_user_can( 'delete_post', $id ) ) {
		wp_die( esc_html__( 'You do not have permission to do that.', 'teatatu-events' ), 403 );
	}
	wp_trash_post( $id );
	teatatu_events_admin_notice( __( 'Event moved to the trash.', 'teatatu-events' ) );
	wp_safe_redirect( add_query_arg( array( 'page' => 'teatatu-events', 'tab' => 'events' ), admin_url( 'admin.php' ) ) );
	exit;
}

// ---------------------------------------------------------------------------
// Event Tags / Sources / Categories (shared term tab)
// ---------------------------------------------------------------------------

/**
 * Settings for each simple term tab.
 *
 * @param string $taxonomy Taxonomy.
 * @return array {tab, cap, label, plural}
 */
function teatatu_events_term_tab_def( $taxonomy ) {
	$defs = array(
		'teatatu_events_tag'      => array( 'tags', 'manage_teatatu_events_tags', __( 'Event Tag', 'teatatu-events' ), __( 'Event Tags', 'teatatu-events' ) ),
		'teatatu_events_source'   => array( 'sources', 'manage_teatatu_events_sources', __( 'Source', 'teatatu-events' ), __( 'Sources', 'teatatu-events' ) ),
		'teatatu_events_category' => array( 'categories', 'manage_teatatu_events_categories', __( 'Category', 'teatatu-events' ), __( 'Categories', 'teatatu-events' ) ),
	);
	return isset( $defs[ $taxonomy ] ) ? array_combine( array( 'tab', 'cap', 'label', 'plural' ), $defs[ $taxonomy ] ) : null;
}

/**
 * Renders the Event Tags / Sources / Categories tab.
 *
 * @param string $taxonomy Taxonomy.
 */
function teatatu_events_render_term_tab( $taxonomy ) {
	$def = teatatu_events_term_tab_def( $taxonomy );
	if ( ! $def || ! current_user_can( $def['cap'] ) ) {
		echo '<p>' . esc_html__( 'You do not have permission to manage this.', 'teatatu-events' ) . '</p>';
		return;
	}
	$editing_id = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$editing    = $editing_id ? get_term( $editing_id, $taxonomy ) : null;
	$editing    = ( $editing && ! is_wp_error( $editing ) ) ? $editing : null;
	$stashed    = teatatu_events_get_stashed_formdata();
	$hier       = is_taxonomy_hierarchical( $taxonomy );
	if ( 'teatatu_events_tag' === $taxonomy ) {
		echo '<p>' . esc_html__( 'Event Tags are a fixed list editors manage (e.g. Show, Garage sale, Community event, Fundraiser). Imports and AI agents can only use tags that exist here.', 'teatatu-events' ) . '</p>';
	}
	?>
	<h2><?php echo esc_html( ( $editing ? __( 'Edit', 'teatatu-events' ) : __( 'Add', 'teatatu-events' ) ) . ' ' . $def['label'] ); ?></h2>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'teatatu_events_save_term', 'teatatu_events_term_nonce' ); ?>
		<input type="hidden" name="action" value="teatatu_events_save_term" />
		<input type="hidden" name="taxonomy" value="<?php echo esc_attr( $taxonomy ); ?>" />
		<input type="hidden" name="term_id" value="<?php echo esc_attr( $editing ? $editing->term_id : 0 ); ?>" />
		<table class="form-table">
			<tr>
				<th><label for="tte_term_name"><?php esc_html_e( 'Name', 'teatatu-events' ); ?> *</label></th>
				<td><input type="text" id="tte_term_name" name="name" class="regular-text" required value="<?php echo esc_attr( $stashed['name'] ?? ( $editing ? $editing->name : '' ) ); ?>" /></td>
			</tr>
			<?php if ( $hier ) : ?>
				<tr>
					<th><?php esc_html_e( 'Parent', 'teatatu-events' ); ?></th>
					<td><?php wp_dropdown_categories( array( 'taxonomy' => $taxonomy, 'name' => 'parent', 'hide_empty' => false, 'hierarchical' => true, 'show_option_none' => __( '— None —', 'teatatu-events' ), 'option_none_value' => 0, 'exclude_tree' => $editing ? $editing->term_id : 0, 'selected' => $editing ? $editing->parent : 0 ) ); ?></td>
				</tr>
			<?php endif; ?>
			<tr>
				<th><label for="tte_term_description"><?php esc_html_e( 'Description', 'teatatu-events' ); ?></label></th>
				<td><textarea id="tte_term_description" name="description" class="large-text" rows="2"><?php echo esc_textarea( $stashed['description'] ?? ( $editing ? $editing->description : '' ) ); ?></textarea></td>
			</tr>
			<?php if ( 'teatatu_events_source' === $taxonomy ) : ?>
				<tr>
					<th><label for="tte_term_image"><?php esc_html_e( 'Default Image URL', 'teatatu-events' ); ?></label></th>
					<td><input type="url" id="tte_term_image" name="default_image" class="large-text" value="<?php echo esc_attr( $editing ? get_term_meta( $editing->term_id, 'teatatu_events_source_default_image', true ) : '' ); ?>" /></td>
				</tr>
			<?php elseif ( 'teatatu_events_tag' === $taxonomy ) : ?>
				<tr>
					<th><label for="tte_term_color"><?php esc_html_e( 'Badge colour', 'teatatu-events' ); ?></label></th>
					<td><input type="color" id="tte_term_color" name="color" value="<?php echo esc_attr( $editing ? ( get_term_meta( $editing->term_id, 'teatatu_events_tag_color', true ) ? get_term_meta( $editing->term_id, 'teatatu_events_tag_color', true ) : '#2271b1' ) : '#2271b1' ); ?>" /></td>
				</tr>
			<?php endif; ?>
		</table>
		<?php submit_button( $editing ? __( 'Update', 'teatatu-events' ) : __( 'Add New', 'teatatu-events' ) ); ?>
	</form>
	<hr />
	<h2><?php echo esc_html( $def['plural'] ); ?></h2>
	<?php $terms = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false ) ); ?>
	<table class="wp-list-table widefat fixed striped">
		<thead><tr><th><?php esc_html_e( 'Name', 'teatatu-events' ); ?></th><th><?php esc_html_e( 'Slug', 'teatatu-events' ); ?></th><th><?php esc_html_e( 'Description', 'teatatu-events' ); ?></th><th><?php esc_html_e( 'Events', 'teatatu-events' ); ?></th><th><?php esc_html_e( 'Actions', 'teatatu-events' ); ?></th></tr></thead>
		<tbody>
		<?php if ( ! $terms || is_wp_error( $terms ) ) : ?>
			<tr><td colspan="5"><?php esc_html_e( 'None yet.', 'teatatu-events' ); ?></td></tr>
		<?php else : ?>
			<?php foreach ( $terms as $term ) : ?>
				<tr>
					<td><?php echo esc_html( $term->name ); ?></td>
					<td><code><?php echo esc_html( $term->slug ); ?></code></td>
					<td><?php echo esc_html( $term->description ); ?></td>
					<td><?php echo esc_html( $term->count ); ?></td>
					<td>
						<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'teatatu-events', 'tab' => $def['tab'], 'edit' => $term->term_id ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Edit', 'teatatu-events' ); ?></a>
						| <a href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'teatatu_events_delete_term', 'taxonomy' => $taxonomy, 'term_id' => $term->term_id ), admin_url( 'admin-post.php' ) ), 'teatatu_events_delete_term_' . $term->term_id ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Delete this?', 'teatatu-events' ) ); ?>');"><?php esc_html_e( 'Delete', 'teatatu-events' ); ?></a>
					</td>
				</tr>
			<?php endforeach; ?>
		<?php endif; ?>
		</tbody>
	</table>
	<?php
}

add_action( 'admin_post_teatatu_events_save_term', 'teatatu_events_handle_save_term' );

/**
 * Saves an Event Tag / Source / Category.
 */
function teatatu_events_handle_save_term() {
	if ( ! isset( $_POST['teatatu_events_term_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['teatatu_events_term_nonce'] ) ), 'teatatu_events_save_term' ) ) {
		wp_die( esc_html__( 'Security check failed.', 'teatatu-events' ) );
	}
	$taxonomy = sanitize_key( $_POST['taxonomy'] ?? '' );
	$def      = teatatu_events_term_tab_def( $taxonomy );
	if ( ! $def || ! current_user_can( $def['cap'] ) ) {
		wp_die( esc_html__( 'You do not have permission to do that.', 'teatatu-events' ), 403 );
	}
	$back    = add_query_arg( array( 'page' => 'teatatu-events', 'tab' => $def['tab'] ), admin_url( 'admin.php' ) );
	$term_id = absint( $_POST['term_id'] ?? 0 );
	$name    = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
	if ( '' === trim( $name ) ) {
		teatatu_events_admin_error( __( 'Name is required.', 'teatatu-events' ) );
		wp_safe_redirect( $back );
		exit;
	}
	$args = array(
		'description' => sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) ),
		'parent'      => absint( $_POST['parent'] ?? 0 ),
	);
	if ( $term_id ) {
		$args['name'] = $name;
		$result       = wp_update_term( $term_id, $taxonomy, $args );
	} else {
		$result = wp_insert_term( $name, $taxonomy, $args );
	}
	if ( is_wp_error( $result ) ) {
		teatatu_events_admin_error( teatatu_events_format_wp_error( $result ) );
		wp_safe_redirect( $back );
		exit;
	}
	if ( 'teatatu_events_source' === $taxonomy ) {
		update_term_meta( $result['term_id'], 'teatatu_events_source_default_image', esc_url_raw( wp_unslash( $_POST['default_image'] ?? '' ) ) );
	} elseif ( 'teatatu_events_tag' === $taxonomy ) {
		update_term_meta( $result['term_id'], 'teatatu_events_tag_color', sanitize_hex_color( wp_unslash( $_POST['color'] ?? '' ) ) );
	}
	teatatu_events_bump_cache_version();
	teatatu_events_admin_notice( __( 'Saved.', 'teatatu-events' ) );
	wp_safe_redirect( $back );
	exit;
}

add_action( 'admin_post_teatatu_events_delete_term', 'teatatu_events_handle_delete_term' );

/**
 * Deletes an Event Tag / Source / Category.
 */
function teatatu_events_handle_delete_term() {
	$term_id  = isset( $_GET['term_id'] ) ? absint( $_GET['term_id'] ) : 0;
	$taxonomy = isset( $_GET['taxonomy'] ) ? sanitize_key( $_GET['taxonomy'] ) : '';
	check_admin_referer( 'teatatu_events_delete_term_' . $term_id );
	$def = teatatu_events_term_tab_def( $taxonomy );
	if ( ! $def || ! $term_id || ! current_user_can( $def['cap'] ) ) {
		wp_die( esc_html__( 'You do not have permission to do that.', 'teatatu-events' ), 403 );
	}
	wp_delete_term( $term_id, $taxonomy );
	teatatu_events_bump_cache_version();
	teatatu_events_admin_notice( __( 'Deleted.', 'teatatu-events' ) );
	wp_safe_redirect( add_query_arg( array( 'page' => 'teatatu-events', 'tab' => $def['tab'] ), admin_url( 'admin.php' ) ) );
	exit;
}

// ---------------------------------------------------------------------------
// Pending Updates
// ---------------------------------------------------------------------------

/**
 * Renders the Pending Updates tab: date/status changes first.
 */
function teatatu_events_render_pending_tab() {
	$ids = get_posts(
		array(
			'post_type'        => 'teatatu_event',
			'post_status'      => 'publish',
			'posts_per_page'   => 200,
			'fields'           => 'ids',
			'suppress_filters' => true,
			'meta_query'       => array( array( 'key' => teatatu_events_mk( 'pending_update' ), 'compare' => 'EXISTS' ) ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		)
	);
	$rows = array();
	foreach ( $ids as $id ) {
		$pending = get_post_meta( $id, teatatu_events_mk( 'pending_update' ), true );
		if ( is_array( $pending ) ) {
			$rows[] = array( $id, $pending );
		}
	}
	usort(
		$rows,
		function ( $a, $b ) {
			if ( ! empty( $a[1]['date_status'] ) !== ! empty( $b[1]['date_status'] ) ) {
				return empty( $a[1]['date_status'] ) ? 1 : -1;
			}
			return ( $b[1]['detected_at'] ?? 0 ) <=> ( $a[1]['detected_at'] ?? 0 );
		}
	);
	$labels = array_merge(
		teatatu_events_merge_field_labels(),
		array( 'room' => __( 'Room', 'teatatu-events' ) )
	);
	?>
	<h2><?php esc_html_e( 'Pending Updates', 'teatatu-events' ); ?></h2>
	<p><?php esc_html_e( 'Changes that feeds reported for events that are already published. Date and status changes are listed first.', 'teatatu-events' ); ?></p>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<?php wp_nonce_field( 'teatatu_events_bulk_events', 'teatatu_events_bulk_nonce' ); ?>
	<input type="hidden" name="action" value="teatatu_events_bulk_events" />
	<?php if ( $rows ) : ?>
		<div class="tablenav top">
			<?php teatatu_events_render_bulk_controls( array( 'tte_apply_updates', 'tte_dismiss_updates' ) ); ?>
			<div class="alignleft actions">
				<button type="submit" class="button" name="all_updates" value="tte_apply_updates" onclick="return confirm(<?php echo esc_attr( wp_json_encode( __( 'Apply every pending feed update?', 'teatatu-events' ) ) ); ?>);"><?php esc_html_e( 'Apply all', 'teatatu-events' ); ?></button>
				<button type="submit" class="button" name="all_updates" value="tte_dismiss_updates" onclick="return confirm(<?php echo esc_attr( wp_json_encode( __( 'Dismiss every pending feed update? The events keep their current details.', 'teatatu-events' ) ) ); ?>);"><?php esc_html_e( 'Dismiss all', 'teatatu-events' ); ?></button>
			</div>
		</div>
	<?php endif; ?>
	<table class="wp-list-table widefat striped">
		<thead><tr><td class="manage-column column-cb check-column"><input type="checkbox" class="tte-check-all" aria-label="<?php esc_attr_e( 'Select all', 'teatatu-events' ); ?>" /></td><th><?php esc_html_e( 'Event', 'teatatu-events' ); ?></th><th><?php esc_html_e( 'Changes (current → from feed)', 'teatatu-events' ); ?></th><th><?php esc_html_e( 'Detected', 'teatatu-events' ); ?></th><th><?php esc_html_e( 'Actions', 'teatatu-events' ); ?></th></tr></thead>
		<tbody>
		<?php if ( ! $rows ) : ?>
			<tr><td colspan="5"><?php esc_html_e( 'Nothing waiting for review.', 'teatatu-events' ); ?></td></tr>
		<?php endif; ?>
		<?php foreach ( $rows as $row ) : ?>
			<?php list( $id, $pending ) = $row; ?>
			<tr id="tte-pending-<?php echo esc_attr( $id ); ?>"<?php echo ! empty( $pending['date_status'] ) ? ' style="background:#fcf0f1;"' : ''; ?>>
				<th scope="row" class="check-column"><input type="checkbox" name="event_ids[]" value="<?php echo esc_attr( $id ); ?>" aria-label="<?php echo esc_attr( get_the_title( $id ) ); ?>" /></th>
				<td><a href="<?php echo esc_url( teatatu_events_edit_url( $id ) ); ?>"><?php echo esc_html( get_the_title( $id ) ); ?></a><br /><span class="description"><?php echo esc_html( teatatu_events_origin_label( $id ) ); ?></span></td>
				<td>
					<?php foreach ( (array) ( $pending['diff'] ?? array() ) as $key => $pair ) : ?>
						<div><strong><?php echo esc_html( $labels[ $key ] ?? ucfirst( str_replace( '_', ' ', $key ) ) ); ?>:</strong> <?php echo esc_html( wp_trim_words( (string) $pair[0], 20 ) ); ?> → <?php echo esc_html( wp_trim_words( (string) $pair[1], 20 ) ); ?></div>
					<?php endforeach; ?>
				</td>
				<td><?php echo esc_html( human_time_diff( (int) ( $pending['detected_at'] ?? time() ) ) . ' ' . __( 'ago', 'teatatu-events' ) ); ?></td>
				<td>
					<a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'teatatu_events_apply_update', 'post_id' => $id ), admin_url( 'admin-post.php' ) ), 'teatatu_events_apply_update_' . $id ) ); ?>"><?php esc_html_e( 'Apply', 'teatatu-events' ); ?></a>
					<a class="button" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'teatatu_events_dismiss_update', 'post_id' => $id ), admin_url( 'admin-post.php' ) ), 'teatatu_events_dismiss_update_' . $id ) ); ?>"><?php esc_html_e( 'Dismiss', 'teatatu-events' ); ?></a>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	</form>
	<?php
	teatatu_events_check_all_script();
}

/**
 * "Select all" checkbox behaviour for the plugin's bulk lists.
 */
function teatatu_events_check_all_script() {
	static $done = false;
	if ( $done ) {
		return;
	}
	$done = true;
	?>
	<script>
	document.querySelectorAll( '.tte-check-all' ).forEach( function ( all ) {
		all.addEventListener( 'change', function () {
			all.closest( 'table' ).querySelectorAll( 'tbody input[name="event_ids[]"]' ).forEach( function ( box ) { box.checked = all.checked; } );
		} );
	} );
	</script>
	<?php
}
