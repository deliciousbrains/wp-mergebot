<?php

/**
 * The class that responds to execution of WordPress queries and performs actions
 *
 * @since 0.1
 */

namespace DeliciousBrains\Mergebot\Services\Development;

use DeliciousBrains\Mergebot\Jobs\Send_Queries;
use DeliciousBrains\Mergebot\Models\App_Changeset;
use DeliciousBrains\Mergebot\Models\Changeset;
use DeliciousBrains\Mergebot\Models\Query;
use DeliciousBrains\Mergebot\Models\SQL_Query;
use DeliciousBrains\Mergebot\Models\Plugin;
use DeliciousBrains\Mergebot\Services\Site_Synchronizer;
use DeliciousBrains\Mergebot\Utils\Error;

class Query_Responder {

	/**
	 * @var Plugin;
	 */
	protected $bot;

	/**
	 * @var Schema_Handler
	 */
	protected $schema_handler;

	/**
	 * @var Site_Synchronizer
	 */
	protected $site_synchronizer;

	/**
	 * @var Send_Queries
	 */
	protected $send_queries_job;

	/**
	 * @var bool
	 */
	protected $dispatch_send_queries_job = false;

	/**
	 * Query_Responder constructor.
	 *
	 * @param Plugin            $bot
	 * @param Schema_Handler    $schema_handler
	 * @param Site_Synchronizer $site_synchronizer
	 */
	public function __construct( Plugin $bot, Schema_Handler $schema_handler, Site_Synchronizer $site_synchronizer ) {
		$this->bot               = $bot;
		$this->schema_handler    = $schema_handler;
		$this->site_synchronizer = $site_synchronizer;
	}

	/**
	 * Initialize hooks
	 *
	 * @param Send_Queries $send_queries
	 */
	public function init( Send_Queries $send_queries ) {
		add_filter( 'mergebot_after_query_fail', array( $this, 'clear_recorded_query' ));
		add_filter( 'mergebot_after_query_success', array( $this, 'record_last_insert_id' ), 9 );
		add_filter( 'mergebot_after_query_success', array( $this, 'record_last_insert_id_for_excluded_query' ) );
		add_filter( 'mergebot_after_query_success', array( $this, 'maybe_send_query' ), 11 );
		add_action( 'shutdown', array( $this, 'maybe_dispatch_send_queries_job' ) );
		add_action( $this->bot->slug() . '_refresh_changeset', array( $this, 'maybe_update_remaining_queries' ), 10, 2 );

		$this->send_queries_job = $send_queries;
	}

	/**
	 * Remove the recorded query if the actual query execution failed.
	 *
	 * @param SQL_Query $query
	 *
	 * @return SQL_Query
	 */
	public function clear_recorded_query( $query ) {
		if ( false === $query->to_record() || ! isset( $query->recorded_query ) ) {
			return $query;
		}

		// Remove query and associated data
		$query->recorded_query->clear();

		return $query;
	}

	/**
	 * Update the last INSERT change with its auto increment id
	 *
	 * @param SQL_Query $query
	 *
	 * @return SQL_Query $query
	 */
	public function record_last_insert_id( $query ) {
		if ( is_null( $last_query = $query->recorded_query() ) ) {
			return $query;
		};

		if ( false === $query->is_insert() || $last_query->processed() ) {
			return $query;
		}

		$last_insert_id = $this->get_last_insert_id();

		if ( false === $last_insert_id ) {
			// As a further backup, search for the inserted row to derived the ID
			$last_insert_id = $this->get_insert_id_from_sql( $query );
		}

		if ( false === $last_insert_id ) {
			new Error( Error::$Recorder_getInsertIDFailed, sprintf( __( 'Could not get insert_id for query #%s' ), $last_query->id() ), $last_query->to_array() );

			return $query;
		}

		$last_query->insert_id = $last_insert_id;
		$last_query->processed = 1;
		$last_query->save();

		$query->set_recorded_query( $last_query );


		return $query;
	}

	/**
	 * Get the last insert ID from an INSERT statement.
	 * Typically from $wpdb but if not, reverse engineer the SQL to select the row it has inserted
	 *
	 * @return bool|int
	 */
	protected function get_last_insert_id() {
		global $wpdb;

		$insert_id = $wpdb->get_insert_id();
		if ( 0 !== $insert_id ) {
			// The last ID was set by our db class, trust it, use it, abuse it.
			return $insert_id;
		}

		if ( 0 !== $wpdb->insert_id ) {
			// Use the $wpdb property as a backup.
			return $wpdb->insert_id;
		}

		return false;
	}

	/**
	 * Get the inserted ID by selecting the row of data which has been inserted
	 *
	 * @param SQL_Query $query
	 *
	 * @return bool|int
	 */
	protected function get_insert_id_from_sql( $query ) {
		$row = $query->rows( '', true );
		if ( false === $row ) {
			// No row of data to be found.
			return false;
		}

		$column = $this->schema_handler->get_auto_increment_column( $query->table() );
		if ( false === $column ) {
			// We don't know the PK column for that table
			return false;
		}

		if ( isset( $row[ $column ] ) ) {
			return $row[ $column ];
		}

		return false;
	}

	/**
	 * Update the last INSERT query which has been excluded with the auto increment ID
	 *
	 * @param SQL_Query $query
	 *
	 * @return SQL_Query
	 */
	public function record_last_insert_id_for_excluded_query( $query ) {
		if ( is_null( $query->excluded_object() ) ){
			return $query;
		}

		$last_excluded_object = $query->excluded_object();

		$last_insert_id = $this->get_last_insert_id();

		if ( false === $last_insert_id ) {
			// Can't find INSERT ID, abort
			return $query;
		}

		$last_excluded_object->insert_id = $last_insert_id;
		$last_excluded_object->save();

		return $query;
	}

	/**
	 * Dispatch the send queries job once the query has executed and can be sent
	 *
	 * @param SQL_Query $query
	 *
	 * @return bool
	 */
	public function maybe_send_query( $query ) {
		if ( ! $query->send_query() ) {
			return $query;
		}

		$recorded_query = $query->recorded_query();
		if ( false === $this->dispatch_send_queries_job && $recorded_query->processed() ) {
			$this->dispatch_send_queries_job = true;
		}

		return $query;
	}

	/**
	 * Dispatch the send queries job on shutdown of the request, if we have queries to send.
	 */
	public function maybe_dispatch_send_queries_job() {
		if ( false === $this->dispatch_send_queries_job ) {
			return;
		}

		// Dispatch the send queries job
		$this->send_queries_job->dispatch();
	}

	/**
	 * Update the remaining queries total on the app if it is out of date when refreshing the changeset.
	 *
	 * @param Changeset     $old_changeset
	 * @param App_Changeset $new_changeset
	 *
	 * @return bool
	 */
	public function maybe_update_remaining_queries( Changeset $old_changeset, App_Changeset $new_changeset ) {
		if ( ! isset( $new_changeset->remaining_queries ) ) {
			return false;
		}

		$remaining_queries = Query::total_processed();
		if ( (int) $new_changeset->remaining_queries === $remaining_queries ) {
			return false;
		}

		// App has outdated info about the remaining queries to be sent, update the total.
		return $this->site_synchronizer->post_site_settings( array( 'remaining_queries' => $remaining_queries ) );
	}
}