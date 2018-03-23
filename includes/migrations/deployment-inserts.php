<?php

/**
 * The custom database table class to hold the data of a row before a change.
 *
 * @since 0.1
 */

namespace DeliciousBrains\Mergebot\Migrations;

class Deployment_Inserts extends Abstract_Migration {

	/**
	 * @var string Table version
	 */
	protected $version = '0.2';

	/**
	 * Get columns and formats
	 *
	 * @return array
	 */
	public function get_columns() {
		return array(
			'id'                  => array( 'format' => '%d', 'length' => 20 ),
			'changeset_id'        => array( 'format' => '%d', 'length' => 20 ),
			'deployment_id'       => array( 'format' => '%d', 'length' => 20 ),
			'query_id'            => array( 'format' => '%d', 'length' => 20 ),
			'deployed_site_id'    => array( 'format' => '%d', 'length' => 20 ),
			'deployed_insert_id'  => array( 'format' => '%d', 'length' => 20 ),
			'is_on_duplicate_key' => array( 'format' => '%d' ),
		);
	}
}