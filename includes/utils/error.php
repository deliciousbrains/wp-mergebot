<?php

/**
 * The class for errors and logging.
 *
 * This extends WP_Error to handle all plugin errors.
 *
 * @since 0.1
 */

namespace DeliciousBrains\Mergebot\Utils;

use DeliciousBrains\Mergebot\App\API\API_Response;

class Error extends \WP_Error {

	// API errors
	public static $API_noKeySupplied = 101;
	public static $API_HTTPRequest = 102;
	public static $API_JSONDecode = 103;
	public static $API_APIReturn = 104;
	public static $API_APIDown = 105;
	// DB errors
	public static $DB_insertFailed = 201;
	public static $DB_updateFailed = 202;
	// SQL errors
	public static $SQL_createFailed = 301;
	public static $SQL_parseFailed = 305;
	// Recorder errors
	public static $Recorder_insertFailed = 401;
	public static $Recorder_getInsertIDFailed = 402;
	public static $Recorder_insertToSelectFailed = 403;
	public static $Recorder_selectInsertRowsFailed = 404;
	public static $Recorder_getPKColumnFailed = 405;
	public static $Recorder_getPKIDFailed = 406;
	public static $Recorder_getInsertIDSendFailed = 407;
	// Settings errors
	public static $Settings_noRecordingID = 501;
	// Dev App errors
	public static $Dev_App_sendQueriesFailed = 601;
	public static $Dev_App_openDeployments = 602;
	public static $Dev_App_databaseNotSynced = 603;
	// Deployment Errors
	public static $Deploy_scriptDirFailed = 701;
	public static $Deploy_queryExecFailed = 702;
	public static $Deploy_emptyScript = 703;
	public static $Deploy_lastInsertIDFail = 704;
	public static $Deploy_lastSelectRowFail = 705;
	public static $Deploy_maxExecTimeReached = 706;
	public static $Deploy_maxMemoryReached = 707;
	public static $Deploy_getDeploymentScript = 708;
	public static $Deploy_downloadDeploymentScript = 709;
	public static $Deploy_deploymentScriptChecksum = 710;
	public static $Deploy_deploymentKeyExistsPre = 711;
	public static $Deploy_deploymentKeyNotExistsPost = 712;
	public static $Deploy_sendInsertIDsFailed = 713;
	public static $Deploy_deploymentScriptGzip = 714;
	public static $Deploy_downloadedScriptFileNotExists = 715;
	public static $Deploy_appGenerateDeploymentFailed = 716;
	public static $Deploy_deploymentScriptFileWrite = 717;
	public static $Deploy_conflictSelectFailed = 719;
	// Changeset Errors
	public static $Changeset_statusUpdateFailed = 801;
	public static $Changeset_getFailed = 802;
	public static $Changeset_noID = 803;
	public static $Changeset_noConflicts = 804;
	public static $Changeset_rejected = 805;
	public static $Changeset_deployedProd = 806;
	public static $Changeset_missingOnApp = 807;
	public static $Changeset_sendConflictDataFailed = 808;
	public static $Changeset_missingOnPlugin = 809;
	public static $Changeset_cantDeployOnProd = 810;

	// Plugin Errors
	public static $Plugin_apiKeyNotDefined = 901;
	public static $Plugin_modeNotDefined = 902;
	public static $Plugin_apiMethodNotExists = 903;

	// MU Plugin Errors
	public static $MUPlugin_mupluginDirIssue = 1001;
	public static $MUPlugin_mupluginCopyIssue = 1002;

	// Schema Errors
	public static $schema_uniqueMetaTableMultipleKeys = 1101;

	/**
	 * Error constructor.
	 *
	 * @param string|int      $code  $error Error code
	 * @param string|\WP_Error $error Error message or instance of WP_Error
	 * @param mixed           $data  Optional. Error data.
	 * @param bool            $log
	 */
	public function __construct( $code = '', $error = '', $data = '', $log = true ) {
		$message = $error;

		if ( is_wp_error( $error ) ) {
			$message = $error->get_error_code() . ': ' . $error->get_error_message();
			$data    = ( $data ) ? $data : $error->get_error_data();
		}

		// Debug log
		if ( $log ) {
			$this->debug_log_error( $code, $message, $data );
		}

		parent::__construct( $code, $message, $data );
	}

	/**
	 * Log the error somewhere if we are debugging
	 *
	 * @param string       $code
	 * @param string       $message
	 * @param string|array $data
	 */
	protected function debug_log_error( $code = '', $message = '', $data = '' ) {
		if ( ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) || defined( 'TEST_SUITE_DIR' ) ) {
			return;
		}

		if ( is_string( $data ) && '' !== $data ) {
			$message .= ' (' . $data . ')';
		}

		error_log( 'Mergebot Error #' . $code . ': ' . $message );
		if ( is_array( $data ) || is_object( $data ) ) {
			error_log( print_r( $data, true ) );
		}
	}

	/**
	 * Get api response code for an error.
	 *
	 * @return int
	 */
	public function get_api_response_code() {
		$error_data = $this->get_error_data();

		if ( isset( $error_data['code'] ) ) {
			return (int) $error_data['code'];
		}

		return 200;
	}

	/**
	 * Get api response message for an error.
	 *
	 * @return int
	 */
	public function get_api_response_message() {
		$error_data = $this->get_error_data();

		if ( isset( $error_data['message'] ) ) {
			return $error_data['message'];
		}

		return '';
	}

	/**
	 * Magic method to support checks for API response on an Error object
	 * Ie. $error->is_unauthenticated()
	 *
	 * @param string $name
	 * @param array  $arguments
	 *
	 * @return bool
	 */
	public function __call( $name, $arguments  = array()) {
		if ( 0 !== strpos( $name, 'is_' ) ) {
			return false;
		}

		$response = str_replace( 'is_', '', $name );

		if ( ! isset( API_Response::$api_responses[ $response ] ) ) {
			return false;
		}

		return call_user_func_array( 'DeliciousBrains\Mergebot\App\API\API_Response::' . $name, array( $this->get_api_response_code(), $this->get_api_response_message() ) );
	}
}