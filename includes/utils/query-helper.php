<?php

/**
 * The query helper class.
 *
 * This is for methods shared acorss Query classes
 *
 * @since 0.1
 */

namespace DeliciousBrains\Mergebot\Utils;

class Query_Helper {

	/**
	 * Get the type of query from SQL statement.
	 *
	 * @param $sql
	 *
	 * @return bool|string Returns false if not an allowed type.
	 */
	public static function get_statement_type( $sql ) {
		global $wpdb;
		$search = self::prep_search_array( array_keys( $wpdb->allowed_types ) );

		if ( ! preg_match( '/^(' . $search . ')/i', $sql, $matches ) ) {
			return false;
		}

		$match = strtoupper( $matches[0] );

		if ( isset( $wpdb->allowed_types[ $match ] ) ) {
			return $wpdb->allowed_types[ $match ];
		}

		// Remove non-space whitespace characters for matching to our array.
		$match = preg_replace( '/\s+/', ' ', $match );

		if ( isset( $wpdb->allowed_types[ $match ] ) ) {
			return $wpdb->allowed_types[ $match ];
		}

		return false;
	}

	/**
	 * Get the table involved in the SQL statement.
	 *
	 * @param $type
	 * @param $sql
	 *
	 * @return bool|string
	 */
	public static function get_statement_table( $type, $sql ) {
		global $wpdb;
		$types = $wpdb->allowed_types;

		if ( ! in_array( $type, $types ) ) {
			return false;
		}

		$type_search = array_keys( $types, $type );
		$search = self::prep_search_array( $type_search );

		if ( ! preg_match( '/^(?>' . $search . ')\s+([^\s]+)/i', $sql, $matches ) ) {
			return false;
		}

		$table = $matches[1];
		$table = str_replace( array( '`', ';' ), '', $table );

		return $table;
	}

	/**
	 * Prepare regex string for multiple SQL type search terms.
	 *
	 * @param array $search
	 *
	 * @return array|mixed|string
	 */
	protected static function prep_search_array( $search = array() ) {
		$search = implode( '|', $search );
		$search = str_replace( ' ', '\s+', $search );

		return $search;
	}

	/**
	 * Remove table prefix from table name.
	 *
	 * @param string $table
	 *
	 * @return string
	 */
	public static function strip_prefix_from_table( $table ) {
		global $wpdb;

		return str_replace( array( '`', $wpdb->prefix, $wpdb->base_prefix ), '', $table );
	}

	/**
	 * Get the blog ID that the SQL statement table pertains to.
	 *
	 * @param string $table
	 * @param string $table_without_prefix
	 *
	 * @return int
	 */
	public static function get_statement_blog_id( $table, $table_without_prefix ) {
		if ( ! is_multisite() ) {
			return 1;
		}

		global $wpdb;

		if ( $wpdb->base_prefix . $table_without_prefix === $table ) {
			// Main site table
			return 1;
		}

		$blog_prefix = str_replace( array( $wpdb->base_prefix, $table_without_prefix, '_' ), '', $table );

		return $blog_prefix;
	}

	/**
	 * Clean INSERT SQL statements.
	 *
	 * @param string $sql
	 *
	 * @return string
	 */
	public static function clean_insert_sql( $sql ) {
		if ( preg_match( '/INSERT INTO\s+([^ ][^\s.]*)?\(/', $sql, $matches ) ) {
			// Ensure INSERT statements have space after table name
			$sql = str_replace( $matches[1] . '(', $matches[1] . ' (', $sql );
		}

		// Ensure INSERT statements have space after VALUES
		$sql = str_replace( 'VALUES(', 'VALUES (', $sql );

		return $sql;
	}

	/**
	 * Clean CREATE SQL statements.
	 *
	 * @param string $sql
	 *
	 * @return string
	 */
	public static function clean_create_table_sql( $sql ) {
		if ( preg_match( '/(^CREATE\s+TABLE(?!\s+IF\s+NOT\s+EXISTS))/i', trim( $sql), $matches ) ) {
			// Ensure a CREATE table statement is safe and only executes if table doesn't exist.
			$sql = str_replace( $matches[1], $matches[1] . ' IF NOT EXISTS', $sql );
		}

		if ( preg_match( '/\((\S*),(\S*)\)/', $sql, $matches ) ) {
			// Ensure any compound keys have spaces after the comma, so the parser can handle it.
			$replace = str_replace( ',', ', ', $matches[0] );
			$sql     = str_replace( $matches[0], $replace, $sql );
		}

		return $sql;
	}

	/**
	 * Clean DROP SQL statements.
	 *
	 * @param string $sql
	 *
	 * @return string
	 */
	public static function clean_drop_table_sql( $sql ) {
		if ( preg_match( '/(^DROP\s+TABLE(?!\s+IF\s+EXISTS))/i', trim( $sql ), $matches ) ) {
			// Ensure a CREATE table statement is safe and only executes if table doesn't exist.
			$sql = str_replace( $matches[1], $matches[1] . ' IF EXISTS', $sql );
		}

		return $sql;
	}

	/**
	 * Does the SQL statement contain a string?
	 *
	 * @param string $sql
	 * @param string $needle       Regex for searching
	 * @param bool   $compress_sql Removes spaces from SQL for searching
	 *
	 * @return bool|mixed
	 */
	public static function contains( $sql, $needle, $compress_sql = true ) {
		if ( $compress_sql ) {
			$sql = str_replace( ' ', '', $sql );
		}

		if ( preg_match( '@(' . $needle . ')@i', $sql ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Does SQL statement contain any excluded queries
	 *
	 * @param string $sql
	 * @param array     $blacklist
	 *
	 * @return bool
	 */
	public static function sql_contains_excluded_queries( $sql, $blacklist ) {
		$blacklist = implode( '|', $blacklist );
		$blacklist = str_replace( ' ', '', $blacklist );

		return Query_Helper::contains( stripslashes( $sql ), $blacklist );
	}

	/**
	 * Does the query require a snapshot of the data to be take before execution.
	 *
	 * @param string $query
	 * @param string $type
	 * @param string $table
	 *
	 * @return bool
	 */
	public static function requires_data_snapshot( $query, $type, $table ) {
		if ( in_array( $type, array( 'UPDATE', 'DELETE' ) ) ) {
			// Record existing data for rows affected by an UPDATE or DELETE
			return true;
		}

		global $wpdb;
		if ( ! isset( $wpdb->unique_meta_tables ) ) {
			return false;
		}

		if ( 'INSERT' === $type && isset( $wpdb->unique_meta_tables[ $table ] ) && self::contains( $query, 'ON DUPLICATE KEY', false ) ) {
			// Record existing data for rows potentially affected by an INSERT..ON DUPLICATE KEY...UPDATE
			return true;
		}

		return false;
	}
}