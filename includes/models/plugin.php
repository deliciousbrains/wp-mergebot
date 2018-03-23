<?php

/**
 * The Plugin Model class holding data about the plugin itself
 *
 * @since 0.1
 */

namespace DeliciousBrains\Mergebot\Models;

use DeliciousBrains\Mergebot\Utils\Config;
use DeliciousBrains\Mergebot\Utils\Error;

class Plugin extends Base_Model {

	/**
	 * @var string
	 */
	protected $name;

	/**
	 * @var string
	 */
	protected $version;

	/**
	 * @var string
	 */
	protected $slug;

	/**
	 * @var string
	 */
	protected $mode;

	/**
	 * @var string
	 */
	protected $file_path;

	/**
	 * @var string
	 */
	protected $dir_path;

	/**
	 * @var string
	 */
	protected $capability;

	/**
	 * @var string
	 */
	protected $hook_suffix;

	/**
	 * @var string
	 */
	protected $settings_key;

	/**
	 * @var string
	 */
	protected $api_key;

	/**
	 * @var string
	 */
	public $api_url;

	/**
	 * @var string
	 */
	public $app_url;

	/**
	 * Plugin constructor.
	 *
	 * @param array $properties
	 */
	public function __construct( array $properties = array() ) {
		$properties['slug']         = strtolower( $properties['name'] );
		$properties['dir_path']     = rtrim( plugin_dir_path( $properties['file_path'] ), '/' );
		$properties['capability']   = apply_filters( $properties['slug'] . '_required_capability', 'manage_options' );
		$properties['hook_suffix']  = $this->get_hook_suffix( $properties['slug'] );
		$properties['settings_key'] = $properties['slug'] . '_settings';
		$properties['api_url']      = Config::api_url( 'https://api.mergebot.com' );
		$properties['app_url']      = Config::app_url( str_replace( 'api', 'app', $properties['api_url'] ) );

		parent::__construct( $properties );
	}

	/**
	 * Is the plugin setup?
	 **
	 * @return bool|Error
	 */
	public function is_setup() {
		if ( false === $this->api_key ) {
			$message = __( 'You must define your API key in your wp-config.php file.' );

			return new Error( Error::$Plugin_apiKeyNotDefined, $message, '', false );
		}

		if ( false === $this->mode ) {
			$message = sprintf( __( 'You must define a valid plugin mode (\'%s\' or \'%s\') in your wp-config.php file.' ), Config::MODE_DEV, Config::MODE_PROD );

			return new Error( Error::$Plugin_modeNotDefined, $message, '', false );
		}

		return true;
	}

	/**
	 * Get the plugin mode. Defined instead of relying on __Call so we can mock
	 *
	 * @return string
	 */
	public function mode(){
		return $this->mode;
	}

	/**
	 * Get hook suffix of menu item
	 *
	 * @param string $slug
	 *
	 * @return string
	 */
	protected function get_hook_suffix( $slug ) {
		$prefix = 'tools_page_';
		if ( is_multisite() ) {
			$prefix = 'settings_page_';
		}

		return $prefix . $slug;
	}

	/**
	 * Is the plugin a beta version
	 *
	 * @return bool
	 */
	public function is_beta() {
		return false !== strpos( $this->version, 'beta' );
	}

	/**
	 * Is Development mode
	 *
	 * @return bool
	 */
	public function is_dev_mode() {
		return Config::MODE_DEV === $this->mode;
	}

	/**
	 * Helper to see if WordPress is running an AJAX request
	 *
	 * @return bool
	 */
	public function doing_ajax() {
		return ( defined( 'DOING_AJAX' ) && DOING_AJAX );
	}

	/**
	 * Helper to see if WordPress is running a Cron job
	 *
	 * @return bool
	 */
	public function doing_cron() {
		return ( defined( 'DOING_CRON' ) && DOING_CRON );
	}

	/**
	 * Render a view template file
	 *
	 * @param string $view View filename without the extension
	 * @param array  $args Arguments to pass to the view
	 */
	public function render_view( $view, $args = array() ) {
		extract( $args );
		$bot = $this;

		include $this->dir_path . '/views/' . $view . '.php';
	}

	/**
	 * Get the suffix for an asset filename, eg. 'min' if not debugging scripts
	 *
	 * @return string
	 */
	public function get_asset_suffix() {
		return defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';
	}

	/**
	 * Get the version for an asset so we can cache bust when debugging
	 *
	 * @return int|string
	 */
	public function get_asset_version() {
		return defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? time() : $this->version;
	}

	/**
	 * Get the URL for the plugin directory
	 *
	 * @return string
	 */
	public function get_asset_base_url() {
		return trailingslashit( plugins_url() ) . trailingslashit( $this->slug );
	}

	/**
	 * Helper function for filtering super globals. Easily testable.
	 *
	 * @param string $variable
	 * @param int    $type
	 * @param int    $filter
	 *
	 * @return mixed
	 */
	public function filter_input( $variable, $type = INPUT_GET, $filter = FILTER_DEFAULT ) {
		return filter_input( $type, $variable, $filter );
	}

	/**
	 * Helper function for sending a JSON success response to an AJAX call
	 */
	public function send_json_success() {
		return wp_send_json_success();
	}

	/**
	 * Helper function for sending a JSON error response to an AJAX call
	 *
	 * @param string $error_message
	 */
	public function send_json_error( $error_message ) {
		return wp_send_json_error( array( 'statusText' => $error_message ) );
	}

	/**
	 * Get URL to the plugin page
	 *
	 * @param array       $args
	 * @param string|null $url
	 *
	 * @return string
	 */
	public function get_url( $args = array(), $url = null ) {
		if ( is_null( $url ) ) {
			$url = is_multisite() ? network_admin_url( 'settings.php' ) : admin_url( 'tools.php' );
		}

		$defaults = array(
			'page' => $this->slug,
		);

		$args = array_merge( $args, $defaults );

		$url = add_query_arg( $args, $url );

		return $url;
	}

	/**
	 * Helper for is_multisite() so it can be mocked.
	 *
	 * @return bool
	 */
	public function is_multisite() {
		return is_multisite();
	}

	/**
	 * Helper for is_network_admin() so it can be mocked.
	 *
	 * @return bool
	 */
	public function is_network_admin() {
		return is_network_admin();
	}

	/**
	 * Are we on the plugin settings page?
	 *
	 * @return bool
	 */
	public function is_page() {
		global $pagenow;
		$expected_page = is_multisite() ? 'settings.php' : 'tools.php';
		if ( $pagenow !== $expected_page ) {
			return false;
		}

		$page = $this->filter_input( 'page' );
		if ( empty( $page ) || $this->slug() !== $page ) {
			return false;
		}

		return true;
	}

	/**
	 * Redirect helper
	 *
	 * @param string|null $url
	 */
	public function redirect( $url = null ) {
		if ( is_null( $url ) ) {
			$url = $this->get_url();
		}

		wp_redirect( $url );
		exit;
	}

	/**
	 * Kill the process
	 *
	 * @param string $message
	 */
	public function wp_die( $message = '' ) {
		wp_die( $message );
	}

	/**
	 * Exit the process
	 */
	public function _exit() {
		exit;
	}

	/**
	 * Get URL without scheme
	 *
	 * @param string $url
	 *
	 * @return string
	 */
	public function url_without_scheme( $url ) {
		return preg_replace( '(^https?://)', '', $url );
	}

	/**
	 * Get the read more HTML link for a URL
	 *
	 * @param string $url
	 * @param null   $text
	 * @param bool   $arrow
	 *
	 * @return string
	 */
	public function get_more_info_link( $url, $text = null, $arrow = true ) {
		$text = is_null( $text ) ? __( 'More&nbsp;info' ) : $text;

		return sprintf( ' <a target="_blank" href="%s">%s' . ( $arrow ? '&nbsp;&raquo;' : '' ) . '</a>', $url, $text );
	}

	/**
	 * Get more info link for a documentation link on the app
	 *
	 * @param string $doc Doc slug eg. 04-schemas
	 * @param null   $text
	 * @param bool   $arrow
	 *
	 * @return string
	 */
	public function get_more_info_doc_link( $doc, $text = null, $arrow = true ) {
		$url = trailingslashit( $this->app_url ) . 'docs#/' . $doc;

		return $this->get_more_info_link( $url, $text, $arrow );
	}

	/**
	 * Get app link.
	 *
	 * @param string $path
	 *
	 * @return string
	 */
	public function get_app_url( $path ) {
		return trailingslashit( $this->app_url ) . $path;
	}

}