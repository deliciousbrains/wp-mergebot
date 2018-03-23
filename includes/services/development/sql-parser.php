<?php

/**
 * The SQL Parser class to parse a SQL statement
 *
 * This class interfaces to the SQL parsing library so we can breakdown parts of statements.
 *
 * @since 0.1
 */

namespace DeliciousBrains\Mergebot\Services\Development;

use DeliciousBrains\Mergebot\Utils\Error;
use DeliciousBrains\Mergebot\Utils\Query_Helper;
use PHPSQLParser\PHPSQLParser;

class SQL_Parser extends PHPSQLParser {

	/**
	 * Return the SQL query in parsed array format
	 *
	 * @param string $sql
	 * @param bool   $calculate_positions
	 *
	 * @return bool
	 */
	public function parse( $sql, $calculate_positions = false ) {
		try {
			$sql = $this->prepare_sql_for_parse( $sql );

			parent::parse( $sql, $calculate_positions );
		} catch ( \Exception $e ) {
			$error_data = array(
				'error'  => $e->getMessage(),
				'parsed' => $sql,
			);

			new Error( Error::$SQL_parseFailed, __( 'Error parsing SQL statement' ), $error_data );

			return false;
		}

		return $this->parsed;
	}

	/**
	 * Ensure the SQL string will get parsed successfully
	 *
	 * @param string $sql
	 *
	 * @return string
	 */
	protected function prepare_sql_for_parse( $sql ) {
		if ( Query_Helper::contains( $sql, 'ON DUPLICATE KEY', false ) ) {
			// Parser library can't handle ON DUPLICATE KEY statements
			return substr( $sql, 0, strpos( $sql, ' ON DUPLICATE KEY' ) );
		}

		return $sql;
	}

	/**
	 * Is the statement an UPDATE?
	 *
	 * @return bool
	 */
	public function is_update() {
		return isset( $this->parsed['UPDATE'] );
	}

	/**
	 * Is the statement an DELETE?
	 *
	 * @return bool
	 */
	public function is_delete() {
		return isset( $this->parsed['DELETE'] );
	}

	/**
	 * Is the statement an INSERT?
	 *
	 * @return bool
	 */
	public function is_insert() {
		return isset( $this->parsed['INSERT'] );
	}

	/**
	 * Wrapper to find which tables will an UPDATE or DELETE query affect
	 *
	 * @return array
	 */
	public function get_tables_affected_by_statement() {
		$tables = array();

		if ( $this->is_update() ) {
			$tables = $this->get_tables_affected_by_update();
		} else if ( $this->is_delete() ) {
			$tables = $this->get_tables_affected_by_delete();
		} else if ( $this->is_insert() ) {
			$tables = $this->get_tables_affected_by_insert();
		}

		return $tables;
	}

	/**
	 * Get the tables that are affected by the SET clause of an UPDATE
	 *
	 * @return array
	 */
	protected function get_tables_affected_by_update() {
		$tables = array();

		$all_tables = $this->get_statement_tables( 'UPDATE' );

		if ( 1 === count( $all_tables ) ) {
			// No JOINS, only one table in question without alias
			$alias            = end( $all_tables );
			$table            = $all_tables[ $alias ];
			$tables[ $table ] = $alias;

			return $tables;
		}

		// Get all tables affected by SET, matched on alias
		foreach ( $this->parsed['SET'] as $clause ) {
			$alias = $clause['sub_tree'][0]['no_quotes']['parts'][0];

			if ( isset( $all_tables[ $alias ] ) ) {
				$table = $all_tables[ $alias ];

				$tables[ $table ] = $alias;
			}
		}

		return $tables;
	}

	/**
	 * Get the tables that will have rows deleted by a DELETE
	 */
	protected function get_tables_affected_by_delete() {
		$tables = array();

		$all_tables = $this->get_statement_tables();

		if ( false === $this->parsed['DELETE']['tables'] ) {
			// Only one table used in DELETE
			// No JOINS, only one table in question without alias
			$alias            = end( $all_tables );
			$table            = $all_tables[ $alias ];
			$tables[ $table ] = $alias;

			return $tables;
		}

		// Get all tables affected by DELETE, matched on alias
		foreach ( $this->parsed['DELETE']['tables'] as $alias ) {
			$alias = str_replace( '`', '', $alias );
			if ( isset( $all_tables[ $alias ] ) ) {
				$table = $all_tables[ $alias ];

				$tables[ $table ] = $alias;
			}
		}

		return $tables;
	}

	/**
	 * Get the tables that will have rows deleted by a DELETE
	 */
	protected function get_tables_affected_by_insert() {
		$tables = array();

		$all_tables = $this->get_statement_tables( 'INSERT' );

		// You can only INSERT to one table
		$alias            = end( $all_tables );
		$table            = $all_tables[ $alias ];
		$tables[ $table ] = $alias;

		return $tables;
	}

	/**
	 * Get all the tables used by a statement
	 *
	 * @param string $clause_type
	 *
	 * @return array
	 */
	protected function get_statement_tables( $clause_type = 'FROM' ) {
		$all_tables = array();

		foreach ( $this->parsed[ $clause_type ] as $clause ) {
			if ( 'table' === $clause['expr_type'] ) {
				$table = str_replace( '`', '', $clause['table'] );
				$alias = $table;
				if ( isset( $clause['alias']['name'] ) ) {
					$alias = $clause['alias']['name'];
				}
				$all_tables[ $alias ] = $table;
			}
		}

		return $all_tables;
	}

	/**
	 * Get a section of the parsed statement
	 *
	 * @param string $key
	 *
	 * @return bool|mixed
	 */
	protected function parse_data( $key ) {
		if ( ! isset( $this->parsed[ $key ] ) || ! is_array( $this->parsed[ $key ] ) ) {
			return false;
		}

		return $this->parsed[ $key ];
	}

	/**
	 * Get the columns and values for a WHERE clause containing ID values
	 *
	 * @return array|bool
	 */
	public function get_pk_where_clause() {
		if ( false === ( $where_data = $this->parse_data( 'WHERE' ) ) ) {
			return false;
		}

		$data = array();

		foreach ( $where_data as $where_key => $where_item ) {
			if ( 'colref' === $where_item['expr_type'] ) {
				$column = str_replace( '`', '', $where_item['base_expr'] );
				$found  = true;
			}
			if ( in_array( $where_item['expr_type'], array( 'const', 'in-list' ) ) ) {
				if ( $found ) {
					if ( $where_item['expr_type'] == 'const' ) {
						$data[ $column ] = preg_replace( '/[^0-9]/', '', $where_item['base_expr'] );
					}
					if ( $where_item['expr_type'] == 'in-list' ) {
						foreach ( $where_item['sub_tree'] as $in_list_key => $in_list ) {
							$data[ $column ][] = preg_replace( '/[^0-9]/', '', $in_list['base_expr'] );
						}
					}
				}
				$column = '';
				$found  = false;
			}
		}

		return $data;
	}

	/**
	 * Get an array of column => value data for an INSERT
	 *
	 * @param bool $formatted
	 *
	 * @return array|bool
	 */
	public function get_parsed_insert_data( $formatted = true ) {
		if ( ! isset( $this->parsed['INSERT'] ) ) {
			return false;
		}

		if ( false === ( $columns = $this->get_parsed_insert_columns() ) ) {
			return false;
		}

		if ( false === ( $values = $this->get_parsed_insert_values( $formatted ) ) ) {
			return false;
		}

		$data = array();
		foreach ( $columns as $i => $column ) {
			if ( ! isset( $values[ $i ] ) ) {
				// Can't match value for the column
				return false;
			}
			$data[ $column ] = $values[ $i ];
		}

		return $data;
	}

	/**
	 * Get all the columns for an INSERT
	 *
	 * @return array|bool
	 */
	protected function get_parsed_insert_columns() {
		if ( false === ( $insert_data = $this->parse_data( 'INSERT' ) ) ) {
			return false;
		}

		$columns = array();

		foreach ( $insert_data as $data ) {
			if ( 'column-list' !== $data['expr_type'] ) {
				continue;
			}

			if ( ! isset( $data['sub_tree'] ) ) {
				return false;
			}

			foreach ( $data['sub_tree'] as $column ) {
				$columns[] = str_replace( '`', '', $column['base_expr'] );
			}
		}
		if ( empty( $columns ) ) {
			return false;
		}

		return $columns;
	}

	/**
	 * Get the VALUES from an INSERT statement.
	 *
	 * @return bool|mixed
	 */
	public function get_insert_values() {
		return $this->parse_data( 'VALUES' );
	}

	/**
	 * Get all the values for an INSERT
	 *
	 * @param bool $formatted
	 *
	 * @return array|bool
	 */
	protected function get_parsed_insert_values( $formatted = true ) {
		if ( false === ( $insert_values = $this->parse_data( 'VALUES' ) ) ) {
			return false;
		}

		$values = array();

		foreach ( $insert_values as $data ) {
			if ( 'record' !== $data['expr_type'] ) {
				continue;
			}

			if ( ! isset( $data['data'] ) ) {
				return false;
			}

			foreach ( $data['data'] as $value ) {
				$values[] = $formatted ? str_replace( "'", '', $value['base_expr'] ) : $value['base_expr'];
			}
		}

		if ( empty( $values ) ) {
			return false;
		}

		return $values;
	}
}