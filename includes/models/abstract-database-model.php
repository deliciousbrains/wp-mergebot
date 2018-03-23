<?php

/**
 * The base Model class
 *
 * @since 0.1
 */

namespace DeliciousBrains\Mergebot\Models;

use DeliciousBrains\Mergebot\Models\ORM\Query_Builder;
use DeliciousBrains\Mergebot\Utils\Error;

abstract class Abstract_Database_Model extends Base_Model implements Database_Model_Interface {

	/**
	 * @var string
	 */
	protected static $table_name;

	/**
	 * @var array
	 */
	protected $columns;

	/**
	 * @var string
	 */
	protected static $pk_column = 'id';

	/**
	 * Database_Model constructor.
	 *
	 * @param array $properties
	 *
	 * @throws \Exception
	 */
	public function __construct( array $properties = array() ) {
		if ( ! isset( static::$table_name ) ) {
			// Ensure the table_name property is defined in the calling class
			throw new \Exception( 'Child class ' . get_called_class() . ' failed to define static table_name property' );
		}

		$migration_name  = str_replace( '-', ' ', static::$table_name );
		$migration_name  = str_replace( ' ', '_', ucwords( $migration_name ) );
		$migration_class = 'DeliciousBrains\\Mergebot\\Migrations\\' . $migration_name;
		$migration       = new $migration_class;
		$this->columns   = $migration->get_columns();

		parent::__construct( $properties );
	}

	/**
	 * Get the name of the table as it is in the database
	 *
	 * @return string
	 */
	public static function get_table() {
		global $wpdb;

		// Get the table name
		return $wpdb->base_prefix . 'mergebot_' . static::$table_name;
	}

	/**
	 * Set the properties of the model from an array using the columns whitelist
	 *
	 * @param array $properties
	 */
	protected function set_properties( $properties ) {
		$properties = array_intersect_key( $properties, $this->columns );
		foreach ( $properties as $property => $value ) {
			$this->{$property} = $value;
		}
	}

	/**
	 * Get the properties of the model using the columns whitelist
	 *
	 * @return array
	 */
	protected function properties() {
		$properties = parent::properties();

		return array_intersect_key( $properties, $this->columns );
	}

	/**
	 * Get a property for the model
	 *
	 * @param string $key
	 *
	 * @return bool|mixed
	 */
	public function __get( $key ) {
		if ( ! isset( $this->columns[ $key ] ) ) {
			return false;
		}

		return parent::__get( $key );
	}

	/**
	 * Set a property for the model
	 *
	 * @param string $key
	 * @param mixed  $value
	 */
	public function __set( $key, $value ) {
		if ( ! isset( $this->columns[ $key ] ) ) {
			return;
		}

		return parent::__set( $key, $value );
	}

	/**
	 * Get an array of formats for data provided
	 *
	 * @param array $data
	 *
	 * @return array
	 */
	protected function get_formats( $data ) {
		// Initialise column format array
		$column_formats = $this->get_column_formats();

		// Force fields to lower case
		$data = array_change_key_case( $data );

		// White list columns
		$data           = array_intersect_key( $data, $column_formats );
		$column_formats = array_intersect_key( $column_formats, $data );

		// Reorder $column_formats to match the order of columns given in $data
		$data_keys      = array_keys( $data );
		$column_formats = array_merge( array_flip( $data_keys ), $column_formats );

		return $column_formats;
	}

	/**
	 * Get column formats
	 *
	 * @return array
	 */
	protected function get_column_formats() {
		$columns = $this->columns;
		$formats = wp_list_pluck( $columns, 'format' );

		return $formats;
	}

	/**
	 * Get the columns of the table that hold meta data about the model
	 *
	 * @return array
	 */
	public function get_meta_columns() {
		$meta    = array();
		$columns = $this->columns;

		foreach ( $columns as $column => $data ) {
			if ( isset( $data['meta'] ) && $data['meta'] ) {
				$meta[] = $column;
			}
		}

		return $meta;
	}

	/**
	 * Save the model
	 *
	 * @return bool|Abstract_Database_Model|Error
	 */
	public function save() {
		$properties = $this->properties();

		if ( ! isset( $properties[ static::$pk_column ] ) || is_null( $properties[ static::$pk_column ] ) ) {
			return $this->_insert( $properties );
		}

		return $this->_update_by_id( $properties );
	}

	/**
	 * Insert the model into the table
	 *
	 * @param array $properties
	 *
	 * @return $this|Error
	 */
	protected function _insert( $properties ) {
		global $wpdb;

		// Store the last insert ID
		$last_insert_id = $wpdb->insert_id;

		// Get the formats for the data
		$column_formats = $this->get_formats( $properties );

		// Perform the insert
		$rows = $wpdb->insert( static::get_table(), $properties, $column_formats );

		if ( false === $rows ) {
			// Reset the real last insert ID
			$wpdb->insert_id = $last_insert_id;

			return new Error( Error::$DB_insertFailed, sprintf( __( 'Insert to %s failed' ), static::get_table() ), $properties );
		}

		// Store this insert id
		$insert_id = $wpdb->insert_id;

		$this->{static::$pk_column} = $insert_id;

		// Reset the real last insert ID
		$wpdb->insert_id = $last_insert_id;

		return $this;
	}

	/**
	 * Update model by PK Id
	 *
	 * @param array $properties
	 *
	 * @return bool|Error
	 */
	protected function _update_by_id( $properties ) {
		return static::_update( $properties, static::$pk_column, $this->{static::$pk_column} );
	}

	/**
	 * Update the model in the table
	 *
	 * @param array  $properties
	 * @param string $column
	 * @param mixed  $value
	 *
	 * @return bool|Error
	 */
	protected static function _update( $properties, $column, $value ) {
		global $wpdb;

		// Make sure we aren't updating the PK column
		unset( $properties[ static::$pk_column ] );

		$result = $wpdb->update( static::get_table(), $properties, array( $column => $value ) );

		if ( $result ) {
			return true;
		}

		return new Error( Error::$DB_updateFailed, sprintf( __( 'Update to %s failed' ), static::get_table() ), $properties );
	}

	/**
	 * Delete the model
	 *
	 * @return bool
	 */
	public function delete() {
		return self::delete_by( static::$pk_column, $this->{static::$pk_column} );
	}

	/**
	 * Delete the model by a given property value
	 *
	 * @param string $property
	 * @param mixed  $value
	 *
	 * @return bool
	 */
	public static function delete_by( $property, $value ) {
		global $wpdb;

		return (bool ) $wpdb->delete( static::get_table(), array( $property => $value ) );
	}

	/**
	 * Delete models from the table
	 *
	 * @return bool
	 */
	public static function delete_all() {
		global $wpdb;

		$table = static::get_table();

		return ( bool ) $wpdb->query( "DELETE FROM {$table}" );
	}

	/**
	 * Return EVERY instance of this model from the database, with NO filtering.
	 *
	 * @return array
	 */
	public static function all() {
		global $wpdb;
		// Get the table name
		$table = static::get_table();
		// Get the items
		$results = $wpdb->get_results( "SELECT * FROM `{$table}`" );
		foreach ( $results as $index => $result ) {
			$results[ $index ] = static::create( (array) $result );
		}

		return $results;
	}

	/**
	 * Find a specific model by a given property value.
	 *
	 * @param  string $property
	 * @param  string $value
	 *
	 * @return false|static
	 */
	public static function find_by( $property, $value ) {
		global $wpdb;
		// Escape the value
		$value = esc_sql( $value );
		// Get the table name
		$table = static::get_table();
		// Get the item
		$object = $wpdb->get_row( "SELECT * FROM `{$table}` WHERE `{$property}` = '{$value}'", ARRAY_A );

		if ( $object ) {
			return static::create( $object );
		}

		return false;
	}

	/**
	 * Find a specific model by it's unique ID.
	 *
	 * @param  integer $id
	 *
	 * @return false|static
	 */
	public static function find( $id ) {
		return static::find_by( static::$pk_column, (int) $id );
	}

	/**
	 * Find the first model in the table
	 *
	 * @return false|static
	 */
	public static function first() {
		$first = static::query()->order( Query_Builder::ORDER_ASCENDING )->limit( 1 )->find();

		if ( ! empty( $first ) ) {
			return $first[0];
		}

		return false;
	}

	/**
	 * Find the last model in the table
	 *
	 * @return false|static
	 */
	public static function last() {
		$last = static::query()->order( Query_Builder::ORDER_DESCENDING )->limit( 1 )->find();

		if ( ! empty( $last ) ) {
			return $last[0];
		}

		return false;
	}

	/**
	 * Get the total rows in table
	 *
	 * @return int
	 */
	public static function total() {
		return static::query()->total_count();
	}

	/**
	 * Start a query to find models matching specific criteria.
	 *
	 * @return Query
	 */
	public static function query() {
		$query = new Query_Builder( get_called_class() );
		$query->set_searchable_fields( array() );
		$query->set_primary_key( static::$pk_column );

		return $query;
	}
}