<?php
/**
 * Self-hosted GitHub-release-based auto-updater. Teatatu Events isn't on
 * WordPress.org, so this plugs into the normal wp-admin "update available"
 * UI (and the native auto-update opt-in) using only wp_remote_get() and
 * core update-related filters — no bundled update library.
 *
 * How a release is installed:
 *  - the latest GitHub Release of the configured repo is compared with the
 *    installed version (tag "v1.0.1" or "1.0.1");
 *  - the package is the release's attached `teatatu-events*.zip` asset (built
 *    by tools/build-release.sh), else GitHub's source zip of the tag;
 *  - the source zip holds the whole repository with the plugin in its
 *    `teatatu-events/` subfolder, so only that subfolder is installed;
 *  - with a GitHub token set (private repos), the token is sent with the
 *    release lookup and the download.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads a header from this plugin's own file, with a tiny static cache.
 *
 * @param string $key 'Name', 'Description', 'RequiresWP', or 'RequiresPHP'.
 * @return string
 */
function teatatu_events_get_plugin_header( $key ) {
	static $headers = null;
	if ( null === $headers ) {
		$headers = get_file_data(
			TEATATU_EVENTS_FILE,
			array(
				'Name'        => 'Plugin Name',
				'Description' => 'Description',
				'RequiresWP'  => 'Requires at least',
				'RequiresPHP' => 'Requires PHP',
			)
		);
	}
	return isset( $headers[ $key ] ) ? $headers[ $key ] : '';
}

/**
 * Gets the configured `owner/repo` GitHub slug.
 *
 * @return string
 */
function teatatu_events_get_github_repo() {
	$repo = teatatu_events_get_option( 'teatatu_events_github_repo', 'TeAtatu/Teatatu-events' );
	return $repo ? $repo : 'TeAtatu/Teatatu-events';
}

/**
 * Fetches (and caches) the latest GitHub release for the configured repo.
 *
 * @param bool $force Bypass the cache.
 * @return array|false Decoded release data, or false on failure.
 */
function teatatu_events_fetch_latest_release( $force = false ) {
	$cache_key = 'teatatu_events_github_release';

	if ( ! $force ) {
		$cached = get_site_transient( $cache_key );
		if ( false !== $cached ) {
			return ( is_array( $cached ) && ! empty( $cached['tte_failed'] ) ) ? false : $cached;
		}
	}

	$repo = teatatu_events_get_github_repo();

	$headers = array(
		'User-Agent' => 'Teatatu-Events-Plugin/' . TEATATU_EVENTS_VERSION . ' (' . home_url() . ')',
		'Accept'     => 'application/vnd.github+json',
	);
	$token = teatatu_events_get_option( 'teatatu_events_github_token', '' );
	if ( $token ) {
		$headers['Authorization'] = 'token ' . $token;
	}

	$response = wp_remote_get(
		"https://api.github.com/repos/{$repo}/releases/latest",
		array(
			'headers' => $headers,
			'timeout' => 15,
		)
	);

	$body = ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) ? json_decode( wp_remote_retrieve_body( $response ), true ) : null;
	if ( ! is_array( $body ) || empty( $body['tag_name'] ) ) {
		// No release yet, GitHub down or rate-limited: don't ask again for an hour.
		set_site_transient( $cache_key, array( 'tte_failed' => 1 ), HOUR_IN_SECONDS );
		return false;
	}

	set_site_transient( $cache_key, $body, 12 * HOUR_IN_SECONDS );
	return $body;
}

/**
 * Download URL for a release: its attached teatatu-events*.zip asset when
 * there is one (with a token: the API asset URL, which accepts the token),
 * else GitHub's source zip of the tag.
 *
 * @param array $release Release data.
 * @return string
 */
function teatatu_events_release_package( $release ) {
	$token = teatatu_events_get_option( 'teatatu_events_github_token', '' );
	foreach ( (array) ( $release['assets'] ?? array() ) as $asset ) {
		$name = (string) ( $asset['name'] ?? '' );
		// The plugin's zip (teatatu-events.zip / teatatu-events-1.2.0.zip), never the theme's.
		if ( preg_match( '/^teatatu-events(-\d[\w.]*)?\.zip$/i', $name ) ) {
			if ( $token && ! empty( $asset['url'] ) ) {
				return (string) $asset['url'];
			}
			if ( ! empty( $asset['browser_download_url'] ) ) {
				return (string) $asset['browser_download_url'];
			}
		}
	}
	return isset( $release['zipball_url'] ) ? (string) $release['zipball_url'] : '';
}

/**
 * The plugin's entry for the update_plugins transient.
 *
 * @param string $version Version offered.
 * @param string $package Download URL ('' when up to date).
 * @return object
 */
function teatatu_events_update_item( $version, $package ) {
	return (object) array(
		'id'           => 'https://github.com/' . teatatu_events_get_github_repo(),
		'slug'         => 'teatatu-events',
		'plugin'       => TEATATU_EVENTS_BASENAME,
		'new_version'  => $version,
		'url'          => 'https://github.com/' . teatatu_events_get_github_repo(),
		'package'      => $package,
		'tested'       => get_bloginfo( 'version' ),
		'requires'     => teatatu_events_get_plugin_header( 'RequiresWP' ),
		'requires_php' => teatatu_events_get_plugin_header( 'RequiresPHP' ),
		'icons'        => array(),
		'banners'      => array(),
	);
}

add_filter( 'pre_set_site_transient_update_plugins', 'teatatu_events_check_for_update' );

/**
 * Populates the update_plugins transient: an update when a newer GitHub
 * release exists, otherwise a "no update" entry — WordPress only offers the
 * "Enable auto-updates" toggle for plugins listed in one or the other.
 *
 * @param object $transient Existing update_plugins transient.
 * @return object
 */
function teatatu_events_check_for_update( $transient ) {
	if ( ! is_object( $transient ) || empty( $transient->checked ) ) {
		return $transient;
	}

	$release        = teatatu_events_fetch_latest_release();
	$remote_version = $release ? ltrim( (string) $release['tag_name'], 'vV' ) : '';
	$package        = $release ? teatatu_events_release_package( $release ) : '';

	if ( $remote_version && $package && version_compare( $remote_version, TEATATU_EVENTS_VERSION, '>' ) ) {
		$transient->response[ TEATATU_EVENTS_BASENAME ] = teatatu_events_update_item( $remote_version, $package );
		unset( $transient->no_update[ TEATATU_EVENTS_BASENAME ] );
	} else {
		$transient->no_update[ TEATATU_EVENTS_BASENAME ] = teatatu_events_update_item( TEATATU_EVENTS_VERSION, '' );
		unset( $transient->response[ TEATATU_EVENTS_BASENAME ] );
	}
	return $transient;
}

add_filter( 'http_request_args', 'teatatu_events_github_download_auth', 10, 2 );

/**
 * Sends the GitHub token with requests to GitHub's API and source-zip host
 * (private repos), and asks the API for the file itself when downloading a
 * release asset. Nothing is added without a token or for other hosts.
 *
 * @param array  $args Request arguments.
 * @param string $url  URL.
 * @return array
 */
function teatatu_events_github_download_auth( $args, $url ) {
	$token = teatatu_events_get_option( 'teatatu_events_github_token', '' );
	$host  = wp_parse_url( $url, PHP_URL_HOST );
	if ( ! $token || ! in_array( $host, array( 'api.github.com', 'codeload.github.com' ), true ) ) {
		return $args;
	}
	$repo = teatatu_events_get_github_repo();
	$path = (string) wp_parse_url( $url, PHP_URL_PATH );
	if ( 'api.github.com' === $host && 0 !== stripos( $path, '/repos/' . $repo . '/' ) ) {
		return $args;
	}
	$args['headers']                  = isset( $args['headers'] ) && is_array( $args['headers'] ) ? $args['headers'] : array();
	$args['headers']['Authorization'] = 'token ' . $token;
	if ( preg_match( '#/releases/assets/\d+$#', $path ) ) {
		$args['headers']['Accept'] = 'application/octet-stream';
	}
	return $args;
}

add_filter( 'plugins_api', 'teatatu_events_plugins_api', 10, 3 );

/**
 * Powers the "View version details" popup from the latest GitHub release.
 *
 * @param false|object|array $result The result object/array (default false).
 * @param string              $action  The type of information being requested.
 * @param object              $args    Plugin API arguments.
 * @return false|object
 */
function teatatu_events_plugins_api( $result, $action, $args ) {
	if ( 'plugin_information' !== $action || empty( $args->slug ) || 'teatatu-events' !== $args->slug ) {
		return $result;
	}

	$release        = teatatu_events_fetch_latest_release();
	$remote_version = ( $release && ! empty( $release['tag_name'] ) ) ? ltrim( $release['tag_name'], 'vV' ) : TEATATU_EVENTS_VERSION;
	$changelog      = ( $release && ! empty( $release['body'] ) ) ? $release['body'] : '';

	$info = array(
		'name'          => teatatu_events_get_plugin_header( 'Name' ),
		'slug'          => 'teatatu-events',
		'version'       => $remote_version,
		'author'        => '<a href="https://github.com/TeAtatu">TeAtatu</a>',
		'homepage'      => 'https://github.com/' . teatatu_events_get_github_repo(),
		'requires'      => teatatu_events_get_plugin_header( 'RequiresWP' ),
		'requires_php'  => teatatu_events_get_plugin_header( 'RequiresPHP' ),
		'download_link' => $release ? teatatu_events_release_package( $release ) : '',
		'sections'      => array(
			'description' => wp_kses_post( teatatu_events_get_plugin_header( 'Description' ) ),
			'changelog'   => $changelog ? wpautop( wp_kses_post( $changelog ) ) : '<p>' . esc_html__( 'No changelog available.', 'teatatu-events' ) . '</p>',
		),
	);

	return (object) $info;
}

add_filter( 'upgrader_source_selection', 'teatatu_events_fix_source_dir', 10, 4 );

/**
 * Makes the extracted package install as `teatatu-events/`:
 *  - a built release zip already holds `teatatu-events/` (left as is);
 *  - GitHub's source zip holds `owner-repo-<sha>/` with the whole
 *    repository, and the plugin in its `teatatu-events/` subfolder — only
 *    that subfolder is installed (README, SPEC etc. are left behind);
 *  - any other folder whose top level is the plugin is renamed.
 *
 * @param string|WP_Error $source        Path to the extracted source.
 * @param string          $remote_source Path to the parent temp directory.
 * @param WP_Upgrader     $upgrader      Upgrader instance.
 * @param array           $hook_extra    Extra arguments, including 'plugin'.
 * @return string|WP_Error
 */
function teatatu_events_fix_source_dir( $source, $remote_source, $upgrader, $hook_extra = array() ) {
	global $wp_filesystem;

	if ( is_wp_error( $source ) || empty( $hook_extra['plugin'] ) || TEATATU_EVENTS_BASENAME !== $hook_extra['plugin'] || ! $wp_filesystem ) {
		return $source;
	}

	$source  = trailingslashit( $source );
	$desired = trailingslashit( $remote_source ) . 'teatatu-events/';

	if ( $wp_filesystem->exists( $source . 'teatatu-events.php' ) ) {
		$plugin_dir = $source;
	} elseif ( $wp_filesystem->exists( $source . 'teatatu-events/teatatu-events.php' ) ) {
		$plugin_dir = $source . 'teatatu-events/';
	} else {
		return new WP_Error( 'teatatu_events_bad_package', __( 'The downloaded update does not contain teatatu-events/teatatu-events.php. Nothing was changed.', 'teatatu-events' ) );
	}
	if ( $plugin_dir === $desired ) {
		return $desired;
	}
	// Move the plugin folder out to remote_source/teatatu-events/ (via a
	// temporary name when it is nested inside a folder of the same name).
	$staging = trailingslashit( $remote_source ) . 'tte-staging-' . wp_generate_password( 6, false ) . '/';
	if ( ! $wp_filesystem->move( $plugin_dir, $staging, true ) ) {
		return new WP_Error( 'teatatu_events_move_failed', __( 'Could not prepare the downloaded update.', 'teatatu-events' ) );
	}
	if ( $wp_filesystem->exists( $desired ) ) {
		$wp_filesystem->delete( $desired, true );
	}
	if ( ! $wp_filesystem->move( $staging, $desired, true ) ) {
		return new WP_Error( 'teatatu_events_move_failed', __( 'Could not prepare the downloaded update.', 'teatatu-events' ) );
	}
	return $desired;
}

add_action( 'admin_post_teatatu_events_check_updates', 'teatatu_events_handle_check_updates' );

/**
 * Handles the "Check for Updates Now" button: clears cached GitHub data and
 * the core update_plugins transient so the next admin page load re-checks.
 */
function teatatu_events_handle_check_updates() {
	if ( ! isset( $_POST['teatatu_events_check_updates_nonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['teatatu_events_check_updates_nonce'] ), 'teatatu_events_check_updates' ) ) {
		wp_die( esc_html__( 'Security check failed.', 'teatatu-events' ) );
	}
	$cap = is_multisite() ? 'manage_network_options' : 'manage_options';
	if ( ! current_user_can( $cap ) ) {
		wp_die( esc_html__( 'You do not have permission to do that.', 'teatatu-events' ), 403 );
	}

	delete_site_transient( 'teatatu_events_github_release' );
	delete_site_transient( 'update_plugins' );

	$redirect_base = is_multisite() ? network_admin_url( 'settings.php' ) : admin_url( 'options-general.php' );
	wp_safe_redirect( add_query_arg( array( 'page' => 'teatatu-events-settings', 'teatatu_events_settings_saved' => '1' ), $redirect_base ) );
	exit;
}
