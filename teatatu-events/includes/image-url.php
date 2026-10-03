<?php
/**
 * Resolves the display image URL for an event. Images are never downloaded
 * or stored locally — the plugin only ever stores and serves the remote URL
 * an editor, agent or feed supplied, so display always hotlinks the original.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The event's own image URL, falling back to its Source's default image,
 * then its Venue's default image.
 *
 * @param int $post_id Event ID.
 * @return string Image URL, or '' if none is available.
 */
function teatatu_events_get_image_url( $post_id ) {
	$raw = get_post_meta( $post_id, '_teatatu_events_image_url', true );
	if ( $raw ) {
		return $raw;
	}
	$source = teatatu_events_first_term_id( $post_id, 'teatatu_events_source' );
	if ( $source ) {
		$url = get_term_meta( $source, 'teatatu_events_source_default_image', true );
		if ( $url ) {
			return $url;
		}
	}
	$venue = teatatu_events_first_term_id( $post_id, 'teatatu_events_venue' );
	if ( $venue ) {
		$url = get_term_meta( $venue, 'teatatu_events_venue_default_image', true );
		if ( $url ) {
			return $url;
		}
	}
	return '';
}
