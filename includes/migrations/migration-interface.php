<?php

namespace DeliciousBrains\Mergebot\Migrations;

interface Migration_Interface {
	/**
	 * Get column data about the table
	 *
	 * @return array
	 */
	public function get_columns();
}