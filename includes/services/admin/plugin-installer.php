<?php

/**
 * The class that handles the installation of the plugin's custom tables
 *
 * @since 0.1
 */

namespace DeliciousBrains\Mergebot\Services\Admin;

use DeliciousBrains\Mergebot\Migrations\Abstract_Migration;
use DeliciousBrains\Mergebot\Models\Notice;
use DeliciousBrains\Mergebot\Models\Plugin;
use DeliciousBrains\Mergebot\Migrations\Migration_Interface;

class Plugin_Installer {

	/**
	 * @var Plugin
	 */
	protected $bot;

	/**
	 * @var Settings_Handler
	 */
	protected $settings_handler;

	/**
	 * @var \wpdb
	 */
	protected $db;

	/**
	 * @var array
	 */
	protected $table_versions;

	/**
	 * @var array
	 */
	protected static $installed_tables = array();

	/**
	 * Installer constructor.
	 *
	 * @param Plugin           $bot
	 * @param Settings_Handler $settings_handler
	 * @param \wpdb            $db
	 */
	public function __construct( Plugin $bot, Settings_Handler $settings_handler, \wpdb $db ) {
		$this->bot              = $bot;
		$this->settings_handler = $settings_handler;
		$this->db               = $db;
	}

	/**
	 * Instantiate the hooks for the installer
	 */
	public function init() {
		if ( $this->bot->doing_ajax() ) {
			return false;
		}

		add_action( 'admin_init', array( $this, 'run' ) );
		register_deactivation_hook( $this->bot->file_path(), array( $this, 'deactivate_plugin' ) );
	}

	/**
	 * Deactivate plugin callback.
	 */
	public function deactivate_plugin() {
		do_action( $this->bot->slug() . '_deactivate' );
	}

	/**
	 * Initialize the hook for displaying the required update notice.
	 */
	public function init_required_notice() {
		add_action( 'admin_init', array( $this, 'render_update_required_notice' ) );
	}

	/**
	 * Does the plugin require updating?
	 *
	 * @return bool
	 */
	public function needs_update() {
		$latest_version = $this->get_latest_version();
		if ( false === $latest_version ) {
			return false;
		}

		$current_version = $this->bot->version();

		if ( version_compare( $current_version, $latest_version ) >= 0 ) {
			// Versions are equal, or latest is lower, somehow.
			return false;
		}

		$latest_parsed  = $this->parse_version( $latest_version );
		$current_parsed = $this->parse_version( $current_version );

		return $this->is_breaking_change( $current_parsed, $latest_parsed );
	}

	/**
	 * Get the version of an update of the plugin, if available
	 *
	 * @return bool|string
	 */
	protected function get_latest_version() {
		$update_plugins = get_site_transient( 'update_plugins' );
		$basename       = plugin_basename( $this->bot->file_path() );
		if ( ! isset( $update_plugins->response ) || ! isset( $update_plugins->response[ $basename ] ) ) {
			// No update available for the plugin.
			return false;
		}

		if ( ! isset( $update_plugins->response[ $basename ]->new_version ) ) {
			// Update available but can't get version.
			return false;
		}

		return $update_plugins->response[ $basename ]->new_version;
	}

	/**
	 * Render the notice about required plugin update.
	 */
	public function render_update_required_notice() {
		$latest_version = $this->get_latest_version();
		if ( false === $latest_version ) {
			return false;
		}

		$basename    = plugin_basename( $this->bot->file_path() );
		$update_url  = wp_nonce_url( admin_url( 'update.php?action=upgrade-plugin&plugin=' . urlencode( $basename ) ), 'upgrade-plugin_' . $basename );
		$update_link = sprintf( '<a href="%s">%s</a>', $update_url, __( 'Update' ) );

		$notice_args = array(
			'id'                => 'update-required',
			'type'              => 'error',
			'title'             => sprintf( __( '%s Update Required' ), $this->bot->name() ),
			'message'           => sprintf( __( 'The API has changed and requires version %s of the plugin.' ), $latest_version ) . ' ' . $update_link,
			'only_show_to_user' => false,
			'dismissible'       => false,
		);

		return Notice::create( $notice_args )->save();
	}

	/**
	 * Parse the version string
	 *
	 * @param string $version
	 *
	 * @return array
	 */
	protected function parse_version( $version ) {
		// Clean up our beta version flags
		$version = str_replace( '-beta', '', $version );
		$version = str_replace( 'b', '.', $version );

		$parts = explode( '.', $version );

		return $parts;
	}

	/**
	 * Is the new version a breaking change?
	 * We define a breaking change for the plugin as a major increment, and a breaking API change as a minor one.
	 *
	 * @param array $parsed_version_current
	 * @param array $parsed_version_latest
	 *
	 * @return bool
	 */
	protected function is_breaking_change( $parsed_version_current, $parsed_version_latest ) {
		if ( $parsed_version_latest[0] > $parsed_version_current[0] ) {
			return true;
		}

		if ( $parsed_version_latest[1] > $parsed_version_current[1] ) {
			return true;
		}

		return false;
	}

	/**
	 * Run the installer
	 *
	 * @return bool
	 */
	public function run() {
		$this->table_versions = $this->settings_handler->get( 'db_table_versions', array() );

		$migrations = self::get_migrations( $this->bot->dir_path() );
		$collation  = self::get_db_collation( $this->db );

		$prefix = $this->db->base_prefix . $this->bot->slug() . '_';

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$installed = 0;
		foreach ( $migrations as $migration ) {
			$table   = $migration->get_table_name();
			$version = $migration->get_version();

			if ( false === $migration->allowed_for_mode( $this->bot->mode() ) ) {
				// Not a table for this mode;
				continue;
			}

			if ( ! $this->table_needs_installing( $table, $version, $prefix ) ) {
				continue;
			}

			$this->run_migration( $migration, $prefix, $collation );

			$this->table_versions[ $table ] = $version;
			$installed ++;
		}

		if ( 0 === $installed ) {
			return false;
		}

		return $this->settings_handler->set( 'db_table_versions', $this->table_versions )->save();
	}

	/**
	 * Get all the migration classes
	 *
	 * @param string $directory_path
	 *
	 * @return array
	 */
	public static function get_migrations( $directory_path ) {
		$migrations_files = glob( $directory_path . '/includes/migrations/*.php' );

		$migrations = array();

		foreach ( $migrations_files as $file ) {
			$class_name = self::class_from_file( $file, $directory_path );

			if ( 'abstract-migration' === basename( $file, '.php' ) ) {
				continue;
			}

			if ( ! class_exists( $class_name ) ) {
				continue;
			}

			$migration = new $class_name;

			if ( ! $migration instanceof Migration_Interface ) {
				continue;
			}

			$migrations[] = $migration;
		}

		return $migrations;
	}

	/**
	 * Does the table need installing/altering
	 *
	 * @param string $table
	 * @param string $version
	 * @param string $prefix
	 *
	 * @return bool
	 */
	protected function table_needs_installing( $table, $version, $prefix = '' ) {
		if ( ! isset( $this->table_versions[ $table ] ) ) {
			return true;
		}

		if ( version_compare( $this->table_versions[ $table ], $version, '!=' ) ) {
			return true;
		}

		if ( ! self::table_exists( $prefix . $table ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Get Charset and Collate for database table
	 *
	 * @param \wpdb $wpdb
	 *
	 * @return string
	 */
	public static function get_db_collation( $wpdb ) {
		$collate = 'CHARACTER SET utf8 COLLATE utf8_general_ci;';
		if ( $wpdb->has_cap( 'collation' ) ) {
			$collate = '';
			if ( ! empty( $wpdb->charset ) ) {
				$collate .= "CHARACTER SET {$wpdb->charset}";
			}
			if ( ! empty( $wpdb->collate ) ) {
				$collate .= " COLLATE {$wpdb->collate}";
			}
		}

		return $collate;
	}

	/**
	 * Run the migration
	 *
	 * @param Abstract_Migration $migration
	 * @param string             $prefix    Database table prefix
	 * @param string             $collation Database table collation
	 *
	 * @return array
	 */
	protected function run_migration( $migration, $prefix, $collation ) {
		$sql = $migration->get_create_table_statement( $prefix, $collation );

		return dbDelta( $sql );
	}

	/**
	 * Check if the table exists
	 *
	 * @param string $table
	 * @param bool   $cache
	 *
	 * @return bool
	 */
	public static function table_exists( $table, $cache = true ) {
		if ( $cache && isset( self::$installed_tables[ $table ] ) ) {
			return true;
		}

		global $wpdb;

		$result = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE '%s'", $table ) );

		$installed = ( $table === $result );

		if ( $cache ) {
			self::$installed_tables[ $table ] = $installed;
		}

		return $installed;
	}

	/**
	 * Get a class name from a file
	 *
	 * @param string $file
	 * @param string $base_dir
	 *
	 * @return bool
	 */
	protected static function class_from_file( $file, $base_dir ) {
		$class = str_replace( array( $base_dir . '/includes/', '.php' ), '', $file );
		$parts = explode( '/', $class );
		$parts = str_replace( '-', ' ', $parts );
		$parts = array_map( 'ucwords', $parts );
		$parts = str_replace( ' ', '_', $parts );
		$class = implode( '\\', $parts );
		$class = 'DeliciousBrains\\Mergebot\\' . $class;

		return $class;
	}
}