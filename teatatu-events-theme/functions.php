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
 * The five Te Atatū Local sites for the network strip, in the design
 * system's fixed order. Filter `ttev_network_sites` to change addresses
 * (e.g. on a staging network).
 *
 * @return array[] key => [label, url]
 */
function ttev_network_sites() {
	return apply_filters(
		'ttev_network_sites',
		array(
			'main'   => array( __( 'Te Atatū', 'teatatu-events-theme' ), 'https://teatatu.nz/' ),
			'events' => array( __( 'Events', 'teatatu-events-theme' ), home_url( '/' ) ),
			'news'   => array( __( 'News', 'teatatu-events-theme' ), 'https://news.teatatu.nz/' ),
			'homes'  => array( __( 'Homes', 'teatatu-events-theme' ), 'https://homes.teatatu.nz/' ),
			'market' => array( __( 'Market', 'teatatu-events-theme' ), 'https://market.teatatu.nz/' ),
		)
	);
}
