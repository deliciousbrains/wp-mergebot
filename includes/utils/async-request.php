<?php

/**
 * The class for asynchronous requests
 *
 * This is used to create background requests used by the plugin.
 *
 * @since 0.1
 */

namespace DeliciousBrains\Mergebot\Utils;

class Async_Request extends Abstract_Request {

	/**
	 * @var string
	 */
	protected $identifier;

	/**
	 * @var array
	 */
	protected $data = array();

	/**
	 * Initialize the request
	 */
	protected function init() {
		$this->identifier = $this->get_identifier();

		add_action( 'wp_ajax_' . $this->identifier, array( $this, 'maybe_handle' ) );
		add_action( 'wp_ajax_nopriv_' . $this->identifier, array( $this, 'maybe_handle' ) );
	}

	/**
	 * Set data used during the request
	 *
	 * @param array $data
	 *
	 * @return $this
	 */
	public function data( $data ) {
		$this->data = $data;

		return $this;
	}

	/**
	 * Dispatch the async request
	 *
	 * @return array|WP_Error
	 */
	public function dispatch() {
		$url  = add_query_arg( $this->get_query_args(), $this->get_query_url() );
		$args = $this->get_post_args();
		$args = apply_filters( 'mergebot_request_http_args', $args, $this->identifier );

		return wp_remote_post( esc_url_raw( $url ), $args );
	}

	/**
	 * Get query args
	 *
	 * @return array
	 */
	protected function get_query_args() {
		return array(
			'action' => $this->identifier,
			'nonce'  => wp_create_nonce( $this->identifier ),
		);
	}

	/**
	 * Get query URL
	 *
	 * @return string
	 */
	protected function get_query_url() {
		return admin_url( 'admin-ajax.php' );
	}

	/**
	 * Get post args
	 *
	 * @return array
	 */
	protected function get_post_args() {
		return array(
			'timeout'   => 0.01,
			'blocking'  => false,
			'cookies'   => $_COOKIE, // Passing cookies ensures request is performed as initiating user
			'body'      => $this->data,
			'sslverify' => apply_filters( 'https_local_ssl_verify', false ), // Local requests, fine to pass false
		);
	}

	/**
	 * Maybe handle the request.
	 *
	 * Check for correct nonce and pass to handler.
	 */
	public function maybe_handle() {
		// Don't lock up other requests whilst processing
		session_write_close();

		$result = check_ajax_referer( $this->identifier, 'nonce', false );
		if ( false === $result ) {
			return $this->bot->wp_die();
		}
		
		$this->handle();

		return $this->bot->wp_die();
	}

	/**
	 * Run the code on the request
	 */
	protected function handle() {
		$method = $this->method;

		$this->instance->$method();
	}
}

