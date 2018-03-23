<?php

/**
 * The class for the WP CLI mergebot recording commands.
 *
 * @since 0.1
 */

namespace DeliciousBrains\Mergebot\CLI;

use DeliciousBrains\Mergebot\Jobs\Send_Queries;
use DeliciousBrains\Mergebot\Models\Plugin;
use DeliciousBrains\Mergebot\Services\Admin\Settings_Handler;
use DeliciousBrains\Mergebot\Services\Changeset_Handler;
use DeliciousBrains\Mergebot\Services\Changeset_Synchronizer;
use DeliciousBrains\Mergebot\Services\Development\Recorder_Handler;
use DeliciousBrains\Mergebot\Services\Site_Register;

class CLI_Queries extends CLI_Recordings {

	/**
	 * @var Send_Queries
	 */
	protected $send_queries;

	/**
	 * CLI_Queries constructor.
	 *
	 * @param Plugin                 $bot
	 * @param Settings_Handler       $settings_handler
	 * @param Changeset_Synchronizer $changeset_synchronizer
	 * @param Changeset_Handler      $changeset_handler
	 * @param Site_Register          $site_register
	 * @param Recorder_Handler       $recorder_handler
	 * @param Send_Queries           $send_queries
	 */
	public function __construct( Plugin $bot, Settings_Handler $settings_handler, Changeset_Synchronizer $changeset_synchronizer, Changeset_Handler $changeset_handler, Site_Register $site_register, Recorder_Handler $recorder_handler, Send_Queries $send_queries ) {
		$this->send_queries = $send_queries;

		parent::__construct( $bot, $settings_handler, $changeset_synchronizer, $changeset_handler, $site_register, $recorder_handler );
	}

	/**
	 * Sends recorded queries to the app
	 *
	 * ## EXAMPLES
	 *
	 *     wp mergebot send
	 *     wp mergebot send --force
	 *
	 */
	public function send( $args, $assoc_args ) {
		if ( isset( $assoc_args['force'] ) && $assoc_args['force'] ) {
			$this->send_queries->clear_error_lock();
		}

		// Dispatch the send queries job
		$this->send_queries->dispatch();
	}
}
