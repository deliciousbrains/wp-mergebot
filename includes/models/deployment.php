<?php

/**
 * The Deployment Model class representing the object for the changeset deployment
 *
 * @since 0.1
 */

namespace DeliciousBrains\Mergebot\Models;

class Deployment extends Base_Model {

	/**
	 * @var Changeset
	 */
	protected $changeset;

	/**
	 * @var int
	 */
	protected $changeset_id;

	/**
	 * @var
	 */
	protected $file;

	/**
	 * @var object
	 */
	protected $script;

	/**
	 * Create a new model with a changeset
	 *
	 * @param Changeset $changeset
	 *
	 * @return static
	 */
	public static function make( $changeset ) {
		$data = array(
			'changeset'    => $changeset,
			'changeset_id' => $changeset->changeset_id,
		);

		return parent::create( $data );
	}
}
