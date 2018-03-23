<?php

namespace DeliciousBrains\Mergebot\Models;

interface Database_Model_Interface {

	/**
	 * Save the instance to the database
	 *
	 * @return mixed
	 */
	public function save();

	/**
	 * Delete the instance from the database
	 *
	 * @return mixed
	 */
	public function delete();

	/**
	 * Fetch all instances from the database
	 *
	 * @return mixed
	 */
	public static function all();

	/**
	 * Fetch an instance from the database by ID
	 *
	 * @return mixed
	 */
	public static function find( $id );
}