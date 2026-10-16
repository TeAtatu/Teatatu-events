<?php
/**
 * Self-hosted GitHub-release-based updater for the theme, alongside the
 * Teatatu Events plugin's updater (teatatu-events/includes/updater.php).
 *
 * One GitHub release (latest, tag vX.Y.Z) ships both the plugin and this
 * theme. The tag only points at the release: the theme's *own* Version
 * header in teatatu-events-theme/style.css at that tag is compared with the
 * installed version, so a release that only changes the plugin never
 * offers a theme update.
 *
 * Package: the release's attached teatatu-events-theme*.zip (built by
 * teatatu-events/tools/build-release.sh) when there is one, else GitHub's
 * source zip, from which only the teatatu-events-theme/ folder is installed.
 *
 * Installing: automatically only when "Enable auto-updates" is on for this
 * theme (Appearance > Themes; Network Admin > Themes on multisite).
 * Otherwise the "update available" notice shows and an admin clicks
 * Update now. The GitHub repository and token come from the plugin's
 * settings (Settings > Teatatu Events) when the plugin is active.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The theme's folder name (its stylesheet/template slug).
 *
 * @return string
 */
function ttev_updater_slug() {
	return get_template();
}

/**
 * Installed theme version.
 *
 * @return string
 */
function ttev_updater_installed_version() {
	return (string) wp_get_theme( ttev_updater_slug() )->get( 'Version' );
}

/**
 * `owner/repo` for updates: the plugin's setting when available.
 *
 * @return string
 */
function ttev_updater_repo() {
	$repo = function_exists( 'teatatu_events_get_github_repo' ) ? teatatu_events_get_github_repo() : '';
	return (string) apply_filters( 'ttev_updater_repo', $repo ? $repo : 'TeAtatu/Teatatu-events' );
}

/**
 * GitHub token (only needed for a private repository).
 *
 * @return string
 */
function ttev_updater_token() {
	return function_exists( 'teatatu_events_get_option' ) ? (string) teatatu_events_get_option( 'teatatu_events_github_token', '' ) : '';
}

/**
 * Request headers for GitHub.
 *
 * @param string $accept Accept header.
 * @return array
 */
function ttev_updater_headers( $accept = 'application/vnd.github+json' ) {
	$headers = array(
		'User-Agent' => 'Teatatu-Events-Theme/' . ttev_updater_installed_version() . ' (' . home_url() . ')',
		'Accept'     => $accept,
	);
	$token = ttev_updater_token();
	if ( $token ) {
		$headers['Authorization'] = 'token ' . $token;
	}
	return $headers;
}

/**
 * The theme's version and download at the latest release (cached 12h; a
 * failed lookup is cached for an hour).
 *
 * @param bool $force Bypass the cache.
 * @return array|null {version, package, changelog, url, requires, requires_php}
 */
function ttev_updater_release_info( $force = false ) {
	$cache_key = 'ttev_theme_github_release';
	if ( ! $force ) {
		$cached = get_site_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached ? $cached : null;
		}
	}
	$fail = function () use ( $cache_key ) {
		set_site_transient( $cache_key, 0, HOUR_IN_SECONDS );
		return null;
	};

	$repo     = ttev_updater_repo();
	$response = wp_remote_get( "https://api.github.com/repos/{$repo}/releases/latest", array( 'headers' => ttev_updater_headers(), 'timeout' => 15 ) );
	if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
		return $fail();
	}
	$release = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( ! is_array( $release ) || empty( $release['tag_name'] ) ) {
		return $fail();
	}

	$style = wp_remote_get(
		"https://api.github.com/repos/{$repo}/contents/teatatu-events-theme/style.css?ref=" . rawurlencode( $release['tag_name'] ),
		array( 'headers' => ttev_updater_headers( 'application/vnd.github.raw+json' ), 'timeout' => 15 )
	);
	if ( is_wp_error( $style ) || 200 !== (int) wp_remote_retrieve_response_code( $style ) ) {
		return $fail();
	}
	$head    = substr( wp_remote_retrieve_body( $style ), 0, 8192 );
	$field   = function ( $name ) use ( $head ) {
		return preg_match( '/^[ \t\/*#@]*' . preg_quote( $name, '/' ) . ':\s*(.+)$/mi', $head, $m ) ? trim( $m[1] ) : '';
	};
	$version = $field( 'Version' );
	if ( '' === $version ) {
		return $fail();
	}

	$package = isset( $release['zipball_url'] ) ? (string) $release['zipball_url'] : '';
	foreach ( (array) ( $release['assets'] ?? array() ) as $asset ) {
		if ( preg_match( '/^teatatu-events-theme.*\.zip$/i', (string) ( $asset['name'] ?? '' ) ) ) {
			$package = ttev_updater_token() && ! empty( $asset['url'] ) ? (string) $asset['url'] : (string) ( $asset['browser_download_url'] ?? $package );
			break;
		}
	}
	if ( '' === $package ) {
		return $fail();
	}

	$info = array(
		'version'      => $version,
		'package'      => $package,
		'changelog'    => isset( $release['body'] ) ? (string) $release['body'] : '',
		'url'          => isset( $release['html_url'] ) ? $release['html_url'] : 'https://github.com/' . $repo,
		'requires'     => $field( 'Requires at least' ),
		'requires_php' => $field( 'Requires PHP' ),
	);
	set_site_transient( $cache_key, $info, 12 * HOUR_IN_SECONDS );
	return $info;
}

add_filter( 'pre_set_site_transient_update_themes', 'ttev_updater_check' );

/**
 * Offers the update in wp-admin when the release has a newer theme version;
 * otherwise lists the theme as up to date (so the auto-update toggle shows).
 *
 * @param object $transient update_themes transient.
 * @return object
 */
function ttev_updater_check( $transient ) {
	if ( ! is_object( $transient ) || empty( $transient->checked ) ) {
		return $transient;
	}
	$slug = ttev_updater_slug();
	$info = ttev_updater_release_info();
	$item = array(
		'theme'        => $slug,
		'new_version'  => ttev_updater_installed_version(),
		'url'          => add_query_arg( array( 'action' => 'ttev_theme_changelog' ), admin_url( 'admin-ajax.php' ) ),
		'package'      => '',
		'requires'     => '',
		'requires_php' => '',
	);
	if ( $info && version_compare( $info['version'], ttev_updater_installed_version(), '>' ) ) {
		$transient->response[ $slug ] = array_merge(
			$item,
			array(
				'new_version'  => $info['version'],
				'package'      => $info['package'],
				'requires'     => $info['requires'],
				'requires_php' => $info['requires_php'],
			)
		);
		unset( $transient->no_update[ $slug ] );
	} else {
		$transient->no_update[ $slug ] = $item;
		unset( $transient->response[ $slug ] );
	}
	return $transient;
}

add_action( 'wp_ajax_ttev_theme_changelog', 'ttev_updater_changelog' );

/**
 * The "View version details" modal: the release notes.
 */
function ttev_updater_changelog() {
	if ( ! current_user_can( 'update_themes' ) ) {
		wp_die( esc_html__( 'You do not have permission to view this.', 'teatatu-events-theme' ), 403 );
	}
	$info = ttev_updater_release_info();
	echo '<!doctype html><html><head><meta charset="utf-8"><title>' . esc_html__( 'Teatatu Events Theme', 'teatatu-events-theme' ) . '</title>';
	echo '<style>body{font:15px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;margin:24px;color:#1d2327}h1{font-size:20px}</style></head><body>';
	if ( ! $info ) {
		echo '<p>' . esc_html__( 'Release details are not available right now.', 'teatatu-events-theme' ) . '</p>';
	} else {
		/* translators: %s: version. */
		echo '<h1>' . esc_html( sprintf( __( 'Teatatu Events Theme %s', 'teatatu-events-theme' ), $info['version'] ) ) . '</h1>';
		echo $info['changelog'] ? wp_kses_post( wpautop( $info['changelog'] ) ) : '<p>' . esc_html__( 'No changelog available.', 'teatatu-events-theme' ) . '</p>';
		echo '<p><a href="' . esc_url( $info['url'] ) . '" target="_blank" rel="noopener">' . esc_html__( 'View the release on GitHub', 'teatatu-events-theme' ) . '</a></p>';
	}
	echo '</body></html>';
	exit;
}

add_filter( 'upgrader_source_selection', 'ttev_updater_source_dir', 10, 4 );

/**
 * Makes the downloaded release install as themes/<this theme's folder>/.
 * GitHub's source zip holds the whole repository, so the
 * teatatu-events-theme/ subfolder is lifted out; a built theme zip already
 * has the theme at its top level.
 *
 * @param string|WP_Error $source        Extracted source path.
 * @param string          $remote_source Parent temp directory.
 * @param WP_Upgrader     $upgrader      Upgrader.
 * @param array           $hook_extra    Includes 'theme'.
 * @return string|WP_Error
 */
function ttev_updater_source_dir( $source, $remote_source, $upgrader, $hook_extra = array() ) {
	global $wp_filesystem;
	$slug = ttev_updater_slug();
	if ( is_wp_error( $source ) || empty( $hook_extra['theme'] ) || $slug !== $hook_extra['theme'] || ! $wp_filesystem ) {
		return $source;
	}

	$source  = trailingslashit( $source );
	$desired = trailingslashit( $remote_source ) . $slug . '/';

	if ( $wp_filesystem->exists( $source . 'teatatu-events-theme/style.css' ) ) {
		$from = $source . 'teatatu-events-theme/';
	} elseif ( $wp_filesystem->exists( $source . 'style.css' ) && $wp_filesystem->exists( $source . 'single-teatatu_event.php' ) ) {
		$from = $source;
	} else {
		return new WP_Error( 'ttev_bad_package', __( 'The downloaded update does not contain the Teatatu Events theme. Nothing was changed.', 'teatatu-events-theme' ) );
	}

	if ( $from === $desired ) {
		return $desired;
	}
	$staging = trailingslashit( $remote_source ) . 'ttev-staging-' . wp_generate_password( 6, false ) . '/';
	if ( $wp_filesystem->move( untrailingslashit( $from ), untrailingslashit( $staging ), true ) ) {
		if ( $wp_filesystem->exists( $desired ) ) {
			$wp_filesystem->delete( $desired, true );
		}
		if ( $wp_filesystem->move( untrailingslashit( $staging ), untrailingslashit( $desired ), true ) ) {
			return $desired;
		}
	}
	return new WP_Error( 'ttev_move_failed', __( 'Could not prepare the Teatatu Events theme update. Nothing was changed.', 'teatatu-events-theme' ) );
}

add_filter( 'auto_update_theme', 'ttev_updater_auto_update_gate', PHP_INT_MAX, 2 );

/**
 * Automatic updates only when "Enable auto-updates" is on for this theme.
 * Left alone when WordPress only asks whether auto-updates are forced
 * (null), so the Enable/Disable link stays clickable.
 *
 * @param bool|null $update Whether to update automatically.
 * @param object    $item   The update offer.
 * @return bool|null
 */
function ttev_updater_auto_update_gate( $update, $item ) {
	if ( null === $update || empty( $item->theme ) || ttev_updater_slug() !== $item->theme ) {
		return $update;
	}
	return in_array( ttev_updater_slug(), (array) get_site_option( 'auto_update_themes', array() ), true );
}

add_filter( 'http_request_args', 'ttev_updater_authorize_download', 10, 2 );

/**
 * Sends the GitHub token (if set) when downloading the release (source zip
 * or attached asset), so updates work from a private repository.
 *
 * @param array  $args Request arguments.
 * @param string $url  URL.
 * @return array
 */
function ttev_updater_authorize_download( $args, $url ) {
	$token = ttev_updater_token();
	$repo  = 'https://api.github.com/repos/' . ttev_updater_repo() . '/';
	if ( ! $token || 0 !== strpos( $url, $repo ) ) {
		return $args;
	}
	$is_zip   = 0 === strpos( $url, $repo . 'zipball/' );
	$is_asset = (bool) preg_match( '#/releases/assets/\d+$#', $url );
	if ( $is_zip || $is_asset ) {
		$args['headers']                  = isset( $args['headers'] ) ? (array) $args['headers'] : array();
		$args['headers']['Authorization'] = 'token ' . $token;
		if ( $is_asset ) {
			$args['headers']['Accept'] = 'application/octet-stream';
		}
	}
	return $args;
}

add_action( 'load-update-core.php', 'ttev_updater_force_check' );
add_action( 'admin_post_teatatu_events_check_updates', 'ttev_updater_clear_cache', 1 );

/**
 * Dashboard > Updates > "Check again" skips the release cache.
 */
function ttev_updater_force_check() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only cache clear, same as core's force-check.
	if ( ! empty( $_GET['force-check'] ) && current_user_can( 'update_themes' ) ) {
		delete_site_transient( 'ttev_theme_github_release' );
	}
}

/**
 * The plugin's "Check for Updates Now" button checks the theme too. The
 * plugin's own handler verifies the nonce and capability and redirects;
 * this only clears caches, after the same checks.
 */
function ttev_updater_clear_cache() {
	if ( isset( $_POST['teatatu_events_check_updates_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['teatatu_events_check_updates_nonce'] ) ), 'teatatu_events_check_updates' ) && current_user_can( 'update_themes' ) ) {
		delete_site_transient( 'ttev_theme_github_release' );
		delete_site_transient( 'update_themes' );
	}
}
