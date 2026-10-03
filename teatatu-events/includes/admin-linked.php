<?php
/**
 * Linked Events tab: turn "Show events from other sites" on or off for this
 * site, browse other sites' linkable events, and manage this site's links
 * (status, note, remove).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the Linked Events tab.
 */
function teatatu_events_render_linked_tab() {
	if ( ! is_multisite() || ! current_user_can( 'manage_teatatu_events_links' ) ) {
		echo '<p>' . esc_html__( 'Linked events are only available on a multisite network.', 'teatatu-events' ) . '</p>';
		return;
	}
	$enabled = (bool) get_option( 'teatatu_events_show_linked', false );
	?>
	<h2><?php esc_html_e( 'Linked Events', 'teatatu-events' ); ?></h2>
	<p><?php esc_html_e( "Show events from other sites on this network in this site's own listings — without copying them. The event stays on its own site, and cards link there.", 'teatatu-events' ); ?></p>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'teatatu_events_linked_setting', 'teatatu_events_linked_nonce' ); ?>
		<input type="hidden" name="action" value="teatatu_events_linked_setting" />
		<label><input type="checkbox" name="show_linked" value="1" <?php checked( $enabled ); ?> <?php disabled( ! current_user_can( 'manage_options' ) ); ?> /> <?php esc_html_e( 'Show events from other sites', 'teatatu-events' ); ?></label>
		<?php if ( current_user_can( 'manage_options' ) ) : ?>
			<?php submit_button( __( 'Save', 'teatatu-events' ), 'secondary', 'submit', false ); ?>
		<?php endif; ?>
		<p class="description"><?php esc_html_e( 'Turning this off hides every link without deleting them.', 'teatatu-events' ); ?></p>
	</form>
	<?php
	if ( ! $enabled ) {
		return;
	}
	// phpcs:disable WordPress.Security.NonceVerification.Recommended
	$search = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
	$nbhd   = isset( $_GET['nbhd'] ) ? sanitize_title( wp_unslash( $_GET['nbhd'] ) ) : '';
	$from   = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : '';
	$to     = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : '';
	// phpcs:enable
	$items = teatatu_events_linkable_items( array( 'search' => $search, 'neighbourhood' => $nbhd, 'from' => $from, 'to' => $to ) );
	?>
	<h3><?php esc_html_e( 'Browse network events', 'teatatu-events' ); ?></h3>
	<form method="get" action="">
		<input type="hidden" name="page" value="teatatu-events" />
		<input type="hidden" name="tab" value="linked" />
		<input type="search" name="q" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search titles', 'teatatu-events' ); ?>" />
		<select name="nbhd">
			<option value=""><?php esc_html_e( 'Any neighbourhood', 'teatatu-events' ); ?></option>
			<?php foreach ( teatatu_events_neighbourhood_defs() as $slug => $def ) : ?>
				<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $nbhd, $slug ); ?>><?php echo esc_html( $def[0] ); ?></option>
			<?php endforeach; ?>
		</select>
		<input type="date" name="from" value="<?php echo esc_attr( $from ); ?>" />
		<input type="date" name="to" value="<?php echo esc_attr( $to ); ?>" />
		<button class="button"><?php esc_html_e( 'Search', 'teatatu-events' ); ?></button>
	</form>
	<table class="wp-list-table widefat striped" style="margin-top:8px;">
		<thead><tr><th><?php esc_html_e( 'When', 'teatatu-events' ); ?></th><th><?php esc_html_e( 'Event', 'teatatu-events' ); ?></th><th><?php esc_html_e( 'Site', 'teatatu-events' ); ?></th><th><?php esc_html_e( 'Show on this site', 'teatatu-events' ); ?></th></tr></thead>
		<tbody>
		<?php if ( ! $items ) : ?>
			<tr><td colspan="4"><?php esc_html_e( 'No linkable upcoming events found on other sites.', 'teatatu-events' ); ?></td></tr>
		<?php endif; ?>
		<?php foreach ( $items as $item ) : ?>
			<tr>
				<td><?php echo esc_html( $item['display_when'] ); ?></td>
				<td><a href="<?php echo esc_url( $item['link'] ); ?>" target="_blank"><?php echo esc_html( $item['title'] ); ?></a><br /><span class="description"><?php echo esc_html( $item['place']['line'] ); ?></span></td>
				<td><?php echo esc_html( $item['home_site']['name'] ); ?></td>
				<td>
					<?php if ( $item['linked_here'] ) : ?>
						<?php esc_html_e( 'Shown here', 'teatatu-events' ); ?>
					<?php else : ?>
						<?php if ( $item['has_own_copy'] ) : ?>
							<p style="color:#b32d2e;margin:0 0 4px;"><?php esc_html_e( 'This site already has this event (imported) — its own copy will be shown instead.', 'teatatu-events' ); ?></p>
						<?php endif; ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
							<?php wp_nonce_field( 'teatatu_events_add_link', 'teatatu_events_link_nonce' ); ?>
							<input type="hidden" name="action" value="teatatu_events_add_link" />
							<input type="hidden" name="site_id" value="<?php echo esc_attr( $item['site_id'] ); ?>" />
							<input type="hidden" name="event_id" value="<?php echo esc_attr( $item['id'] ); ?>" />
							<input type="text" name="note" placeholder="<?php esc_attr_e( 'Optional note', 'teatatu-events' ); ?>" />
							<button class="button" name="whole_series" value="0"><?php esc_html_e( 'Show on this site', 'teatatu-events' ); ?></button>
							<?php if ( $item['series_id'] ) : ?>
								<button class="button" name="whole_series" value="1"><?php esc_html_e( 'Show whole series', 'teatatu-events' ); ?></button>
							<?php endif; ?>
						</form>
					<?php endif; ?>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>

	<h3><?php esc_html_e( "This site's links", 'teatatu-events' ); ?></h3>
	<?php $links = get_posts( array( 'post_type' => 'teatatu_evt_link', 'post_status' => 'any', 'posts_per_page' => -1, 'post_parent' => 0 ) ); ?>
	<table class="wp-list-table widefat striped">
		<thead><tr><th><?php esc_html_e( 'Event', 'teatatu-events' ); ?></th><th><?php esc_html_e( 'From', 'teatatu-events' ); ?></th><th><?php esc_html_e( 'Status', 'teatatu-events' ); ?></th><th><?php esc_html_e( 'Note', 'teatatu-events' ); ?></th><th><?php esc_html_e( 'Actions', 'teatatu-events' ); ?></th></tr></thead>
		<tbody>
		<?php if ( ! $links ) : ?>
			<tr><td colspan="5"><?php esc_html_e( 'No linked events yet.', 'teatatu-events' ); ?></td></tr>
		<?php endif; ?>
		<?php foreach ( $links as $link ) : ?>
			<?php
			$site   = (int) get_post_meta( $link->ID, '_teatatu_events_link_site_id', true );
			$series = ! get_post_meta( $link->ID, '_teatatu_events_link_event_id', true );
			$info   = teatatu_events_site_info( $site );
			if ( get_post_meta( $link->ID, '_teatatu_events_link_hidden', true ) ) {
				$status = __( 'Hidden — source event no longer available', 'teatatu-events' );
			} elseif ( get_post_meta( $link->ID, '_teatatu_events_link_shadowed', true ) ) {
				$status = __( "Not shown — this site has its own copy", 'teatatu-events' );
			} elseif ( $series ) {
				/* translators: %d: count. */
				$status = sprintf( __( 'Series: %d dates shown', 'teatatu-events' ), count( get_children( array( 'post_parent' => $link->ID, 'post_type' => 'teatatu_evt_link', 'fields' => 'ids' ) ) ) );
			} else {
				$snap   = get_post_meta( $link->ID, '_teatatu_events_link_snapshot', true );
				$status = is_array( $snap ) ? ( $snap['end'] < time() ? __( 'Past', 'teatatu-events' ) : $snap['status_label'] ) : '';
			}
			?>
			<tr>
				<td><?php echo esc_html( $link->post_title ); ?></td>
				<td><?php echo esc_html( $info['name'] ); ?></td>
				<td><?php echo esc_html( $status ); ?></td>
				<td>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'teatatu_events_link_note_' . $link->ID, 'teatatu_events_link_nonce' ); ?>
						<input type="hidden" name="action" value="teatatu_events_link_note" />
						<input type="hidden" name="link_id" value="<?php echo esc_attr( $link->ID ); ?>" />
						<input type="text" name="note" value="<?php echo esc_attr( get_post_meta( $link->ID, '_teatatu_events_link_note', true ) ); ?>" />
						<button class="button button-small"><?php esc_html_e( 'Save', 'teatatu-events' ); ?></button>
					</form>
				</td>
				<td><a href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'teatatu_events_remove_link', 'link_id' => $link->ID ), admin_url( 'admin-post.php' ) ), 'teatatu_events_remove_link_' . $link->ID ) ); ?>"><?php esc_html_e( 'Remove', 'teatatu-events' ); ?></a></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<?php
}

add_action( 'admin_post_teatatu_events_linked_setting', 'teatatu_events_handle_linked_setting' );

/**
 * Saves this site's "Show events from other sites" setting.
 */
function teatatu_events_handle_linked_setting() {
	if ( ! isset( $_POST['teatatu_events_linked_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['teatatu_events_linked_nonce'] ) ), 'teatatu_events_linked_setting' ) ) {
		wp_die( esc_html__( 'Security check failed.', 'teatatu-events' ) );
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to do that.', 'teatatu-events' ), 403 );
	}
	update_option( 'teatatu_events_show_linked', ! empty( $_POST['show_linked'] ) );
	teatatu_events_schedule_all_cron();
	teatatu_events_bump_cache_version();
	teatatu_events_admin_notice( __( 'Saved.', 'teatatu-events' ) );
	wp_safe_redirect( add_query_arg( array( 'page' => 'teatatu-events', 'tab' => 'linked' ), admin_url( 'admin.php' ) ) );
	exit;
}

add_action( 'admin_post_teatatu_events_add_link', 'teatatu_events_handle_add_link' );

/**
 * Adds a link from the browser.
 */
function teatatu_events_handle_add_link() {
	if ( ! isset( $_POST['teatatu_events_link_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['teatatu_events_link_nonce'] ) ), 'teatatu_events_add_link' ) ) {
		wp_die( esc_html__( 'Security check failed.', 'teatatu-events' ) );
	}
	$result = teatatu_events_create_link( absint( $_POST['site_id'] ?? 0 ), absint( $_POST['event_id'] ?? 0 ), sanitize_text_field( wp_unslash( $_POST['note'] ?? '' ) ), ! empty( $_POST['whole_series'] ) );
	if ( is_wp_error( $result ) ) {
		teatatu_events_admin_error( $result->get_error_message() );
	} else {
		teatatu_events_admin_notice( __( 'The event now shows on this site.', 'teatatu-events' ) );
	}
	wp_safe_redirect( add_query_arg( array( 'page' => 'teatatu-events', 'tab' => 'linked' ), admin_url( 'admin.php' ) ) );
	exit;
}

add_action( 'admin_post_teatatu_events_link_note', 'teatatu_events_handle_link_note' );

/**
 * Saves a link's note (also applied to a series' occurrence links).
 */
function teatatu_events_handle_link_note() {
	$id = absint( $_POST['link_id'] ?? 0 );
	if ( ! isset( $_POST['teatatu_events_link_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['teatatu_events_link_nonce'] ) ), 'teatatu_events_link_note_' . $id ) ) {
		wp_die( esc_html__( 'Security check failed.', 'teatatu-events' ) );
	}
	if ( 'teatatu_evt_link' !== get_post_type( $id ) || ! current_user_can( 'manage_teatatu_events_links' ) ) {
		wp_die( esc_html__( 'You do not have permission to do that.', 'teatatu-events' ), 403 );
	}
	$note = sanitize_text_field( wp_unslash( $_POST['note'] ?? '' ) );
	update_post_meta( $id, '_teatatu_events_link_note', $note );
	foreach ( get_children( array( 'post_parent' => $id, 'post_type' => 'teatatu_evt_link', 'fields' => 'ids' ) ) as $child ) {
		update_post_meta( $child, '_teatatu_events_link_note', $note );
	}
	teatatu_events_bump_cache_version();
	teatatu_events_admin_notice( __( 'Note saved.', 'teatatu-events' ) );
	wp_safe_redirect( add_query_arg( array( 'page' => 'teatatu-events', 'tab' => 'linked' ), admin_url( 'admin.php' ) ) );
	exit;
}

add_action( 'admin_post_teatatu_events_remove_link', 'teatatu_events_handle_remove_link' );

/**
 * Removes a link.
 */
function teatatu_events_handle_remove_link() {
	$id = isset( $_GET['link_id'] ) ? absint( $_GET['link_id'] ) : 0;
	check_admin_referer( 'teatatu_events_remove_link_' . $id );
	if ( 'teatatu_evt_link' !== get_post_type( $id ) || ! current_user_can( 'manage_teatatu_events_links' ) ) {
		wp_die( esc_html__( 'You do not have permission to do that.', 'teatatu-events' ), 403 );
	}
	teatatu_events_delete_link( $id );
	teatatu_events_admin_notice( __( 'Link removed.', 'teatatu-events' ) );
	wp_safe_redirect( add_query_arg( array( 'page' => 'teatatu-events', 'tab' => 'linked' ), admin_url( 'admin.php' ) ) );
	exit;
}
