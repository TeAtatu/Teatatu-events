<?php
/**
 * Customizer: presentation settings only (hero photo, "Add an event" link,
 * footer line). Event data lives in the Teatatu Events plugin.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'customize_register', 'ttev_customize_register' );

/**
 * Registers the "Te Atatū Events" Customizer section.
 *
 * @param WP_Customize_Manager $wp_customize Manager.
 */
function ttev_customize_register( $wp_customize ) {
	$wp_customize->add_section(
		'ttev_theme',
		array(
			'title'    => __( 'Te Atatū Events', 'teatatu-events-theme' ),
			'priority' => 30,
		)
	);

	$settings = array(
		'ttev_hero_image'     => array( __( 'Home page photo URL (optional — without one, the home page shows "Happening next")', 'teatatu-events-theme' ), 'url', 'esc_url_raw' ),
		'ttev_hero_image_alt' => array( __( 'Home page photo description (alt text)', 'teatatu-events-theme' ), 'text', 'sanitize_text_field' ),
		'ttev_submit_url'     => array( __( '"Add an event" link (a form or contact page)', 'teatatu-events-theme' ), 'url', 'esc_url_raw' ),
		'ttev_footer_text'    => array( __( 'Footer line', 'teatatu-events-theme' ), 'text', 'sanitize_text_field' ),
	);
	foreach ( $settings as $id => $def ) {
		$wp_customize->add_setting( $id, array( 'default' => '', 'sanitize_callback' => $def[2] ) );
		$wp_customize->add_control( $id, array( 'label' => $def[0], 'section' => 'ttev_theme', 'type' => $def[1] ) );
	}
}
