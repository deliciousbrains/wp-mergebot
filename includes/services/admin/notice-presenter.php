<?php

/**
 * The class that renders the notices
 *
 * @since 0.1
 */

namespace DeliciousBrains\Mergebot\Services\Admin;

use DeliciousBrains\Mergebot\Models\Notice;
use DeliciousBrains\Mergebot\Models\Plugin;

class Notice_Presenter {

	/**
	 * @var Plugin
	 */
	protected $bot;

	/**
	 * @var string
	 */
	protected $dashboard_title_prefix;

	/**
	 * Notice_Presenter constructor.
	 *
	 * @param Plugin $bot
	 */
	public function __construct( Plugin $bot ) {
		$this->bot = $bot;
		$this->dashboard_title_prefix = $this->bot->name();
	}

	/**
	 * Initialize the hooks
	 */
	public function init() {
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );
		add_action( 'network_admin_notices', array( $this, 'admin_notices' ) );
		add_action( $this->bot->slug() . '_pre_settings', array( $this, 'plugin_notices' ) );
		add_action( $this->bot->slug() . '_view_post_admin', array( $this, 'plugin_footer_notices' ), 100 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_notice_scripts' ) );
	}

	/**
	 * Render notices across the dashboard
	 */
	public function admin_notices() {
		$screen = get_current_screen();
		if ( isset( $screen->id ) && false !== strpos( $screen->id, $this->bot->hook_suffix() ) ) {
			return;
		}

		$this->render_notices();
	}

	/**
	 * Render notices on plugin page
	 */
	public function plugin_notices() {
		$this->render_notices();
	}

	/**
	 * Render notices on plugin page that have been added in the footer of the settings page
	 */
	public function plugin_footer_notices() {
		$this->render_notices( true );
	}

	/**
	 * Render the notices
	 *
	 * @param bool $footer Show notices add in the footer of the settings page
	 */
	protected function render_notices( $footer = false ) {
		$user_id = get_current_user_id();

		$dismissed_notices = Notice::all_dismissed_for_user( $user_id );

		$user_notices = Notice::all_for_user( $user_id );
		$this->maybe_show_notices( $user_notices, $dismissed_notices, $footer );


		$global_notices = Notice::all_global();
		$this->maybe_show_notices( $global_notices, $dismissed_notices, $footer );
	}

	/**
	 * Maybe show notices if they aren't dismissed
	 *
	 * @param array $notices
	 * @param array $dismissed_notices
	 * @param bool  $show_in_footer
	 */
	protected function maybe_show_notices( $notices, $dismissed_notices, $show_in_footer ) {
		if ( ! is_array( $notices ) || empty( $notices ) ) {
			return;
		}

		foreach ( $notices as $notice ) {
			$this->maybe_show_notice( $notice, $dismissed_notices, $show_in_footer );
		}
	}

	/**
	 * If it should be shown, display an individual notice
	 *
	 * @param Notice $notice
	 * @param array  $dismissed_notices
	 * @param bool   $footer Show notices added in the footer
	 */
	protected function maybe_show_notice( $notice, $dismissed_notices, $footer ) {
		$screen = get_current_screen();
		if ( $notice->only_show_in_settings && false === strpos( $screen->id, $this->bot->hook_suffix() ) ) {
			return;
		}

		if ( false === strpos( $screen->id, $this->bot->hook_suffix() ) ) {
			if ( $this->dashboard_title_prefix !== substr( $notice->title, 0, strlen( $this->dashboard_title_prefix ) ) ) {
				// Add the prefix to the title for dashboard notice
				$notice->title = $this->dashboard_title_prefix . ' ' . $notice->title;
			}
		}

		if ( ! $notice->only_show_to_user && in_array( $notice->id, $dismissed_notices ) ) {
			return;
		}

		if ( ! $this->check_capability_for_notice( $notice ) ) {
			return;
		}

		if ( $notice->footer !== $footer ) {
			// Display notices for the right section, eg. pre or post settings page hook
			return;
		}

		if ( 'info' === $notice->type ) {
			$notice->type = 'notice-info';
		}

		$notice->slug = $this->bot->slug();

		$this->bot->render_view( 'notice', $notice->to_array() );

		if ( $notice->flash ) {
			$notice->delete();
		}
	}

	/**
	 * Ensure the user has the correct capabilities for the notice to be displayed.
	 *
	 * @param array $notice
	 *
	 * @return bool|mixed
	 */
	protected function check_capability_for_notice( $notice ) {
		if ( ! isset( $notice->user_capabilities ) || empty( $notice->user_capabilities ) ) {
			// No capability restrictions, show the notice
			return true;
		}

		$caps = $notice->user_capabilities;

		if ( 2 === count( $caps ) && is_callable( array( $caps[0], $caps[1] ) ) ) {
			// Handle callback passed for capabilities
			return call_user_func( array( $caps[0], $caps[1] ) );
		}

		foreach ( $caps as $cap ) {
			if ( is_string( $cap ) && ! current_user_can( $cap ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Enqueue notice scripts in the admin
	 */
	public function enqueue_notice_scripts() {
		// Enqueue notice.js globally as notices can be dismissed on any admin page
		$src = plugins_url( 'assets/js/notices' . $this->bot->get_asset_suffix() . '.js', $this->bot->file_path() );
		wp_enqueue_script( $this->bot->slug() . '-notice', $src, array( 'jquery', 'wp-util' ), $this->bot->get_asset_version(), true );

		wp_localize_script( $this->bot->slug() . '-notice', $this->bot->slug() . '_notice', array(
			'strings' => array(
				'dismiss_notice_error' => __( 'Error dismissing notice.' ),
			),
			'nonces'  => array(
				'dismiss_notice' => wp_create_nonce( $this->bot->slug() . '_dismiss_notice' ),
			),
		) );
	}
}