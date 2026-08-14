<?php
/**
 * Cron scheduling for periodic catalog sync.
 *
 * @package TopTex_WooCommerce
 */

namespace TopTexWooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Schedules and runs periodic imports.
 */
class Cron {

	/**
	 * Cron event hook.
	 */
	const HOOK = 'toptex_sync_event';

	/**
	 * Recurrence key prefix (dynamic based on setting).
	 */
	const RECURRENCE = 'toptex_interval';

	/**
	 * Boots the cron handler.
	 *
	 * @return Cron
	 */
	public static function instance() {
		static $instance = null;
		if ( null === $instance ) {
			$instance = new self();
			$instance->hooks();
		}
		return $instance;
	}

	/**
	 * Wires hooks.
	 *
	 * @return void
	 */
	private function hooks() {
		add_action( self::HOOK, array( $this, 'run' ) );
		add_filter( 'cron_schedules', array( $this, 'register_intervals' ) );
		add_action( 'update_option_toptex_options', array( $this, 'maybe_reschedule' ), 10, 2 );
	}

	/**
	 * Schedules the recurring event on activation (or when enabled).
	 *
	 * @return void
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	/**
	 * Unschedules the event on deactivation.
	 *
	 * @return void
	 */
	public static function unschedule() {
		$timestamp = wp_next_scheduled( self::HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::HOOK );
		}
	}

	/**
	 * Registers custom recurrence intervals.
	 *
	 * @param array $schedules Existing schedules.
	 * @return array
	 */
	public function register_intervals( $schedules ) {
		$schedules['toptex_hourly'] = array(
			'interval' => HOUR_IN_SECONDS,
			'display'  => \__( 'Every hour', 'toptex-woocommerce' ),
		);

		return $schedules;
	}

	/**
	 * Reschedules when the frequency setting changes.
	 *
	 * @param mixed $old_value Previous option value.
	 * @param mixed $new_value New option value.
	 * @return void
	 */
	public function maybe_reschedule( $old_value, $new_value ) {
		$freq = isset( $new_value['sync_frequency'] ) ? $new_value['sync_frequency'] : 'daily';

		self::unschedule();

		if ( 'manual' !== $freq ) {
			$interval = $this->map_frequency( $freq );
			wp_schedule_event( time() + $interval, $interval, self::HOOK );
		}
	}

	/**
	 * Maps a frequency slug to a WP cron interval.
	 *
	 * @param string $freq Frequency slug.
	 * @return string Interval key.
	 */
	private function map_frequency( $freq ) {
		switch ( $freq ) {
			case 'hourly':
				return 'toptex_hourly';
			case 'twicedaily':
				return 'twicedaily';
			case 'weekly':
				return 'weekly';
			case 'daily':
			default:
				return 'daily';
		}
	}

	/**
	 * Runs the scheduled import.
	 *
	 * @return void
	 */
	public function run() {
		if ( ! class_exists( \WooCommerce::class ) ) {
			return;
		}

		// Avoid overlapping runs.
		if ( get_transient( 'toptex_sync_running' ) ) {
			return;
		}

		set_transient( 'toptex_sync_running', true, HOUR_IN_SECONDS );

		Importer::instance()->run_import();

		delete_transient( 'toptex_sync_running' );
	}
}
