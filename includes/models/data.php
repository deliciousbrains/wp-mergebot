<?php

/**
 * The Data Model class
 *
 * @since 0.1
 */

namespace DeliciousBrains\Mergebot\Models;

class Data extends Abstract_Database_Model {

	protected static $table_name = 'data';

	/**
	 * Get all data rows for a query
	 *
	 * @param int $query_id
	 *
	 * @return null|array
	 */
	public static function all_for_query( $query_id ) {
		return static::query()->where( 'query_id', $query_id )->find();
	}
}