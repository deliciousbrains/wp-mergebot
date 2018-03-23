<?php

/**
 * The WordPress Query Model class
 *
 * @since 0.1
 */

namespace DeliciousBrains\Mergebot\Models;

use DeliciousBrains\Mergebot\Services\Development\SQL_Creator;
use DeliciousBrains\Mergebot\Services\Development\SQL_Parser;
use DeliciousBrains\Mergebot\Utils\Error;
use DeliciousBrains\Mergebot\Utils\Query_Helper;

class SQL_Query extends Base_Model {

	/**
	 * @var string SQL statement of the query
	 */
	protected $sql;

	/**
	 * @var SQL_Parser
	 */
	protected $parser;

	/**
	 * @var SQL_Creator
	 */
	protected $creator;

	/**
	 * @var string Type of SQL statement
	 */
	protected $type = false;

	/**
	 * @var string Main table involved in SQL
	 */
	protected $table = false;

	/**
	 * @var int The blog ID the query is run on
	 */
	protected $blog_id;

	/**
	 * @var Query The instance of the query inserted into our table
	 */
	protected $recorded_query;

	/**
	 * @var Excluded_Object The instance of the excluded object when excluding a query
	 */
	protected $excluded_object;

	/**
	 * @var bool Should we record the query?
	 */
	protected $to_record = false;

	/**
	 * @var bool Can we dispatch the query to the app
	 */
	protected $send_query = false;

	/**
	 * @var int The ID for the last inserted record
	 */
	protected $insert_id = 0;

	/**
	 * SQL_Query constructor.
	 *
	 * @param string      $sql_statement
	 * @param SQL_Parser  $parser
	 * @param SQL_Creator $creator
	 */
	public function __construct( $sql_statement, SQL_Parser $parser, SQL_Creator $creator ) {
		$this->init( $sql_statement );
		$this->parser  = $parser;
		$this->creator = $creator;
	}

	/**
	 * Initialize the query by setting the properties
	 *
	 * @param string $sql
	 */
	protected function init( $sql ) {
		$this->sql  = trim( $sql );
		$this->type = Query_Helper::get_statement_type( $this->sql );

		if ( false === $this->type ) {
			return;
		}

		$this->sql = $this->clean_sql( $this->sql );
		$table     = Query_Helper::get_statement_table( $this->type, $this->sql );
		if ( false === $table ) {
			return;
		}

		$this->table   = Query_Helper::strip_prefix_from_table( $table );
		$this->blog_id = Query_Helper::get_statement_blog_id( $table, $this->table );
	}

	/**
	 * Format the SQL statement to ensure parsing doesn't break.
	 * This is because some plugins will formulate SQL badly.
	 *
	 * @param string $sql
	 *
	 * @return string
	 */
	protected function clean_sql( $sql ) {
		$clean_method = 'clean_' . strtolower( $this->type ) . '_sql';
		$helper_class = 'DeliciousBrains\Mergebot\Utils\Query_Helper';
		if ( method_exists( $helper_class, $clean_method ) ) {
			$sql = $helper_class::$clean_method( $sql );
		}

		return $sql;
	}

	/**
	 * Is the query type valid?
	 *
	 * @return bool
	 */
	public function valid_type() {
		return (bool) $this->type;
	}

	/**
	 * Magic method for checking the type of the query via is_insert() etc.
	 *
	 * @param string $function
	 * @param array  $arguments
	 *
	 * @return bool|mixed
	 */
	public function __call( $function, $arguments ) {
		if ( 0 !== strpos( $function, 'is_' ) ) {
			return parent::__call( $function, $arguments );
		}

		$type = str_replace( 'is_', '', $function );

		return strtoupper( $type ) === $this->type;
	}

	/**
	 * Set the instance of of the query we inserted
	 *
	 * @param Query $recorded_query
	 */
	public function set_recorded_query( Query $recorded_query ) {
		$this->recorded_query = $recorded_query;
	}

	/**
	 * Set the instance of the object when excluding a query
	 *
	 * @param Excluded_Object $excluded_object
	 */
	public function set_excluded_object( Excluded_Object $excluded_object ) {
		$this->excluded_object = $excluded_object;
	}

	/**
	 * Set the INSERT id
	 *
	 * @param int $id
	 */
	public function set_insert_id( $id ) {
		$this->insert_id = $id;
	}

	/**
	 * Mark the query as able to be dispatched to the app
	 */
	public function dispatch() {
		$this->send_query = true;
	}

	/**
	 * Mark the query as able to be recorded
	 */
	public function record() {
		$this->to_record = true;
	}

	/**
	 * Return the SQL query in parsed array format
	 *
	 * @return SQL_Parser|bool
	 */
	public function parse() {
		if ( ! is_null( $this->parser->parsed ) ) {
			return $this->parser;
		}

		if ( false === $this->parser->parse( $this->sql ) ) {
			return false;
		}

		return $this->parser;
	}

	/**
	 * Turn an SQL query into a SELECT query
	 *
	 * @param string $alias
	 *
	 * @return bool|string|Error
	 */
	public function to_select( $alias = '' ) {
		if ( false === $this->parse() ) {
			return false;
		}

		$this->creator->set_parser( $this->parser );

		return $this->creator->convert_to_select( $alias );
	}

	/**
	 * Create SQL statement using parsed array.
	 *
	 * @param array $parsed
	 *
	 * @return Error|string
	 */
	public function to_sql( $parsed ) {
		$sql = $this->creator->create( $parsed );
		if ( is_wp_error( $sql ) ) {
			return false;
		}

		return $sql;
	}

	/**
	 * Return the rows of data associated with the SQL statement
	 *
	 * @param string $alias
	 * @param bool   $single
	 *
	 * @return array|bool
	 */
	public function rows( $alias = '', $single = false ) {
		$select_sql = $this->to_select( $alias );

		if ( false === $select_sql || is_wp_error( $select_sql ) ) {
			// SELECT statement not created successfully
			return false;
		}

		// Select the rows from the database
		global $wpdb;
		$rows = $wpdb->get_results( $select_sql, ARRAY_A );

		if ( empty( $rows ) ) {
			return false;
		}

		if ( $single ) {
			return $rows[0];
		}

		return $rows;
	}
}

