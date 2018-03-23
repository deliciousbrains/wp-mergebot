<?php

/**
 * The custom database table class to hold the data of a row before a change.
 *
 * @since 0.1
 */

namespace DeliciousBrains\Mergebot\Migrations;

use DeliciousBrains\Mergebot\Utils\Config;

class Data extends Abstract_Migration {

	/**
	 * @var string Table version
	 */
	protected $version = '0.2';

	/**
	 * @var string Only for the Dev mode
	 */
	protected $mode = Config::MODE_DEV;

	/**
	 * Get columns and formats
	 *
	 * @return array
	 */
	public function get_columns() {
		return array(
			'id'         => array( 'format' => '%d', 'length' => 20 ),
			'query_id'   => array( 'format' => '%d', 'length' => 20 ),
			'table_name' => array( 'format' => '%s', 'length' => 255 ),
			'data'       => array( 'format' => '%s' ),
		);
	}
}