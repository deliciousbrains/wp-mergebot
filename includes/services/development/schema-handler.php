<?php

/**
 * The class that replicates aspects of the App's Data Schemas in the plugin
 *
 * @since 0.1
 */

namespace DeliciousBrains\Mergebot\Services\Development;

use DeliciousBrains\Mergebot\Models\Plugin;
use DeliciousBrains\Mergebot\Services\Admin\Settings_Handler;
use DeliciousBrains\Mergebot\Services\App_Interface;
use DeliciousBrains\Mergebot\Utils\Async_Request;
use DeliciousBrains\Mergebot\Utils\Error;

class Schema_Handler {

	/**
	 * @var Plugin;
	 */
	protected $bot;

	/**
	 * @var Settings_Handler
	 */
	protected $settings_handler;

	/**
	 * @var App_Interface
	 */
	protected $app;

	/**
	 * @var Async_Request
	 */
	protected $prime_schema_cache_request;

	/**
	 * Recorder_Presenter constructor.
	 *
	 * @param Plugin           $bot
	 * @param Settings_Handler $settings_handler
	 * @param App_Interface    $app
	 */
	public function __construct( Plugin $bot, Settings_Handler $settings_handler, App_Interface $app ) {
		$this->bot                        = $bot;
		$this->settings_handler           = $settings_handler;
		$this->app                        = $app;
		$this->prime_schema_cache_request = new Async_Request( $this->bot, $this, 'prime_schema_cache' );
	}

	/**
	 * Init the hooks.
	 */
	public function init() {
		add_action( 'update_option_active_plugins', array( $this, 'dispatch_prime_cache_request' ) );
		add_action( 'update_option_active_sitewide_plugins', array( $this, 'dispatch_prime_cache_request' ) );
		add_action( 'switch_theme', array( $this, 'dispatch_prime_cache_request' ) );
		add_action( 'upgrader_process_complete', array( $this, 'dispatch_prime_cache_request' ) );
	}

	/**
	 * Dispatch an async request to clear and refill the schema data cache.
	 */
	public function dispatch_prime_cache_request() {
		$this->prime_schema_cache_request->dispatch();
	}

	/**
	 * Get all schema keys.
	 *
	 * @return array
	 */
	protected function get_schema_keys() {
		return array(
			'ignored_queries',
			'primary_keys',
			'auto_increment_columns',
			'meta_tables',
		);
	}

	/**
	 * Prime all the schema data cache entries
	 */
	public function prime_schema_cache() {
		$keys = $this->get_schema_keys();

		foreach ( $keys as $key ) {
			$this->get_schema_data( $key, true );
		}
	}

	/**
	 * Get the ignored queries for tables.
	 *
	 * @return array|mixed
	 */
	public function get_ignored_queries() {
		$ignored_queries = $this->get_schema_data( 'ignored_queries' );

		$queries = $this->format_ignored_queries( $ignored_queries );

		return apply_filters( $this->bot->slug() . '_schema_ignored_queries', $queries );
	}

	/**
	 * Turn the queries from the app into a format we can use.
	 *
	 * @param array $ignored_queries
	 *
	 * @return array
	 */
	protected function format_ignored_queries( $ignored_queries ) {
		$queries = array();
		foreach ( $ignored_queries as $query ) {
			if ( ! isset( $query->table ) || ! isset( $query->search ) ) {
				continue;
			}

			$queries[ $query->table ][] = $query->search;
		}

		return $queries;
	}

	/**
	 * Hardcoded manual backup of WordPress tables and PK column(s).
	 *
	 * @return array
	 */
	protected function get_primary_keys_backup() {
		return array(
			'commentmeta'        => 'meta_id',
			'comments'           => 'comment_ID',
			'links'              => 'link_id',
			'options'            => 'option_id',
			'postmeta'           => 'meta_id',
			'posts'              => 'ID',
			'term_taxonomy'      => 'term_taxonomy_id',
			'termmeta'           => 'meta_id',
			'term_relationships' => array(
				'object_id',
				'term_taxonomy_id'
			),
			'terms'              => 'term_id',
			'usermeta'           => 'umeta_id',
			'users'              => 'ID',
		);
	}

	/**
	 * Get the primary key columns for tables
	 *
	 * @return array|mixed
	 */
	public function get_primary_keys() {
		$primary_keys = $this->get_schema_data( 'primary_keys' );

		if ( empty( $primary_keys ) ) {
			// Manual backup
			$primary_keys = $this->get_primary_keys_backup();
		}

		return apply_filters( $this->bot->slug() . '_schema_primary_keys', $primary_keys );
	}

	/**
	 * Get the AUTO INCREMENT columns for tables.
	 *
	 * @return array|mixed
	 */
	public function get_auto_increment_columns() {
		$columns = $this->get_schema_data( 'auto_increment_columns' );

		if ( empty( $columns ) ) {
			// Manual backup
			$columns = $this->get_primary_keys_backup();
			foreach ( $columns as $table => $column ) {
				if ( is_array( $column ) ) {
					// Remove compound keys from backup list of PKs.
					unset( $columns[ $table ] );
				}
			}
		}

		return $columns;
	}

	/**
	 * Get the meta tables that have a UNIQUE column.
	 *
	 * @return array|mixed
	 */
	public function get_unique_meta_tables() {
		$meta_tables = $this->get_schema_data( 'meta_tables' );

		$unique_meta_tables = array();
		foreach ( $meta_tables as $table => $data ) {
			if ( ! isset( $data->unique ) || ! $data->unique ) {
				continue;
			}

			if ( is_array( $data->keys ) && count( $data->keys ) > 1 ) {
				// Throw error, unique meta table should only have one key.
				new Error( Error::$schema_uniqueMetaTableMultipleKeys, sprintf( __( 'Unique meta table %s has multiple keys defined' ), $table ) );
				continue;
			}

			$unique_meta_tables[ $table ] = $data->keys[0];
		}

		if ( empty( $unique_meta_tables ) ) {
			// Manual backup
			$unique_meta_tables = array( 'options' => 'option_name' );
		}

		return apply_filters( $this->bot->slug() . '_schema_unique_meta_tables', $unique_meta_tables );
	}

	/**
	 * Wrapper to get data about the schema.
	 *
	 * @param string $key
	 * @param bool   $bust_cache
	 *
	 * @return array|mixed
	 */
	protected function get_schema_data( $key, $bust_cache = false ) {
		$schema_data = array();
		$schema_key  = $this->bot->slug() . '_schema_' . $key;

		if ( false !== ( $cached_data = get_site_transient( $schema_key ) ) && false === $bust_cache ) {
			return $cached_data;
		}

		if ( $this->app->is_api_down() ) {
			return $schema_data;
		}

		$method = 'get_schema_' . $key;
		if ( ! $this->app->method_exists( $method ) ) {
			throw new \RuntimeException( sprintf( 'API method %s does not exist.', $key ) );
		}

		// No cached data or we are busting it, get it from the app.
		$app_data = $this->app->{$method}( $this->settings_handler->get_site_id() );

		if ( ! is_wp_error( $app_data ) ) {
			// Set our transient and option backup
			$app_data = (array) $app_data;
			set_site_transient( $schema_key, $app_data, 12 * HOUR_IN_SECONDS );
			update_site_option( $schema_key, $app_data );

			return $app_data;
		}

		// Can't get a refresh of data from the app, use backup option
		if ( false !== ( $backup_data = get_site_option( $schema_key, false ) ) ) {
			$schema_data = $backup_data;
		}

		return $schema_data;
	}

	/**
	 * Is the table part of a schema we have defined
	 *
	 * @param string $table
	 *
	 * @return bool
	 */
	public function is_table_defined_in_schema( $table ) {
		$primary_keys = $this->get_primary_keys();

		return isset( $primary_keys[ $table ] );
	}

	/**
	 * Get the PK column for a table
	 *
	 * @param string $table
	 *
	 * @return bool|string
	 */
	public function get_auto_increment_column( $table ) {
		$columns = $this->get_auto_increment_columns();

		if ( isset( $columns[ $table ] ) ) {
			return $columns[ $table ];
		}

		return false;
	}

	/**
	 * Is the column the primary key column of the table
	 *
	 * @param string $column
	 * @param string $table
	 *
	 * @return bool
	 */
	public function is_pk_column( $column, $table ) {
		$primary_keys = $this->get_primary_keys();

		if ( isset( $primary_keys[ $table ] ) && $column === $primary_keys[ $table ] ) {
			return true;
		}

		return false;
	}

	/**
	 * Get the tables that have no PK column
	 *
	 * @return array
	 */
	public function get_tables_with_no_pk() {
		$no_pk_tables = array(
			'term_relationships',
		);

		return apply_filters( $this->bot->slug() . '_no_pk_tables', $no_pk_tables );
	}

	/**
	 * Check a table does not have a PK column
	 *
	 * @param string $table
	 *
	 * @return bool
	 */
	public function table_has_auto_increment_column( $table ) {
		if ( $this->is_table_defined_in_schema( $table ) ) {
			return (bool) $this->get_auto_increment_column( $table );
		}

		// Table not defined in schema, check manually for AUTO INCREMENT column.
		return $this->auto_increment_column_exists_for_table( $table );
	}

	/**
	 * Check if the table has an AUTO INCREMENT column by asking MySQL.
	 *
	 * @param string $table
	 *
	 * @return bool
	 */
	protected function auto_increment_column_exists_for_table( $table ) {
		global $wpdb;

		$columns = $wpdb->get_results( 'DESCRIBE ' . $wpdb->base_prefix . $table );

		foreach( $columns as $column ) {
			if ( 'auto_increment' === $column->Extra ) {
				return true;
			}
		}

		return false;
	}
}