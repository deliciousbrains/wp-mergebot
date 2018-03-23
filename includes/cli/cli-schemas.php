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
use DeliciousBrains\Mergebot\Services\Development\Schema_Handler;
use DeliciousBrains\Mergebot\Services\Site_Register;

class CLI_Schemas extends CLI_Queries {

	/**
	 * @var Schema_Handler
	 */
	protected $schema_handler;

	/**
	 * CLI_Recordings constructor.
	 *
	 * @param Plugin                 $bot
	 * @param Settings_Handler       $settings_handler
	 * @param Changeset_Synchronizer $changeset_synchronizer
	 * @param Changeset_Handler      $changeset_handler
	 * @param Site_Register          $site_register
	 * @param Recorder_Handler       $recorder_handler
	 * @param Send_Queries           $send_queries
	 * @param Schema_Handler         $schema_handler
	 */
	public function __construct( Plugin $bot, Settings_Handler $settings_handler, Changeset_Synchronizer $changeset_synchronizer, Changeset_Handler $changeset_handler, Site_Register $site_register, Recorder_Handler $recorder_handler, Send_Queries $send_queries, Schema_Handler $schema_handler) {
		$this->schema_handler = $schema_handler;

		parent::__construct( $bot, $settings_handler, $changeset_synchronizer, $changeset_handler, $site_register, $recorder_handler, $send_queries );
	}


	/**
	 * Prime the schema cache
	 *
	 * ## OPTIONS
	 *
	 * ## EXAMPLES
	 *     wp mergebot prime-schemas
	 *
	 * @subcommand prime-schemas
	 * @param array $args
	 * @param array $assoc_args
	 *
	 * @return bool
	 */
	public function prime_schemas( $args, $assoc_args ) {
		if ( ! $this->is_setup() ) {
			return false;
		}

		$this->schema_handler->prime_schema_cache();
		\WP_CLI::success( __( 'Schema cache primed!' ) );
	}
}
