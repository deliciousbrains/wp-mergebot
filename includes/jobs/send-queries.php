<?php

/**
 * The class sends queries to the app that have been recorded
 *
 * @since 0.1
 */

namespace DeliciousBrains\Mergebot\Jobs;

use DeliciousBrains\Mergebot\Services\Admin\Settings_Handler;
use DeliciousBrains\Mergebot\Services\App_Interface;
use DeliciousBrains\Mergebot\Services\Development\Query_Recorder;
use DeliciousBrains\Mergebot\Services\Development\Schema_Handler;
use DeliciousBrains\Mergebot\Models\Data;
use DeliciousBrains\Mergebot\Models\Query;
use DeliciousBrains\Mergebot\Models\Plugin;
use DeliciousBrains\Mergebot\Utils\Error;
use DeliciousBrains\Mergebot\Utils\Query_Helper;

class Send_Queries extends Abstract_Job {

	/**
	 * @var string
	 */
	protected $action = 'send_queries';

	/**
	 * @var Query_Recorder
	 */
	protected $query_recorder;

	/**
	 * @var Schema_Handler
	 */
	protected $schema_handler;

	/**
	 * @var App_Interface
	 */
	protected $app;

	/**
	 * post_max_size 32MB of api.mergebot.com
	 */
	const max_content_length = 33554432;

	/**
	 * Initiate new background process
	 *
	 * @param Plugin           $bot Instance of calling class
	 * @param Query_Recorder   $query_recorder
	 * @param Schema_Handler   $schema_handler
	 * @param App_Interface    $app
	 * @param Settings_Handler $settings_handler
	 */
	public function __construct( Plugin $bot, Query_Recorder $query_recorder, Schema_Handler $schema_handler, App_Interface $app, Settings_Handler $settings_handler ) {
		parent::__construct( $bot, $settings_handler );

		$this->query_recorder = $query_recorder;
		$this->schema_handler = $schema_handler;
		$this->app            = $app;
	}

	/**
	 * Is queue empty
	 *
	 * @return bool
	 */
	protected function is_queue_empty() {
		$total_processed = Query::total_processed();

		return $total_processed > 0 ? false : true;
	}

	/**
	 * Get batch
	 *
	 * @return \stdClass Return the first batch from the queue
	 */
	protected function get_batch() {
		$this->clean_up_unprocessed();

		$limit = apply_filters( $this->identifier . '_app_request_limit', 10 );

		return Query::processed_batch( $limit );
	}

	/**
	 * Clean up an unprocessed queries that really should be processed to be sent to the app
	 */
	protected function clean_up_unprocessed() {
		$unprocessed_queries = Query::fetch_unprocessed_blocking();

		foreach ( $unprocessed_queries as $query ) {
			if ( (int) get_site_transient( $this->identifier . '_error_count' ) >= $this->error_retry_limit ) {
				// Already retried to our limit with errors
				break;
			}

			if ( $this->query_recorder->should_mark_as_processed( $query->type, $query->insert_table, $query->insert_id ) ) {
				// Mark as processed if can be sent to the app immediately
				$query->processed = 1;
				$query->save();

				continue;
			}

			$error_title     = __( 'Query Error' );
			$error_msg       = __( 'Could not send query to the app' );
			$error_msg_extra = ' Unable to find ID for INSERT query';
			$this->throw_sending_error( Error::$Recorder_getInsertIDSendFailed, $error_title, $error_msg, $error_msg_extra, $query->to_array() );
		}
	}

	/**
	 * Task
	 *
	 * @param array $batch
	 *
	 * @return mixed
	 */
	protected function task( $batch ) {
		$data = array();

		$max_content_length = self::max_content_length * 0.9;

		foreach ( $batch as $query ) {
			$data_length = strlen( json_encode( $data ) );
			if ( $data_length >= $max_content_length ) {
				break;
			}

			$data_change = array(
				'id'            => $query->id,
				'recording_id'  => $query->recording_id,
				'type'          => strtolower( $query->type ),
				'sql_statement' => $query->sql_statement,
				'recorded_at'   => $query->date_recorded,
				'blog_id'       => $query->blog_id,
			);

			if ( Query_Helper::requires_data_snapshot( $query->sql_statement(), $query->type(), $query->insert_table() ) ) {
				$query_data = Data::all_for_query( $query->id );

				if ( is_array( $query_data ) && count( $query_data ) > 0 ) {
					$pre_update_data = array();
					foreach ( $query_data as $row ) {
						$pre_update_data[] = array(
							'table' => $row->table_name,
							'data'  => unserialize( $row->data ),
						);
					}

					$data_change['pre_update_data'] = $pre_update_data;
				}
			}

			if ( 'INSERT' === $query->type() ) {
				$data_change['insert_id'] = $query->insert_id;
			}

			if ( strlen( json_encode( $data_change ) ) + $data_length >= $max_content_length ) {
				break;
			}

			$data[] = $data_change;
		}

		return $this->send_queries_to_app( $data );
	}

	/**
	 * Send batch of queries to the app in one request
	 *
	 * @param array $data
	 *
	 * @return bool
	 */
	protected function send_queries_to_app( $data ) {
		$site_id = $this->settings_handler->get_site_id();

		// Send remaining queries left to be sent to the app, after this batch.
		$remaining_queries = Query::total_processed() - count( $data );

		$result = $this->app->post_queries( $site_id, $data, $remaining_queries );

		if ( is_wp_error( $result ) ) {
			$this->throw_sending_error( Error::$Dev_App_sendQueriesFailed, __( 'App Communication Error' ), __( 'Could not send queries to the app.' ), ' ' . $result->get_error_message(), $data );

			return false;
		}

		$queries = isset( $result->queries ) ? (array) $result->queries : array();
		foreach ( $queries as $query_id => $query ) {
			$plugin_query = Query::find( $query_id );
			if ( false === $plugin_query ) {
				continue;
			}

			if ( isset( $query->status ) && 'error' === $query->status ) {
				$query_error     = isset( $query->error ) ? ' ' . $query->error : '';
				$error_title     = __( 'App Query Processing Error' );
				$error_msg       = sprintf( __( 'The app could not process query %s.' ), $query_id );
				$error_data      = array();
				$error_msg_extra = $query_error;
				foreach ( $data as $sent_query ) {
					if ( $sent_query['id'] == $query_id ) {
						$error_data       = $sent_query;
						$plugin_query->app_error = $error_msg . $error_msg_extra;
						$plugin_query->save();

						break;
					}
				}

				break;
			}

			// Clear the data for the query
			$plugin_query->clear();
		}

		// Has the query limit been reached
		$this->handle_query_limit_reached( $result );

		if ( isset( $query_error ) ) {
			$this->throw_sending_error( Error::$Dev_App_sendQueriesFailed, $error_title, $error_msg, $error_msg_extra, $error_data );

			return false; // Add back to queue
		}

		return true;  // Remove from queue
	}

	/**
	 * Handle if the query limit has been reached
	 *
	 * @param object $result
	 */
	protected function handle_query_limit_reached( $result ) {
		if ( ! isset( $result->status ) ) {
			return;
		}

		if ( ! isset( $result->status->limit_reached ) || false === $result->status->limit_reached ) {
			return;
		}

		$changeset_id = isset( $result->status->changeset_id ) ? $result->status->changeset_id : 0;

		// Limit reached, store the changeset ID that the limit has been reached for
		update_site_option( $this->bot->slug() . '_query_limit', $changeset_id );
	}
}