<?php

/**
 * The class that initiates the deployment of the changeset
 *
 * @since 0.1
 */

namespace DeliciousBrains\Mergebot\Services;

use DeliciousBrains\Mergebot\Models\Notice;
use DeliciousBrains\Mergebot\Services\Admin\Settings_Handler;
use DeliciousBrains\Mergebot\Models\App_Changeset;
use DeliciousBrains\Mergebot\Models\Changeset;
use DeliciousBrains\Mergebot\Models\Deployment;
use DeliciousBrains\Mergebot\Models\Plugin;
use DeliciousBrains\Mergebot\Utils\Config;
use DeliciousBrains\Mergebot\Utils\Error;
use DeliciousBrains\Mergebot\Utils\Support;

class Changeset_Deployer {

	/**
	 * @var Plugin;
	 */
	protected $bot;

	/**
	 * @var Settings_Handler
	 */
	protected $settings_handler;

	/**
	 * @var Changeset_Handler
	 */
	protected $changeset_handler;

	/**
	 * @var Changeset_Synchronizer
	 */
	protected $changeset_synchronizer;

	/**
	 * @var Conflict_Sender
	 */
	protected $conflict_sender;

	/**
	 * @var Deployment_Agent
	 */
	protected $deployment_agent;

	/**
	 * Changeset_Deployer constructor.
	 *
	 * @param Plugin                 $bot
	 * @param Settings_Handler       $settings_handler
	 * @param Changeset_Handler      $changeset_handler
	 * @param Changeset_Synchronizer $changeset_synchronizer
	 * @param Conflict_Sender        $conflict_sender
	 * @param Deployment_Agent       $deployment_agent
	 */
	public function __construct( Plugin $bot, Settings_Handler $settings_handler, Changeset_Handler $changeset_handler, Changeset_Synchronizer $changeset_synchronizer, Conflict_Sender $conflict_sender, Deployment_Agent $deployment_agent ) {
		$this->bot                    = $bot;
		$this->settings_handler       = $settings_handler;
		$this->changeset_handler      = $changeset_handler;
		$this->changeset_synchronizer = $changeset_synchronizer;
		$this->conflict_sender        = $conflict_sender;
		$this->deployment_agent       = $deployment_agent;
	}

	/**
	 * Initialize hooks
	 */
	public function init() {
		add_action( $this->bot->slug() . '_load_plugin', array( $this, 'handle_initiate_changeset_deployment' ) );
		add_action( $this->bot->slug() . '_load_plugin', array( $this, 'handle_changeset_deployment' ) );
	}

	/**
	 * Listen for the initiation of the deployment of a changeset
	 */
	public function handle_initiate_changeset_deployment() {
		$changeset_id = $this->bot->filter_input( 'generate' );

		if ( ! isset( $changeset_id ) ) {
			return false;
		}

		$nonce = $this->bot->filter_input( 'nonce' );
		if ( ! isset( $nonce ) || ! wp_verify_nonce( $nonce, 'generate' ) ) {
			return false;
		}

		$redirect = $this->bot->get_url();

		$can_deploy = $this->changeset_handler->can_deploy( $changeset_id );
		if ( is_wp_error( $can_deploy ) ) {
			return $this->bot->redirect( $redirect );
		}

		// Recursive sending of conflicts to the app
		$conflicts      = $this->bot->filter_input( 'conflicts', INPUT_GET, FILTER_VALIDATE_INT );
		$conflicts_only = ( isset( $conflicts ) && 1 === (int) $conflicts );

		$changeset = Changeset::find_by( 'changeset_id', $changeset_id );
		if ( false === $changeset ) {
			new Error( Error::$Changeset_missingOnPlugin, sprintf( __( 'Changeset %s not found in plugin.' ), $changeset_id ) );

			return $this->bot->redirect( $redirect );
		}

		$url = $this->initiate_deployment( $changeset, $conflicts_only );

		if ( is_wp_error( $url ) ) {
			$title       = __( 'Deployment Generation Error' );
			$error_msg   = $url->get_error_message();
			$notice_args = array(
				'type'                  => 'error',
				'message'               => rtrim( $error_msg, '.' ) . '. ' . Support::generate_link( $this->settings_handler->get_site_id(), $title, $error_msg ),
				'title'                 => $title,
				'only_show_in_settings' => true,
			);

			Notice::create( $notice_args )->save();

			return $this->bot->redirect( $redirect );
		}

		$args = array(
			'nonce'    => wp_create_nonce( 'deploy-' . $changeset_id ),
			'site_id'  => $this->settings_handler->get_site_id(),
			'redirect' => urlencode( $redirect ),
		);

		$url = add_query_arg( $args, $url );

		// Redirect to the App's deployment URL to kick off the deployment
		return $this->bot->redirect( $url );
	}


	/**
	 * Initiate the deployment
	 *
	 * Send all the data related to possible conflicts in batches, and then return the App's URL for deploying the changeset
	 *
	 * @param Changeset $changeset
	 * @param bool      $send_conflicts_only
	 *
	 * @return Error|string
	 */
	protected function initiate_deployment( Changeset $changeset, $send_conflicts_only = false ) {
		$site_id = $this->settings_handler->get_site_id();

		if ( ! $send_conflicts_only ) {
			$changeset = $this->changeset_synchronizer->maybe_check_and_refresh_changeset( $changeset, $site_id );
			if ( is_wp_error( $changeset ) ) {
				return $changeset;
			}
		}

		// Send conflict data
		$result = $this->conflict_sender->send( $site_id, $changeset );
		if ( false === $result ) {
			return new Error( Error::$Changeset_sendConflictDataFailed, sprintf( __( 'There was an error sending the conflict data for the Changeset %s.' ), $changeset->changeset_id ) );
		}

		return $changeset->link;
	}

	/**
	 * Listen for deployment execution from the app
	 */
	public function handle_changeset_deployment() {
		$deploy = $this->bot->filter_input( 'deploy' );

		if ( ! isset( $deploy ) ) {
			return false;
		}

		$changeset = $this->should_deploy();
		if ( false !== $changeset ) {
			// All good, let's do this!
			$deployment = Deployment::make( $changeset );

			$this->deployment_agent->deploy( $deployment );
		}

		// Redirect to to remove the query strings
		$redirect = $this->bot->get_url();

		return $this->bot->redirect( $redirect );
	}

	/**
	 * Can we deploy the changeset?
	 *
	 * @return bool|Changeset
	 */
	protected function should_deploy() {
		$changeset = Changeset::get();
		if ( false === $changeset ) {
			return false;
		}

		$nonce = $this->bot->filter_input( 'nonce' );
		if ( ! isset( $nonce ) || ! wp_verify_nonce( $nonce, 'deploy-' . $changeset->changeset_id ) ) {
			return false;
		}

		$status = $this->bot->filter_input( 'status' );
		if ( isset( $status ) && Deployment_Agent::FAILED_STATUS === $status ) {
			// Deployment generation failed on the app
			$error_msg = __( 'The app failed to generate the deployment' );
			$error     = new Error( Error::$Deploy_appGenerateDeploymentFailed, $error_msg, array( 'changeset_id' => $changeset->changeset_id ) );
			$this->deployment_agent->add_error_notice( $changeset->changeset_id, $error_msg, $error );

			return false;
		}

		return $changeset;
	}
}
