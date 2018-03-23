<?php

/**
 * The class for cron jobs
 *
 * This is used to create cron jobs for processes used by the plugin.
 *
 * @since 0.1
 */

namespace DeliciousBrains\Mergebot\Utils;

class Cron extends Abstract_Request {

	/**
	 * @var string
	 */
	protected $cron_hook;

	/**
	 * @var
	 */
	protected $cron_interval_in_minutes;

	/**
	 * Initialize the request
	 */
	protected function init() {
		$this->cron_hook = $this->get_identifier();

		$this->cron_interval_in_minutes = apply_filters( $this->cron_hook . '_interval', 5 );
	}

	/**
	 * Fire the cron up
	 */
	public function fire() {
		add_filter( 'cron_schedules', array( $this, 'add_cron_interval' ) );
		add_action( $this->cron_hook, array( $this, 'process_cron' ) );

		self::schedule_event( $this->cron_hook );
	}

	/**
	 * Add a custom cron interval to the schedules
	 *
	 * @param array $schedules
	 *
	 * @return array
	 */
	public function add_cron_interval( $schedules ) {
		$schedules[ $this->cron_hook ] = array(
			'interval' => $this->cron_interval_in_minutes * 60,
			'display'  => sprintf( __( 'Every %d Minutes' ), $this->cron_interval_in_minutes ),
		);

		return $schedules;
	}

	/**
	 * Run the code on the cron schedule
	 */
	public function process_cron() {
		$method = $this->method;

		$this->instance->$method();
	}

	/**
	 * Wrapper for scheduling cron jobs
	 *
	 * @param string      $hook
	 * @param null|string $interval Defaults to hook if not supplied
	 * @param array       $args
	 */
	public static function schedule_event( $hook, $interval = null, $args = array() ) {
		if ( is_null( $interval ) ) {
			$interval = $hook;
		}

		if ( ! wp_next_scheduled( $hook ) ) {
			wp_schedule_event( time(), $interval, $hook, $args );
		}
	}

	/**
	 * Wrapper for clearing scheduled events for a specific cron job
	 *
	 * @param string $hook
	 */
	public static function clear_scheduled_event( $hook ) {
		$timestamp = wp_next_scheduled( $hook );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, $hook );
		}
	}

}