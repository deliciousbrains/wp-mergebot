<?php

/**
 * The class for dealing with conflicts.
 *
 * @since 0.1
 */

namespace DeliciousBrains\Mergebot\Services;

use DeliciousBrains\Mergebot\Models\Changeset;
use DeliciousBrains\Mergebot\Models\Conflict;
use DeliciousBrains\Mergebot\Models\Plugin;
use DeliciousBrains\Mergebot\Services\Admin\Plugin_Installer;
use DeliciousBrains\Mergebot\Utils\Error;

class Conflict_Sender extends Abstract_Process {

	/**
	 * @var App_Interface
	 */
	protected $app;

	/**
	 * @var array
	 */
	protected $tables;

	/**
	 * Conflicts constructor.
	 *
	 * @param Plugin        $bot
	 * @param App_Interface $app
	 */
	public function __construct( Plugin $bot, App_Interface $app ) {
		parent::__construct( $bot );
		$this->app = $app;
	}

	/**
	 * Send all conflicts data in batches
	 *
	 * @param int       $site_id
	 * @param Changeset $changeset
	 *
	 * @return bool|null
	 */
	public function send( $site_id, $changeset ) {
		$finish_time = $this->get_execution_finish_time();

		do {
			if ( false === $this->send_batch( $site_id, $changeset ) ) {
				// Batch has an error
				return false;
			}

			if ( $this->time_exceeded( $finish_time ) || $this->memory_exceeded() ) {
				// Batch limits reached
				break;
			}
		} while ( ! $this->time_exceeded( $finish_time ) && ! $this->memory_exceeded() && ! $this->conflicts_sent( $changeset->changeset_id ) );

		// Start next batch or complete process
		if ( ! $this->conflicts_sent( $changeset->changeset_id ) ) {
			// Redirect to start process again
			$redirect = $changeset->get_deploy_url( $this->bot, true );

			return $this->bot->redirect( $redirect );
		}

		return true;
	}

	/**
	 * Send the data for conflict resolution to the app
	 *
	 * @param int       $site_id
	 * @param Changeset $changeset
	 *
	 * @return bool
	 */
	public function send_batch( $site_id, $changeset ) {
		$conflicts = $this->get_conflicts_with_data( $changeset->changeset_id );
		if ( is_wp_error( $conflicts ) ) {
			return false;
		}

		if ( empty( $conflicts ) ) {
			return true;
		}

		// Get the data to be sent to the app
		$conflict_data = wp_list_pluck( $conflicts, 'data' );
		$conflict_data = array_values( $conflict_data );

		$data = array(
			'site_id'       => $site_id,
			'conflict_data' => $conflict_data,
		);

		$result = $this->app->post_production_data( $data );

		if ( is_wp_error( $result ) ) {
			return false;
		}

		foreach ( $conflicts as $item ) {
			if ( ! isset ( $item['conflict'] ) ) {
				continue;
			}

			$conflict = $item['conflict'];

			$conflict->sent = 1;
			$conflict->save();
		}

		return true;
	}

	/**
	 * Have all conflicts been sent
	 *
	 * @param int $changeset_id
	 *
	 * @return bool
	 */
	protected function conflicts_sent( $changeset_id ) {
		$unsent_total = Conflict::total_unsent( $changeset_id );

		return $unsent_total > 0 ? false : true;
	}

	/**
	 * Select all the data from the site that has been updated on
	 * the development site so the app can check for conflicts.
	 *
	 * @param int $changeset_id
	 *
	 * @return array|Error
	 */
	protected function get_conflicts_with_data( $changeset_id ) {
		global $wpdb;

		$conflicts    = array();
		$this->tables = array();

		$limit = apply_filters( $this->bot->slug() . '_send_conflict_data_limit', 10 );

		$saved_conflicts = Conflict::fetch_unsent( $changeset_id, $limit );

		foreach ( $saved_conflicts as $conflict ) {
			$table = $this->get_table_with_prefix( $conflict );
			if ( ! $this->check_table_exists( $table ) ) {
				// Table doesn't exist for the conflict record, remove.
				$conflict->delete();

				continue;
			}

			$conflict_data = array(
				'recording_id' => $conflict->recording_id,
				'table'        => $conflict->table_name,
				'pk_id'        => $conflict->pk_id,
				'blog_id'      => $conflict->blog_id,
			);

			$sql = $this->create_select_sql( $table, $conflict );

			$data = $wpdb->get_row( $sql, ARRAY_A );

			if ( is_null( $data ) && $wpdb->last_error ) {
				// DB error when selecting the row, abort.
				return new Error( Error::$Deploy_conflictSelectFailed, $wpdb->last_error, '', false );
			}

			if ( ! is_null( $data ) ) {
				$conflict_data['data'] = serialize( $data );
			}

			$conflicts[ $conflict->id ] = array(
				'data'     => $conflict_data,
				'conflict' => $conflict,
			);
		}

		return $conflicts;
	}

	/**
	 * Is a column or value a compound key
	 *
	 * @param string $data
	 *
	 * @return bool
	 */
	protected function is_compound_key( $data ) {
		return false !== strpos( $data, ',' );
	}

	/**
	 * Get the table for conflict with correct prefix.
	 *
	 * @param $conflict
	 *
	 * @return string
	 */
	protected function get_table_with_prefix( $conflict ) {
		global $wpdb;
		$table_prefix = $wpdb->base_prefix;
		if ( ! empty( $conflict->blog_id ) && $conflict->blog_id > 1 ) {
			$table_prefix .= $conflict->blog_id . '_';
		}

		return $table_prefix . $conflict->table_name();
	}

	/**
	 * Check a table exists in the database and cache the result.
	 *
	 * @param string $table
	 *
	 * @return bool
	 */
	protected function check_table_exists( $table ) {
		if ( isset( $this->tables[ $table ] ) ) {
			return $this->tables[ $table ];
		}

		$exists = Plugin_Installer::table_exists( $table, false );

		$this->tables[ $table ] = $exists;

		return $exists;
	}

	/**
	 * Create the SELECT statement to get the row of a potential conflict data item.
	 *
	 * @param string   $table
	 * @param Conflict $conflict
	 *
	 * @return string
	 */
	protected function create_select_sql( $table, $conflict ) {
		$columns = $conflict->pk_column();
		$values  = $conflict->pk_id();

		$conflict_columns = $columns;
		$conflict_values  = "'" . $values . "'";

		if ( $this->is_compound_key( $columns ) && $this->is_compound_key( $values ) ) {
			$conflict_columns = '(' . $columns . ')';
			$conflict_values  = '(' . $this->prepare_values( $values ) . ')';
		}

		$sql = "SELECT *
				FROM {$table}
				WHERE {$conflict_columns} = {$conflict_values}";

		return $sql;
	}

	/**
	 * Make sure string values are quoted.
	 *
	 * @param $values
	 *
	 * @return string
	 */
	protected function prepare_values( $values ) {
		$prepped     = array();
		$value_parts = explode( ',', $values );
		foreach ( $value_parts as $part ) {
			if ( is_numeric( $part ) ) {
				$prepped[] = $part;
				continue;
			}

			$prepped[] = "'" . $part . "'";
		}

		return implode( ', ', $prepped );
	}
}