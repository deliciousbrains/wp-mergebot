<?php

/**
 * The class for the defining compatibility with WP Migrate DB
 *
 * @since 0.1
 */

namespace DeliciousBrains\Mergebot\Integrations;

use DeliciousBrains\Mergebot\Models\Plugin;
use DeliciousBrains\Mergebot\Providers\Modes\Abstract_Mode;

class WPMDB {

	/**
	 * @var Plugin
	 */
	protected $bot;

	/**
	 * @var Abstract_Mode
	 */
	protected $mode;

	/**
	 * WPMDB constructor.
	 *
	 * @param Plugin           $bot
	 * @param Abstract_Mode    $mode
	 */
	public function __construct( Plugin $bot, Abstract_Mode $mode ) {
		$this->bot = $bot;
		$this->mode = $mode;
	}

	/**
	 * Instantiate the hooks
	 */
	public function init() {
		add_filter( $this->bot->slug() . '_schema_ignored_queries', array( $this, 'exclude_queries_from_recording' ) );
		add_filter( $this->bot->slug() . '_ignore_excluded_queries', array( $this, 'ignore_excluded_queries' ) );
		add_filter( $this->bot->slug() . '_no_pk_tables', array( $this, 'no_pk_tables_migration' ) );

		add_filter( 'wpmdb_preserved_options', array( $this, 'preserve_settings' ) );
		add_filter( 'wpmdb_tables', array( $this, 'exclude_tables' ) );
		add_filter( 'wpmdb_rows_where', array( $this, 'exclude_changes_recorded_flag' ), 10, 2 );
	}

	/**
	 * Don't record queries on the migration temp tables and any state queries
	 *
	 * @param array $queries
	 *
	 * @return array
	 */
	public function exclude_queries_from_recording( $queries ) {
		global $wpdb;
		$queries['_mig_(.*)']              = array( '(.*)' ); // Ignore migration temporary table queries.
		$queries['wpmdb_alter_statements'] = array( '(.*)' ); // Ignore alter table name.

		$options_table = $wpdb->base_prefix;
		$options_table .= is_multisite() ? 'sitemeta' : 'options';

		$queries[ $options_table ][] = 'wpmdb_(.*)'; // Ignore option record queries

		return $queries;
	}

	/**
	 * Don't store the excluded INSERTs for the _mig tables
	 *
	 * @param array $queries
	 *
	 * @return array
	 */
	public function ignore_excluded_queries( $queries ) {
		$queries[] = '`_mig_';

		return $queries;
	}

	/**
	 * Make sure WP Migrate DB Pro does not migrate our settings
	 * across environments with different modes
	 *
	 * @param array $options
	 *
	 * @return array
	 */
	public function preserve_settings( $options ) {
		$options[] = $this->bot->settings_key();
		$options[] = $this->bot->slug() . '_query_limit';
		$options[] = $this->bot->slug() . '_dismissed_reminder';

		return $options;
	}

	/**
	 * Remove the tables from any migrations using WP Migrate DB Pro.
	 *
	 * @param array $clean_tables
	 *
	 * @return array
	 */
	public function exclude_tables( $clean_tables ) {
		$plugin_tables = $this->mode->get_tables( false );
		
		foreach ( $clean_tables as $i => $table ) {
			foreach ( $plugin_tables as $plugin_table ) {
				if ( false !== strpos( $table, $plugin_table ) ) {
					unset( $clean_tables[ $i ] );

					break;
				}
			}
		}

		return array_values( $clean_tables );
	}

	/**
	 * Never migrate the changes_recorded option between installs
	 *
	 * @param string $where
	 * @param string $table
	 *
	 * @return string
	 */
	public function exclude_changes_recorded_flag( $where, $table ) {
		global $wpdb;

		$table_name  = 'options';
		$column_name = 'option_name';
		if ( is_multisite() ) {
			$table_name  = 'sitemeta';
			$column_name = 'meta_key';
		}

		if ( $wpdb->base_prefix . $table_name !== $table ) {
			return $where;
		}

		$where .= ( empty( $where ) ? 'WHERE ' : ' AND ' );
		$where .= sprintf( "`%s` != '%s'", $column_name, $this->bot->slug() . '_changes_recorded' );

		return $where;
	}
}
