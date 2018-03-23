<?php

/**
 * The Deployment_Insert Model class
 *
 * @since 0.1
 */

namespace DeliciousBrains\Mergebot\Models;

class Deployment_Insert extends Abstract_Database_Model {

	protected static $table_name = 'deployment_inserts';

	/**
	 * Get all deployment IDs for a changeset
	 *
	 * @param int $changeset_id
	 *
	 * @return array
	 */
	public static function all_for_changeset( $changeset_id ) {
		return static::query()->where( 'changeset_id', $changeset_id )->find( false, true );
	}
}