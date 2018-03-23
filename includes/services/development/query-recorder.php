<?php

/**
 * The class records a WordPress SQL query, regardless of if we are 'recording'
 *
 * @since 0.1
 */

namespace DeliciousBrains\Mergebot\Services\Development;

use DeliciousBrains\Mergebot\Models\Data;
use DeliciousBrains\Mergebot\Models\Excluded_Object;
use DeliciousBrains\Mergebot\Models\Notice;
use DeliciousBrains\Mergebot\Models\Query;
use DeliciousBrains\Mergebot\Models\SQL_Query;
use DeliciousBrains\Mergebot\Models\Plugin;
use DeliciousBrains\Mergebot\Providers\Modes\Development;
use DeliciousBrains\Mergebot\Services\Admin\Settings_Handler;
use DeliciousBrains\Mergebot\Utils\Config;
use DeliciousBrains\Mergebot\Utils\Error;
use DeliciousBrains\Mergebot\Utils\Query_Helper;
use DeliciousBrains\Mergebot\Utils\Support;

class Query_Recorder {

	/**
	 * @var Plugin;
	 */
	protected $bot;

	/**
	 * @var Development;
	 */
	protected $mode;

	/**
	 * @var Recorder_Handler
	 */
	protected $recorder_handler;

	/**
	 * @var Schema_Handler
	 */
	protected $schema_handler;

	/**
	 * @var Settings_Handler
	 */
	protected $settings_handler;

	/**
	 * Recorder_Presenter constructor.
	 *
	 * @param Plugin           $bot
	 * @param Development      $mode
	 * @param Recorder_Handler $recorder_handler
	 * @param Schema_Handler   $schema_handler
	 * @param Settings_Handler $settings_handler
	 */
	public function __construct( Plugin $bot, Development $mode, Recorder_Handler $recorder_handler, Schema_Handler $schema_handler, Settings_Handler $settings_handler ) {
		$this->bot              = $bot;
		$this->mode             = $mode;
		$this->recorder_handler = $recorder_handler;
		$this->schema_handler   = $schema_handler;
		$this->settings_handler = $settings_handler;
	}

	/**
	 * Initialize hooks
	 */
	public function init() {
		add_filter( 'mergebot_before_query', array( $this, 'query_listener' ), 9999 );
	}

	/**
	 * Listen to every WordPress query and attempt to record query
	 *
	 * @param SQL_Query $query
	 *
	 * @return SQL_Query
	 */
	public function query_listener( $query ) {
		return $this->maybe_record_query( $query );
	}

	/**
	 * Maybe record the query
	 *
	 * @param SQL_Query $query
	 *
	 * @return SQL_Query|bool
	 */
	public function maybe_record_query( SQL_Query $query ) {
		if ( false === ( $query = $this->should_record_query( $query ) ) ) {
			// Query should not be executed, abort execution.
			return false;
		}

		if ( false === $query->to_record() ) {
			// Query should not be recorded, ignore.
			return $query;
		}

		// Record the query
		$recorded_query = $this->insert_query( $query );
		if ( false === $recorded_query ) {
			// Query insert failed, bail
			return $query;
		}

		$query->set_recorded_query( $recorded_query );

		if ( Query_Helper::requires_data_snapshot( $query->sql(), $query->type(), $query->table() ) ) {
			// Record existing data for rows affected by query
			$this->record_affected_rows( $query );
		}

		if ( $this->should_mark_as_processed( $query->type(), $query->table() ) ) {
			// Mark query as processed if can be sent to the app immediately
			$recorded_query->processed = 1;
			$recorded_query->save();
		}

		// Mark the query so it can be dispatched if possible to the app
		$query->dispatch();

		return $query;
	}

	/**
	 * Get the recording id to use for the query.
	 *
	 * If a query is executed by a user that is not recording queries,
	 * don't include it in the recording.
	 *
	 * @return string
	 */
	protected function get_recording_id_for_query() {
		$recording_id = $this->recorder_handler->get_recording_id();

		if ( empty ( $recording_id ) ) {
			return '';
		}

		if ( wp_get_current_user()->ID !== (int) $this->recorder_handler->get_recording_user_id() ) {
			return '';
		}

		return $recording_id;
	}

	/**
	 * Insert the recorded change
	 *
	 * @param SQL_Query $query
	 * @param array     $data
	 *
	 * @return Query|bool
	 */
	protected function insert_query( SQL_Query $query, $data = array() ) {
		$defaults = array(
			'recording_id'  => $this->get_recording_id_for_query(),
			'type'          => $query->type(),
			'insert_table'  => $query->table(),
			'insert_id'     => 0, // placeholder to be updated for INSERTs next time
			'sql_statement' => $query->sql(),
			'date_recorded' => date( 'Y-m-d H:i:s' ),
			'blog_id'       => $query->blog_id(),
		);

		$data = array_merge( $defaults, $data );

		$recorded_query = Query::create( $data )->save();

		if ( is_wp_error( $recorded_query ) || is_null( $recorded_query ) ) {
			// Query insert failed, bail
			return false;
		}

		do_action( $this->bot->slug() . '_query_recorded', $recorded_query );

		return $recorded_query;
	}

	/**
	 * Should the query be recorded
	 *
	 * @param SQL_Query $query
	 *
	 * @return SQL_Query|bool
	 */
	protected function should_record_query( SQL_Query $query ) {
		$sql = $query->sql();

		if ( false === $query->valid_type() ) {
			// Not a query type we want to record
			return $query;
		}

		if ( $this->is_internal_query( $query ) ) {
			// Internal plugin query, don't record
			return $query;
		}

		if ( $query->is_insert() && false === $query->table() ) {
			// Can't work out the table name for the query
			new Error( Error::$Recorder_insertFailed, __( 'Cannot determine table in INSERT question for query' ), $sql );

			return $query;
		}

		global $wpdb;
		if ( $this->ignore_query( $query ) && ! $wpdb->suppress_ignore_query_check ) {
			// Record the ignored INSERT for later, so we can also ignore its DELETEs
			$query = $this->maybe_store_excluded_insert_query( $query );

			// Not a query we want to record
			return $query;
		}

		if ( $this->maybe_process_insert_with_multiple_values( $query ) ) {
			// INSERT query has multiple values, abort execution, we will execute as separate queries.
			return false;
		}

		if ( $query->is_delete() && $this->is_delete_for_excluded_insert( $query ) ) {
			// If this is a DELETE statement for a previously ignored INSERT, ignore
			return $query;
		}

		// This IS a query we should record
		$query->record();

		// Reset the flag so we check to ignore queries.
		$wpdb->suppress_ignore_query_check = false;

		return $query;
	}

	/**
	 * If an INSERT statement has multiple VALUES (ie. inserting multiple records at once),
	 * split up all records into different statements and execute them separately.
	 * This is so we can get each inserted ID, required for conflict checking in the app.
	 *
	 * @param SQL_Query $query
	 *
	 * @return bool
	 */
	protected function maybe_process_insert_with_multiple_values( $query ) {
		if ( ! $query->is_insert() ) {
			return false;
		}

		// Quick and dirty check for multiple VALUES, so we don't have to parse every INSERT.
		if ( false === stripos( str_replace( ' ', '', $query->sql() ), '),(' ) ) {
			return false;
		}

		if ( false === ( $query_data = $query->parse() ) ) {
			return false;
		}

		$values = $query_data->get_insert_values();
		if ( 1 === count( $values ) ) {
			// Only single VALUE - stripos false positive.
			return false;
		}

		unset( $query_data->parsed['VALUES'] );

		global $wpdb;
		foreach ( $values as $value ) {
			// Clean up delimiter and base expression
			$value['delim']     = false;
			$value['base_expr'] = rtrim( $value['base_expr'], ',' );

			$query_data->parsed['VALUES'][0] = $value;
			reset( $query_data->parsed );

			$insert_sql = $query->to_sql( $query_data->parsed );
			if ( false === $insert_sql ) {
				$error_title = 'SQL Error';
				$error_msg   = 'There was an error processing an INSERT statement with multiple values.';
				$notice_args = array(
					'type'              => 'error',
					'message'           => $error_msg . ' ' . Support::generate_link( $this->settings_handler->get_site_id(), $error_title, $error_msg, array(
							'parent_sql'   => $query->sql(),
							'child_parsed' => $query_data->parsed
						) ),
					'title'             => $error_title,
					'only_show_to_user' => false,
					'flash'             => false,
				);

				Notice::create( $notice_args )->save();

				return false;
			}

			$wpdb->query( $insert_sql );
		}

		return true;
	}

	/**
	 * If the query is an excluded INSERT, store it in the Excluded Objects table
	 *
	 * @param SQL_Query $query
	 *
	 * @return SQL_Query
	 */
	protected function maybe_store_excluded_insert_query( SQL_Query $query ) {
		if ( ! $query->is_insert() || false === ( $excluded_insert_table = $query->table() ) ) {
			return $query;
		}

		$ignore_queries = apply_filters( $this->bot->slug() . '_ignore_excluded_queries', array() );
		if ( ! empty( $ignore_queries ) && Query_Helper::sql_contains_excluded_queries( $query->sql(), $ignore_queries ) ) {
			// Some INSERT queries we really don't care about remembering, if we know they won't be updated or deleted
			return $query;
		}

		$data = array(
			'sql_statement' => $query->sql(),
			'insert_id'     => 0,
			'insert_table'  => $excluded_insert_table,
		);

		$excluded_object = Excluded_Object::create( $data )->save();
		if ( is_wp_error( $excluded_object ) ) {
			return $query;
		}

		// Store object so our custom db provider can mark the INSERT ID after execution
		$query->set_excluded_object( $excluded_object );

		return $query;
	}

	/**
	 * Check if the statement is an internal query
	 *
	 * @param SQL_Query $query
	 *
	 * @return bool
	 */
	protected function is_internal_query( $query ) {
		$internal_tables = $this->mode->get_tables();

		$internal_strings = array(
			'\'' . $this->bot->slug() . '_settings' . '\'',
			'\'' . $this->bot->slug() . '_process_lock' . '\'',
			'\'' . $this->bot->slug() . '_changes_recorded' . '\'',
			'\'' . $this->bot->slug() . '_recorded_query' . '\'',
			'\'' . $this->bot->slug() . '_dismissed_reminder' . '\'',
			'\'' . $this->bot->slug() . '_deployment_' . Config::MODE_DEV . '_',
			'\'' . $this->bot->slug() . '_deployment_' . Config::MODE_PROD . '_',
			$this->bot->slug() . '_changeset_handler_close',
			$this->bot->slug() . '_send_queries',
			$this->bot->slug() . '_schema_',
			$this->bot->slug() . '_query_limit',
			'_transient_' . $this->bot->slug() . '_',
			'_transient_timeout_' . $this->bot->slug() . '_',
			$this->bot->slug() . '_notices_' . md5( network_home_url() ),
			$this->bot->slug() . '_dismissed_notices_' . md5( network_home_url() ),
			'#' . $this->bot->slug(),
		);

		$internal_strings = array_merge( $internal_tables, $internal_strings );

		return Query_Helper::sql_contains_excluded_queries( $query->sql(), $internal_strings );
	}

	/**
	 * Should we ignore the query?
	 *
	 * @param SQL_Query $query
	 *
	 * @return bool
	 */
	protected function ignore_query( $query ) {
		$ignore = $this->is_ignored_query( $query );

		return (bool) apply_filters( $this->bot->slug() . '_ignore_query', $ignore, $query );
	}

	/**
	 * Is the query one defined to be ignored?
	 *
	 * @param SQL_Query $query
	 *
	 * @return bool
	 */
	protected function is_ignored_query( $query ) {
		$ignored_queries = $this->schema_handler->get_ignored_queries();
		if ( empty( $ignored_queries ) ) {
			return false;
		}

		$table_key = $this->get_ignored_query_table_key( $query, $ignored_queries );
		if ( false === $table_key ) {
			return false;
		}

		$queries = $ignored_queries[ $table_key ];

		return Query_Helper::sql_contains_excluded_queries( $query->sql(), $queries );
	}

	/**
	 * Get the table key to use to search for ignored queries for it.
	 *
	 * @param SQL_Query $query
	 * @param array     $ignored_queries
	 *
	 * @return bool|string
	 */
	protected function get_ignored_query_table_key( $query, $ignored_queries ) {
		global $wpdb;
		$table = $wpdb->base_prefix . $query->table();

		if ( isset( $ignored_queries[ $table ] ) ) {
			return $table;
		}

		return $this->table_match( $ignored_queries, $query->table() );
	}

	/**
	 * Does the query table have a partial match in the ignored queries array.
	 *
	 * @param array  $ignored_queries
	 * @param string $table
	 *
	 * @return bool
	 */
	protected function table_match( $ignored_queries, $table ) {
		foreach ( $ignored_queries as $ignored_table => $ignored_query ) {
			$parts = explode( ':', $ignored_table );
			if ( $parts[0] === $table && count( $parts ) == 2 ) {
				// Find table in the ignore format of table:column
				return $ignored_table;
			}

			if ( Query_Helper::contains( $table, $ignored_table ) ) {
				// Regex match
				return $ignored_table;
			}
		}

		return false;
	}

	/**
	 * Is the query a DELETE of a recently excluded INSERT statement?
	 *
	 * @param SQL_Query $query
	 *
	 * @return bool
	 */
	protected function is_delete_for_excluded_insert( SQL_Query $query ) {
		// Parse SQL to get table and find if where clause is using PK column
		if ( false === ( $query_data = $query->parse() ) ) {
			return false;
		}

		$tables = $query_data->get_tables_affected_by_statement();

		$is_delete = false;

		foreach ( $tables as $table => $alias ) {
			$where = $query_data->get_pk_where_clause();
			if ( empty( $where ) ) {
				continue;
			}

			$table_name = Query_Helper::strip_prefix_from_table( $alias );

			$column = key( $where );
			if ( count( $where ) > 1 || false === $this->schema_handler->is_pk_column( $column, $table_name ) ) {
				// The WHERE needs to be just targeting the PK column only
				continue;
			}

			// Handle WHERE IN clause, e.g. WHERE meta_id IN (100,101)
			$ids = $where[ $column ];
			if ( ! is_array( $ids ) ) {
				$ids = array( $ids );
			}

			foreach ( $ids as $id ) {
				// Search for it in excluded objects table
				$excluded_insert = Excluded_Object::search( $id, $table_name );

				if ( is_null( $excluded_insert ) ) {
					// Can't find an excluded parent INSERT
					continue;
				}

				// Clear object from table
				$excluded_insert->delete();

				$is_delete = true;
			}
		}

		return $is_delete;
	}

	/**
	 * Get all the rows affected by a query and record
	 * the existing data for comparison later.
	 *
	 * @param SQL_Query $query
	 *
	 * @return void
	 */
	protected function record_affected_rows( SQL_Query $query ) {
		// Parse the SQL query
		if ( false === ( $query_data = $query->parse() ) ) {
			return;
		}

		// Get all the tables that the query affects
		// This is to cope with multiple tables writes in statements
		$tables = $query_data->get_tables_affected_by_statement();

		foreach ( $tables as $table => $alias ) {
			// Get the from / join and where clauses and prepend SELECT
			$rows = $query->rows( $alias );
			if ( empty( $rows ) ) {
				continue;
			}

			$table_name = Query_Helper::strip_prefix_from_table( $table );
			foreach ( $rows as $row ) {
				$data = array(
					'query_id'   => $query->recorded_query->id(),
					'table_name' => $table_name,
					'data'       => serialize( $row ),
				);

				Data::create( $data )->save();
			}
		}
	}

	/**
	 * Should we mark query as processed so can be sent to app?
	 *
	 * @param string $type
	 * @param string $table
	 * @param int    $insert_id
	 *
	 * @return bool
	 */
	public function should_mark_as_processed( $type, $table, $insert_id = 0 ) {
		if ( 'INSERT' !== $type ) {
			// Not an insert, process
			return true;
		}

		if ( ! $this->schema_handler->table_has_auto_increment_column( $table ) ) {
			// Table has no AUTO INCREMENT column, we don't need to wait for LAST_INSERT_ID().
			return true;
		}

		if ( 0 != $insert_id ) {
			// INSERT ID already populated, process
			return true;
		}

		// Waiting on INSERT id, hold off
		return false;
	}
}
