<?php

/**
 * The Query Model class
 *
 * @since 0.1
 */

namespace DeliciousBrains\Mergebot\Models;

class Query extends Abstract_Database_Model {

	protected static $table_name = 'queries';

	/**
	 * Get all queries that have been processed
	 *
	 * @return mixed
	 */
	public static function total_processed() {
		return static::query()->where( 'processed', 1 )->total_count();
	}

	/**
	 * Get a batch of processed queries before an unprocessed query
	 *
	 * @param int $limit
	 *
	 * @return mixed
	 */
	public static function processed_batch( $limit ) {
		$unprocessed = static::query()->where( 'processed', 0 )->limit( 1 )->find();

		if ( empty( $unprocessed ) ) {
			return static::query()->where( 'processed', 1 )->limit( $limit )->find();
		}

		return static::query()->where( 'processed', 1 )->where_lt( static::$pk_column, $unprocessed[0]->id )->limit( $limit )->find();
	}

	/**
	 * Does the first query in the table unprocessed or has an app error?
	 *
	 * @return bool
	 */
	public static function is_blocked_query() {
		$query = static::first();
		if ( ! $query ) {
			return false;
		}

		if ( ! (bool) $query->processed || $query->app_error ) {
			return true;
		}

		return false;
	}

	/**
	 * Get all unprocessed queries blocking sending processed ones to the app
	 *
	 * @return mixed
	 */
	public static function fetch_unprocessed_blocking() {
		$processed = static::query()->where( 'processed', 1 )->limit( 1 )->find();

		if ( empty( $processed ) ) {
			return static::query()->where( 'processed', 0 )->find();
		}

		return static::query()->where( 'processed', 0 )->where_lt( static::$pk_column, $processed[0]->id )->find();
	}

	/**
	 * Delete rows from the queries and data tables.
	 *
	 * @return bool
	 */
	public function clear() {
		Data::delete_by( 'query_id', $this->id );
		return $this->delete();
	}

}