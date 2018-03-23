<?php

/**
 * The class that receives conflicts for a changeset and stores them in the plugin table
 *
 * @since 0.1
 */

namespace DeliciousBrains\Mergebot\Services;

use DeliciousBrains\Mergebot\Models\App_Changeset;
use DeliciousBrains\Mergebot\Models\Conflict;
use DeliciousBrains\Mergebot\Utils\Error;

class Conflict_Receiver {

	/**
	 * Take the conflicts for the changeset and save them to the plugin table
	 *
	 * @param App_Changeset $changeset
	 *
	 * @return bool
	 */
	public function receive( App_Changeset $changeset ) {
		if ( ! isset( $changeset->id ) ) {
			new Error( Error::$Changeset_noID, 'Changeset from app with no ID', array( 'changeset' => $changeset ) );

			return false;
		}

		if ( ! isset( $changeset->conflicts ) || ! is_array( $changeset->conflicts ) ) {
			new Error( Error::$Changeset_noConflicts, 'Changeset does not have conflicts property', array( 'changeset' => $changeset ) );

			return false;
		}

		// Clear old conflict data
		Conflict::delete_by( 'changeset_id', $changeset->id );

		// Insert new conflict data
		return $this->insert( $changeset );
	}

	/**
	 * Insert data about possible conflicts
	 *
	 * @param App_Changeset $changeset
	 *
	 * @return bool
	 */
	protected function insert( App_Changeset $changeset ) {
		foreach ( $changeset->conflicts as $conflict ) {
			$data = array(
				'changeset_id' => $changeset->id,
				'recording_id' => $conflict->recording_id,
				'blog_id'      => $conflict->blog_id,
				'table_name'   => $conflict->table,
				'pk_column'    => $conflict->columns,
				'pk_id'        => $conflict->values,
			);

			Conflict::create( $data )->save();
		}

		return true;
	}
}
