<?php

/**
 * Extends the wpdb class
 *
 * @since 0.1
 */

namespace DeliciousBrains\Mergebot\Providers;

use DeliciousBrains\Mergebot\Services\Development\SQL_Creator;
use DeliciousBrains\Mergebot\Services\Development\SQL_Parser;
use DeliciousBrains\Mergebot\Models\SQL_Query;

class Db extends Db_Facade {

	/**
	 * Meta tables and columns that are UNIQUE indexed.
	 *
	 * @var Array keyed by table name with column as the value.
	 */
	public $unique_meta_tables = array();

	/**
	 * @var array
	 */
	public $allowed_types = array(
		'INSERT INTO'                => 'INSERT',
		'INSERT IGNORE INTO'         => 'INSERT',
		'UPDATE'                     => 'UPDATE',
		'DELETE FROM'                => 'DELETE',
		'CREATE TABLE IF NOT EXISTS' => 'CREATE_TABLE',
		'CREATE TABLE'               => 'CREATE_TABLE',
		'ALTER IGNORE TABLE'         => 'ALTER_TABLE',
		'ALTER TABLE'                => 'ALTER_TABLE',
		'DROP TABLE IF EXISTS'       => 'DROP_TABLE',
		'DROP TABLE'                 => 'DROP_TABLE',
		'RENAME TABLE'               => 'RENAME_TABLE',
		'TRUNCATE TABLE'             => 'TRUNCATE_TABLE',
		'CREATE INDEX'               => 'CREATE_INDEX',
		'CREATE UNIQUE INDEX'        => 'CREATE_INDEX',
		'CREATE FULLTEXT INDEX'      => 'CREATE_INDEX',
		'CREATE SPATIAL INDEX'       => 'CREATE_INDEX',
		'DROP INDEX'                 => 'DROP_INDEX',
	);

	/**
	 * @var int
	 */
	protected $original_time_limit;

	/**
	 * @var bool
	 */
	public $suppress_ignore_query_check = false;

	/**
	 * Overrides the query method so we can hook in after execution
	 *
	 * @param string $sql
	 *
	 * @return int|false
	 */
	public function query( $sql ) {
		$query = new SQL_Query( $sql, new SQL_Parser(), new SQL_Creator( $this->prefix, $this->unique_meta_tables ) );

		// Make sure Mergebot processes have enough time to complete.
		$this->maybe_set_time_limit( 300 );

		$query = apply_filters( 'mergebot_before_query', $query );

		if ( false === $query ) {
			return false;
		}

		$result = parent::query( $sql );

		if ( false === $result ) {
			apply_filters( 'mergebot_after_query_fail', $query );

			return $result;
		}

		apply_filters( 'mergebot_after_query_success', $query );

		// Reset the time out.
		$this->maybe_set_time_limit();

		return $result;
	}

	/**
	 * Get the last inserted ID
	 *
	 * @return int|string
	 */
	public function get_insert_id() {
		if ( $this->use_mysqli ) {
			$insert_id = mysqli_insert_id( $this->dbh );
		} else {
			$insert_id = mysql_insert_id( $this->dbh );
		}

		return $insert_id;
	}

	/**
	 * Attempt to set the time out so we don't whitescreen whilst processing queries.
	 *
	 * @param null|int $limit
	 *
	 * @return bool
	 */
	protected function maybe_set_time_limit( $limit = null ) {
		if ( ini_get( 'safe_mode' ) ) {
			return false;
		}

		if ( ! function_exists( 'set_time_limit' ) || ! function_exists( 'ini_get' ) ) {
			return false;
		}

		if ( ! is_null( $this->original_time_limit )) {
			// If we have recorded the original time limit, use it to reset it.
			$limit = $this->original_time_limit;
		}

		if ( ! is_null( $limit ) ) {
			// We are manually changing the time limit, so record the original for later.
			$this->original_time_limit = ini_get( 'max_execution_time' );
		}

		return @set_time_limit( $limit );
	}

	/**
	 * Get all the prefixed tables in the database.
	 *
	 * @param bool $prefixed
	 *
	 * @return array
	 */
	public function get_all_tables( $prefixed = true ) {
		$all_tables = $this->get_results( 'SHOW TABLES' );
		$tables     = array();
		foreach ( $all_tables as $table ) {
			$table = (array) $table;
			$table = current( $table );

			if ( $prefixed && 0 !== strpos( $table, $this->base_prefix ) ) {
				continue;
			}

			$tables[] = $table;
		}

		return $tables;
	}

}