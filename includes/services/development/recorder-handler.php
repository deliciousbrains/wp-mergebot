<?php

/**
 * The class that controls the state of the recorder
 *
 * @since 0.1
 */

namespace DeliciousBrains\Mergebot\Services\Development;

use DeliciousBrains\Mergebot\Models\App_Changeset;
use DeliciousBrains\Mergebot\Models\Changeset;
use DeliciousBrains\Mergebot\Models\Notice;
use DeliciousBrains\Mergebot\Models\Query;
use DeliciousBrains\Mergebot\Services\Admin\Settings_Handler;
use DeliciousBrains\Mergebot\Models\Plugin;
use DeliciousBrains\Mergebot\Services\App_Interface;
use DeliciousBrains\Mergebot\Services\Site_Register;

class Recorder_Handler {

	/**
	 * @var Plugin;
	 */
	protected $bot;

	/**
	 * @var Settings_Handler
	 */
	protected $settings_handler;

	/**
	 * @var Site_Register
	 */
	protected $site_register;

	/**
	 * @var App_Interface
	 */
	protected $app;

	/**
	 * Do not allow recording after having this many queries blocked
	 */
	const BLOCKED_QUERY_CAP = 1000;

	/**
	 * Recorder_Presenter constructor.
	 *
	 * @param Plugin           $bot
	 * @param Settings_Handler $settings_handler
	 * @param Site_Register    $site_register
	 * @param App_Interface    $app
	 */
	public function __construct( Plugin $bot, Settings_Handler $settings_handler, Site_Register $site_register, App_Interface $app ) {
		$this->bot              = $bot;
		$this->settings_handler = $settings_handler;
		$this->site_register    = $site_register;
		$this->app              = $app;
	}

	/**
	 * Initialize hooks
	 */
	public function init() {
		add_action( 'wp_ajax_mergebot_toggle_recorder', array( $this, 'ajax_toggle_recording' ) );
		add_action( $this->bot->slug() . '_refresh_changeset', array( $this, 'maybe_update_recording_status' ), 10, 2 );
		add_filter( $this->bot->slug() . '_diagnostic_data', array( $this, 'add_recording_diagnostic_data' ) );
	}

	/**
	 * Has recording been disabled as we have a blocked query and have reached the limit of queries.
	 * This is so we don't have a huge amount of queries to send when the blockage is cleared.
	 * It can only be disabled if we are not in recording session.
	 *
	 * @return bool
	 */
	public function is_recording_disabled() {
		if ( $this->is_recording() ) {
			return false;
		}

		if ( ! Query::is_blocked_query() ) {
			return false;
		}

		if ( Query::total() < self::BLOCKED_QUERY_CAP ) {
			return false;
		}

		return true;
	}

	/**
	 * Is the user allowed to record changes?
	 *
	 * @return bool
	 */
	public function user_allowed_to_record() {
		$recording_capability = apply_filters( $this->bot->slug() . '_recording_capability', 'edit_posts' );

		return (bool) apply_filters( $this->bot->slug() . '_user_allowed_to_record', current_user_can( $recording_capability ) );
	}

	/**
	 * Start the recording session
	 *
	 * @return bool
	 */
	public function start_recording() {
		$recording_id = $this->generate_recording_id();
		$user_id      = wp_get_current_user()->ID;

		$result = apply_filters( $this->bot->slug() . '_start_recording', true, $recording_id, $user_id );

		if ( true !== $result ) {
			return false;
		}

		$data = array(
			'recording_id'      => $recording_id,
			'recording_user_id' => $user_id
		);
		$this->settings_handler->set( $data )->save();
		update_site_option( $this->bot->slug() . '_changes_recorded', true );

		if ( false === $this->toggle_app_recording() ) {
			$notice_args = array(
				'id'      => 'recording-app-toggle',
				'title'   => __( 'App Communication Issue' ),
				'message' => __( 'There was an issue telling the app that you are recording, make sure to turn off recording before deploying to Production.' ),
				'type'    => 'notice-warning',
			);

			Notice::create( $notice_args )->save();
		}

		return true;
	}

	/**
	 * Stop the recording session
	 *
	 * @param string $context
	 */
	public function stop_recording( $context = '' ) {
		$this->settings_handler->delete( array( 'recording_id', 'recording_user_id' ) )->save();

		$this->toggle_app_recording( false );
		Notice::delete_by_id( 'recording-app-toggle' );

		do_action( $this->bot->slug() . '_stop_recording', $context );
	}

	/**
	 * Tell the app the state of recording
	 *
	 * @param bool $recording
	 *
	 * @return bool
	 */
	protected function toggle_app_recording( $recording = true ) {
		$args = array(
			'unique_id'    => $this->site_register->get_site_unique_id(),
			'is_recording' => $recording,
		);

		$site = $this->app->post_site_settings( $args );

		if ( is_wp_error( $site ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Are we currently recording queries?
	 *
	 * @return bool
	 */
	public function is_recording() {
		return (bool) $this->get_recording_id();
	}

	/**
	 * Get the User ID who started the recording
	 *
	 * @return int|false
	 */
	public function get_recording_user_id() {
		return $this->settings_handler->get( 'recording_user_id', 0 );
	}

	/**
	 * Get the recording ID
	 *
	 * @return string
	 */
	public function get_recording_id() {
		return $this->settings_handler->get( 'recording_id' );
	}

	/**
	 * Generate the recording ID
	 *
	 * @param null|int $time Timestamp
	 *
	 * @return string
	 */
	public function generate_recording_id( $time = null ) {
		if ( is_null( $time ) ) {
			$time = time();
		}

		return md5( $time . network_home_url() );
	}

	/**
	 * AJAX callback to toggle a recording session
	 */
	public function ajax_toggle_recording() {
		if ( ! $this->user_allowed_to_record() ) {
			return $this->bot->send_json_error( __( 'User cannot perform a recording' ) );
		}

		if ( 1 === $this->bot->filter_input( 'active_status', INPUT_POST, FILTER_VALIDATE_INT ) ) {
			$this->stop_recording();

			return $this->bot->send_json_success();
		}

		if ( false === $this->start_recording() ) {
			return $this->bot->send_json_error( __( 'There was an error starting recording' ) );
		}

		return $this->bot->send_json_success();
	}

	/**
	 * Update the recording state on the app if it is out of date when refreshing the changeset.
	 *
	 * @param Changeset     $old_changeset
	 * @param App_Changeset $new_changeset
	 *
	 * @return bool
	 */
	public function maybe_update_recording_status( Changeset $old_changeset, App_Changeset $new_changeset ) {
		if ( ! isset( $new_changeset->is_site_recording ) ) {
			return false;
		}

		$is_recording = $this->is_recording();
		if ( (bool) $new_changeset->is_site_recording === $is_recording ) {
			return false;
		}

		// App has outdated info about the recording state, update it.
		return $this->toggle_app_recording( $is_recording );
	}

	/**
	 * @param array $data
	 *
	 * @return array
	 */
	public function add_recording_diagnostic_data( $data ) {
		$recording = $this->is_recording();

		$data['Recording'] = $recording ? 'Yes' : 'No';
		if ( $recording ) {
			$recording_user_id = $this->get_recording_user_id();
			$user              = get_userdata( $recording_user_id );

			$data['Recording User'] = ( $user ? $user->user_login . ' ' : '' ) . '(' . $recording_user_id . ')';
		}

		return $data;
	}
}