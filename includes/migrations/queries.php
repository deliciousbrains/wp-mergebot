<?php

/**
 * The custom database table class to hold the data of a row before a change.
 *
 * @since 0.1
 */

namespace DeliciousBrains\Mergebot\Migrations;

use DeliciousBrains\Mergebot\Utils\Config;

class Queries extends Abstract_Migration {

	/**
	 * @var string Table version
	 */
	protected $version = '0.4';

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
			'id'            => array( 'format' => '%d', 'length' => 20 ),
			'recording_id'  => array( 'format' => '%s', 'length' => 32 ),
			'type'          => array( 'format' => '%s', 'length' => 50 ),
			'insert_table'  => array( 'format' => '%s', 'length' => 255 ),
			'insert_id'     => array( 'format' => '%d', 'length' => 20 ),
			'sql_statement' => array( 'format' => '%s' ),
			'processed'     => array( 'format' => '%d' ),
			'date_recorded' => array( 'format' => '%s', 'type' => 'datetime' ),
			'blog_id'       => array( 'format' => '%d' ),
			'app_error'     => array( 'format' => '%s', 'length' => 255 ),
		);
	}
}