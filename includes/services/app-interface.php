<?php

/**
 * The class for the handling the communication to the Mergebot App
 *
 * @since 0.1
 */

namespace DeliciousBrains\Mergebot\Services;

use DeliciousBrains\Mergebot\App\API\API;
use DeliciousBrains\Mergebot\App\API\API_Exception;
use DeliciousBrains\Mergebot\App\API\API_Response;
use DeliciousBrains\Mergebot\Models\Notice;
use DeliciousBrains\Mergebot\Models\Plugin;
use DeliciousBrains\Mergebot\Utils\Error;

class App_Interface {

	/**
	 * @var Plugin
	 */
	protected $bot;

	/**
	 * @var API
	 */
	protected $api;

	/**
	 * @var string
	 */
	public $api_key;

	/**
	 * @var bool Should the API error be logged
	 */
	protected $log_error = true;

	/**
	 * App constructor.
	 *
	 * @param Plugin  $bot
	 * @param API     $api
	 */
	public function __construct( Plugin $bot, API $api ) {
		$this->bot     = $bot;
		$this->api     = $api;
		$this->api_key = $this->bot->api_key();
	}

	/**
	 * Initialize app with the API wrapper
	 */
	public function init() {
		if ( false === $this->api_key ) {
			return false;
		}

		$this->api->set_key( $this->api_key );
		$this->api->set_url( $this->bot->api_url() );
	}

	/**
	 * Is the API down?
	 *
	 * @return bool
	 */
	public function is_api_down() {
		return Notice::exists( 'api-down' );
	}

	/**
	 * Wrap all calls to the API methods so we can catch Exceptions and translate to our Errors
	 *
	 * @param string $name
	 * @param array  $arguments
	 *
	 * @return Error|mixed
	 */
	public function __call( $name, $arguments ) {
		if ( ! method_exists( $this->api, $name ) ) {
			return new Error( Error::$Plugin_apiMethodNotExists, sprintf( __( 'Method %s does not exist' ), $name ) );
		}

		try {
			$result = call_user_func_array( array( $this->api, $name ), $arguments );
		} catch ( API_Exception $API_Exception ) {
			return $this->error( $API_Exception );
		}

		Notice::delete_by_id( 'api-down' );
		$this->log_error = true; // Reset log flag

		return $result;
	}

	/**
	 * Turn off logging of errors
	 *
	 * @return $this
	 */
	public function silent() {
		$this->log_error = false;

		return $this;
	}

	/**
	 * Handle the API error and possibly show notices
	 *
	 * @param API_Exception $API_Exception
	 *
	 * @return Error
	 */
	protected function error( API_Exception $API_Exception ) {
		$log_error       = $this->log_error;
		$this->log_error = true; // Reset log flag

		$log_error = $this->maybe_render_authentication_notice( $log_error, $API_Exception->getErrorData() );

		$error = new Error( $API_Exception->getCode(), $API_Exception->getMessage(), $API_Exception->getErrorData(), $log_error );
		$this->maybe_render_api_notice( $error );

		return $error;
	}

	/**
	 * Wrapper for method exists of the API class.
	 *
	 * @param string $method
	 *
	 * @return bool
	 */
	public function method_exists( $method ) {
		return method_exists( $this->api, $method );
	}

	/**
	 * Display a notice about the API being down
	 *
	 * @param Error $error
	 */
	public function maybe_render_api_notice( Error $error ) {
		if ( Error::$API_HTTPRequest !== $error->get_error_code() ) {
			return;
		}

		$try_again   = sprintf( '<a href="%s">%s</a>', esc_url( $this->bot->get_url() ), __( 'Try again' ) );
		$error_msg   = sprintf( __( 'Could not connect to the Mergebot API. %s.' ), $try_again );
		$notice_args = array(
			'id'                    => 'api-down',
			'type'                  => 'error',
			'message'               => $error_msg,
			'title'                 => __( 'Mergebot API Issue' ),
			'only_show_to_user'     => false,
			'flash'                 => true,
			'only_show_in_settings' => true,
			'dismissible'           => false,
			'footer'                => true,
		);

		Notice::create( $notice_args )->save();
	}

	/**
	 * Maybe display notice about API key being unauthenticated
	 *
	 * @param bool  $log Should we log the error
	 * @param mixed $data
	 *
	 * @return bool
	 */
	public function maybe_render_authentication_notice( $log, $data = '' ) {
		if ( ! is_array( $data ) ) {
			return $log;
		}

		if ( ! isset( $data['code'] ) || ! isset( $data['message'] ) ) {
			return $log;
		}

		$code = (int) $data['code'];

		if ( $this->render_unauthenticated_notice( $code, $data['message'] ) ) {
			// Don't log the error
			return false;
		}

		if ( $this->render_subscription_required_notice( $code, $data['message'] ) ) {
			// Don't log the error
			return false;
		}

		return $log;
	}

	/**
	 * Render a notice when the response is a 401 Unauthenticated.
	 * This means the API key does not exist in the app.
	 *
	 * @param int    $code
	 * @param string $message
	 *
	 * @return bool
	 */
	protected function render_unauthenticated_notice( $code, $message ) {
		if ( ! API_Response::is_unauthenticated( $code, $message ) ) {
			return false;
		}

		$notice_args = array(
			'id'                    => 'invalid-api-key',
			'type'                  => 'error',
			'message'               => __( 'Invalid API key, please check the key you copied from the app.' ),
			'title'                 => __( 'Mergebot API Key' ),
			'only_show_to_user'     => false,
			'flash'                 => true,
			'only_show_in_settings' => true,
			'dismissible'           => false,
		);

		Notice::create( $notice_args )->save();

		return true;
	}

	/**
	 * Render a notice when the response is a 402 Subscription Required.
	 * This means the user needs to subscribe to a plan in the app.
	 *
	 * @param int    $code
	 * @param string $message
	 *
	 * @return bool
	 */
	protected function render_subscription_required_notice( $code, $message ) {
		if ( ! API_Response::is_subscription_required( $code, $message ) ) {
			return false;
		}

		$subscribe_link = sprintf( '<a href="%s">%s</a>', $this->bot->app_url . '/login', __( 'View Plans' ) );

		$notice_args = array(
			'id'                    => 'app-sub-req',
			'type'                  => 'error',
			'message'               => __( 'You will need to subscribe to a billing plan in the app.' ) . ' ' . $subscribe_link,
			'title'                 => __( 'Mergebot Subscription Required' ),
			'only_show_to_user'     => false,
			'flash'                 => true,
			'only_show_in_settings' => true,
			'dismissible'           => false,
		);

		Notice::create( $notice_args )->save();

		return true;
	}
}