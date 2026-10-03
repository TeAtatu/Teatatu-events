<?php
/**
 * Scheduled jobs (WP-Cron). One tick per importer (not a shared
 * dispatcher), so a slow or failing source type can't use up the others'
 * run time. Each hook is re-checked on 'init' and rescheduled if missing
 * (schedules can go missing outside deactivation). Deactivation and
 * uninstall clear every hook on every site; nothing here ever touches
 * Teatatu News's hooks.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every recurring hook and its interval.
 *
 * @return string[] hook => schedule
 */
function teatatu_events_cron_hooks() {
	return array(
		'teatatu_events_rss_cron_tick'    => 'teatatu_events_five_minutes',
		'teatatu_events_html_cron_tick'   => 'teatatu_events_five_minutes',
		'teatatu_events_ical_cron_tick'   => 'teatatu_events_five_minutes',
		'teatatu_events_ld_cron_tick'     => 'teatatu_events_five_minutes',
		'teatatu_events_series_cron_tick' => 'hourly',
		'teatatu_events_links_cron_tick'  => 'daily',
	);
}

add_filter( 'cron_schedules', 'teatatu_events_cron_schedules' ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval

/**
 * Registers the 5-minute interval — the finest cadence a feed can use. Each
 * feed still runs on its own cadence; a tick with nothing due returns
 * straight away.
 *
 * @param array $schedules Schedules.
 * @return array
 */
function teatatu_events_cron_schedules( $schedules ) {
	$schedules['teatatu_events_five_minutes'] = array(
		'interval' => 5 * MINUTE_IN_SECONDS,
		'display'  => __( 'Every 5 Minutes (Teatatu Events)', 'teatatu-events' ),
	);
	return $schedules;
}

/**
 * Schedules every recurring hook on the current site (idempotent). The
 * linked-events reconciliation only runs where linked events are on.
 */
function teatatu_events_schedule_all_cron() {
	foreach ( teatatu_events_cron_hooks() as $hook => $schedule ) {
		if ( 'teatatu_events_links_cron_tick' === $hook && ! teatatu_events_linked_enabled() ) {
			if ( wp_next_scheduled( $hook ) ) {
				wp_clear_scheduled_hook( $hook );
			}
			continue;
		}
		if ( ! wp_next_scheduled( $hook ) ) {
			wp_schedule_event( time() + 60, $schedule, $hook );
		}
	}
}
add_action( 'init', 'teatatu_events_schedule_all_cron', 20 );

/**
 * Clears every recurring and one-off hook on the current site.
 */
function teatatu_events_unschedule_all_cron() {
	foreach ( array_keys( teatatu_events_cron_hooks() ) as $hook ) {
		wp_clear_scheduled_hook( $hook );
	}
	wp_unschedule_hook( 'teatatu_events_nbhd_reresolve' );
	wp_unschedule_hook( 'teatatu_events_backfill_keys' );
}
