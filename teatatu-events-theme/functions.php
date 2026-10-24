<?php
/**
 * Teatatu Events Theme — display only.
 *
 * Every event value shown by this theme comes from the Teatatu Events
 * plugin: events are read through teatatu_events_item_from_post() /
 * teatatu_events_search(), archive filters through
 * teatatu_events_current_filters() (the plugin applies them to the main
 * query), and calendar links through teatatu_events_calendar_urls(). The
 * theme stores no event data and reads no post meta. Without the plugin it
 * shows a notice instead of events.
 *
 * Styling follows the Te Atatū Local design system (assets/css/te-atatu-local*.css):
 * <html data-site="events"> gives every component the Pōhutukawa accent,
 * and events use the design system's own EventCard (.tal-event, .tal-date).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TTEV_THEME_VERSION', '1.0.0' );

require_once get_template_directory() . '/inc/template-tags.php';
require_once get_template_directory() . '/inc/customizer.php';
require_once get_template_directory() . '/inc/updater.php';

/**
 * Whether the Teatatu Events plugin (with its front-end API) is active.
 *
 * @return bool
 */
function ttev_plugin_ready() {
	return function_exists( 'teatatu_events_search' ) && function_exists( 'teatatu_events_calendar_urls' );
}

add_action( 'after_setup_theme', 'ttev_setup' );

/**
 * Theme supports and menus.
 */
function ttev_setup() {
	load_theme_textdomain( 'teatatu-events-theme', get_template_directory() . '/languages' );
	add_theme_support( 'title-tag' );
	add_theme_support( 'html5', array( 'search-form', 'gallery', 'caption', 'style', 'script', 'navigation-widgets' ) );
	add_theme_support( 'responsive-embeds' );
	register_nav_menus(
		array(
			'primary' => __( 'Header menu', 'teatatu-events-theme' ),
			'footer'  => __( 'Footer menu', 'teatatu-events-theme' ),
		)
	);
}

add_action( 'wp_enqueue_scripts', 'ttev_assets' );

/**
 * Fonts, styles and the small progressive-enhancement script.
 */
function ttev_assets() {
	$css = get_template_directory_uri() . '/assets/css/';
	wp_enqueue_style( 'tal-font', 'https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap', array(), null );
	wp_enqueue_style( 'tal-tokens', $css . 'te-atatu-local-tokens.css', array(), TTEV_THEME_VERSION );
	wp_enqueue_style( 'tal-components', $css . 'te-atatu-local.css', array( 'tal-tokens', 'tal-font' ), TTEV_THEME_VERSION );
	wp_enqueue_style( 'ttev-style', get_stylesheet_uri(), array( 'tal-components' ), TTEV_THEME_VERSION );
	wp_enqueue_script( 'ttev-theme', get_template_directory_uri() . '/assets/js/theme.js', array(), TTEV_THEME_VERSION, array( 'in_footer' => true, 'strategy' => 'defer' ) );
	wp_localize_script(
		'ttev-theme',
		'ttevTheme',
		array(
			'copied' => __( 'Link copied', 'teatatu-events-theme' ),
		)
	);
}

/**
 * Loads Leaflet (OpenStreetMap tiles) for a page that shows a map.
 * Called from templates only when there is at least one point to show.
 */
function ttev_enqueue_map() {
	wp_enqueue_style( 'leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css', array(), '1.9.4' );
	wp_enqueue_script( 'leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js', array(), '1.9.4', array( 'in_footer' => true ) );
	wp_enqueue_script( 'ttev-map', get_template_directory_uri() . '/assets/js/map.js', array( 'leaflet' ), TTEV_THEME_VERSION, array( 'in_footer' => true ) );
}

add_action( 'wp_head', 'ttev_js_class', 1 );

/**
 * Marks the document as script-capable before paint, so tab panels that
 * JS will manage don't flash.
 */
function ttev_js_class() {
	echo "<script>document.documentElement.classList.add('js');</script>\n";
}

add_filter( 'teatatu_events_content_details', '__return_false' );

add_filter( 'template_include', 'ttev_events_taxonomy_template' );

/**
 * Every event taxonomy archive (Neighbourhood, Category, Event Tag, Venue,
 * Source) is a filtered events list, so it uses the events archive template.
 *
 * @param string $template Template path.
 * @return string
 */
function ttev_events_taxonomy_template( $template ) {
	if ( ttev_plugin_ready() && is_tax( array_values( teatatu_events_archive_taxonomies() ) ) ) {
		$archive = locate_template( 'archive-teatatu_event.php' );
		if ( $archive ) {
			return $archive;
		}
	}
	return $template;
}

add_action( 'admin_notices', 'ttev_plugin_missing_notice' );

/**
 * Tells admins the theme needs the plugin.
 */
function ttev_plugin_missing_notice() {
	if ( ttev_plugin_ready() || ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	echo '<div class="notice notice-warning"><p>' . esc_html__( 'The Teatatu Events theme displays events from the Teatatu Events plugin (version with the front-end API). Activate or update the plugin to show events.', 'teatatu-events-theme' ) . '</p></div>';
}

add_filter( 'document_title_parts', 'ttev_title_parts' );

/**
 * A clearer page title on events lists ("Events this weekend in Matipo").
 *
 * @param array $parts Title parts.
 * @return array
 */
function ttev_title_parts( $parts ) {
	if ( ttev_plugin_ready() && ttev_is_events_list() ) {
		$parts['title'] = ttev_results_heading( teatatu_events_current_filters() );
	}
	return $parts;
}

/**
 * Whether this request is the events archive or an event taxonomy archive.
 *
 * @return bool
 */
function ttev_is_events_list() {
	return ttev_plugin_ready() && ( is_post_type_archive( 'teatatu_event' ) || is_tax( array_values( teatatu_events_archive_taxonomies() ) ) );
}

add_filter( 'language_attributes', 'ttev_site_attribute' );

/**
 * Marks the document as the Events site of the Te Atatū Local network, so
 * the design system's components take the Pōhutukawa accent.
 *
 * @param string $output Attributes for <html>.
 * @return string
 */
function ttev_site_attribute( $output ) {
	return $output . ' data-site="events"';
}

add_filter( 'body_class', 'ttev_body_class' );

/**
 * Applies the design system's root class (font, focus rings, reduced motion).
 *
 * @param string[] $classes Body classes.
 * @return string[]
 */
function ttev_body_class( $classes ) {
	$classes[] = 'tal-root';
	return $classes;
}

/**
 * The Te Atatū Local sites for the network strip, in the design system's
 * fixed order: Te Atatū and Events always; News, Homes and Market only when
 * their plugin is active (see ttev_section_plugins()). Filter
 * `ttev_network_sites` to change labels or addresses (e.g. on a staging
 * network) or to add/remove sites.
 *
 * @return array[] key => [label, url]
 */
function ttev_network_sites() {
	$sites = array(
		'main'   => array( __( 'Te Atatū', 'teatatu-events-theme' ), 'https://teatatu.nz/' ),
		'events' => array( __( 'Events', 'teatatu-events-theme' ), home_url( '/' ) ),
	);
	$labels = array(
		'news'   => __( 'News', 'teatatu-events-theme' ),
		'homes'  => __( 'Homes', 'teatatu-events-theme' ),
		'market' => __( 'Market', 'teatatu-events-theme' ),
	);
	foreach ( ttev_section_plugins() as $key => $def ) {
		$url = ttev_section_url( $key, $def );
		if ( '' !== $url ) {
			$sites[ $key ] = array( $labels[ $key ], $url );
		}
	}
	// Keep the design system's order: Te Atatū, Events, News, Homes, Market.
	$order = array_flip( array( 'main', 'events', 'news', 'homes', 'market' ) );
	uksort(
		$sites,
		function ( $a, $b ) use ( $order ) {
			return ( $order[ $a ] ?? 99 ) <=> ( $order[ $b ] ?? 99 );
		}
	);
	return apply_filters( 'ttev_network_sites', $sites );
}

/**
 * The sections that appear only when their plugin is active: key =>
 * {file: plugin main file name, post_type: its listing post type (for a
 * single-site install), url: default address}.
 *
 * @return array[]
 */
function ttev_section_plugins() {
	return array(
		'news'   => array( 'file' => 'teatatu-news.php', 'post_type' => 'teatatu_news', 'url' => 'https://news.teatatu.nz/' ),
		'homes'  => array( 'file' => 'teatatu-homes.php', 'post_type' => 'teatatu_home', 'url' => 'https://homes.teatatu.nz/' ),
		'market' => array( 'file' => 'teatatu-market.php', 'post_type' => '', 'url' => 'https://market.teatatu.nz/' ),
	);
}

/**
 * Where a plugin (by main file name, any folder) is active. The network-wide
 * list is read fresh every time (one stored option); the slower scan of
 * each site's own list is cached for 10 minutes, and cleared whenever a
 * plugin is (de)activated on a site using this theme.
 *
 * @param string $file Plugin main file name, e.g. teatatu-homes.php.
 * @return array {network: bool, sites: int[]} — sites is every site it is
 *               activated on individually (on single site: this site).
 */
function ttev_plugin_activity( $file ) {
	$matches = function ( $plugins ) use ( $file ) {
		foreach ( (array) $plugins as $plugin ) {
			if ( basename( (string) $plugin ) === $file ) {
				return true;
			}
		}
		return false;
	};
	if ( ! is_multisite() ) {
		return array( 'network' => false, 'sites' => $matches( get_option( 'active_plugins', array() ) ) ? array( get_current_blog_id() ) : array() );
	}
	if ( $matches( array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) ) ) {
		return array( 'network' => true, 'sites' => array() );
	}
	$scan = get_site_transient( 'ttev_plugin_activity' );
	$scan = is_array( $scan ) ? $scan : array();
	if ( ! isset( $scan[ $file ] ) ) {
		$scan[ $file ] = array();
		foreach ( get_sites( array( 'fields' => 'ids', 'number' => 500 ) ) as $site_id ) {
			if ( $matches( get_blog_option( $site_id, 'active_plugins', array() ) ) ) {
				$scan[ $file ][] = (int) $site_id;
			}
		}
		set_site_transient( 'ttev_plugin_activity', $scan, 10 * MINUTE_IN_SECONDS );
	}
	return array( 'network' => false, 'sites' => $scan[ $file ] );
}

add_action( 'activated_plugin', 'ttev_forget_plugin_activity' );
add_action( 'deactivated_plugin', 'ttev_forget_plugin_activity' );

/**
 * Plugin (de)activation anywhere on the network refreshes the strip.
 */
function ttev_forget_plugin_activity() {
	delete_site_transient( 'ttev_plugin_activity' );
}

/**
 * The address for a section, or '' when its plugin isn't active.
 *
 * - Multisite: the site whose address starts with the section name
 *   (homes.example.nz or example.nz/homes/) when the plugin runs there,
 *   else the one site it is activated on, else the default address.
 * - Single site (the plugin runs on this site): its listing page, else the
 *   default address.
 *
 * @param string $key Section key.
 * @param array  $def Section definition from ttev_section_plugins().
 * @return string
 */
function ttev_section_url( $key, $def ) {
	$activity = ttev_plugin_activity( $def['file'] );
	if ( ! $activity['network'] && ! $activity['sites'] ) {
		return '';
	}
	if ( ! is_multisite() ) {
		$archive = $def['post_type'] && post_type_exists( $def['post_type'] ) ? get_post_type_archive_link( $def['post_type'] ) : '';
		return $archive ? $archive : $def['url'];
	}
	$candidates = $activity['network'] ? get_sites( array( 'fields' => 'ids', 'number' => 500 ) ) : $activity['sites'];
	foreach ( $candidates as $site_id ) {
		$site = get_site( $site_id );
		if ( $site && ( 0 === strpos( $site->domain, $key . '.' ) || 0 === strpos( trim( $site->path, '/' ) . '/', $key . '/' ) ) ) {
			return get_home_url( $site_id, '/' );
		}
	}
	if ( ! $activity['network'] && 1 === count( $activity['sites'] ) ) {
		return get_home_url( $activity['sites'][0], '/' );
	}
	return $def['url'];
}
