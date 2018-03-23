<?php

/**
 * The custom database table class for deployment conflict changes.
 *
 * @since 0.1
 */

namespace DeliciousBrains\Mergebot\Migrations;

class Conflicts extends Abstract_Migration {

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
			'id'           => array( 'format' => '%d', 'length' => 20 ),
			'changeset_id' => array( 'format' => '%d', 'length' => 20 ),
			'recording_id' => array( 'format' => '%d', 'length' => 20 ),
			'table_name'   => array( 'format' => '%s', 'length' => 255 ),
			'pk_column'    => array( 'format' => '%s', 'length' => 255 ),
			'pk_id'        => array( 'format' => '%s', 'length' => 255 ),
			'sent'         => array( 'format' => '%d' ),
			'blog_id'      => array( 'format' => '%d' ),
		);
	}
}