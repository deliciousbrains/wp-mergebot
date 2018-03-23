<?php
/**
 * The abstract class for the modes of the plugin
 *
 * This is used to load and control all the common mode related classes and code.
 *
 * @since 0.1
 */

namespace DeliciousBrains\Mergebot\Providers\Modes;

use DeliciousBrains\Mergebot\Services\Admin\Plugin_Installer;
use DeliciousBrains\Mergebot\Models\Plugin;
use DeliciousBrains\Mergebot\Providers\Abstract_Service_Provider;
use Pimple\Container;

abstract class Abstract_Mode extends Abstract_Service_Provider {

	/**
	 * @var Plugin
	 */
	protected $bot;

	/**
	 * @var array
	 */
	protected $tables = array();

	/**
	 * Mode constructor.
	 *
	 * @param Plugin $bot
	 */
	public function __construct( Plugin $bot ) {
		$this->bot = $bot;
	}

	/**
	 * Services to register in the container.
	 *
	 * @return array
	 */
	public function services() {
		return $this->load_config( 'mode' );
	}

	/**
	 * Initialize the mode
	 *
	 * @param Container $container
	 */
	public function init( Container $container ) {
		$container = $this->register( $container );

		if ( $this->is_setup( $container ) ) {
			$this->init_mode( $container );
		}
	}

	/**
	 * Is the mode set up before we can initialize it
	 *
	 * @param Container $container
	 *
	 * @return bool
	 */
	protected function is_setup( Container $container ) {
		return $this->tables_installed();
	}

	/**
	 * Initialize the mode
	 *
	 * @param $container
	 */
	protected function init_mode( Container $container ) {
		$container['changeset_deployer']->init();
		$container['deployment_agent']->init();
		$container['changeset_presenter']->init();
		$container['wordpress']->init();

		add_filter( $this->bot->slug() . '_diagnostic_data', array( $this, 'add_tables_diagnostic_data' ), 11 );
	}

	/**
	 * Get the tables used by the mode
	 *
	 * @param bool $for_mode
	 *
	 * @return array
	 */
	public function get_tables( $for_mode = true ) {
		global $wpdb;
		$tables     = array();
		$migrations = Plugin_Installer::get_migrations( $this->bot->dir_path() );
		$prefix     = $wpdb->base_prefix . $this->bot->slug() . '_';

		foreach ( $migrations as $migration ) {
			if ( $for_mode && false === $migration->allowed_for_mode( $this->bot->mode() ) ) {
				// Not a table for this mode;
				continue;
			}

			$tables[] = $migration->get_table_name( $prefix );
		}

		return $tables;
	}

	/**
	 * Are all the tables needed for the mode installed?
	 *
	 * @return bool
	 */
	public function tables_installed() {
		$tables = $this->get_tables();
		foreach ( $tables as $table ) {
			if ( ! Plugin_Installer::table_exists( $table ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Dependency specific arguments for classes
	 *
	 * @param string $dependency
	 * @param array  $args
	 *
	 * @return string|bool
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
			$dependency = $this->bot->mode();
		}

		return $dependency;
	}

	/**
	 * Add table info to the diagnostic data.
	 *
	 * @param array $data
	 *
	 * @return mixed
	 */
	public function add_tables_diagnostic_data( $data ) {
		$data['Tables'] = '\r\n';
		$tables = $this->get_tables();
		global $wpdb;
		foreach ( $tables as $table ) {
			$count = $wpdb->get_var( "SELECT COUNT(*) FROM $table" );

			$data[ $table ] = number_format( $count ) . ' ' . _n( 'row', 'rows', $count );
		}

		return $data;
	}
}