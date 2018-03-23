<?php

/**
 * The class that handles sites with Basic Authentication, which breaks our background jobs.
 *
 * @since 0.1
 */

namespace DeliciousBrains\Mergebot\Services\Development;

use DeliciousBrains\Mergebot\Models\Notice;
use DeliciousBrains\Mergebot\Models\Plugin;
use DeliciousBrains\Mergebot\Services\Admin\Settings_Handler;

class Basic_Auth_Handler {

	/**
	 * @var Plugin;
	 */
	protected $bot;

	/**
	 * @var Settings_Handler
	 */
	protected $settings_handler;

	/**
	 * Basic_Auth_Handler constructor.
	 *
	 * @param Plugin           $bot
	 * @param Settings_Handler $settings_handler
	 */
	public function __construct( Plugin $bot, Settings_Handler $settings_handler ) {
		$this->bot              = $bot;
		$this->settings_handler = $settings_handler;
	}

	/**
	 * Init the hooks for the class
	 */
	public function init() {
		add_filter( 'mergebot_request_http_args', array( $this, 'maybe_add_basic_auth_creds_to_reuest_args' ), 10, 2 );
		add_action( 'admin_init', array( $this, 'handle_manual_form_render' ) );
	}

	/**
	 * Init the hooks for when basic auth needs to be configured.
	 */
	public function init_template() {
		add_filter( $this->bot->slug() . '_form_settings_whitelist', array( $this, 'add_settings_to_whitelist' ) );
		add_action( $this->bot->slug() . '_pre_settings', array( $this, 'render_basic_auth_settings' ) );
	}

	/**
	 * Add basic auth settings to the form whitelist.
	 *
	 * @param array $settings
	 *
	 * @return array
	 */
	public function add_settings_to_whitelist( $settings ) {
		$settings['basic-auth'] = array( 'auth_username', 'auth_password' );

		return $settings;
	}

	/**
	 * Render the notice about basic auth configuration.
	 *
	 * @return bool
	 */
	public function maybe_render_basic_auth_notice() {
		if ( $this->bot->doing_ajax() || $this->bot->doing_cron() ) {
			return false;
		}

		if ( $this->is_basic_auth_creds_available() ) {
			// Basic auth on, and we can grab the creds from the page request.
			return false;
		}

		if ( false === $this->is_basic_auth() ) {
			// Basic auth off.
			return false;
		}

		if ( $this->is_basic_auth_creds_saved() ) {
			// Basic auth on, and we have the creds manually saved/
			return false;
		}

		$link = __( 'below' );
		if ( ! $this->bot->is_page() ) {
			$url  = $this->bot->get_url();
			$link = sprintf( '<a href="%s">%s</a>', $url, __( 'here') );
		}

		$notice_args = array(
			'id'                => 'basic-auth',
			'type'              => 'error',
			'message'           => sprintf( __( 'As the site is protected by basic auth, some features of the plugin won\'t work. Please enter your credentials %s.' ), $link ),
			'title'             => sprintf( __( '%s Basic Authentication' ), $this->bot->name() ),
			'dismissible'       => false,
			'only_show_to_user' => false,
		);

		return Notice::create( $notice_args )->save();
	}

	/**
	 * Render the view containing the basic auth form.
	 */
	public function render_basic_auth_settings() {
		$data = array(
			'slug'     => $this->bot->slug(),
			'username' => $this->settings_handler->get( 'auth_username', '' ),
			'password' => $this->settings_handler->get( 'auth_password', '' ),
		);

		$this->bot->render_view( 'basic-auth', $data );
	}

	/**
	 * Handle manually displaying the basic auth form, incase credentials need to be changed.
	 * This is via http://example.com/wp-admin/tools.php?page=mergebot&basic-auth=1 which is quick and dirty, but edgecase.
	 */
	public function handle_manual_form_render() {
		if ( $this->bot->doing_ajax() || $this->bot->doing_cron() ) {
			return;
		}

		if ( false === $this->bot->is_page() ) {
			return;
		}

		$basic_auth = $this->bot->filter_input( 'basic-auth' );
		if ( empty( $basic_auth ) ) {
			return;
		}

		$this->init_template();
	}

	/**
	 * Add the basic auth header to Mergebot HTTP requests to the site when basic auth is on.
	 *
	 * @param array  $args
	 * @param string $identifier
	 *
	 * @return array
	 */
	public function maybe_add_basic_auth_creds_to_reuest_args( $args, $identifier ) {
		if ( false === $this->is_basic_auth() ) {
			return $args;
		}

		$username = $this->get_basic_auth_username();
		$password = $this->get_basic_auth_password();

		if ( ! $username || ! $password ) {
			return $args;
		}

		$args['headers']['Authorization'] = 'Basic ' . base64_encode( $username . ':' . $password );

		return $args;
	}

	/**
	 * Can we get the basic auth credentials from the SERVER variable?
	 *
	 * @return bool
	 */
	protected function is_basic_auth_creds_available() {
		if ( isset( $_SERVER['PHP_AUTH_USER'] ) && isset( $_SERVER['PHP_AUTH_PW'] ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Is the site protected by basic authentication?
	 *
	 * @return bool
	 */
	protected function is_basic_auth() {
		if ( isset( $_SERVER['REMOTE_USER'] ) || isset( $_SERVER['PHP_AUTH_USER'] ) || isset( $_SERVER['REDIRECT_REMOTE_USER'] ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Have we saved the basic auth creds manually in the database?
	 *
	 * @return bool
	 */
	protected function is_basic_auth_creds_saved() {
		// Are they stored in the database
		if ( $this->settings_handler->get( 'auth_username', false ) && $this->settings_handler->get( 'auth_password', false ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Get the saved basic auth username.
	 *
	 * @return string|false
	 */
	protected function get_basic_auth_username() {
		if ( isset( $_SERVER['PHP_AUTH_USER'] ) ) {
			return $_SERVER['PHP_AUTH_USER'];
		}

		return $this->settings_handler->get( 'auth_username', false );
	}

	/**
	 * Get the saved basic auth password.
	 *
	 * @return string|false
	 */
	protected function get_basic_auth_password() {
		if ( isset( $_SERVER['PHP_AUTH_PW'] ) ) {
			return $_SERVER['PHP_AUTH_PW'];
		}

		return $this->settings_handler->get( 'auth_password', false );
	}
}