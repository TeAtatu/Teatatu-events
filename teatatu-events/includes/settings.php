<?php
/**
 * Settings: Network Admin > Settings > Teatatu Events on multisite, or
 * Settings > Teatatu Events on a single site. One shared render function
 * stores through get/update_site_option() or get/update_option() depending
 * on is_multisite().
 *
 * The one per-site setting ("Show events from other sites") lives on the
 * Linked Events tab of each site's own Teatatu Events screen instead.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads a plugin option, using the network options table on multisite.
 *
 * @param string $key     Option name.
 * @param mixed  $default Default value.
 * @return mixed
 */
function teatatu_events_get_option( $key, $default = false ) {
	return is_multisite() ? get_site_option( $key, $default ) : get_option( $key, $default );
}

/**
 * Writes a plugin option, using the network options table on multisite.
 *
 * @param string $key   Option name.
 * @param mixed  $value New value.
 * @return bool
 */
function teatatu_events_update_option( $key, $value ) {
	return is_multisite() ? update_site_option( $key, $value ) : update_option( $key, $value );
}

/**
 * Deletes a plugin option, using the network options table on multisite.
 *
 * @param string $key Option name.
 * @return bool
 */
function teatatu_events_delete_option( $key ) {
	return is_multisite() ? delete_site_option( $key ) : delete_option( $key );
}

/**
 * Defaults for every value stored in the `teatatu_events_settings` array.
 *
 * @return array
 */
function teatatu_events_setting_defaults() {
	return array(
		'default_duration'      => 120,   // Minutes.
		'series_upcoming'       => 12,    // 1–12.
		'hide_cancelled'        => 0,
		'removed_threshold'     => 2,     // Consecutive missing checks.
		'default_country'       => 'NZ',
		'venue_match_distance'  => 50,    // Metres.
		'date_format'           => '',    // '' = WordPress setting.
		'time_format'           => '',
		'allow_linked'          => 1,     // Network master switch.
		'dedupe_enabled'        => 1,
		'dedupe_fuzzy'          => 1,
		'dedupe_manual'         => 0,
		'dedupe_priority'       => '',    // Comma list of site IDs.
	);
}

/**
 * Reads one setting, falling back to its default.
 *
 * @param string $key Setting key.
 * @return mixed
 */
function teatatu_events_setting( $key ) {
	static $settings = null;
	if ( null === $settings || ! empty( $GLOBALS['teatatu_events_settings_dirty'] ) ) {
		$stored   = teatatu_events_get_option( 'teatatu_events_settings', array() );
		$settings = array_merge( teatatu_events_setting_defaults(), is_array( $stored ) ? $stored : array() );
		$GLOBALS['teatatu_events_settings_dirty'] = false;
	}
	return array_key_exists( $key, $settings ) ? $settings[ $key ] : null;
}

/**
 * Saves a set of settings (merged over the stored values).
 *
 * @param array $values Values to save.
 */
function teatatu_events_save_settings( $values ) {
	$stored = teatatu_events_get_option( 'teatatu_events_settings', array() );
	$stored = array_merge( is_array( $stored ) ? $stored : array(), $values );
	teatatu_events_update_option( 'teatatu_events_settings', $stored );
	$GLOBALS['teatatu_events_settings_dirty'] = true;
}

add_action( 'network_admin_menu', 'teatatu_events_register_network_settings_menu' );

/**
 * Adds the settings page under Network Admin > Settings on multisite.
 */
function teatatu_events_register_network_settings_menu() {
	add_submenu_page(
		'settings.php',
		__( 'Teatatu Events', 'teatatu-events' ),
		__( 'Teatatu Events', 'teatatu-events' ),
		'manage_network_options',
		'teatatu-events-settings',
		'teatatu_events_render_settings_page'
	);
}

add_action( 'admin_menu', 'teatatu_events_register_site_settings_menu' );

/**
 * Adds the settings page under Settings on a single-site install.
 */
function teatatu_events_register_site_settings_menu() {
	if ( is_multisite() ) {
		return;
	}
	add_options_page(
		__( 'Teatatu Events', 'teatatu-events' ),
		__( 'Teatatu Events', 'teatatu-events' ),
		'manage_options',
		'teatatu-events-settings',
		'teatatu_events_render_settings_page'
	);
}

/**
 * Renders the settings form.
 */
function teatatu_events_render_settings_page() {
	$cap = is_multisite() ? 'manage_network_options' : 'manage_options';
	if ( ! current_user_can( $cap ) ) {
		wp_die( esc_html__( 'You do not have permission to access this page.', 'teatatu-events' ) );
	}

	$github_repo         = teatatu_events_get_option( 'teatatu_events_github_repo', 'TeAtatu/Teatatu-events' );
	$github_token_is_set = (bool) teatatu_events_get_option( 'teatatu_events_github_token', '' );
	$delete_on_uninstall = (bool) teatatu_events_get_option( 'teatatu_events_delete_data_on_uninstall', false );
	$master_site_id      = teatatu_events_get_master_site_id();
	// Always the plain (non-network) admin-post.php, even under Network
	// Admin: wp-admin/network/admin-post.php does not exist in core.
	$post_url = admin_url( 'admin-post.php' );
	$s        = 'teatatu_events_setting';

	if ( isset( $_GET['teatatu_events_settings_saved'] ) ) {
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'teatatu-events' ) . '</p></div>';
	}
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Teatatu Events Settings', 'teatatu-events' ); ?></h1>
		<form method="post" action="<?php echo esc_url( $post_url ); ?>">
			<?php wp_nonce_field( 'teatatu_events_save_settings', 'teatatu_events_settings_nonce' ); ?>
			<input type="hidden" name="action" value="teatatu_events_save_settings" />

			<h2><?php esc_html_e( 'Events', 'teatatu-events' ); ?></h2>
			<table class="form-table">
				<tr>
					<th><label for="tte_default_duration"><?php esc_html_e( 'Default event duration (minutes)', 'teatatu-events' ); ?></label></th>
					<td><input type="number" min="5" step="5" id="tte_default_duration" name="default_duration" class="small-text" value="<?php echo esc_attr( $s( 'default_duration' ) ); ?>" />
					<p class="description"><?php esc_html_e( 'Used as the end time when an event is saved without one.', 'teatatu-events' ); ?></p></td>
				</tr>
				<tr>
					<th><label for="tte_series_upcoming"><?php esc_html_e( 'Default upcoming dates kept per series', 'teatatu-events' ); ?></label></th>
					<td><input type="number" min="1" max="12" id="tte_series_upcoming" name="series_upcoming" class="small-text" value="<?php echo esc_attr( $s( 'series_upcoming' ) ); ?>" />
					<p class="description"><?php esc_html_e( 'Maximum 12. Each series can override this.', 'teatatu-events' ); ?></p></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Cancelled events', 'teatatu-events' ); ?></th>
					<td><label><input type="checkbox" name="hide_cancelled" value="1" <?php checked( (bool) $s( 'hide_cancelled' ) ); ?> /> <?php esc_html_e( 'Hide cancelled events from lists by default', 'teatatu-events' ); ?></label></td>
				</tr>
				<tr>
					<th><label for="tte_removed_threshold"><?php esc_html_e( 'Removed at source', 'teatatu-events' ); ?></label></th>
					<td><input type="number" min="1" max="10" id="tte_removed_threshold" name="removed_threshold" class="small-text" value="<?php echo esc_attr( $s( 'removed_threshold' ) ); ?>" />
					<p class="description"><?php esc_html_e( 'Consecutive successful feed checks an event must be missing from before it is treated as cancelled.', 'teatatu-events' ); ?></p></td>
				</tr>
				<tr>
					<th><label for="tte_date_format"><?php esc_html_e( 'Date / time display formats', 'teatatu-events' ); ?></label></th>
					<td>
						<input type="text" id="tte_date_format" name="date_format" class="regular-text" value="<?php echo esc_attr( $s( 'date_format' ) ); ?>" placeholder="<?php echo esc_attr( 'D j M' ); ?>" />
						<input type="text" name="time_format" class="small-text" value="<?php echo esc_attr( $s( 'time_format' ) ); ?>" placeholder="<?php echo esc_attr( 'g:ia' ); ?>" />
						<p class="description"><?php esc_html_e( 'PHP date formats. Leave blank for the compact defaults ("Sat 3 Oct", "7pm").', 'teatatu-events' ); ?></p>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Places', 'teatatu-events' ); ?></h2>
			<table class="form-table">
				<tr>
					<th><label for="tte_default_country"><?php esc_html_e( 'Default country for venue addresses', 'teatatu-events' ); ?></label></th>
					<td><input type="text" maxlength="2" id="tte_default_country" name="default_country" class="small-text" value="<?php echo esc_attr( $s( 'default_country' ) ); ?>" /> <span class="description"><?php esc_html_e( 'ISO 3166-1 alpha-2, e.g. NZ', 'teatatu-events' ); ?></span></td>
				</tr>
				<tr>
					<th><label for="tte_venue_match_distance"><?php esc_html_e( 'Coordinate match distance (metres)', 'teatatu-events' ); ?></label></th>
					<td><input type="number" min="0" id="tte_venue_match_distance" name="venue_match_distance" class="small-text" value="<?php echo esc_attr( $s( 'venue_match_distance' ) ); ?>" />
					<p class="description"><?php esc_html_e( 'Imported events with coordinates this close to a venue are matched to it.', 'teatatu-events' ); ?></p></td>
				</tr>
			</table>

			<?php if ( is_multisite() ) : ?>
				<h2><?php esc_html_e( 'Network', 'teatatu-events' ); ?></h2>
				<table class="form-table">
					<tr>
						<th><label for="tte_master_site"><?php esc_html_e( 'Master Site', 'teatatu-events' ); ?></label></th>
						<td>
							<select id="tte_master_site" name="master_site_id">
								<option value="0"><?php esc_html_e( '— None —', 'teatatu-events' ); ?></option>
								<?php foreach ( get_sites( array( 'number' => 500 ) ) as $site ) : ?>
									<option value="<?php echo esc_attr( $site->blog_id ); ?>" <?php selected( $master_site_id, (int) $site->blog_id ); ?>>
										<?php echo esc_html( $site->blogname ? $site->blogname : $site->domain . $site->path ); ?> (<?php echo esc_html( $site->domain . $site->path ); ?>)
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'The site where the [teatatu_events_network_*] shortcodes and the network REST/iCal endpoints produce output.', 'teatatu-events' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Linked events', 'teatatu-events' ); ?></th>
						<td><label><input type="checkbox" name="allow_linked" value="1" <?php checked( (bool) $s( 'allow_linked' ) ); ?> /> <?php esc_html_e( 'Allow sites to show events from other sites in their own listings', 'teatatu-events' ); ?></label>
						<p class="description"><?php esc_html_e( 'Each site still has to switch this on for itself (Teatatu Events > Linked Events).', 'teatatu-events' ); ?></p></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Cross-site duplicates', 'teatatu-events' ); ?></th>
						<td>
							<label><input type="checkbox" name="dedupe_enabled" value="1" <?php checked( (bool) $s( 'dedupe_enabled' ) ); ?> /> <?php esc_html_e( 'Merge duplicate events in network listings', 'teatatu-events' ); ?></label><br />
							<label><input type="checkbox" name="dedupe_fuzzy" value="1" <?php checked( (bool) $s( 'dedupe_fuzzy' ) ); ?> /> <?php esc_html_e( 'Also use fuzzy matching (same title, start time and place)', 'teatatu-events' ); ?></label><br />
							<label><input type="checkbox" name="dedupe_manual" value="1" <?php checked( (bool) $s( 'dedupe_manual' ) ); ?> /> <?php esc_html_e( 'Also merge near-identical events created by hand', 'teatatu-events' ); ?></label>
						</td>
					</tr>
					<tr>
						<th><label for="tte_dedupe_priority"><?php esc_html_e( 'Duplicate priority', 'teatatu-events' ); ?></label></th>
						<td><input type="text" id="tte_dedupe_priority" name="dedupe_priority" class="regular-text" value="<?php echo esc_attr( $s( 'dedupe_priority' ) ); ?>" placeholder="1, 4, 2" />
						<p class="description"><?php esc_html_e( 'Site IDs, highest priority first. The copy from the highest-priority site is shown. Unlisted sites follow, Master Site first, then by site ID.', 'teatatu-events' ); ?></p></td>
					</tr>
				</table>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Plugin', 'teatatu-events' ); ?></h2>
			<table class="form-table">
				<tr>
					<th><label for="tte_github_repo"><?php esc_html_e( 'GitHub Repository', 'teatatu-events' ); ?></label></th>
					<td>
						<input type="text" id="tte_github_repo" name="github_repo" class="regular-text" value="<?php echo esc_attr( $github_repo ); ?>" placeholder="owner/repo" />
						<p class="description"><?php esc_html_e( 'Used to check for plugin updates via GitHub Releases.', 'teatatu-events' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="tte_github_token"><?php esc_html_e( 'GitHub Access Token', 'teatatu-events' ); ?></label></th>
					<td>
						<input type="password" id="tte_github_token" name="github_token" class="regular-text" value="" autocomplete="new-password" placeholder="<?php echo $github_token_is_set ? esc_attr__( 'Token is set — leave blank to keep it', 'teatatu-events' ) : esc_attr__( 'Optional, only needed for a private repo', 'teatatu-events' ); ?>" />
						<?php if ( $github_token_is_set ) : ?>
							<label style="display:block;margin-top:6px;"><input type="checkbox" name="github_token_clear" value="1" /> <?php esc_html_e( 'Clear the saved token', 'teatatu-events' ); ?></label>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Data on Uninstall', 'teatatu-events' ); ?></th>
					<td>
						<label><input type="checkbox" name="delete_data_on_uninstall" value="1" <?php checked( $delete_on_uninstall ); ?> /> <?php esc_html_e( 'Delete all Teatatu Events data when this plugin is uninstalled', 'teatatu-events' ); ?></label>
						<p class="description"><?php esc_html_e( 'Unchecked by default. When checked, uninstalling permanently deletes every event, series, linked event, feed configuration and Events term (on every site in a network). Teatatu News data is never touched.', 'teatatu-events' ); ?></p>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Save Settings', 'teatatu-events' ) ); ?>
		</form>

		<hr />
		<h2><?php esc_html_e( 'Updates', 'teatatu-events' ); ?></h2>
		<p><?php esc_html_e( 'Current version:', 'teatatu-events' ); ?> <strong><?php echo esc_html( TEATATU_EVENTS_VERSION ); ?></strong></p>
		<form method="post" action="<?php echo esc_url( $post_url ); ?>">
			<?php wp_nonce_field( 'teatatu_events_check_updates', 'teatatu_events_check_updates_nonce' ); ?>
			<input type="hidden" name="action" value="teatatu_events_check_updates" />
			<?php submit_button( __( 'Check for Updates Now', 'teatatu-events' ), 'secondary' ); ?>
		</form>
	</div>
	<?php
}

add_action( 'admin_post_teatatu_events_save_settings', 'teatatu_events_handle_save_settings' );

/**
 * Handles the settings form submission.
 */
function teatatu_events_handle_save_settings() {
	if ( ! isset( $_POST['teatatu_events_settings_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['teatatu_events_settings_nonce'] ) ), 'teatatu_events_save_settings' ) ) {
		wp_die( esc_html__( 'Security check failed.', 'teatatu-events' ) );
	}
	$cap = is_multisite() ? 'manage_network_options' : 'manage_options';
	if ( ! current_user_can( $cap ) ) {
		wp_die( esc_html__( 'You do not have permission to do that.', 'teatatu-events' ), 403 );
	}

	$priority = isset( $_POST['dedupe_priority'] ) ? sanitize_text_field( wp_unslash( $_POST['dedupe_priority'] ) ) : '';
	$priority = implode( ', ', array_filter( array_map( 'absint', explode( ',', $priority ) ) ) );

	$values = array(
		'default_duration'     => isset( $_POST['default_duration'] ) ? max( 5, absint( $_POST['default_duration'] ) ) : 120,
		'series_upcoming'      => isset( $_POST['series_upcoming'] ) ? max( 1, min( 12, absint( $_POST['series_upcoming'] ) ) ) : 12,
		'hide_cancelled'       => ! empty( $_POST['hide_cancelled'] ) ? 1 : 0,
		'removed_threshold'    => isset( $_POST['removed_threshold'] ) ? max( 1, min( 10, absint( $_POST['removed_threshold'] ) ) ) : 2,
		'default_country'      => isset( $_POST['default_country'] ) ? strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', sanitize_text_field( wp_unslash( $_POST['default_country'] ) ) ), 0, 2 ) ) : 'NZ',
		'venue_match_distance' => isset( $_POST['venue_match_distance'] ) ? absint( $_POST['venue_match_distance'] ) : 50,
		'date_format'          => isset( $_POST['date_format'] ) ? sanitize_text_field( wp_unslash( $_POST['date_format'] ) ) : '',
		'time_format'          => isset( $_POST['time_format'] ) ? sanitize_text_field( wp_unslash( $_POST['time_format'] ) ) : '',
	);
	if ( '' === $values['default_country'] ) {
		$values['default_country'] = 'NZ';
	}
	if ( is_multisite() ) {
		$values['allow_linked']    = ! empty( $_POST['allow_linked'] ) ? 1 : 0;
		$values['dedupe_enabled']  = ! empty( $_POST['dedupe_enabled'] ) ? 1 : 0;
		$values['dedupe_fuzzy']    = ! empty( $_POST['dedupe_fuzzy'] ) ? 1 : 0;
		$values['dedupe_manual']   = ! empty( $_POST['dedupe_manual'] ) ? 1 : 0;
		$values['dedupe_priority'] = $priority;
		if ( isset( $_POST['master_site_id'] ) ) {
			update_site_option( 'teatatu_events_master_site_id', absint( $_POST['master_site_id'] ) );
		}
	}
	teatatu_events_save_settings( $values );

	if ( isset( $_POST['github_repo'] ) ) {
		$repo = sanitize_text_field( wp_unslash( $_POST['github_repo'] ) );
		teatatu_events_update_option( 'teatatu_events_github_repo', $repo ? $repo : 'TeAtatu/Teatatu-events' );
	}
	if ( ! empty( $_POST['github_token_clear'] ) ) {
		teatatu_events_delete_option( 'teatatu_events_github_token' );
	} elseif ( ! empty( $_POST['github_token'] ) ) {
		teatatu_events_update_option( 'teatatu_events_github_token', sanitize_text_field( wp_unslash( $_POST['github_token'] ) ) );
	}
	teatatu_events_update_option( 'teatatu_events_delete_data_on_uninstall', ! empty( $_POST['delete_data_on_uninstall'] ) );

	teatatu_events_bump_cache_version();

	$redirect_base = is_multisite() ? network_admin_url( 'settings.php' ) : admin_url( 'options-general.php' );
	wp_safe_redirect( add_query_arg( array( 'page' => 'teatatu-events-settings', 'teatatu_events_settings_saved' => '1' ), $redirect_base ) );
	exit;
}
