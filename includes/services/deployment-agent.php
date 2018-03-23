<?php

/**
 * The app class for deploying a changeset.
 *
 * @since 0.1
 */

namespace DeliciousBrains\Mergebot\Services;

use DeliciousBrains\Mergebot\Models\Changeset;
use DeliciousBrains\Mergebot\Models\Deployment;
use DeliciousBrains\Mergebot\Models\Notice;
use DeliciousBrains\Mergebot\Models\Plugin;
use DeliciousBrains\Mergebot\Services\Admin\Settings_Handler;
use DeliciousBrains\Mergebot\Utils\Async_Request;
use DeliciousBrains\Mergebot\Utils\Error;
use DeliciousBrains\Mergebot\Utils\Support;

class Deployment_Agent extends Abstract_Process {

	/**
	 * @var Plugin
	 */
	protected $bot;

	/**
	 * @var Changeset_Handler
	 */
	protected $changeset_handler;

	/**
	 * @var Settings_Handler
	 */
	protected $settings_handler;

	/**
	 * @var App_Interface
	 */
	protected $app;

	/**
	 * @var Deployment_Inserts_Sender
	 */
	protected $deployment_inserts_sender;

	/**
	 * @var string
	 */
	protected $script_dir;

	/**
	 * @var array
	 */
	protected $script;

	/**
	 * @var string
	 */
	protected $file;

	/**
	 * @var array
	 */
	protected $insert_ids;

	/**
	 * @var Async_Request
	 */
	protected $deployment_inserts;

	/**
	 * Failed status of a changeset on the app
	 */
	const FAILED_STATUS = 'failed';

	/**
	 * End of query identifier
	 */
	const QUERY_EOL = '#mbend';

	/**
	 * Deployment constructor.
	 *
	 * @param Plugin                    $bot
	 * @param Changeset_Handler         $changeset_handler
	 * @param Settings_Handler          $settings_handler
	 * @param App_Interface             $app
	 * @param Deployment_Inserts_Sender $deployment_inserts_sender
	 */
	public function __construct( Plugin $bot, Changeset_Handler $changeset_handler, Settings_Handler $settings_handler, App_Interface $app, Deployment_Inserts_Sender $deployment_inserts_sender ) {
		parent::__construct( $bot );
		$this->changeset_handler         = $changeset_handler;
		$this->settings_handler          = $settings_handler;
		$this->app                       = $app;
		$this->deployment_inserts_sender = $deployment_inserts_sender;
	}

	/**
	 * Initialize
	 */
	public function init() {
		if ( ! $this->bot->doing_ajax() && ! $this->bot->doing_cron() ) {
			$this->check_script_dir();
		}

		$this->deployment_inserts = new Async_Request( $this->bot, $this->deployment_inserts_sender, 'send' );
	}

	/**
	 * Can we do a deployment
	 *
	 * @return bool
	 */
	public function is_setup() {
		// Deployment script directory set up?
		return (bool) $this->check_script_dir( false );
	}

	/**
	 * Get the path of the folder inside wp-content/uploads
	 *
	 * @return bool|string
	 */
	public function get_script_dir() {
		$upload_dir = wp_upload_dir();
		$dir        = $upload_dir['basedir'] . DIRECTORY_SEPARATOR . $this->bot->slug();

		return $dir;
	}

	/**
	 * Wrapper for wp_mkdir_p()
	 *
	 * @param string $dir
	 *
	 * @return bool
	 */
	protected function mkdir( $dir ) {
		return wp_mkdir_p( $dir );
	}

	/**
	 * Check the script directory exists and is writable
	 *
	 * @param bool|true $log
	 *
	 * @return bool
	 */
	protected function check_script_dir( $log = true ) {
		$dir    = $this->get_script_dir();
		$result = $this->mkdir( $dir );

		if ( false === $log ) {
			return $result;
		}

		$error_msg   = sprintf( __( 'Could not create deployment script directory: %s' ), $dir );
		$notice_args = array(
			'id'                => 'deploy-script-dir-error',
			'type'              => 'error',
			'message'           => $error_msg,
			'title'             => __( 'Directory Issue' ),
			'only_show_to_user' => false,
			'flash'             => false,
		);

		if ( false === $result ) {
			new Error( Error::$Deploy_scriptDirFailed, $error_msg );
			Notice::create( $notice_args )->save();

			return $result;
		}

		Notice::delete_by_id( $notice_args['id'] );

		return $result;
	}

	/**
	 * Automatic deployment of a script
	 *
	 * @param Deployment $deployment
	 *
	 * @return bool
	 */
	public function deploy( $deployment ) {
		$deployment->file = $this->get_script_dir() . DIRECTORY_SEPARATOR . 'mergebot-deployment-' . $deployment->changeset_id . '.sql';

		// Clear past failure notices
		Notice::delete_by_id( 'deployment-' . $deployment->changeset_id . '-failed' );

		do_action( $this->bot->slug() . '_pre_deployment', $deployment->changeset_id );

		// Reset deployed status
		if ( 1 === (int) $deployment->changeset->deployed ) {
			$deployment->changeset->deployed = 0;
			$deployment->changeset->save();
		}

		$result = $this->execute_deployment( $deployment );
		if ( is_wp_error( $result ) ) {
			$this->deployment_error( $result, $deployment->changeset );

			return false;
		}

		$this->deployment_success( $deployment->changeset );

		do_action( $this->bot->slug() . '_post_deployment', $deployment->changeset_id, $result );

		return true;
	}

	/**
	 * Process the deployment
	 *
	 * @param Deployment $deployment
	 *
	 * @return bool|Error
	 */
	protected function execute_deployment( $deployment ) {
		$deployment = $this->should_execute_deployment( $deployment );
		if ( is_wp_error( $deployment ) ) {
			return $deployment; // Deployment cannot be executed
		}

		$result = $this->deploy_sql_script( $deployment );
		if ( is_wp_error( $result ) ) {
			return $result; // SQL execution failed
		}

		if ( is_wp_error( $result = $this->check_deployed_changeset_option_key( $deployment, true ) ) ) {
			return $result; // Not deployed correctly
		}

		return true;
	}

	/**
	 * Should the deployment be executed?
	 *
	 * @param Deployment $deployment
	 *
	 * @return Deployment|Error
	 */
	protected function should_execute_deployment( $deployment ) {
		$deployment = $this->get_deployment_script( $deployment );
		if ( is_wp_error( $deployment ) ) {
			return $deployment; // Failed getting script data from app
		}

		$result = $this->download_deployment_script( $deployment );
		if ( is_wp_error( $result ) ) {
			return $result; // Failed downloading script file
		}

		$result = $this->check_download_file_exists( $deployment );
		if ( is_wp_error( $result ) ) {
			return $result; // Downloaded SQL file doesn't exist
		}

		if ( is_wp_error( $result = $this->check_deployed_changeset_option_key( $deployment ) ) ) {
			return $result; // Already deployed
		}

		return $deployment;
	}

	/**
	 * Deployment succeeded
	 *
	 * @param Changeset $changeset
	 */
	protected function deployment_success( Changeset $changeset ) {
		$data = array( 'changeset_id' => $changeset->changeset_id );

		// Mark changeset as deployed
		$changeset->deployed = 1;
		$changeset->save();

		// Dispatch background request to mark changeset as deployed
		$this->changeset_handler->set_status_request->data( $data )->dispatch();

		// Dispatch background request to send deployment INSERT ids to the app
		$this->deployment_inserts->data( $data )->dispatch();

		$notice_args = array(
			'type'                  => 'updated',
			'message'               => sprintf( __( 'Changeset #%s has been successfully deployed' ), $changeset->changeset_id ),
			'title'                 => __( 'Deployment Success' ),
			'only_show_in_settings' => true,
		);

		Notice::create( $notice_args )->save();
	}

	/**
	 * Deployment failed, mark changeset on app as failed
	 *
	 * @param Error     $result
	 * @param Changeset $changeset
	 */
	protected function deployment_error( $result, $changeset ) {
		$data = array(
			'changeset_id'  => $changeset->changeset_id,
			'status'        => self::FAILED_STATUS,
			'failed_reason' => $result->get_error_code() . ': ' . $result->get_error_message()
		);

		// Dispatch background request to mark changeset as failed
		$this->changeset_handler->set_status_request->data( $data )->dispatch();
	}

	/**
	 * Add error notice
	 *
	 * @param int        $changeset_id
	 * @param string     $error_msg
	 * @param null|Error $error
	 * @param string     $type
	 */
	public function add_error_notice( $changeset_id, $error_msg, $error = null, $type = 'error' ) {
		$title       = __( 'Deployment Failed' );
		$notice_args = array(
			'id'                    => 'deployment-' . $changeset_id . '-failed',
			'type'                  => $type,
			'message'               => rtrim( $error_msg, '.' ) . '. ' . Support::generate_link( $this->settings_handler->get_site_id(), $title, $error_msg, $error ),
			'title'                 => $title,
			'flash'                 => false,
			'only_show_in_settings' => true,
		);

		Notice::create( $notice_args )->save();
	}

	/**
	 * Get the deployment script data from the app (url and checksum)
	 *
	 * @param Deployment $deployment
	 *
	 * @return Error|Deployment
	 */
	public function get_deployment_script( $deployment ) {
		$site_id = $this->settings_handler->get_site_id();

		$script = $this->app->get_deployment_script( $site_id, $deployment->changeset_id );

		if ( is_wp_error( $script ) || empty( $script ) ) {
			$error_msg = __( 'Could not get deployment script from the app' );

			$error = new Error( Error::$Deploy_getDeploymentScript, $error_msg, array( 'changeset_id' => $deployment->changeset_id ) );
			$this->add_error_notice( $deployment->changeset_id, $error_msg, $error );

			return $error;
		}

		$deployment->script = $script;

		return $deployment;
	}

	/**
	 * Deploy the SQL script
	 *
	 * @param Deployment $deployment
	 *
	 * @return bool|Error
	 */
	public function deploy_sql_script( $deployment ) {
		$result = $this->execute_sql_script( $deployment );

		// Delete script file
		unlink( $deployment->file );

		if ( is_wp_error( $result ) ) {
			$this->add_error_notice( $deployment->changeset_id, $result->get_error_message(), $result );

			return $result;
		}

		return $result;
	}

	/**
	 * Get all the lines of a file
	 *
	 * @param string $file
	 *
	 * @return array
	 */
	protected function get_file_lines( $file ) {
		return file( $file );
	}

	/**
	 * Execute SQL query script
	 *
	 * @param Deployment $deployment
	 *
	 * @return Deployment|Error
	 */
	public function execute_sql_script( $deployment ) {
		$lines = $this->get_file_lines( $deployment->file );

		if ( empty( $lines ) ) {
			$error_msg  = __( 'No query lines in SQL script' );
			$error_data = array(
				'changeset_id' => $deployment->changeset_id,
				'file'         => $deployment->file,
			);

			return new Error( Error::$Deploy_emptyScript, $error_msg, $error_data );
		}

		global $wpdb;

		set_time_limit( 0 );
		$wpdb->query( 'START TRANSACTION;' );

		$current_query = '';
		$finish_time   = $this->get_execution_finish_time();

		$insert_ids = array();

		foreach ( $lines as $line ) {
			if ( '' === $line ) {
				// Skip if line break
				continue;
			}

			if ( '--' === substr( $line, 0, 2 ) || '#' === substr( $line, 0, 1 ) ) {
				// Skip if it's a comment
				continue;
			}

			// Add this line to the current query
			$current_query .= $line;

			if ( false === strpos( $line, self::QUERY_EOL ) ) {
				// Doesn't have our end of query comment, not the end of the query
				continue;
			}

			// Update the string totals of serialized values containing placeholders
			$current_query = $this->update_serialized_string_totals( $insert_ids, $current_query );

			// Replace insert id placeholders with actual IDs
			$current_query = str_replace( array_keys( $insert_ids ), array_values( $insert_ids ), $current_query );

			// Mark the query as internal so we don't record it
			$current_query .= '#' . $this->bot->slug();

			// Perform the query
			$result = $wpdb->query( $current_query );
			if ( $result === false ) {
				$error_msg  = __( 'SQL query execution failed' );
				$error_data = array(
					'changeset_id' => $deployment->changeset_id,
					'sql'          => $line,
					'error'        => $wpdb->last_error,
				);
				$error      = new Error( Error::$Deploy_queryExecFailed, $error_msg, $error_data );

				return $this->rollback_deploy( $error );
			}

			$insert = $this->get_last_insert_id( $deployment, $current_query );
			if ( is_wp_error( $insert ) ) {
				return $this->rollback_deploy( $insert );
			}

			if ( false !== $insert ) {
				// Store LAST_INSERT_ID and variable for replacements of subsequent queries
				$insert_ids[ $insert[0] ] = $insert[1];
			}

			if ( $this->time_exceeded( $finish_time ) ) {

				$error_msg  = __( 'Maximum execution time reached' );
				$error_data = array(
					'changeset_id' => $deployment->changeset_id,
					'sql'          => $line,
				);

				$error = new Error( Error::$Deploy_maxExecTimeReached, $error_msg, $error_data );

				// Time limit exceeded
				return $this->rollback_deploy( $error );
			}

			if ( $this->memory_exceeded() ) {
				$error_msg  = __( 'Memory usage exceeded' );
				$error_data = array(
					'changeset_id' => $deployment->changeset_id,
					'sql'          => $line,
					'memory_usage' => memory_get_usage( true ),
					'memory_limit' => $this->get_memory_limit(),
				);

				$error = new Error( Error::$Deploy_maxMemoryReached, $error_msg, $error_data );

				// Memory limit exceeded
				return $this->rollback_deploy( $error );
			}

			// Reset temp variable to empty
			$current_query = '';
		}

		$wpdb->query( 'COMMIT;' );

		// Flush object cache
		wp_cache_flush();

		return true;
	}

	/**
	 * Get the last row of data from a SELECT query
	 *
	 * @param \wpdb $wpdb
	 *
	 * @return mixed
	 */
	protected function get_last_db_result( $wpdb ) {
		return $wpdb->last_result[0];
	}

	/**
	 * If the SQL storing the INSERT ID, get it with the placeholder
	 *
	 * @param Deployment $deployment
	 * @param string     $current_query
	 *
	 * @return array|bool|Error Inserts will be returned as array with key as placeholder and value as ID
	 */
	protected function get_last_insert_id( $deployment, $current_query ) {
		if ( false === strpos( $current_query, 'LAST_INSERT_ID()' ) ) {
			return false;
		}

		global $wpdb;

		$last_row = $this->get_last_db_result( $wpdb );

		if ( empty( $last_row ) ) {
			// Can't get last row selected
			$error_msg  = __( 'Could not get last SELECT row data' );
			$error_data = array(
				'changeset_id' => $deployment->changeset_id,
				'sql'          => $current_query,
				'last_result'  => $wpdb->last_result,
			);

			return new Error( Error::$Deploy_lastSelectRowFail, $error_msg, $error_data );
		}

		// Extract last insert ID and variable from the SELECT statement
		$values = array_values( get_object_vars( $last_row ) );

		if ( ! isset( $values[0] ) || ! isset( $values[1] ) ) {
			// Can't get LAST_INSERT_ID and variable to use
			$error_msg  = __( 'Could not get last INSERT ID from query' );
			$error_data = array(
				'changeset_id' => $deployment->changeset_id,
				'sql'          => $current_query,
			);

			return new Error( Error::$Deploy_lastInsertIDFail, $error_msg, $error_data );
		}

		return $values;
	}

	/**
	 * Get the string prefix for a INSERT ID placeholder
	 *
	 * @return string
	 */
	protected function get_placeholder_prefix() {
		return '@' . $this->bot->slug() . '_query_';
	}

	/**
	 * Make sure we correctly set the length of serialized data strings with placeholders
	 *
	 * @param array  $insert_ids
	 * @param string $current_query
	 *
	 * @return string
	 */
	protected function update_serialized_string_totals( $insert_ids, $current_query ) {
		preg_match_all( '/s:\d*:"(.*?)";/', $current_query, $matches );

		if ( ! isset( $matches[0] ) || empty( $matches[0] ) ) {
			// The query doesn't contain any serialized data
			return $current_query;
		}

		$prefix = $this->get_placeholder_prefix();

		foreach ( $matches[1] as $key => $match ) {
			if ( false === strpos( $match, $prefix ) ) {
				continue;
			}

			preg_match_all( '/' . $prefix . '(\d+)/', $match, $placeholder_matches );
			if ( ! isset( $placeholder_matches[0] ) || empty( $placeholder_matches[0] ) ) {
				// There are no placeholder strings in the serialized data
				continue;
			}

			$placeholder_length = 0;
			$correct_length     = 0;
			foreach ( $placeholder_matches[0] as $placeholder_match ) {
				if ( ! isset( $insert_ids[ $placeholder_match ] ) ) {
					// No INSERT recorded for that placeholder
					continue;
				}

				// Find the ID we have inserted the placeholder as
				$deployed_id = $insert_ids[ $placeholder_match ];

				$placeholder_length += strlen( $placeholder_match );
				$correct_length += strlen( (string) $deployed_id );
			}

			if ( ! $placeholder_length || ! $correct_length ) {
				continue;
			}

			// Get the old whole serialized element
			$old_part = $matches[0][ $key ];

			// Calculate the new length and replace the value and length in the query's serialized data
			$new_length = strlen( $match ) - $placeholder_length + $correct_length;
			$new_match  = preg_replace( '/s:(\d+):"(.*)";/', 's:' . $new_length . ':"$2";', $old_part );

			$current_query = str_replace( $old_part, $new_match, $current_query );
		}

		return $current_query;
	}

	/**
	 * Rollback the deployment transaction
	 *
	 * @param Error $error
	 *
	 * @return Error
	 */
	protected function rollback_deploy( $error ) {
		global $wpdb;

		// Rollback transaction
		$wpdb->query( 'ROLLBACK;' );

		return $error;
	}

	/**
	 * Download the deployment script
	 *
	 * @param Deployment $deployment
	 *
	 * @return bool|Error
	 */
	public function download_deployment_script( $deployment ) {
		$response = $this->app->get_deployment_script_file( $deployment->script->download_url );

		if ( is_wp_error( $response ) ) {
			$error_msg = sprintf( __( 'Could not download deployment script from the app: %s' ), $response->get_error_message() );
			$error     = new Error( Error::$Deploy_downloadDeploymentScript, $error_msg, array(
				'changeset_id' => $deployment->changeset_id,
				'download_url' => $deployment->script->download_url,
				'error'        => $response->get_error_message(),
			) );
			$this->add_error_notice( $deployment->changeset_id, $error_msg, $error );

			return $error;
		}

		// Decode gzipped response
		$raw_response = \WP_Http_Encoding::decompress( $response );

		if ( false === $raw_response || $raw_response === $response ) {
			// Gzip decoding error
			$error_msg = __( 'Downloaded deployment script unzip failure' );
			$error     = new Error( Error::$Deploy_deploymentScriptGzip, $error_msg, array(
				'changeset_id' => $deployment->changeset_id,
				'script'       => $response,
			) );
			$this->add_error_notice( $deployment->changeset_id, $error_msg, $error );

			return $error;
		}

		// Check checksum
		if ( $deployment->script->checksum !== sha1( $raw_response ) ) {
			$error_msg = __( 'Downloaded deployment script different to original' );
			$error     = new Error( Error::$Deploy_deploymentScriptChecksum, $error_msg, array( 'changeset_id' => $deployment->changeset_id ) );
			$this->add_error_notice( $deployment->changeset_id, $error_msg, $error );

			return $error;
		}

		$fp = @fopen( $deployment->file, "w" );
		if ( false === $fp ) {
			$error_msg = __( 'Uable to write script to local file' );
			$error     = new Error( Error::$Deploy_deploymentScriptFileWrite, $error_msg, array( 'changeset_id' => $deployment->changeset_id ) );
			$this->add_error_notice( $deployment->changeset_id, $error_msg, $error );

			return $error;
		}

		fwrite( $fp, $raw_response );
		fclose( $fp );

		return true;
	}

	/**
	 * Wrapper for file_exists()
	 *
	 * @param string $file
	 *
	 * @return bool
	 */
	protected function file_exists( $file ) {
		return file_exists( $file );
	}

	/**
	 * Check the download file exists
	 *
	 * @param Deployment $deployment
	 *
	 * @return bool|Error
	 */
	public function check_download_file_exists( $deployment ) {
		$exists = $this->file_exists( $deployment->file );
		if ( false === $exists ) {
			$error_msg = __( 'Downloaded deployment script file does not exist' );
			$error     = new Error( Error::$Deploy_downloadedScriptFileNotExists, $error_msg, array( 'file' => $deployment->file ) );

			$this->add_error_notice( $deployment->changeset_id, $error_msg, $deployment->file );

			return $error;
		}

		return true;
	}

	/**
	 * Check the deployment ID is in the options table
	 *
	 * @param Deployment $deployment
	 * @param bool|false $exists Are we checking if the key exists?
	 *
	 * @return bool|Error
	 */
	public function check_deployed_changeset_option_key( $deployment, $exists = false ) {
		// Already deployed
		$error_msg  = __( 'Changeset already deployed' );
		$error_code = Error::$Deploy_deploymentKeyExistsPre;
		if ( $exists ) {
			// Not deployed correctly
			$error_msg  = __( 'Deployment did not contain internal ID option INSERT' );
			$error_code = Error::$Deploy_deploymentKeyNotExistsPost;
			$exists     = $deployment->changeset_id;
		}

		if ( $exists !== get_site_option( $this->changeset_handler->get_deployed_changeset_option_key( $deployment->changeset_id ), false ) ) {
			$error = new Error( $error_code, $error_msg, array( 'changeset_id' => $deployment->changeset_id ) );
			$this->add_error_notice( $deployment->changeset_id, $error_msg, $error );

			return $error;
		}

		return true;
	}
}