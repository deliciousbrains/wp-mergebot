<?php

/**
 * The class for the WP CLI mergebot command.
 *
 * This is used to control aspects of the plugin with the CLI.
 *
 * @since 0.1
 */

namespace DeliciousBrains\Mergebot\CLI;


use DeliciousBrains\Mergebot\Models\Plugin;
use DeliciousBrains\Mergebot\Services\Admin\Settings_Handler;
use DeliciousBrains\Mergebot\Services\Changeset_Handler;
use DeliciousBrains\Mergebot\Services\Changeset_Synchronizer;
use DeliciousBrains\Mergebot\Services\Site_Register;

abstract class Abstract_CLI_Command extends \WP_CLI_Command {

	/**
	 * @var Plugin
	 */
	protected $bot;

	/**
	 * @var Settings_Handler
	 */
	protected $settings_handler;

	/**
	 * @var Changeset_Synchronizer
	 */
	protected $changeset_synchronizer;

	/**
	 * @var Changeset_Handler
	 */
	protected $changeset_handler;

	/**
	 * @var Site_Register
	 */
	protected $site_register;

	/**
	 * Abstract_Command constructor.
	 *
	 * @param Plugin                 $bot
	 * @param Settings_Handler       $settings_handler
	 * @param Changeset_Synchronizer $changeset_synchronizer
	 * @param Changeset_Handler      $changeset_handler
	 * @param Site_Register          $site_register
	 */
	public function __construct( Plugin $bot, Settings_Handler $settings_handler, Changeset_Synchronizer $changeset_synchronizer, Changeset_Handler $changeset_handler, Site_Register $site_register ) {
		$this->bot                    = $bot;
		$this->settings_handler       = $settings_handler;
		$this->changeset_synchronizer = $changeset_synchronizer;
		$this->changeset_handler      = $changeset_handler;
		$this->site_register          = $site_register;
	}

	/**
	 * Check the plugin is setup
	 *
	 * @return bool
	 */
	protected function is_setup() {
		$result = $this->bot->is_setup();
		if ( is_wp_error( $result ) ) {
			return $this->error( $result->get_error_message() );
		}

		return true;
	}

	/**
	 * Wrapper for logging an error and returning false
	 *
	 * @param string $message
	 *
	 * @return bool
	 */
	protected function error( $message ) {
		\WP_CLI::error( $message, false );

		return false;
	}
}