<?php

/**
 * The Conflict Model class
 *
 * @since 0.1
 */

namespace DeliciousBrains\Mergebot\Models;

class Conflict extends Abstract_Database_Model {

	protected static $table_name = 'conflicts';

	/**
	 * Fetch unsent conflicts for a changeset with a limit
	 *
	 * @param int $changeset_id
	 * @param int $limit
	 *
	 * @return array|null
	 */
	public static function fetch_unsent( $changeset_id, $limit ) {
		return static::query()->where( 'changeset_id', $changeset_id )->where( 'sent', 0 )->limit( $limit )->find();
	}

	/**
	 * Return total of unsent conflicts for a changeset
	 *
	 * @param int $changeset_id
	 *
	 * @return int
	 */
	public static function total_unsent( $changeset_id ) {
		return static::query()->where( 'changeset_id', $changeset_id )->where( 'sent', 0 )->find( true );
	}
}