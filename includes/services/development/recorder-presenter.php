<?php

/**
 * The class that adds the recorder button to the admin UI
 *
 * @since 0.1
 */

namespace DeliciousBrains\Mergebot\Services\Development;

use DeliciousBrains\Mergebot\Models\Notice;
use DeliciousBrains\Mergebot\Services\Admin\Settings_Handler;
use DeliciousBrains\Mergebot\Models\Plugin;

class Recorder_Presenter {

	/**
	 * @var Plugin;
	 */
	protected $bot;

	/**
	 * @var Settings_Handler
	 */
	protected $settings_handler;

	/**
	 * @var Recorder_Handler
	 */
	protected $recorder_handler;

	/**
	 * Recorder_Presenter constructor.
	 *
	 * @param Plugin           $bot
	 * @param Settings_Handler $settings_handler
	 * @param Recorder_Handler $recorder_handler
	 */
	public function __construct( Plugin $bot, Settings_Handler $settings_handler, Recorder_Handler $recorder_handler ) {
		$this->bot              = $bot;
		$this->settings_handler = $settings_handler;
		$this->recorder_handler = $recorder_handler;
	}

	/**
	 * Initialize hooks
	 */
	public function init() {
		add_action( 'admin_init', array( $this, 'render_recorder_button' ) );
	}

	/**
	 * Should we show the recorder button?
	 *
	 * @return bool
	 */
	protected function should_display_recorder_button() {
		if ( $this->bot->doing_ajax() ) {
			// Bail on AJAX
			return false;
		}

		if ( false === $this->settings_handler->get_site_id() ) {
			// Don't show recorder
			return false;
		}

		if ( $this->recorder_handler->is_recording_disabled() ) {
			return false;
		}

		global $wpdb;
		if ( false === WPDB_Switcher::is_switched( $wpdb ) ) {
			return false;
		}

		if ( $this->recorder_handler->is_recording() && wp_get_current_user()->ID !== (int) $this->recorder_handler->get_recording_user_id() ) {
			// Recording in progress by another user, don't show
			return false;
		}

		if ( ! is_admin_bar_showing() || ! $this->recorder_handler->user_allowed_to_record() ) {
			// Admin bar not showing, or user doesn't have the permission
			return false;
		}

		return true;
	}

	/**
	 * Render the recorder button in the admin bar
	 */
	public function render_recorder_button() {
		if ( false === $this->should_display_recorder_button() ) {
			return false;
		}

		add_action( 'admin_bar_menu', array( $this, 'admin_bar_menu' ) );
		$this->admin_bar_assets();
		$this->maybe_show_query_limit_notice();

		do_action( $this->bot->slug() . '_render_recorder_button' );
	}

	/**
	 * Add the recording menu to the Admin Bar
	 */
	public function admin_bar_menu() {
		global $wp_admin_bar;

		$args = array(
			'id'     => $this->bot->slug(),
			'parent' => 'top-secondary',
			'meta'   => array(
				'class' => $this->bot->slug(),
			),
		);

		$wp_admin_bar->add_menu( $args );
	}

	/**
	 * Load the assets for the recorder button
	 */
	public function admin_bar_assets() {
		$version     = $this->bot->get_asset_version();
		$suffix      = $this->bot->get_asset_suffix();
		$plugins_url = $this->bot->get_asset_base_url();

		// css
		$src = $plugins_url . 'assets/css/admin-bar.css';
		wp_enqueue_style( $this->bot->slug() . '-admin-bar-styles', $src, array(), $version );

		// js
		$src = $plugins_url . 'assets/js/lib/spin' . $suffix . '.js';
		wp_enqueue_script( $this->bot->slug() . '-spin', $src, array( 'jquery' ), '2.3.2', true );

		$src = $plugins_url . 'assets/js/admin-bar' . $suffix . '.js';
		wp_enqueue_script( $this->bot->slug() . '-admin-bar-script', $src, array(
			'jquery',
			'wp-util',
			$this->bot->slug() . '-spin'
		), $version, true );

		wp_localize_script( $this->bot->slug() . '-admin-bar-script', $this->bot->slug(), array(
			'active'   => (int) $this->recorder_handler->is_recording(),
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'strings'  => array(
				'start_recording'  => __( 'Start recording queries' ),
				'stop_recording'   => __( 'Stop recording queries' ),
				'ajax_problem_on'  => __( 'An error occurred attempting to turn on query recording' ),
				'ajax_problem_off' => __( 'An error occurred attempting to turn off query recording' ),
			),
		) );
	}

	/**
	 * Maybe show the notice about reaching a high query limit for a changeset.
	 *
	 * @return bool
	 */
	protected function maybe_show_query_limit_notice() {
		if ( false === get_site_option( $this->bot->slug() . '_query_limit' ) ) {
			return false;
		}

		$more_info_link = $this->bot->get_more_info_doc_link( 'recording-changes' );

		$notice_args = array(
			'id'                => 'query-limit',
			'title'             => sprintf( __( '%s Large Changeset' ), $this->bot->name() ),
			'only_show_to_user' => false,
			'dismissible'       => false,
			'message'           => __( 'Your changeset has a very large number of queries and will probably take a long time to deploy.' ) . $more_info_link,
			'type'              => 'notice-warning',
		);

		Notice::create( $notice_args )->save();

		return true;
	}
}
