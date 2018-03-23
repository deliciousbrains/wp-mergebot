<?php

/**
 * The main plugin class.
 *
 * @since 0.1
 */

namespace DeliciousBrains\Mergebot;

use DeliciousBrains\Mergebot\Models\Plugin;
use DeliciousBrains\Mergebot\Providers\Service_Provider;
use DeliciousBrains\Mergebot\Utils\Config;
use Pimple\Container;

class Mergebot {

	/**
	 * @var Mergebot
	 */
	protected static $instance;

	/**
	 * Make this class a singleton
	 *
	 * Use this instead of __construct()
	 *
	 * @param string $plugin_file_path
	 * @param string $plugin_version
	 *
	 * @return Mergebot
	 */
	public static function get_instance( $plugin_file_path, $plugin_version ) {
		if ( ! isset( static::$instance ) && ! ( self::$instance instanceof Mergebot ) ) {
			static::$instance = new Mergebot();
			// Initialize the class
			static::$instance->init( $plugin_file_path, $plugin_version );
		}

		return static::$instance;
	}

	/**
	 * Initialize the plugin.
	 *
	 * @param string $plugin_file_path
	 * @param string $plugin_version
	 */
	protected function init( $plugin_file_path, $plugin_version ) {
		$args = array(
			'name'      => 'Mergebot',
			'version'   => $plugin_version,
			'mode'      => Config::mode(),
			'api_key'   => Config::api_key(),
			'file_path' => $plugin_file_path,
		);

		$plugin = Plugin::create( $args );

		$this->register_services( $plugin );
		$this->load_textdomain( $plugin_file_path );
	}

	/**
	 * Instantiate the classes used by the plugin
	 *
	 * @param Plugin $plugin
	 */
	protected function register_services( Plugin $plugin ) {
		$container = new Container();
		$provider  = new Service_Provider();
		$provider->init( $plugin, $container );
	}

	/**
	 * Loads the plugin language files
	 *
	 * @param string $plugin_file_path
	 */
	protected function load_textdomain( $plugin_file_path ) {
		load_plugin_textdomain( 'mergebot', false, dirname( plugin_basename( $plugin_file_path ) ) . '/languages/' );
	}

	/**
	 * Protected constructor to prevent creating a new instance of the
	 * class via the `new` operator from outside of this class.
	 */
	protected function __construct() {}

	/**
	 * As this class is a singleton it should not be clone-able
	 */
	protected function __clone() {}

	/**
	 * As this class is a singleton it should not be able to be unserialized
	 */
	protected function __wakeup() {}
}