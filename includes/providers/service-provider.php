<?php

/**
 * The class that registers all our services in the IOC container
 *
 * @since 0.1
 */

namespace DeliciousBrains\Mergebot\Providers;

use DeliciousBrains\Mergebot\Models\Plugin;
use DeliciousBrains\Mergebot\Utils\Config;
use Pimple\Container;

class Service_Provider extends Abstract_Service_Provider {

	/**
	 * @var string
	 */
	protected $mode;

	/**
	 * @var string
	 */
	protected $slug;

	/**
	 * Initialize the service container
	 *
	 * @param Plugin    $plugin
	 * @param Container $container
	 *
	 * @return bool|Container
	 */
	public function init( Plugin $plugin, Container $container ) {
		$this->mode = $plugin->mode();
		$this->slug = $plugin->slug();

		// Add the plugin object to the container
		$container['plugin'] = $plugin;

		$container = $this->register( $container );
		$container = $this->init_services( $container );

		if ( $container['plugin_installer']->needs_update() ) {
			// Plugin needs an update, don't load any further.
			$container['plugin_installer']->init_required_notice();

			return false;
		}

		// Register and init Mode services, which bootstraps other services
		$container = $this->register_mode( $container, $this->mode, Config::api_key() );

		$container['diagnostic_downloader']->init();

		return $container;
	}

	/**
	 * All the services to load in the container
	 *
	 * @return array
	 */
	public function services() {
		return $this->load_config( 'plugin' );
	}

	/**
	 * Initialize services
	 *
	 * @param Container $container
	 *
	 * @return Container
	 */
	public function init_services( Container $container ) {
		$container['plugin_installer']->init();
		$container['app_interface']->init();
		$container['page_presenter']->init();
		$container['settings_handler']->init();
		$container['site_register']->init();
		$container['site_synchronizer']->init();
		$container['admin_handler']->init();
		$container['notice_presenter']->init();
		$container['notice_handler']->init();

		return $container;
	}

	/**
	 * Load the selected mode
	 *
	 * @param Container   $container
	 * @param bool|string $mode
	 * @param bool|string $api_key
	 *
	 * @return Container
	 */
	public function register_mode( Container $container, $mode, $api_key = false ) {
		if ( empty( $api_key ) ) {
			// API key not configured, abort
			return $container;
		}

		if ( false === $mode ) {
			// No mode selected, abort
			return $container;
		}

		$mode_class = 'DeliciousBrains\\Mergebot\\Providers\\Modes\\' . ucfirst( $mode );
		if ( ! class_exists( $mode_class ) ) {
			return $container;
		}

		// Add the mode to the service container
		$this->register_service( $mode_class, $container );
		$container[ $mode ]->init( $container );

		// Register the mode specific CLI commands
		$container = $this->register_cli_commands( $container, $mode );

		return $container;
	}

	/**
	 * Register the CLI command services
	 *
	 * @param Container $container
	 * @param string    $mode
	 *
	 * @return Container
	 */
	public function register_cli_commands( Container $container, $mode ) {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return $container;
		}

		if ( ! defined( 'WP_CLI_VERSION' ) ) {
			return $container;
		}

		$required_wp_cli_version = '0.25';

		if ( true === version_compare( WP_CLI_VERSION, $required_wp_cli_version, '<' ) ) {
			\WP_CLI::warning( sprintf( 'Mergebot commands require WP-CLI version %s or higher', $required_wp_cli_version ));
			return $container;
		}

		$cli_services = $this->load_config( 'cli-' . $mode );
		foreach ( $cli_services as $service ) {
			$this->register_service( $service, $container );
		}

		$instance = $this->get_service_instance( $container, $service );

		// Register the command with WP_CLI using the last class,
		// as we are chaining in order to use multiple classes for the same command
		\WP_CLI::add_command( $this->slug, $instance );

		return $container;
	}

	/**
	 * Dependency specific arguments for classes
	 *
	 * @param string $dependency
	 * @param array  $args
	 *
	 * @return string|false
	 */
	protected function filter_dependency( $dependency, &$args ) {
		if ( 'wpdb' === $dependency ) {
			global $wpdb;
			// Inject the global $wpdb instance
			$args[] = $wpdb;

			return false;
		}

		if ( 'abstract_mode' === $dependency ) {
			// Inject the Mode class
			$dependency = $this->mode;
		}

		return $dependency;
	}
}