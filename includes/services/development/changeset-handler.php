<?php

/**
 * The class that handles the state of a changeset
 *
 * @since 0.1
 */

namespace DeliciousBrains\Mergebot\Services\Development;

use DeliciousBrains\Mergebot\Models\Plugin;
use DeliciousBrains\Mergebot\Models\Query;
use DeliciousBrains\Mergebot\Services\App_Interface;
use DeliciousBrains\Mergebot\Services\Changeset_Synchronizer;
use DeliciousBrains\Mergebot\Utils\Async_Request;
use DeliciousBrains\Mergebot\Services\Changeset_Handler as Base_Changeset_Handler;


class Changeset_Handler extends Base_Changeset_Handler  {

	/**
	 * @var Plugin;
	 */
	protected $bot;

	/**
	 * @var App_Interface
	 */
	protected $app;

	/**
	 * @var Changeset_Synchronizer
	 */
	protected $changeset_synchronizer;

	/**
	 * @var Recorder_Handler
	 */
	protected  $recorder_handler;

	/**
	 * @var Async_Request
	 */
	public $set_status_request;

	/**
	 * Changeset_Handler constructor.
	 *
	 * @param Plugin                 $bot
	 * @param App_Interface          $app
	 * @param Changeset_Synchronizer $changeset_synchronizer
	 * @param Recorder_Handler       $recorder_handler
	 */
	public function __construct( Plugin $bot, App_Interface $app, Changeset_Synchronizer $changeset_synchronizer, Recorder_Handler $recorder_handler ) {
		parent::__construct( $bot, $app, $changeset_synchronizer );
		$this->recorder_handler = $recorder_handler;
	}

	/**
	 * Can a changeset be deployed
	 *
	 * @param int $changeset_id
	 *
	 * @return bool|\WP_Error
	 */
	public function can_deploy( $changeset_id ) {
		if ( Query::is_blocked_query() ) {
			// Unprocessed
			$blocked_query_message = __( "We've disabled Apply Changeset because there was an error sending a query to the app." );

			return new \WP_Error( __( 'Query Processing Failure' ), $blocked_query_message, array( 'db_sync' => false ) );
		}

		$db_sync_title = __( 'Database Refresh Needed' );

		if ( false !== get_site_option( $this->get_deployed_changeset_option_key( $changeset_id ), false ) ) {
			// Already deployed and db hasn't been synced
			$db_sync_message = __( "We've disabled Apply Changeset because you've already applied it. This site is up-to-date with the current changeset" );

			return new \WP_Error( $db_sync_title, $db_sync_message );
		}

		if ( $this->recorder_handler->is_recording() ) {
			// Recordings have happened, no point deploying on top of same changes
			return new \WP_Error( __( 'Recording Queries On' ), __( 'The changeset cannot be applied whilst still recording changes.' ) );
		}

		if ( false !== get_site_option( $this->bot->slug() . '_changes_recorded', false ) ) {
			// Recordings have happened, no point deploying on top of same changes
			$db_sync_message = __( "We've disabled Apply Changeset because this site has recorded some new changes. If you refresh this site with the latest live database you'll be able to apply the changeset." );

			return new \WP_Error( $db_sync_title, $db_sync_message );
		}

		return true;
	}
}