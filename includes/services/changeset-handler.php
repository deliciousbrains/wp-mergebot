<?php

/**
 * The class that handles the state of a changeset
 *
 * @since 0.1
 */

namespace DeliciousBrains\Mergebot\Services;

use DeliciousBrains\Mergebot\Models\Notice;
use DeliciousBrains\Mergebot\Services\Development\Recorder_Handler;
use DeliciousBrains\Mergebot\Models\App_Changeset;
use DeliciousBrains\Mergebot\Models\Changeset;
use DeliciousBrains\Mergebot\Models\Plugin;
use DeliciousBrains\Mergebot\Utils\Async_Request;
use DeliciousBrains\Mergebot\Utils\Config;
use DeliciousBrains\Mergebot\Utils\Error;

class Changeset_Handler extends Abstract_Process  {

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
	 * @var Async_Request
	 */
	public $set_status_request;

	/**
	 * Changeset_Handler constructor.
	 *
	 * @param Plugin                 $bot
	 * @param App_Interface          $app
	 * @param Changeset_Synchronizer $changeset_synchronizer
	 */
	public function __construct( Plugin $bot, App_Interface $app, Changeset_Synchronizer $changeset_synchronizer ) {
		$this->bot                    = $bot;
		$this->app                    = $app;
		$this->changeset_synchronizer = $changeset_synchronizer;
	}

	/**
	 * Initialize hooks
	 */
	public function init() {
		$this->set_status_request = new Async_Request( $this->bot, $this, 'set_status' );

		add_action( $this->bot->slug() . '_load_plugin', array( $this, 'handle_reject_changeset' ) );
	}

	/**
	 * Process the rejection of a changeset on the app
	 */
	public function handle_reject_changeset() {
		$changeset_id = $this->bot->filter_input( 'reject' );

		if ( ! isset( $changeset_id ) ) {
			return false;
		}

		$nonce = $this->bot->filter_input( 'nonce' );
		if ( ! isset( $nonce ) || ! wp_verify_nonce( $nonce, 'reject' ) ) {
			return false;
		}

		if ( $this->reject( $changeset_id ) ) {
			$notice_args = array(
				'type'                  => 'updated',
				'message'               => sprintf( __( 'Changeset #%s has been rejected' ), $changeset_id ),
				'title'                 => __( 'Changeset Rejected' ),
				'only_show_in_settings' => true,
			);

			Notice::create( $notice_args )->save();
		}

		$redirect = $this->bot->get_url();

		return $this->bot->redirect( $redirect );
	}

	/**
	 * Set the status of a changeset on the app
	 *
	 * @param int |null   $changeset_id
	 * @param null|string $status
	 * @param null|string $failed_reason
	 *
	 * @return array|bool
	 */
	public function set_status( $changeset_id = null, $status = null, $failed_reason = null ) {
		if ( is_null( $changeset_id ) ) {
			$changeset_id = $this->bot->filter_input( 'changeset_id', INPUT_POST, FILTER_VALIDATE_INT );
		}

		if ( ! isset( $changeset_id ) ) {
			return false;
		}

		if ( is_null( $status ) ) {
			$status = $this->bot->filter_input( 'status', INPUT_POST );

			if ( ! isset( $status ) ) {
				$status = App_Changeset::get_deployed_status( $this->bot->mode );
			}
		}

		if ( is_null( $failed_reason ) ) {
			$failed_reason = $this->bot->filter_input( 'failed_reason', INPUT_POST );

			if ( ! isset( $failed_reason ) ) {
				$failed_reason = '';
			}
		}

		$result = $this->app->post_changeset_status( $changeset_id, $status, $failed_reason );

		if ( is_wp_error( $result ) ) {
			new Error( Error::$Changeset_statusUpdateFailed, sprintf( __( 'Could not set changeset #%s status as %s on the app' ), $changeset_id, $status ) );
			$result = false;
		}

		return $result;
	}

	/**
	 * Reject changeset on the app
	 *
	 * @param int $changeset_id
	 *
	 * @return array|bool
	 */
	public function reject( $changeset_id ) {
		$result = $this->set_status( $changeset_id, 'rejected' );

		if ( false === $result ) {
			return $result;
		}

		$changeset = Changeset::find_by( 'changeset_id', $changeset_id );
		if ( $changeset ) {
			$this->changeset_synchronizer->remove( $changeset );
		}

		return $result;
	}

	/**
	 * Listen for changesets that have been deployed on production and synced back to development
	 * and close them on the app and clean up on the site.
	 */
	public function close() {
		$process_key = 'close-changesets';

		if ( $this->is_processing( $process_key ) ) {
			// Already processing the closing of deployment
			return false;
		}

		// Lock the process
		$this->set_processing_lock( $process_key );

		$key = $this->get_deployed_changeset_option_key( null, Config::MODE_PROD );

		$table_name    = 'options';
		$column_where  = 'option_name';
		$column_select = 'option_value';
		if ( is_multisite() ) {
			$table_name    = 'sitemeta';
			$column_where  = 'meta_key';
			$column_select = 'meta_value';
		}

		// Get changeset IDs that gave been deployed on production
		global $wpdb;
		$changesets = $wpdb->get_col( $wpdb->prepare( "
				SELECT {$column_select} FROM {$wpdb->$table_name}
				WHERE {$column_where} LIKE %s
			", $key . '_%' ) );

		foreach ( $changesets as $changeset_id ) {
			// Close changeset on app
			if ( $this->set_status( $changeset_id, 'closed' ) ) {
				// Clean up deployment on dev plugin
				delete_site_option( $key . '_' . $changeset_id );
			}
		}

		// Unlock the process
		$this->set_processing_lock( $process_key, false );

		return true;
	}

	/**
	 * Can a changeset be deployed
	 *
	 * @param int $changeset_id
	 *
	 * @return bool|\WP_Error
	 */
	public function can_deploy( $changeset_id ) {
		$changeset = Changeset::find_by( 'changeset_id', $changeset_id );

		$deploy_errors = $changeset->deploy_errors();
		if ( false === $deploy_errors ) {
			return true;
		}

		$error_title   = __( 'Development Site Issues' );
		$error_message = __( 'We\'ve disabled Apply Changeset because there are issues with the Development site.' );
		$error_message .= ' ' . implode( ', ', $deploy_errors ) . '.';

		return new \WP_Error( $error_title, $error_message );
	}

	/**
	 * Get the deployment key added to the database after a changeset is deployed
	 *
	 * @param int|null    $id
	 * @param null|string $mode
	 *
	 * @return string
	 */
	public function get_deployed_changeset_option_key( $id = null, $mode = null ) {
		if ( is_null( $mode ) ) {
			$mode = Config::mode();
		}

		$key = 'mergebot_deployment_' . $mode;

		if ( ! is_null( $id ) ) {
			$key .= '_' . $id;
		}

		return $key;
	}

}