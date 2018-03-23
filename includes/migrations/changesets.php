<?php

/**
 * The custom database table migration class for changesets
 *
 * @since 0.1
 */

namespace DeliciousBrains\Mergebot\Migrations;

class Changesets extends Abstract_Migration {

	/**
	 * @var string Table version
	 */
	protected $version = '0.4';

	/**
	 * Get columns and formats
	 *
	 * @return array
	 */
	public function get_columns() {
		return array(
			'id'                => array( 'format' => '%d', 'length' => 20 ),
			'changeset_id'      => array( 'format' => '%d', 'length' => 20 ),
			'link'              => array( 'format' => '%s', 'length' => 255, 'meta' => true ),
			'total_queries'     => array( 'format' => '%d', 'length' => 20, 'meta' => true ),
			'queries_link'      => array( 'format' => '%s', 'length' => 255, 'meta' => true ),
			'date_created'      => array( 'format' => '%s', 'type' => 'datetime' ),
			'deployed'          => array( 'format' => '%d' ),
			'can_deploy_errors' => array( 'format' => '%s', 'meta' => true ),
		);
	}
}