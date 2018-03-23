<?php

/**
 * The class that handle dismissing the notices
 *
 * @since 0.1
 */

namespace DeliciousBrains\Mergebot\Services\Admin;

use DeliciousBrains\Mergebot\Models\Notice;
use DeliciousBrains\Mergebot\Models\Plugin;

class Notice_Handler {

	/**
	 * @var Plugin
	 */
	protected $bot;

	/**
	 * Notice_Handler constructor.
	 *
	 * @param Plugin $bot
	 */
	public function __construct( Plugin $bot ) {
		$this->bot = $bot;
	}

	/**
	 * Initialize the hooks
	 */
	public function init() {
		add_action( 'wp_ajax_' . $this->bot->slug() . '_dismiss_notice', array( $this, 'ajax_dismiss_notice' ) );
	}

	/**
	 * Dismiss a notice
	 *
	 * @param string $notice_id
	 * @param int    $user_id
	 *
	 * @return bool
	 */
	protected function dismiss_notice( $notice_id, $user_id ) {
		$notice = Notice::find( $notice_id );
		if ( is_null( $notice ) ) {
			return true;
		}

		return $notice->dismiss( $user_id );
	}

	/**
	 * Handler for AJAX request to dismiss a notice
	 */
	public function ajax_dismiss_notice() {
		$notice_id = $this->can_dismiss_notice();
		if ( is_wp_error( $notice_id ) ) {
			return $this->bot->send_json_error( $notice_id->get_error_code() );
		}

		$user_id = get_current_user_id();

		if ( false === $this->dismiss_notice( $notice_id, $user_id ) ) {
			return $this->bot->send_json_error( __( 'There was an error dismissing the notice.' ) );
		}

		return $this->bot->send_json_success();
	}

	/**
	 * Check if the notice can be dismissed
	 *
	 * @return mixed|string|\WP_Error
	 */
	protected function can_dismiss_notice() {
		if ( ! is_admin() || ! wp_verify_nonce( sanitize_key( $this->bot->filter_input( '_nonce', INPUT_POST ) ), sanitize_key( $this->bot->filter_input( 'action', INPUT_POST ) ) ) ) { // input var okay
			return new \WP_Error( __( 'Cheating eh?' ) );
		}

		if ( ! current_user_can( $this->bot->capability() ) ) {
			return new \WP_Error( __( 'You do not have sufficient permissions to access this page.' ) );
		}

		$notice_id = $this->bot->filter_input( 'notice_id', INPUT_POST );

		if ( ! isset ( $notice_id ) || ! ( $notice_id = sanitize_text_field( $notice_id ) ) ) {
			return new \WP_Error( __( 'Invalid notice ID.' ) );
		}

		return $notice_id;
	}
}