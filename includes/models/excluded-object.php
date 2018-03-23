<?php

/**
 * The Excluded_Object Model class
 *
 * @since 0.1
 */

namespace DeliciousBrains\Mergebot\Models;

class Excluded_Object extends Abstract_Database_Model {

	protected static $table_name = 'excluded_objects';

	/**
	 * Find for an object with ID and table
	 *
	 * @param int    $id
	 * @param string $table
	 *
	 * @return Excluded_Object|null
	 */
	public static function search( $id, $table ) {
		$object = static::query()->where( 'insert_id', $id )->where( 'insert_table', $table )->find();

		if ( $object ) {
			return $object[0];
		}

		return null;
	}

}