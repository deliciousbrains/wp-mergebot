<?php

/**
 * The class for the plugin admin.
 *
 * This is used to handle WordPress admin actions
 *
 * @since 0.1
 */

namespace DeliciousBrains\Mergebot\Services\Admin;

use DeliciousBrains\Mergebot\Models\Notice;
use DeliciousBrains\Mergebot\Models\Plugin;
use DeliciousBrains\Mergebot\Uninstall;

class Admin_Handler {

	/**
	 * @var Plugin
	 */
	protected $bot;

	/**
	 * @var Settings_Handler
	 */
	protected $settings_handler;

	/**
	 * @var Page_Presenter
	 */
	protected $page_presenter;

	/**
	 * Admin constructor.
	 *
	 * @param Plugin           $bot
	 * @param Settings_Handler $settings_handler
	 * @param Page_Presenter   $page_presenter
	 */
	public function __construct( Plugin $bot, Settings_Handler $settings_handler, Page_Presenter $page_presenter ) {
		$this->bot              = $bot;
		$this->settings_handler = $settings_handler;
		$this->page_presenter   = $page_presenter;
	}

	/**
	 * Instantiate the admin hooks
	 */
	public function init() {
		add_action( $this->bot->slug() . '_load_plugin', array( $this, 'maybe_rollback_plugin' ) );
		add_action( 'admin_notices', array( $this, 'plugin_setup_notice' ), 9 );
	}

	/**
	 * Display a notice if the plugin requires further setup
	 */
	public function plugin_setup_notice() {
		$notice_args = array(
			'title'             => sprintf( __( '%s Plugin Setup' ), $this->bot->name() ),
			'only_show_to_user' => false,
			'dismissible'       => false,
		);

		$is_setup = $this->bot->is_setup();

		if ( is_wp_error( $is_setup ) ) {
			$notice_args['type']    = 'error';
			$notice_args['message'] = $is_setup->get_error_message() . $this->bot->get_more_info_doc_link( 'installation' ) ;
			Notice::create( $notice_args )->save();

			return false;
		}

		if ( $this->settings_handler->is_site_connected() ) {
			// We are good to go!
			return false;
		}

		global $current_screen;
		if ( $this->bot->hook_suffix() === $current_screen->id ) {
			// Already on the settings page
			return false;
		}

		$settings_url  = $this->bot->get_url();
		$settings_link = sprintf( '<a href="%s">%s &raquo;</a>', esc_url( $settings_url ), __( 'Visit settings' ) );
		$message       = sprintf( __( 'Finishing off setting up the plugin. %s' ), $settings_link );

		$notice_args['message'] = $message;

		return Notice::create( $notice_args )->save();
	}

	/**
	 * Rollback the plugin settings like an uninstall
	 */
	public function maybe_rollback_plugin() {
		$uninstall = $this->bot->filter_input( 'uninstall', INPUT_GET, FILTER_VALIDATE_INT );

		if ( ! isset( $uninstall ) || 1 !== $uninstall ) {
			return false;
		}

		Uninstall::init();

		return $this->bot->redirect();
	}
}