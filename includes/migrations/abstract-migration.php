<?php

/**
 * The custom database table migration class for changesets to be deployed.
 *
 * @since 0.1
 */

namespace DeliciousBrains\Mergebot\Migrations;

abstract class Abstract_Migration implements Migration_Interface {

	/**
	 * @var string Current version of the table
	 */
	protected $version;

	/**
	 * @var string The plugin mode the table is exclusively needed for
	 */
	protected $mode;

	/**
	 * Get table version
	 *
	 * @return string
	 */
	public function get_version() {
		return $this->version;
	}

	/**
	 * Get the table name
	 *
	 * @param string $prefix
	 *
	 * @return string
	 */
	public function get_table_name( $prefix = '' ) {
		$table_name = get_called_class();
		$parts      = explode( '\\', $table_name );
		$table_name = array_pop( $parts );
		$table_name = $prefix . strtolower( $table_name );

		return $table_name;
	}

	/**
	 * Get the create table statement
	 *
	 * @param string $prefix
	 * @param string $collation
	 *
	 * @return string
	 */
	public function get_create_table_statement( $prefix = '', $collation = '' ) {
		$columns = $this->get_columns();
		reset( $columns );
		$pk  = key( $columns );
		$sql = "CREATE TABLE " . $this->get_table_name( $prefix ) . " (";

		foreach ( $columns as $column => $data ) {
			$sql .= $this->get_column_sql( $column, $data );
			$sql .= ( $column === $pk ) ? ' AUTO_INCREMENT, ' : ', ';
			$sql .= "\n";
		}

		$sql .= "PRIMARY KEY (" . $pk . ")" . "\n";
		$sql .= ") " . $collation;

		return $sql;
	}

	/**
	 * Generate the column string for the CREATE TABLE sql
	 *
	 * @param string $name
	 * @param array  $column
	 *
	 * @return string
	 */
	protected function get_column_sql( $name, $column ) {
		$type = 'text';

		if ( isset( $column['type'] ) ) {
			$type = $column['type'];
		} else if ( '%d' === $column['format'] ) {
			$type = isset( $column['length'] ) ? 'bigint(' . $column['length'] . ')' : 'int(11)';
		} else if ( '%s' === $column['format'] ) {
			$type = isset( $column['length'] ) ? 'varchar(' . $column['length'] . ')' : 'longtext';
		}

		return $name . ' ' . $type . ' NOT NULL';
	}

	/**
	 * Is the table allowed for the mode?
	 *
	 * @param string $mode
	 *
	 * @return bool
	 */
	public function allowed_for_mode( $mode ) {
		if ( ! is_null( $this->mode ) && $mode !== $this->mode ) {
			// Not a table for this mode;
			return false;
		}

		return true;
	}
}