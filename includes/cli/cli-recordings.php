<?php

/**
 * The class for the WP CLI mergebot recording commands.
 *
 * @since 0.1
 */

namespace DeliciousBrains\Mergebot\CLI;

use DeliciousBrains\Mergebot\Models\Plugin;
use DeliciousBrains\Mergebot\Services\Admin\Settings_Handler;
use DeliciousBrains\Mergebot\Services\Changeset_Handler;
use DeliciousBrains\Mergebot\Services\Changeset_Synchronizer;
use DeliciousBrains\Mergebot\Services\Development\Recorder_Handler;
use DeliciousBrains\Mergebot\Services\Site_Register;

class CLI_Recordings extends CLI_Changeset {

	/**
	 * @var Recorder_Handler
	 */
	protected $recorder_handler;

	/**
	 * CLI_Recordings constructor.
	 *
	 * @param Plugin                 $bot
	 * @param Settings_Handler       $settings_handler
	 * @param Changeset_Synchronizer $changeset_synchronizer
	 * @param Changeset_Handler      $changeset_handler
	 * @param Site_Register          $site_register
	 * @param Recorder_Handler       $recorder_handler
	 */
	public function __construct( Plugin $bot, Settings_Handler $settings_handler, Changeset_Synchronizer $changeset_synchronizer, Changeset_Handler $changeset_handler, Site_Register $site_register, Recorder_Handler $recorder_handler ) {
		$this->recorder_handler = $recorder_handler;

		parent::__construct( $bot, $settings_handler, $changeset_synchronizer, $changeset_handler, $site_register );
	}


	/**
	 * Can user perform query recording
	 *
	 * @return bool
	 */
	protected function can_record() {
		if ( ! $this->is_setup() ) {
			return false;
		}

		if ( ! $this->bot->is_dev_mode() ) {
			return $this->error( __( 'Invalid mode for recording queries' ) );
		}

		return true;
	}

	/**
	 * Starts recording in development mode
	 *
	 * ## EXAMPLES
	 *
	 *     wp mergebot start
	 *     wp mergebot start --user=1
	 *
	 */
	public function start() {
		if ( ! $this->can_record() ) {
			return false;
		}

		if ( $this->recorder_handler->is_recording() ) {
			\WP_CLI::warning( __( 'Already recording' ) );

			return false;
		}

		if ( $this->recorder_handler->start_recording() ) {
			\WP_CLI::success( __( 'Recording' ) );

			return true;
		}

		\WP_CLI::warning( __( 'Error turning on recording' ) );

		return false;
	}

	/**
	 * Stops recording in development mode
	 *
	 * ## EXAMPLES
	 *
	 *     wp mergebot stop
	 *     wp mergebot stop --user=1
	 *
	 */
	public function stop() {
		if ( ! $this->can_record() ) {
			return false;
		}

		$this->recorder_handler->stop_recording();

		\WP_CLI::success( __( 'Recording off' ) );

		return true;
	}

	/**
	 * Gets recording status in development mode
	 *
	 * ## EXAMPLES
	 *
	 *     wp mergebot status
	 *
	 */
	public function status() {
		$is_recording = $this->recorder_handler->is_recording();

		if ( $is_recording ) {
			$message = __( 'Recording' );
		} else {
			$message = __( 'Not recording' );
		}

		\WP_CLI::log( $message );
	}
}
