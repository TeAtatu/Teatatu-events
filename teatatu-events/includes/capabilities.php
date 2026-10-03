<?php
/**
 * Custom role, capability provisioning, and taxonomy-capability mapping for
 * the `teatatu_events_agent` role plus human editor roles.
 *
 * The agent role drafts events and proposes changes via draft copies, but
 * can never publish, edit published or other users' events, configure
 * feeds, manage series, create Venues/Event Tags/Neighbourhoods, or manage
 * linked events. That boundary is permanent, not a temporary restriction.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Capabilities granted to the restricted AI-agent role.
 *
 * Venues and Event Tags are curated lists, so the agent may only assign
 * them. Sources and Categories are hierarchical; WordPress requires
 * edit_terms (not just manage_terms) to create a hierarchical term over
 * REST, so the agent gets both for those two.
 *
 * @return bool[]
 */
function teatatu_events_agent_capabilities() {
	return array(
		'read'                             => true,
		'edit_teatatu_events_items'        => true,
		'delete_teatatu_events_items'      => true,
		'assign_teatatu_events_sources'    => true,
		'manage_teatatu_events_sources'    => true,
		'edit_teatatu_events_sources'      => true,
		'assign_teatatu_events_categories' => true,
		'manage_teatatu_events_categories' => true,
		'edit_teatatu_events_categories'   => true,
		'assign_teatatu_events_venues'     => true,
		'assign_teatatu_events_tags'       => true,
	);
}

/**
 * Full set of Events capabilities granted to Administrator and Editor.
 *
 * @return bool[]
 */
function teatatu_events_editor_capabilities() {
	$caps = array(
		'edit_teatatu_event'                    => true,
		'read_teatatu_event'                    => true,
		'delete_teatatu_event'                  => true,
		'edit_teatatu_events_items'             => true,
		'edit_others_teatatu_events_items'      => true,
		'publish_teatatu_events_items'          => true,
		'read_private_teatatu_events_items'     => true,
		'delete_teatatu_events_items'           => true,
		'delete_private_teatatu_events_items'   => true,
		'delete_published_teatatu_events_items' => true,
		'delete_others_teatatu_events_items'    => true,
		'edit_private_teatatu_events_items'     => true,
		'edit_published_teatatu_events_items'   => true,
		'manage_teatatu_events_series'          => true,
		'manage_teatatu_events_feeds'           => true,
		'manage_teatatu_events_links'           => true,
	);
	foreach ( array( 'sources', 'categories', 'venues', 'tags', 'neighbourhoods' ) as $tax ) {
		foreach ( array( 'manage', 'edit', 'delete', 'assign' ) as $verb ) {
			$caps[ $verb . '_teatatu_events_' . $tax ] = true;
		}
	}
	return $caps;
}

/**
 * Adds the `teatatu_events_agent` role and grants Events capabilities to
 * Administrator/Editor on the current site. Safe to call repeatedly.
 */
function teatatu_events_add_role_and_caps() {
	add_role(
		'teatatu_events_agent',
		__( 'Events Agent', 'teatatu-events' ),
		teatatu_events_agent_capabilities()
	);

	// Re-adding an existing role with add_role() is a no-op, so refresh caps explicitly.
	$agent_role = get_role( 'teatatu_events_agent' );
	if ( $agent_role ) {
		foreach ( teatatu_events_agent_capabilities() as $cap => $grant ) {
			$agent_role->add_cap( $cap, $grant );
		}
	}

	foreach ( array( 'administrator', 'editor' ) as $role_name ) {
		$role = get_role( $role_name );
		if ( ! $role ) {
			continue;
		}
		foreach ( teatatu_events_editor_capabilities() as $cap => $grant ) {
			$role->add_cap( $cap, $grant );
		}
	}
}

/**
 * Re-syncs roles/capabilities whenever the plugin's version has changed
 * since they were last provisioned on this site. Capabilities are database
 * state: a site whose plugin files were replaced in place never otherwise
 * receives capabilities added in a later version.
 */
function teatatu_events_maybe_upgrade_caps() {
	if ( get_option( 'teatatu_events_caps_version' ) !== TEATATU_EVENTS_VERSION ) {
		teatatu_events_add_role_and_caps();
		teatatu_events_seed_neighbourhood_terms();
		teatatu_events_seed_street_rules();
		update_option( 'teatatu_events_caps_version', TEATATU_EVENTS_VERSION );
		// Fill in duplicate-matching keys for any events that predate them.
		if ( ! wp_next_scheduled( 'teatatu_events_backfill_keys', array( 0 ) ) ) {
			wp_schedule_single_event( time() + 30, 'teatatu_events_backfill_keys', array( 0 ) );
		}
	}
}
add_action( 'admin_init', 'teatatu_events_maybe_upgrade_caps' );

/**
 * Removes the `teatatu_events_agent` role and strips Events capabilities
 * from Administrator/Editor on the current site.
 */
function teatatu_events_remove_role_and_caps() {
	remove_role( 'teatatu_events_agent' );

	foreach ( array( 'administrator', 'editor' ) as $role_name ) {
		$role = get_role( $role_name );
		if ( ! $role ) {
			continue;
		}
		foreach ( array_keys( teatatu_events_editor_capabilities() ) as $cap ) {
			$role->remove_cap( $cap );
		}
	}
}

/**
 * Whether the current user has the agent role (used for agent-only rules
 * such as the REST duplicate guard and automatic needs-venue flagging).
 *
 * @return bool
 */
function teatatu_events_current_user_is_agent() {
	$user = wp_get_current_user();
	return $user && in_array( 'teatatu_events_agent', (array) $user->roles, true );
}

add_filter( 'pre_insert_term', 'teatatu_events_guard_curated_term_creation', 10, 2 );

/**
 * Venues, Event Tags and Neighbourhoods are curated lists. WordPress lets
 * anyone with assign_terms create a *non-hierarchical* term over REST, so
 * this enforces the "pick from the list, never grow it" rule for every code
 * path: creating one of these terms requires the matching manage_ cap.
 * Neighbourhood terms can only be created by the plugin's own seeding.
 *
 * @param string|WP_Error $term     Term name.
 * @param string          $taxonomy Taxonomy.
 * @return string|WP_Error
 */
function teatatu_events_guard_curated_term_creation( $term, $taxonomy ) {
	if ( is_wp_error( $term ) ) {
		return $term;
	}
	if ( 'teatatu_events_neighbourhood' === $taxonomy && empty( $GLOBALS['teatatu_events_seeding_neighbourhoods'] ) ) {
		return new WP_Error( 'teatatu_events_curated_term', __( 'Neighbourhoods are fixed; they cannot be added.', 'teatatu-events' ) );
	}
	$caps = array(
		'teatatu_events_venue' => 'manage_teatatu_events_venues',
		'teatatu_events_tag'   => 'manage_teatatu_events_tags',
	);
	if ( isset( $caps[ $taxonomy ] ) && ! current_user_can( $caps[ $taxonomy ] ) ) {
		return new WP_Error( 'rest_cannot_create', __( 'Only editors can add to this list.', 'teatatu-events' ), array( 'status' => 403 ) );
	}
	return $term;
}
