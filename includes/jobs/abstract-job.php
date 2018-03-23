<?php

/**
 * The class sends queries to the app that have been recorded
 *
 * @since 0.1
 */

namespace DeliciousBrains\Mergebot\Jobs;

use DeliciousBrains\Mergebot\Models\Notice;
use DeliciousBrains\Mergebot\Services\Admin\Settings_Handler;
use DeliciousBrains\Mergebot\Models\Plugin;
use DeliciousBrains\Mergebot\Utils\Async_Request;
use DeliciousBrains\Mergebot\Utils\Cron;
use DeliciousBrains\Mergebot\Utils\Error;
use DeliciousBrains\Mergebot\Utils\Support;

abstract class Abstract_Job extends Async_Request {

	/**
	 * @var string
	 */
	protected $action = false;

	/**
	 * Start time of current process
	 *
	 * @var int
	 */
	protected $start_time = 0;

	/**
	 * Number of times the job will retry before stopping if it encounters an error
	 *
	 * @var int
	 */
	protected $error_retry_limit;

	/**
	 * @var Settings_Handler
	 */
	protected $settings_handler;

	/**
	 * Initiate new background process
	 *
	 * @param Plugin           $bot Instance of calling class
	 *
	 * @param Settings_Handler $settings_handler
	 *
	 * @throws \Exception
	 */
	public function __construct( Plugin $bot, Settings_Handler $settings_handler ) {
		if ( empty( $this->action ) ) {
			throw new \Exception( 'Job ' . get_called_class() . ' requires an action property' );
		}

		parent::__construct( $bot, null, $this->action );

		$this->settings_handler = $settings_handler;

		add_action( $this->identifier . '_cron', array( $this, 'handle_cron_healthcheck' ) );
		add_action( $this->identifier . '_cron_force', array( $this, 'handle_cron_healthcheck_force' ) );
		add_filter( 'cron_schedules', array( $this, 'schedule_cron_healthcheck' ) );

		$this->error_retry_limit = apply_filters( $this->identifier . '_error_retry_limit', 3 );

		add_action( 'admin_init', array( $this, 'handle_retry_job' ) );

		// Schedule the cron healthcheck
		Cron::schedule_event( $this->identifier . '_cron', $this->identifier . '_cron_interval' );
		// Schedule the cron force healthcheck
		Cron::schedule_event( $this->identifier . '_cron_force', 'hourly' );
	}

	/**
	 * Get the identifier string
	 *
	 * @return string
	 */
	public function identifier() {
		return $this->identifier;
	}

	/**
	 * Should we process the job?
	 *
	 * @return bool
	 */
	protected function should_process_job() {
		if ( $this->is_process_running() ) {
			// Background process already running
			return false;
		}

		if ( $this->is_queue_empty() ) {
			// No data to process
			return false;
		}

		if ( (int) get_site_transient( $this->identifier . '_error_count' ) >= $this->error_retry_limit ) {
			// Already retried to our limit with errors
			return false;
		}

		return true;
	}

	/**
	 * Dispatch
	 */
	public function dispatch() {
		if ( false === $this->should_process_job() ) {
			return;
		}

		// Perform remote post
		parent::dispatch();
	}

	/**
	 * Maybe process queue
	 *
	 * Checks whether data exists within the queue and that
	 * the process is not already running.
	 */
	public function maybe_handle() {
		if ( false === $this->should_process_job() ) {
			return $this->bot->wp_die();
		}

		if ( false === apply_filters( $this->identifier, true ) ) {
			return $this->bot->wp_die();
		}

		return parent::maybe_handle();
	}

	/**
	 * Is process running
	 *
	 * Check whether the current process is already running
	 * in a background process.
	 */
	protected function is_process_running() {
		if ( get_site_transient( $this->identifier . '_process_lock' ) ) {
			// Process already running
			return true;
		}

		return false;
	}

	/**
	 * Lock process
	 *
	 * Lock the process so that multiple instances can't run simultaneously.
	 */
	protected function lock_process() {
		$this->start_time = time(); // Set start time of current process

		$lock_duration = apply_filters( $this->identifier . '_queue_lock_time', 60 );

		set_site_transient( $this->identifier . '_process_lock', microtime(), $lock_duration );
	}

	/**
	 * Unlock process
	 *
	 * Unlock the process so that other instances can spawn.
	 *
	 * @return $this
	 */
	protected function unlock_process() {
		delete_site_transient( $this->identifier . '_process_lock' );

		return $this;
	}

	/**
	 * Is queue empty
	 *
	 * @return bool
	 */
	protected abstract function is_queue_empty();

	/**
	 * Get batch
	 *
	 * @return \stdClass Return the first batch from the queue
	 */
	protected abstract function get_batch();

	/**
	 * Handle
	 *
	 * Pass each queue item to the task handler, while remaining
	 * within server memory and time limit constraints.
	 */
	protected function handle() {
		$this->lock_process();

		do {
			$batch = $this->get_batch();

			if ( empty( $batch ) ) {
				// Batch empty
				break;
			}

			if ( false === $this->task( $batch ) ) {
				// Batch has had an error
				break;
			}

			if ( $this->time_exceeded() || $this->memory_exceeded() ) {
				// Batch limits reached
				break;
			}
		} while ( ! $this->time_exceeded() && ! $this->memory_exceeded() && ! $this->is_queue_empty() );

		$this->unlock_process();

		// Start next batch or complete process
		if ( ! $this->is_queue_empty() ) {
			$this->dispatch();
		} else {
			$this->complete();
		}

		$this->bot->wp_die();
	}

	/**
	 * Task
	 *
	 * @param array $batch
	 *
	 * @return mixed
	 */
	protected abstract function task( $batch );

	/**
	 * Time exceeded
	 *
	 * Ensures the batch never exceeds a sensible time limit.
	 * A timeout limit of 30s is common on shared hosting.
	 *
	 * @param bool|int $finish
	 *
	 * @return bool
	 */
	public function time_exceeded( $finish = false ) {
		$finish = $this->start_time + apply_filters( $this->bot->slug() . '_default_time_limit', 20 ); // 20 seconds
		$return = false;

		if ( parent::time_exceeded( $finish ) ) {
			$return = true;
		}

		return apply_filters( $this->identifier . '_time_exceeded', $return );
	}

	/**
	 * Complete
	 */
	protected function complete() {
		// Unschedule the cron healthcheck
		Cron::clear_scheduled_event( $this->identifier . '_cron' );
	}

	/**
	 * Schedule cron healthcheck
	 *
	 * @param $schedules
	 *
	 * @return mixed
	 */
	public function schedule_cron_healthcheck( $schedules ) {
		$interval = apply_filters( $this->identifier . '_cron_interval', 2 );

		// Adds every 5 minutes to the existing schedules.
		$schedules[ $this->identifier . '_cron_interval' ] = array(
			'interval' => MINUTE_IN_SECONDS * $interval,
			'display'  => sprintf( __( 'Every %d Minutes' ), $interval ),
		);

		return $schedules;
	}

	/**
	 * Handle cron healthcheck
	 *
	 * Restart the background process if not already running
	 * and data exists in the queue.
	 */
	public function handle_cron_healthcheck() {
		if ( $this->is_process_running() ) {
			// Background process already running
			return $this->bot->_exit();
		}

		if ( $this->is_queue_empty() ) {
			// No data to process
			return $this->bot->_exit();
		}

		$this->dispatch();
	}

	/**
	 * Handle cron healthcheck ignoring the error count
	 *
	 * This is helpful for any load issues on the mergebot.com server,
	 * so queries will get retried automatically instead of waiting for 'Try again' click.
	 */
	public function handle_cron_healthcheck_force() {
		$this->clear_error_lock();

		$this->handle_cron_healthcheck();
	}

	/**
	 * Get the retry link for the job
	 *
	 * @return string
	 */
	protected function get_retry_link() {
		$args = array(
			'retry' => $this->identifier,
		);

		return $this->bot->get_url( $args );
	}

	/**
	 * Listen for requests to retry the job
	 */
	public function handle_retry_job() {
		$retry = $this->bot->filter_input( 'retry' );
		if ( ! isset( $retry ) || $this->identifier !== $retry ) {
			return false;
		}

		$this->clear_error_lock();
		$this->dispatch();

		return $this->bot->redirect();
	}

	/**
	 * Clears the lock for error retries
	 */
	public function clear_error_lock() {
		delete_site_transient( $this->identifier . '_error_count' );
		Notice::delete_by_id( $this->identifier . '-error-' );
	}

	/**
	 * Log an error and handle show notice when an error occurs sending a query
	 *
	 * @param string $error_code
	 * @param string $error_title
	 * @param string $error_msg
	 * @param string $error_msg_extra
	 * @param string $error_data
	 */
	protected function throw_sending_error( $error_code, $error_title, $error_msg, $error_msg_extra = '', $error_data = '' ) {
		$error_count = ( int ) get_site_transient( $this->identifier . '_error_count' );
		$error_count++;
		set_site_transient( $this->identifier . '_error_count', $error_count, HOUR_IN_SECONDS );

		// Could not send the queries
		$error = new Error( $error_code, $error_msg . $error_msg_extra, $error_data );

		$flash           = true;
		$error_msg_extra = '';
		if ( $error_count === $this->error_retry_limit ) {
			$error_msg       = rtrim( $error_msg, '.' );
			$error_msg_extra = sprintf( '. <a href="%s">%s</a>.', $this->get_retry_link(), __( 'Try Again' ) );
			$flash           = false;
		}

		$notice_args = array(
			'id'                => $this->identifier . '-error-' . $error_code,
			'type'              => 'error',
			'message'           => $error_msg . $error_msg_extra . ' ' . Support::generate_link( $this->settings_handler->get_site_id(), $error_title, $error_msg, $error ),
			'title'             => $error_title,
			'dismissible'       => false,
			'only_show_to_user' => false,
			'flash'             => $flash,
		);

		Notice::create( $notice_args )->save();
	}
}