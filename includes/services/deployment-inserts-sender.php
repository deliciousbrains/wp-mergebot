<?php

/**
 * The app class for deploying a changeset.
 *
 * @since 0.1
 */

namespace DeliciousBrains\Mergebot\Services;

use DeliciousBrains\Mergebot\Models\Deployment_Insert;
use DeliciousBrains\Mergebot\Models\Notice;
use DeliciousBrains\Mergebot\Models\Plugin;
use DeliciousBrains\Mergebot\Services\Admin\Settings_Handler;
use DeliciousBrains\Mergebot\Utils\Error;
use DeliciousBrains\Mergebot\Utils\Support;

class Deployment_Inserts_Sender {

	/**
	 * @var Plugin
	 */
	protected $bot;

	/**
	 * @var App_Interface
	 */
	protected $app;

	/**
	 * @var Settings_Handler
	 */
	protected $settings_handler;

	/**
	 * Deployment_Inserts_Sender constructor.
	 *
	 * @param Plugin           $bot
	 * @param App_Interface    $app
	 * @param Settings_Handler $settings_handler
	 */
	public function __construct( Plugin $bot, App_Interface $app, Settings_Handler $settings_handler ) {
		$this->bot              = $bot;
		$this->app              = $app;
		$this->settings_handler = $settings_handler;
	}

	/**
	 * Send the INSERT ids created by a deployment to the app
	 *
	 * @param int|null $changeset_id
	 *
	 * @return bool|Error
	 */
	public function send( $changeset_id = null ) {
		if ( is_null( $changeset_id ) ) {
			$changeset_id = $this->bot->filter_input( 'changeset_id', INPUT_POST, FILTER_VALIDATE_INT );
		}

		if ( ! isset( $changeset_id ) ) {
			return false;
		}

		$deployment_ids = Deployment_Insert::all_for_changeset( $changeset_id );

		$deployment_ids = $this->clean_deployment_ids( $deployment_ids );

		if ( empty( $deployment_ids ) ) {
			// No INSERTs, bail
			return false;
		}

		$result = $this->app->post_deployment_ids( $deployment_ids );

		if ( is_wp_error( $result ) ) {
			return $this->sending_error( $changeset_id, $result );
		}

		Deployment_Insert::delete_by( 'changeset_id', $changeset_id );

		return true;
	}

	/**
	 * Ensure there are no IDs we don't want to send to the app.
	 *
	 * @param array $all_deployment_ids
	 *
	 * @return array
	 */
	protected function clean_deployment_ids( $all_deployment_ids ) {
		$deployment_ids = array();
		foreach ( $all_deployment_ids as $deployment_id ) {
			if ( 0 === (int) $deployment_id->deployed_insert_id && 1 === (int) $deployment_id->is_on_duplicate_key ) {
				// ON DUPLICATE KEY INSERT statement that was applied as an UPDATE.
				// Therefore we don't have an insert id and we don't want to send to the app.
				Deployment_Insert::delete_by( 'id', $deployment_id->id );

				continue;
			}

			$deployment_ids[] = $deployment_id;
		}

		return $deployment_ids;
	}

	/**
	 * Add notice and log error for issues sending to app.
	 *
	 * @param int   $changeset_id
	 * @param Error $result
	 *
	 * @return Error
	 */
	protected function sending_error( $changeset_id, $result ) {
		$title       = __( 'Deployment Inserts Error' );
		$error_msg   = sprintf( __( 'Could not send INSERT IDs after deployment for changeset %s to the app.' ), $changeset_id );

		$notice_args = array(
			'id'                    => 'deployment-inserts-send',
			'type'                  => 'error',
			'title'                 => $title,
			'message'               => $error_msg . ' ' . Support::generate_link( $this->settings_handler->get_site_id(), $title, $error_msg, $result ),
			'flash'                 => false,
			'only_show_in_settings' => true,
		);

		Notice::create( $notice_args )->save();

		return new Error( Error::$Deploy_sendInsertIDsFailed, $error_msg, array( 'changeset_id' => $changeset_id ) );
	}
}