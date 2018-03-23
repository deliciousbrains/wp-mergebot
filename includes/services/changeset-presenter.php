<?php

/**
 * The class that renders the changeset in the plugin page
 *
 * @since 0.1
 */

namespace DeliciousBrains\Mergebot\Services;

use DeliciousBrains\Mergebot\Services\Admin\Settings_Handler;
use DeliciousBrains\Mergebot\Models\Plugin;

class Changeset_Presenter {

	/**
	 * @var Plugin;
	 */
	protected $bot;

	/**
	 * @var Settings_Handler
	 */
	protected $settings_handler;

	/**
	 * @var Deployment_Agent
	 */
	protected $deployment_agent;

	/**
	 * @var Changeset_Synchronizer
	 */
	protected $changeset_synchronizer;

	/**
	 * @var Changeset_Handler
	 */
	protected $changeset_handler;

	/**
	 * @var null
	 */
	protected $changeset = null;

	/**
	 * Changeset_Presenter constructor.
	 *
	 * @param Plugin                 $bot
	 * @param Settings_Handler       $settings_handler
	 * @param Deployment_Agent       $deployment_agent
	 * @param Changeset_Synchronizer $changeset_synchronizer
	 * @param Changeset_Handler      $changeset_handler
	 */
	public function __construct( Plugin $bot, Settings_Handler $settings_handler, Deployment_Agent $deployment_agent, Changeset_Synchronizer $changeset_synchronizer, Changeset_Handler $changeset_handler ) {
		$this->bot                    = $bot;
		$this->settings_handler       = $settings_handler;
		$this->deployment_agent       = $deployment_agent;
		$this->changeset_synchronizer = $changeset_synchronizer;
		$this->changeset_handler      = $changeset_handler;
	}

	/**
	 * Initialize the hooks
	 */
	public function init() {
		add_action( $this->bot->slug() . '_view_admin_metaboxes', array( $this, 'render_metaboxes' ) );
		add_filter( $this->bot->slug() .'_pre_render_page_site_id', array( $this, 'check_site_exists' ) );
	}

	/**
	 * Render the app and site metaboxes
	 */
	public function render_metaboxes() {
		$data = $this->get_changeset_view_data();

		if ( is_wp_error( $data ) ) {
			$data = array( 'can_deploy' => false );
		}

		$data['app_url']         = $this->bot->url_without_scheme( $this->bot->app_url() );
		$data['site_url']        = $this->bot->url_without_scheme( home_url() );
		$data['db_support_link'] = $this->bot->get_more_info_doc_link( 'refreshing-the-development-database' );

		$this->bot->render_view( 'app', $data );
		$this->bot->render_view( 'site', $data );
	}

	public function check_site_exists( $site_id ) {
		if ( false === $site_id ) {
			return $site_id;
		}

		$this->get_changeset( $site_id );

		return $this->settings_handler->get_site_id();
	}

	protected function get_changeset( $site_id ) {
		if ( is_null( $this->changeset ) ) {
			$this->changeset = $this->changeset_synchronizer->get_changeset( $site_id );
		}

		return $this->changeset;
	}

	/**
	 * Render the changeset to be deployed on the settings page
	 */
	protected function get_changeset_view_data() {
		if ( false === ( $site_id = $this->settings_handler->get_site_id() ) ) {
			return new \WP_Error( 'site_not_connected' );
		}

		if ( false === $this->deployment_agent->is_setup() ) {
			return new \WP_Error( 'deployment_not_setup' );
		}

		$changeset = $this->get_changeset( $site_id );

		if ( false === $changeset ) {
			return new \WP_Error( 'no_changeset' );
		}

		if ( ! $this->bot->is_dev_mode() && 1 === (int) $changeset->deployed ) {
			return new \WP_Error( 'no_changeset_deployed' );
		}

		$can_deploy = $this->changeset_handler->can_deploy( $changeset->changeset_id );

		$deploy_url = $changeset->get_deploy_url( $this->bot );
		$reject_url = $changeset->get_reject_url( $this->bot );

		return array(
			'changeset'  => $changeset,
			'can_deploy' => $can_deploy,
			'deploy_url' => $deploy_url,
			'reject_url' => $reject_url,
		);
	}

}