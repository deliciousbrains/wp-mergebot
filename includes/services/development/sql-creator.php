<?php

/**
 * The SQL SQL_Creator class to create a SQL statement from an array
 *
 * This class interfaces to the SQL creator library so we can breakdown parts of statements.
 *
 * @since 0.1
 */

namespace DeliciousBrains\Mergebot\Services\Development;

use DeliciousBrains\Mergebot\Utils\Error;
use PHPSQLParser\PHPSQLCreator;

class SQL_Creator extends PHPSQLCreator {

	/**
	 * @var SQL_Parser
	 */
	protected $parser;

	/**
	 * @var string
	 */
	protected $table_prefix = '';

	/**
	 * @var array
	 */
	protected $unique_meta_tables;

	/**
	 * SQL_Creator constructor.
	 *
	 * @param string $table_prefix
	 * @param array  $unique_meta_tables
	 * @param bool   $parsed
	 */
	public function __construct( $table_prefix, $unique_meta_tables = array(), $parsed = false ) {
		$this->table_prefix       = $table_prefix;
		$this->unique_meta_tables = $unique_meta_tables;

		parent::__construct( $parsed );
	}

	/**
	 * @param SQL_Parser $parser
	 */
	public function set_parser( SQL_Parser $parser ) {
		$this->parser = $parser;
	}

	/**
	 * Create a SQL statement string from parsed array
	 *
	 * @param array $parsed
	 *
	 * @return string|Error
	 */
	public function create( $parsed = null ) {
		if ( is_null( $parsed ) ) {
			$parsed = $this->parser->parsed;
		}

		try {
			$sql = parent::create( $parsed );
		} catch ( \Exception $e ) {
			return new Error( Error::$SQL_createFailed, __( 'Error creating SQL statement' ), array(
				'error'  => $e->getMessage(),
				'parsed' => $parsed
			) );
		}

		return $sql;
	}

	/**
	 * Convert parsed statement to a SELECT SQL statement string using the same JOIN/WHERE clauses
	 *
	 * @param string $alias
	 *
	 * @return Error|string
	 */
	public function convert_to_select( $alias = '' ) {
		$parsed = $this->parser->parsed;

		if ( $this->parser->is_insert() ) {
			$alias  = $this->get_insert_statement_table();
			$parsed = $this->reshape_insert_query( $alias );
		} else if ( $this->parser->is_update() ) {
			$parsed = $this->reshape_update_query();
		} else if ( $this->parser->is_delete() ) {
			$parsed = $this->reshape_delete_query();
		}

		// Get the SELECT clause
		$select_clause = $this->generate_parsed_select_clause( $alias );

		// Add the SELECT clause to start of array
		$parsed = array_merge( array( 'SELECT' => $select_clause ), $parsed );

		// Create the SQL SELECT statement
		$sql = $this->create( $parsed );

		return $sql;
	}

	/**
	 * Reshape a parsed INSERT to a parsed array ready for making a SELECT statement
	 *
	 * @param string $table
	 *
	 * @return array
	 */
	protected function reshape_insert_query( $table ) {
		$parsed['FROM'] = $this->generate_parsed_from_clause( $table );

		$table_data = $this->parser->get_parsed_insert_data( false );

		$table_data = $this->get_unique_table_data( $table_data, $table );

		$parsed['WHERE'] = $this->generate_parsed_where_clause( $table_data );

		unset( $parsed['INSERT'] );
		unset( $parsed['VALUES'] );

		return $parsed;
	}

	/**
	 * Return only the unique column data from an INSERT col/val array
	 *
	 * @param array  $data
	 * @param string $table
	 *
	 * @return array
	 */
	protected function get_unique_table_data( $data, $table ) {
		$raw_table = $this->table_without_prefix( $table );

		if ( ! isset( $this->unique_meta_tables[ $raw_table ] ) ) {
			return $data;
		}

		$unique_column = $this->unique_meta_tables[ $raw_table ];
		if ( ! isset( $data[ $unique_column ] ) ) {
			return $data;
		}

		return array( $unique_column => $data[ $unique_column ] );
	}

	/**
	 *
	 * @return array
	 */
	protected function reshape_update_query() {
		$parsed = $this->parser->parsed;

		// Add the FROM clause
		$parsed['FROM'] = $parsed['UPDATE'];

		// Remove old clauses
		unset( $parsed['SET'] );
		unset( $parsed['UPDATE'] );

		return $parsed;
	}

	/**
	 *
	 * @return array
	 */
	protected function reshape_delete_query() {
		$parsed = $this->parser->parsed;
		// Remove old clause
		unset( $parsed['DELETE'] );

		return $parsed;
	}

	/**
	 * Generate a SELECT all clause
	 *
	 * @param string $alias
	 *
	 * @return array
	 */
	protected function generate_parsed_select_clause( $alias = '' ) {
		$select = array();

		$select[] = array(
			'expr_type' => 'colref',
			'alias'     => false,
			'base_expr' => $alias . '.*',
			'no_quotes' => array(
				'delim' => '.',
				'parts' => array(
					str_replace( '`', '', $alias ),
					'*',
				),
			),
			'sub_tree'  => false,
			'delim'     => false,
			'position'  => 1,
		);

		return $select;
	}

	/**
	 * Get the table the INSERT is happening on
	 *
	 * @return string|bool
	 */
	protected function get_insert_statement_table() {
		foreach ( $this->parser->parsed['INSERT'] as $data ) {
			if ( 'table' !== $data['expr_type'] ) {
				continue;
			}

			if ( ! isset( $data['table'] ) ) {
				return false;
			}

			return str_replace( "`", '', $data['table'] );
		}

		return false;
	}

	/**
	 * Generate a FROM clause for a parsed array
	 *
	 * @param string $table
	 *
	 * @return array
	 */
	protected function generate_parsed_from_clause( $table ) {
		$from = array();

		$args = array(
			'join_type'  => 'JOIN',
			'ref_type'   => '',
			'ref_clause' => '',
			'sub_tree'   => '',
		);

		$from[] = $this->generate_parsed_fragment_table( $table, 12, $args );

		return $from;
	}

	/**
	 * Generate parsed fragment for the table data
	 *
	 * @param string $table
	 * @param int    $position
	 * @param array  $args
	 *
	 * @return array
	 */
	protected function generate_parsed_fragment_table( $table, $position, $args = array() ) {
		$defaults = array(
			'expr_type' => 'table',
			'table'     => '`' . $table . '`',
			'no_quotes' => array(
				'delim' => '',
				'parts' => array(
					$table,
				),
			),
			'alias'     => false,
			'base_expr' => '`' . $table . '`',
			'position'  => $position,
		);

		return array_merge( $defaults, $args );
	}

	/**
	 * Generate a WHERE parsed clause for a simple SELECT
	 *
	 * @param $data Key value pair of Column name and value
	 *
	 * @return array
	 */
	protected function generate_parsed_where_clause( $data ) {
		$where = array();

		$columns = count( $data );
		$count   = 0;
		foreach ( $data as $column => $value ) {
			$count ++;
			$where[] = array(
				'expr_type' => 'colref',
				'base_expr' => '`' . $column . '`',
				'no_quotes' => array(
					'delim' => '',
					'parts' => array(
						$column,
					),
				),
				'sub_tree'  => '',
				'position'  => 35,
			);

			$where[] = array(
				'expr_type' => 'operator',
				'base_expr' => '=',
				'sub_tree'  => '',
				'position'  => 45,
			);

			$where[] = array(
				'expr_type' => 'const',
				'base_expr' => $value,
				'sub_tree'  => '',
				'position'  => 47,
			);

			if ( $count !== $columns ) {
				$where[] = array(
					'expr_type' => 'operator',
					'base_expr' => 'AND',
					'sub_tree'  => '',
				);
			}
		}

		return $where;
	}

	/**
	 * Get a table name without the prefix
	 *
	 * @param string $table
	 *
	 * @return string
	 */
	protected function table_without_prefix( $table ) {
		return str_replace( $this->table_prefix, '', $table );
	}
}