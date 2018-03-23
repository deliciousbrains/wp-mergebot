<?php

/**
 * The class that handles the recorder reminder popup
 *
 * @since 0.1
 */

namespace DeliciousBrains\Mergebot\Services\Development;

use DeliciousBrains\Mergebot\Models\Plugin;
use DeliciousBrains\Mergebot\Models\Query;
use DeliciousBrains\Mergebot\Services\Admin\Settings_Handler;
use DeliciousBrains\Mergebot\Services\App_Interface;

class Recorder_Reminder {

	/**
	 * @var Plugin
	 */
	protected $bot;

	/**
	 * @var Recorder_Handler
	 */
	protected $recorder_handler;

	/**
	 * @var App_Interface
	 */
	protected $app;

	/**
	 * @var string
	 */
	protected $recorded_query_key;

	/**
	 * @var string
	 */
	protected $dismissed_key;

	/**
	 * Recorder_Reminder constructor.
	 *
	 * @param Plugin           $bot
	 * @param Settings_Handler $settings_handler
	 * @param Recorder_Handler $recorder_handler
	 * @param App_Interface    $app
	 */
	public function __construct( Plugin $bot, Settings_Handler $settings_handler, Recorder_Handler $recorder_handler, App_Interface $app ) {
		$this->bot              = $bot;
		$this->settings_handler = $settings_handler;
		$this->recorder_handler = $recorder_handler;
		$this->app              = $app;

		$this->recorded_query_key = $this->bot->slug() . '_recorded_query';
		$this->dismissed_key      = $this->bot->slug() . '_dismissed_reminder';
	}

	/**
	 * Initialize hooks
	 */
	public function init() {
		if ( ! apply_filters( $this->bot->slug() . '_enable_recorder_reminder_popup', true ) ) {
			return;
		}

		add_action( $this->bot->slug() . '_query_recorded', array( $this, 'set_background_recorded_query' ) );
		add_action( $this->bot->slug() . '_render_recorder_button', array( $this, 'render_reminder_popup' ) );
		add_action( 'wp_ajax_mergebot_dismiss_recorder_reminder_popup', array( $this, 'ajax_dismiss_popup' ) );
		add_filter( $this->bot->slug() . '_start_recording', array( $this, 'send_background_recorded_query_to_app' ), 10, 2 );
		add_action( $this->bot->slug() . '_stop_recording', array( $this, 'clear_reminder_data' ) );
	}

	/**
	 * Can we display the popup
	 *
	 * @return bool
	 */
	protected function can_display_popup() {
		if ( $this->recorder_handler->is_recording() ) {
			// Abort if recording is on
			return false;
		}

		$dismissed = get_site_option( $this->dismissed_key, false );
		if ( false !== $dismissed && time() <= (int) $dismissed ) {
			// Abort as we have dismissed the reminder
			return false;
		}

		return true;
	}

	/**
	 * Remember the Query ID after it has been background recorded
	 *
	 * @param Query $query
	 *
	 * @return bool
	 */
	public function set_background_recorded_query( $query ) {
		if ( false === $this->can_display_popup() ) {
			return false;
		}

		if ( false !== get_site_option( $this->recorded_query_key, false ) ) {
			// Abort if we have already set a previous query ID
			return false;
		}

		$result = update_site_option( $this->recorded_query_key, $query->to_array() );

		return $result;
	}

	/**
	 * Render the reminder popup
	 */
	public function render_reminder_popup() {
		if ( false === $this->can_display_popup() ) {
			return;
		}

		if ( false === get_site_option( $this->recorded_query_key, false ) ) {
			// Haven't set a query ID yet
			return;
		}

		add_action( 'admin_footer', array( $this, 'render_view' ) );
		$this->load_reminder_popup_assets();
	}

	/**
	 * Load the assets for the recorder button
	 */
	protected function load_reminder_popup_assets() {
		$version     = $this->bot->get_asset_version();
		$suffix      = $this->bot->get_asset_suffix();
		$plugins_url = $this->bot->get_asset_base_url();

		$src = $plugins_url . 'assets/css/recorder-reminder-popup.css';
		wp_enqueue_style( $this->bot->slug() . '-recorder-reminder-popup-styles', $src, array(), $version );

		$src = $plugins_url . 'assets/js/recorder-reminder-popup' . $suffix . '.js';
		wp_enqueue_script( $this->bot->slug() . '-recorder-reminder-popup', $src, array( $this->bot->slug() . '-admin-bar-script' ), $version, true );
	}

	/**
	 * Get the number of minutes we can dismiss the popup for
	 *
	 * @return int
	 */
	protected function get_dismiss_delay_in_minutes() {
		return (int) apply_filters( $this->bot->slug() . '_recorder_reminder_dismiss_delay_minutes', 15 );
	}

	/**
	 * Render the popup HTML view
	 */
	public function render_view() {
		$args = array(
			'name'    => $this->bot->name(),
			'minutes' => $this->get_dismiss_delay_in_minutes(),
		);

		$this->bot->render_view( 'recorder-reminder-popup', $args );
	}

	/**
	 * Ajax handler for dismissing the reminder popup
	 */
	public function ajax_dismiss_popup() {
		if ( ! $this->recorder_handler->user_allowed_to_record() ) {
			return $this->bot->send_json_error( __( 'User cannot dismiss popup' ) );
		}

		$minutes = $this->bot->filter_input( 'minutes', INPUT_POST, FILTER_VALIDATE_INT );
		if ( is_null( $minutes ) ) {
			$minutes = 0;
		}

		// Clear Query
		delete_site_option( $this->recorded_query_key );
		// Set the dismissed flag for the popup
		update_site_option( $this->dismissed_key, time() + ( $minutes * 60 ) );

		return $this->bot->send_json_success();
	}

	/**
	 * When we start recording send the ID of the background recorded query to the app
	 *
	 * @param bool       $result
	 * @param string $recording_id
	 *
	 * @return bool|void
	 */
	public function send_background_recorded_query_to_app( $result, $recording_id ) {
		if ( 1 !== $this->bot->filter_input( 'background_record', INPUT_POST, FILTER_VALIDATE_INT ) ) {
			// We haven't turned on recording via the reminder popup
			return $result;
		}

		$query = get_site_option( $this->recorded_query_key );
		if ( false === $query ) {
			// Set Query doesn't exist
			return $result;
		}

		$query = Query::create( $query );

		// Send the ID and the recording ID to the app
		$app_result = $this->app->post_query_to_record_from( $this->settings_handler->get_site_id(), $query->id(), $query->date_recorded(), $recording_id );
		if ( is_wp_error( $app_result ) ) {
			return false;
		}

		// Clear Query
		delete_site_option( $this->recorded_query_key );

		return $result;
	}

	/**
	 * Remove all reminder data when a recording is stopped.
	 *
	 * @param string $context
	 */
	public function clear_reminder_data( $context = '' ) {
		if ( 'wpmdb-cli' === $context ) {
			// Don't clear the data when stopping recording pre-migration.
			// Let the migration choose which data to keep.
			return;
		}

		delete_site_option( $this->recorded_query_key );
		delete_site_option( $this->dismissed_key );
	}
}