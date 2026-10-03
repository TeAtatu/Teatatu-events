<?php
/**
 * Series tab: the event template (same fields as a single event), a rule
 * builder (every N days/weeks/months/years; weekdays; monthly by date or by
 * "2nd Tuesday"; ends never / on a date / after N times), extra and skipped
 * dates, and "Upcoming dates to keep" (1–12). Shows a preview of the
 * upcoming dates and the generated occurrences. Row actions: Edit ·
 * Duplicate · Trash.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Form values (tte_* names) from a series template, for the shared fields renderer.
 *
 * @param array $t Template.
 * @return array
 */
function teatatu_events_series_form_values( $t ) {
	$all_day = ! empty( $t['all_day'] );
	return array(
		'tte_start_date'    => $t['start'] ? wp_date( 'Y-m-d', $t['start'] ) : '',
		'tte_start_time'    => $t['start'] && ! $all_day ? wp_date( 'H:i', $t['start'] ) : '',
		'tte_end_date'      => $t['end'] ? wp_date( 'Y-m-d', $t['end'] ) : '',
		'tte_end_time'      => $t['end'] && ! $all_day ? wp_date( 'H:i', $t['end'] ) : '',
		'tte_all_day'       => $all_day,
		'tte_status'        => $t['status'],
		'tte_ticket_url'    => $t['ticket_url'],
		'tte_price'         => $t['price'],
		'tte_is_free'       => (bool) $t['is_free'],
		'tte_read_more_url' => $t['read_more_url'],
		'tte_link_mode'     => $t['link_mode'],
		'tte_image_url'     => $t['image_url'],
		'tte_room'          => $t['room'],
		'tte_address'       => $t['address'],
		'tte_no_linking'    => (bool) $t['no_linking'],
		'tte_venue'         => isset( $t['terms']['teatatu_events_venue'][0] ) ? (int) $t['terms']['teatatu_events_venue'][0] : 0,
		'tte_nbhd_override' => $t['nbhd_override'],
	);
}

/**
 * Renders the Series tab.
 */
function teatatu_events_render_series_tab() {
	if ( ! current_user_can( 'manage_teatatu_events_series' ) ) {
		echo '<p>' . esc_html__( 'You do not have permission to manage series.', 'teatatu-events' ) . '</p>';
		return;
	}
	$editing_id = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$editing    = ( $editing_id && 'teatatu_evt_series' === get_post_type( $editing_id ) ) ? get_post( $editing_id ) : null;
	$t          = $editing ? teatatu_events_series_template( $editing->ID ) : teatatu_events_series_template( 0 );
	$rule       = $editing ? teatatu_events_rrule_parse( (string) get_post_meta( $editing->ID, '_teatatu_events_rrule', true ) ) : null;
	$rule       = $rule ? $rule : array( 'freq' => $editing ? 'NONE' : 'WEEKLY', 'interval' => 1, 'count' => 0, 'until' => 0, 'byday' => array(), 'bymonthday' => array(), 'bymonth' => array() );
	$exdates    = $editing ? (array) get_post_meta( $editing->ID, '_teatatu_events_exdates', true ) : array();
	$rdates     = $editing ? (array) get_post_meta( $editing->ID, '_teatatu_events_rdates', true ) : array();
	$upcoming   = $editing ? teatatu_events_series_upcoming_count( $editing->ID ) : (int) teatatu_events_setting( 'series_upcoming' );
	$days       = array( 'MO' => __( 'Mon', 'teatatu-events' ), 'TU' => __( 'Tue', 'teatatu-events' ), 'WE' => __( 'Wed', 'teatatu-events' ), 'TH' => __( 'Thu', 'teatatu-events' ), 'FR' => __( 'Fri', 'teatatu-events' ), 'SA' => __( 'Sat', 'teatatu-events' ), 'SU' => __( 'Sun', 'teatatu-events' ) );
	$weekdays   = array_map( function ( $bd ) { return $bd[1]; }, array_filter( $rule['byday'], function ( $bd ) { return 0 === $bd[0]; } ) );
	$nth        = array_values( array_filter( $rule['byday'], function ( $bd ) { return 0 !== $bd[0]; } ) );
	$monthly    = $nth ? 'nth' : 'date';
	$ends       = $rule['count'] ? 'count' : ( $rule['until'] ? 'until' : 'never' );
	?>
	<h2><?php echo $editing ? esc_html__( 'Edit Series', 'teatatu-events' ) : esc_html__( 'Add Series', 'teatatu-events' ); ?></h2>
	<p><?php esc_html_e( 'A series repeats on a rule and keeps its next upcoming dates (up to 12) as real events. Editing one date on its own detaches it from the series.', 'teatatu-events' ); ?></p>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'teatatu_events_save_series', 'teatatu_events_series_nonce' ); ?>
		<input type="hidden" name="action" value="teatatu_events_save_series" />
		<input type="hidden" name="series_id" value="<?php echo esc_attr( $editing ? $editing->ID : 0 ); ?>" />
		<table class="form-table" role="presentation">
			<tr><th><label for="tte_series_title"><?php esc_html_e( 'Title', 'teatatu-events' ); ?> *</label></th><td><input type="text" id="tte_series_title" name="title" class="regular-text" required value="<?php echo esc_attr( $editing ? $editing->post_title : '' ); ?>" /></td></tr>
			<tr><th><label for="tte_series_excerpt"><?php esc_html_e( 'Excerpt', 'teatatu-events' ); ?></label></th><td><textarea id="tte_series_excerpt" name="excerpt" class="large-text" rows="2"><?php echo esc_textarea( $editing ? $editing->post_excerpt : '' ); ?></textarea></td></tr>
			<tr><th><label for="tte_series_content"><?php esc_html_e( 'Description', 'teatatu-events' ); ?></label></th><td><textarea id="tte_series_content" name="content" class="large-text" rows="5"><?php echo esc_textarea( $editing ? $editing->post_content : '' ); ?></textarea></td></tr>
		</table>
		<h3><?php esc_html_e( 'First date and details', 'teatatu-events' ); ?></h3>
		<?php teatatu_events_render_event_fields( 0, teatatu_events_series_form_values( $t ) ); ?>
		<?php teatatu_events_render_term_pickers( $t['terms'] ); ?>

		<h3><?php esc_html_e( 'Repeats', 'teatatu-events' ); ?></h3>
		<table class="form-table" role="presentation">
			<tr>
				<th><?php esc_html_e( 'Repeat', 'teatatu-events' ); ?></th>
				<td>
					<?php esc_html_e( 'Every', 'teatatu-events' ); ?>
					<input type="number" min="1" max="52" name="interval" class="small-text" value="<?php echo esc_attr( $rule['interval'] ); ?>" />
					<select name="freq">
						<option value="NONE" <?php selected( $rule['freq'], 'NONE' ); ?>><?php esc_html_e( '(no rule — only the extra dates below)', 'teatatu-events' ); ?></option>
						<option value="DAILY" <?php selected( $rule['freq'], 'DAILY' ); ?>><?php esc_html_e( 'day(s)', 'teatatu-events' ); ?></option>
						<option value="WEEKLY" <?php selected( $rule['freq'], 'WEEKLY' ); ?>><?php esc_html_e( 'week(s)', 'teatatu-events' ); ?></option>
						<option value="MONTHLY" <?php selected( $rule['freq'], 'MONTHLY' ); ?>><?php esc_html_e( 'month(s)', 'teatatu-events' ); ?></option>
						<option value="YEARLY" <?php selected( $rule['freq'], 'YEARLY' ); ?>><?php esc_html_e( 'year(s)', 'teatatu-events' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Weekly on', 'teatatu-events' ); ?></th>
				<td>
					<?php foreach ( $days as $code => $label ) : ?>
						<label style="margin-right:10px;"><input type="checkbox" name="byday[]" value="<?php echo esc_attr( $code ); ?>" <?php checked( in_array( $code, $weekdays, true ) ); ?> /> <?php echo esc_html( $label ); ?></label>
					<?php endforeach; ?>
					<p class="description"><?php esc_html_e( 'For weekly series. Leave empty to repeat on the first date\'s weekday.', 'teatatu-events' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Monthly on', 'teatatu-events' ); ?></th>
				<td>
					<label><input type="radio" name="monthly_mode" value="date" <?php checked( $monthly, 'date' ); ?> /> <?php esc_html_e( 'the same day of the month as the first date', 'teatatu-events' ); ?></label><br />
					<label><input type="radio" name="monthly_mode" value="nth" <?php checked( $monthly, 'nth' ); ?> /> <?php esc_html_e( 'the', 'teatatu-events' ); ?></label>
					<select name="nth">
						<?php foreach ( array( 1 => '1st', 2 => '2nd', 3 => '3rd', 4 => '4th', -1 => 'last' ) as $n => $label ) : ?>
							<option value="<?php echo esc_attr( $n ); ?>" <?php selected( $nth ? $nth[0][0] : 1, $n ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<select name="nth_day">
						<?php foreach ( $days as $code => $label ) : ?>
							<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $nth ? $nth[0][1] : 'TU', $code ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Ends', 'teatatu-events' ); ?></th>
				<td>
					<label><input type="radio" name="ends" value="never" <?php checked( $ends, 'never' ); ?> /> <?php esc_html_e( 'Never', 'teatatu-events' ); ?></label><br />
					<label><input type="radio" name="ends" value="until" <?php checked( $ends, 'until' ); ?> /> <?php esc_html_e( 'On', 'teatatu-events' ); ?></label> <input type="date" name="until" value="<?php echo esc_attr( $rule['until'] ? wp_date( 'Y-m-d', $rule['until'] ) : '' ); ?>" /><br />
					<label><input type="radio" name="ends" value="count" <?php checked( $ends, 'count' ); ?> /> <?php esc_html_e( 'After', 'teatatu-events' ); ?></label> <input type="number" min="1" name="count" class="small-text" value="<?php echo esc_attr( $rule['count'] ? $rule['count'] : 10 ); ?>" /> <?php esc_html_e( 'times', 'teatatu-events' ); ?>
				</td>
			</tr>
			<tr>
				<th><label for="tte_rdates"><?php esc_html_e( 'Extra dates', 'teatatu-events' ); ?></label></th>
				<td><textarea id="tte_rdates" name="rdates" rows="3" class="regular-text" placeholder="2026-12-24&#10;2027-01-05 18:30"><?php echo esc_textarea( implode( "\n", $rdates ) ); ?></textarea>
				<p class="description"><?php esc_html_e( 'One per line (YYYY-MM-DD, optionally with a time). Also use this for irregular, hand-picked dates.', 'teatatu-events' ); ?></p></td>
			</tr>
			<tr>
				<th><label for="tte_exdates"><?php esc_html_e( 'Skip dates', 'teatatu-events' ); ?></label></th>
				<td><textarea id="tte_exdates" name="exdates" rows="3" class="regular-text" placeholder="2026-12-25"><?php echo esc_textarea( implode( "\n", $exdates ) ); ?></textarea>
				<p class="description"><?php esc_html_e( 'One date per line (YYYY-MM-DD) to leave out.', 'teatatu-events' ); ?></p></td>
			</tr>
			<tr>
				<th><label for="tte_upcoming"><?php esc_html_e( 'Upcoming dates to keep', 'teatatu-events' ); ?></label></th>
				<td><input type="number" id="tte_upcoming" name="upcoming_count" min="1" max="12" class="small-text" value="<?php echo esc_attr( $upcoming ); ?>" />
				<p class="description"><?php esc_html_e( 'How many upcoming dates exist as events at any time (1–12). As each one ends, the next is added.', 'teatatu-events' ); ?></p></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Publish status', 'teatatu-events' ); ?></th>
				<td>
					<select name="post_status">
						<option value="draft" <?php selected( $editing ? $editing->post_status : 'draft', 'draft' ); ?>><?php esc_html_e( 'Draft (dates stay drafts)', 'teatatu-events' ); ?></option>
						<?php if ( current_user_can( 'publish_teatatu_events_items' ) ) : ?>
							<option value="publish" <?php selected( $editing ? $editing->post_status : '', 'publish' ); ?>><?php esc_html_e( 'Published (publishes every date not edited separately)', 'teatatu-events' ); ?></option>
						<?php endif; ?>
					</select>
				</td>
			</tr>
		</table>
		<?php submit_button( $editing ? __( 'Save Series', 'teatatu-events' ) : __( 'Add Series', 'teatatu-events' ) ); ?>
	</form>

	<?php if ( $editing ) : ?>
		<h3><?php esc_html_e( 'Upcoming dates', 'teatatu-events' ); ?></h3>
		<p><em><?php echo esc_html( teatatu_events_rrule_describe( (string) get_post_meta( $editing->ID, '_teatatu_events_rrule', true ) ) ); ?></em></p>
		<ol>
			<?php foreach ( teatatu_events_series_upcoming_starts( $editing->ID ) as $ts ) : ?>
				<li><?php echo esc_html( teatatu_events_format_when( $ts, $ts + max( 0, $t['end'] - $t['start'] ), $t['all_day'] ) ); ?></li>
			<?php endforeach; ?>
		</ol>
		<h3><?php esc_html_e( 'Generated events', 'teatatu-events' ); ?></h3>
		<table class="wp-list-table widefat striped">
			<thead><tr><th><?php esc_html_e( 'When', 'teatatu-events' ); ?></th><th><?php esc_html_e( 'Status', 'teatatu-events' ); ?></th><th><?php esc_html_e( 'Actions', 'teatatu-events' ); ?></th></tr></thead>
			<tbody>
			<?php
			$occ = teatatu_events_series_occurrences( $editing->ID );
			usort( $occ, function ( $a, $b ) { return teatatu_events_get_start( $a ) <=> teatatu_events_get_start( $b ); } );
			foreach ( $occ as $id ) :
				$statuses = teatatu_events_statuses();
				?>
				<tr>
					<td><?php echo esc_html( teatatu_events_format_when( teatatu_events_get_start( $id ), teatatu_events_get_end( $id ), teatatu_events_is_all_day( $id ) ) ); ?></td>
					<td><?php echo esc_html( get_post_status_object( get_post_status( $id ) )->label . ' · ' . $statuses[ teatatu_events_get_status( $id ) ] ); ?><?php echo get_post_meta( $id, teatatu_events_mk( 'detached' ), true ) ? ' · ' . esc_html__( 'edited separately', 'teatatu-events' ) : ''; ?></td>
					<td><a href="<?php echo esc_url( teatatu_events_edit_url( $id ) ); ?>"><?php esc_html_e( 'Edit this date', 'teatatu-events' ); ?></a></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<hr />
	<h2><?php esc_html_e( 'All Series', 'teatatu-events' ); ?></h2>
	<?php $all = get_posts( array( 'post_type' => 'teatatu_evt_series', 'post_status' => array( 'publish', 'draft', 'pending' ), 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC' ) ); ?>
	<table class="wp-list-table widefat fixed striped">
		<thead><tr><th><?php esc_html_e( 'Title', 'teatatu-events' ); ?></th><th><?php esc_html_e( 'Repeats', 'teatatu-events' ); ?></th><th><?php esc_html_e( 'Status', 'teatatu-events' ); ?></th><th><?php esc_html_e( 'Actions', 'teatatu-events' ); ?></th></tr></thead>
		<tbody>
		<?php if ( ! $all ) : ?>
			<tr><td colspan="4"><?php esc_html_e( 'No series yet.', 'teatatu-events' ); ?></td></tr>
		<?php endif; ?>
		<?php foreach ( $all as $series ) : ?>
			<tr>
				<td><strong><?php echo esc_html( $series->post_title ); ?></strong></td>
				<td><?php echo esc_html( teatatu_events_rrule_describe( (string) get_post_meta( $series->ID, '_teatatu_events_rrule', true ) ) ); ?></td>
				<td><?php echo esc_html( get_post_status_object( $series->post_status )->label ); ?></td>
				<td>
					<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'teatatu-events', 'tab' => 'series', 'edit' => $series->ID ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Edit', 'teatatu-events' ); ?></a>
					| <a href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'teatatu_events_duplicate_series', 'id' => $series->ID ), admin_url( 'admin-post.php' ) ), 'teatatu_events_duplicate_series_' . $series->ID ) ); ?>"><?php esc_html_e( 'Duplicate', 'teatatu-events' ); ?></a>
					| <a href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'teatatu_events_trash_series', 'id' => $series->ID ), admin_url( 'admin-post.php' ) ), 'teatatu_events_trash_series_' . $series->ID ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Trash this series? Its future dates that were not edited separately are trashed too.', 'teatatu-events' ) ); ?>');"><?php esc_html_e( 'Trash', 'teatatu-events' ); ?></a>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<?php
}

add_action( 'admin_post_teatatu_events_save_series', 'teatatu_events_handle_save_series' );

/**
 * Saves a series and syncs its occurrences.
 */
function teatatu_events_handle_save_series() {
	if ( ! isset( $_POST['teatatu_events_series_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['teatatu_events_series_nonce'] ) ), 'teatatu_events_save_series' ) ) {
		wp_die( esc_html__( 'Security check failed.', 'teatatu-events' ) );
	}
	if ( ! current_user_can( 'manage_teatatu_events_series' ) ) {
		wp_die( esc_html__( 'You do not have permission to do that.', 'teatatu-events' ), 403 );
	}
	$src       = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised per field.
	$series_id = absint( $src['series_id'] ?? 0 );
	$back      = add_query_arg( array( 'page' => 'teatatu-events', 'tab' => 'series' ), admin_url( 'admin.php' ) );
	if ( $series_id && 'teatatu_evt_series' !== get_post_type( $series_id ) ) {
		wp_die( esc_html__( 'That is not a series.', 'teatatu-events' ) );
	}
	$title = sanitize_text_field( $src['title'] ?? '' );
	if ( '' === trim( $title ) ) {
		teatatu_events_admin_error( __( 'Title is required.', 'teatatu-events' ) );
		wp_safe_redirect( $series_id ? add_query_arg( 'edit', $series_id, $back ) : $back );
		exit;
	}
	$fields = teatatu_events_validate_fields( teatatu_events_fields_from_form( $src ) );
	if ( is_wp_error( $fields ) ) {
		teatatu_events_admin_error( $fields->get_error_message() );
		wp_safe_redirect( $series_id ? add_query_arg( 'edit', $series_id, $back ) : $back );
		exit;
	}

	// Rule.
	$freq = strtoupper( sanitize_key( $src['freq'] ?? 'WEEKLY' ) );
	$rrule = '';
	if ( in_array( $freq, array( 'DAILY', 'WEEKLY', 'MONTHLY', 'YEARLY' ), true ) ) {
		$valid = array( 'MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU' );
		$rule  = array( 'freq' => $freq, 'interval' => max( 1, absint( $src['interval'] ?? 1 ) ), 'count' => 0, 'until' => 0, 'byday' => array(), 'bymonthday' => array(), 'bymonth' => array() );
		if ( 'WEEKLY' === $freq ) {
			foreach ( (array) ( $src['byday'] ?? array() ) as $code ) {
				$code = strtoupper( sanitize_key( $code ) );
				if ( in_array( $code, $valid, true ) ) {
					$rule['byday'][] = array( 0, $code );
				}
			}
		} elseif ( 'MONTHLY' === $freq && 'nth' === ( $src['monthly_mode'] ?? '' ) ) {
			$n    = (int) ( $src['nth'] ?? 1 );
			$code = strtoupper( sanitize_key( $src['nth_day'] ?? 'TU' ) );
			if ( in_array( $n, array( 1, 2, 3, 4, -1 ), true ) && in_array( $code, $valid, true ) ) {
				$rule['byday'][] = array( $n, $code );
			}
		}
		$ends = sanitize_key( $src['ends'] ?? 'never' );
		if ( 'count' === $ends ) {
			$rule['count'] = max( 1, absint( $src['count'] ?? 1 ) );
		} elseif ( 'until' === $ends && ! empty( $src['until'] ) ) {
			$rule['until'] = (int) teatatu_events_parse_local_datetime( sanitize_text_field( $src['until'] ), true );
		}
		$rrule = teatatu_events_rrule_build( $rule );
	}
	$lines   = function ( $text, $pattern ) {
		return array_values( array_filter( array_map( 'trim', preg_split( '/[\r\n,]+/', (string) $text ) ), function ( $l ) use ( $pattern ) { return (bool) preg_match( $pattern, $l ); } ) );
	};
	$exdates = $lines( $src['exdates'] ?? '', '/^\d{4}-\d{2}-\d{2}$/' );
	$rdates  = $lines( $src['rdates'] ?? '', '/^\d{4}-\d{2}-\d{2}( \d{1,2}:\d{2})?$/' );
	if ( ! $rrule && ! $rdates ) {
		teatatu_events_admin_error( __( 'Choose how the series repeats, or add extra dates.', 'teatatu-events' ) );
		wp_safe_redirect( $series_id ? add_query_arg( 'edit', $series_id, $back ) : $back );
		exit;
	}

	$status = sanitize_key( $src['post_status'] ?? 'draft' );
	if ( 'publish' !== $status || ! current_user_can( 'publish_teatatu_events_items' ) ) {
		$status = 'draft';
	}
	$postarr = array(
		'post_title'   => $title,
		'post_excerpt' => sanitize_textarea_field( $src['excerpt'] ?? '' ),
		'post_content' => wp_kses_post( $src['content'] ?? '' ),
		'post_status'  => $status,
	);
	if ( $series_id ) {
		$postarr['ID'] = $series_id;
		$result        = wp_update_post( wp_slash( $postarr ), true );
	} else {
		$postarr['post_type'] = 'teatatu_evt_series';
		$result               = wp_insert_post( wp_slash( $postarr ), true );
	}
	if ( is_wp_error( $result ) ) {
		teatatu_events_admin_error( teatatu_events_format_wp_error( $result ) );
		wp_safe_redirect( $back );
		exit;
	}
	$series_id = (int) $result;

	$terms                         = teatatu_events_terms_from_form( $src );
	$terms['teatatu_events_venue'] = ! empty( $src['tte_venue'] ) ? array( absint( $src['tte_venue'] ) ) : array();
	$override                      = sanitize_key( $src['tte_nbhd_override'] ?? '' );
	$template                      = array_merge(
		$fields,
		array(
			'terms'         => $terms,
			'nbhd_override' => in_array( $override, teatatu_events_neighbourhood_slugs(), true ) ? $override : '',
		)
	);
	update_post_meta( $series_id, '_teatatu_events_series_template', $template );
	update_post_meta( $series_id, '_teatatu_events_rrule', $rrule );
	update_post_meta( $series_id, '_teatatu_events_exdates', $exdates );
	update_post_meta( $series_id, '_teatatu_events_rdates', $rdates );
	update_post_meta( $series_id, '_teatatu_events_upcoming_count', max( 1, min( TEATATU_EVENTS_MAX_UPCOMING, absint( $src['upcoming_count'] ?? 12 ) ) ) );

	$stats = teatatu_events_series_sync( $series_id );
	/* translators: 1-3: counts. */
	teatatu_events_admin_notice( sprintf( __( 'Series saved: %1$d dates created, %2$d updated, %3$d removed.', 'teatatu-events' ), $stats['created'], $stats['updated'], $stats['trashed'] ) );
	wp_safe_redirect( add_query_arg( 'edit', $series_id, $back ) );
	exit;
}

add_action( 'admin_post_teatatu_events_duplicate_series', 'teatatu_events_handle_duplicate_series' );

/**
 * Duplicates a series as a draft with the same template and rule (no
 * occurrences until it is saved).
 */
function teatatu_events_handle_duplicate_series() {
	$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
	check_admin_referer( 'teatatu_events_duplicate_series_' . $id );
	if ( ! $id || 'teatatu_evt_series' !== get_post_type( $id ) || ! current_user_can( 'manage_teatatu_events_series' ) ) {
		wp_die( esc_html__( 'You do not have permission to do that.', 'teatatu-events' ), 403 );
	}
	$src = get_post( $id );
	$new = wp_insert_post(
		wp_slash(
			array(
				'post_type'    => 'teatatu_evt_series',
				'post_status'  => 'draft',
				/* translators: %s: title. */
				'post_title'   => sprintf( __( '%s (copy)', 'teatatu-events' ), $src->post_title ),
				'post_excerpt' => $src->post_excerpt,
				'post_content' => $src->post_content,
			)
		),
		true
	);
	if ( is_wp_error( $new ) ) {
		wp_die( esc_html( $new->get_error_message() ) );
	}
	foreach ( array( '_teatatu_events_series_template', '_teatatu_events_rrule', '_teatatu_events_exdates', '_teatatu_events_rdates', '_teatatu_events_upcoming_count' ) as $key ) {
		update_post_meta( $new, $key, get_post_meta( $id, $key, true ) );
	}
	teatatu_events_admin_notice( __( 'Series duplicated as a draft. Adjust it and save to generate its dates.', 'teatatu-events' ) );
	wp_safe_redirect( add_query_arg( array( 'page' => 'teatatu-events', 'tab' => 'series', 'edit' => $new ), admin_url( 'admin.php' ) ) );
	exit;
}

add_action( 'admin_post_teatatu_events_trash_series', 'teatatu_events_handle_trash_series' );

/**
 * Trashes a series (and its future, non-detached occurrences).
 */
function teatatu_events_handle_trash_series() {
	$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
	check_admin_referer( 'teatatu_events_trash_series_' . $id );
	if ( ! $id || 'teatatu_evt_series' !== get_post_type( $id ) || ! current_user_can( 'manage_teatatu_events_series' ) ) {
		wp_die( esc_html__( 'You do not have permission to do that.', 'teatatu-events' ), 403 );
	}
	wp_trash_post( $id );
	teatatu_events_admin_notice( __( 'Series moved to the trash.', 'teatatu-events' ) );
	wp_safe_redirect( add_query_arg( array( 'page' => 'teatatu-events', 'tab' => 'series' ), admin_url( 'admin.php' ) ) );
	exit;
}
