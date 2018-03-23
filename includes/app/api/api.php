<?php

namespace DeliciousBrains\Mergebot\App\API;

class API {

	/**
	 * @var string
	 */
	protected $key;

	/**
	 * @var string
	 */
	protected $url;

	/**
	 * @var int
	 */
	protected $version;

	/**
	 * API constructor.
	 */
	public function __construct() {
		$this->version = 1;
	}

	/**
	 * @param string $api_key
	 */
	public function set_key( $api_key ) {
		$this->key = $api_key;
	}

	/**
	 * @param string $api_url
	 */
	public function set_url( $api_url ) {
		$this->url = $api_url;
	}

	/**
	 * Get the production sites registered for the API key
	 *
	 * @param int $site_id
	 *
	 * @return array|bool|object
	 */
	public function get_sites( $site_id = 0 ) {
		return $this->get( 'sites/' . $site_id );
	}

	/**
	 * Get the development sites connected to a production site
	 *
	 * @param int $site_id
	 *
	 * @return array|bool|object
	 */
	public function get_connected_sites( $site_id ) {
		return $this->get( 'sites/connected/' . $site_id );
	}

	/**
	 * Register a site with the app
	 *
	 * @param array $data
	 *
	 * @return array|bool|object
	 */
	public function post_site( $data ) {
		$site = $this->post( 'sites', $data );

		return $site;
	}

	/**
	 * Get the settings for a site
	 *
	 * @param string $unique_id
	 *
	 * @return array|bool|object
	 */
	public function get_site_settings( $unique_id ) {
		return $this->get( 'sites/settings/' . $unique_id );
	}

	/**
	 * Post settings for a site
	 *
	 * @param array $data
	 *
	 * @return array|bool|object
	 */
	public function post_site_settings( $data ) {
		$site = $this->post( 'sites/settings', $data );

		return $site;
	}

	/**
	 * Post plugins for a site
	 *
	 * @param array $data
	 *
	 * @return array|bool|object
	 */
	public function post_site_plugins( $data ) {
		$site = $this->post( 'sites/plugins', $data );

		return $site;
	}

	/**
	 * Post theme for a site
	 *
	 * @param array $data
	 *
	 * @return array|bool|object
	 */
	public function post_site_themes( $data ) {
		$site = $this->post( 'sites/themes', $data );

		return $site;
	}

	/**
	 * Get the teams the user is part of
	 *
	 * @return array|bool|object
	 */
	public function get_teams() {
		return $this->get( 'teams' );
	}

	/**
	 * Post a set of queries and associated pre update data
	 *
	 * @param int   $site_id
	 * @param array $queries
	 * @param int   $remaining_queries
	 *
	 * @return array|bool|object
	 */
	public function post_queries( $site_id, $queries, $remaining_queries = 0 ) {
		$data = array(
			'site_id'           => $site_id,
			'queries'           => $queries,
			'remaining_queries' => $remaining_queries,
		);

		$changeset = $this->post( 'queries', $data );

		return $changeset;
	}

	/**
	 * Get the open changeset not deployed to production.
	 *
	 * @param string $site_id
	 *
	 * @return array|bool|object
	 */
	public function get_site_changeset( $site_id ) {
		return $this->get( 'changesets/open/' . $site_id );
	}

	/**
	 * Get a changeset
	 *
	 * @param string $changeset_id
	 * @param int    $site_id
	 * @param bool   $conflicts With conflict data
	 *
	 * @return array|bool|object
	 */
	public function get_changeset( $changeset_id, $site_id, $conflicts = false ) {
		$url = 'changesets/status/' . $changeset_id;

		$params = array( 'site_id' => $site_id );
		if ( $conflicts ) {
			$params['conflicts'] = 1;
		}

		return $this->get( $url, array(), $params );
	}

	/**
	 * Send latest data for conflict checking
	 *
	 * @param array $data
	 *
	 * @return array|bool|object
	 */
	public function post_production_data( $data ) {
		return $this->post( 'changesets', $data );
	}

	/**
	 * Change a changesets's status
	 *
	 * @param int    $id
	 * @param string $status
	 * @param string $failed_reason
	 *
	 * @return array|bool|object
	 */
	public function post_changeset_status( $id, $status, $failed_reason = '' ) {
		$data = array(
			'changesets' => array(
				array(
					'id'            => $id,
					'status'        => $status,
					'failed_reason' => $failed_reason,
				),
			),
		);

		return $this->post( 'changesets/status', $data );
	}

	/**
	 * Get the link to the deployment script for a changeset and script checksum
	 *
	 * @param int $site_id
	 * @param int $changeset_id
	 *
	 * @return array|bool|object
	 */
	public function get_deployment_script( $site_id, $changeset_id ) {
		$params = array( 'site_id' => $site_id );

		return $this->get( 'changesets/' . $changeset_id . '/script', array(), $params );
	}

	/**
	 * Get the deployment script file
	 *
	 * @param string $url
	 *
	 * @return array|bool|object
	 */
	public function get_deployment_script_file( $url ) {
		return $this->request( 'get', $url, array(), array(), false );
	}

	/**
	 * Send deployed IDs to the app for a deployment
	 *
	 * @param array $data
	 *
	 * @return array|bool|object
	 */
	public function post_deployment_ids( $data ) {
		$data = array(
			'deployment_ids' => $data,
		);

		return $this->post( 'deployment-ids', $data );
	}

	/**
	 * Get the primary key columns for all the tables
	 *
	 * @param int $site_id
	 *
	 * @return array|bool|object
	 */
	public function get_schema_primary_keys( $site_id ) {
		return $this->get( 'schema/' . $site_id . '/primary-keys' );
	}

	/**
	 * Get the AUTO INCREMENT columns for all the tables.
	 *
	 * @param int $site_id
	 *
	 * @return array|bool|object
	 */
	public function get_schema_auto_increment_columns( $site_id ) {
		return $this->get( 'schema/' . $site_id . '/auto-increment-columns' );
	}

	/**
	 * Get the ignored queries for the site.
	 *
	 * @param int $site_id
	 *
	 * @return array|bool|object
	 */
	public function get_schema_ignored_queries( $site_id ) {
		return $this->get( 'schema/' . $site_id . '/ignored-queries' );
	}

	/**
	 * Get the meta tables for the site.
	 *
	 * @param int $site_id
	 *
	 * @return array|bool|object
	 */
	public function get_schema_meta_tables( $site_id ) {
		return $this->get( 'schema/' . $site_id . '/meta-tables' );
	}

	/**
	 * Post a query and recording ID to attach and all subsequent queries
	 *
	 * @param int    $site_id
	 * @param int    $query_id
	 * @param string $query_recorded_at
	 * @param string $recording_id
	 *
	 * @return array|bool|object
	 */
	public function post_query_to_record_from( $site_id, $query_id, $query_recorded_at, $recording_id ) {
		$data = array(
			'site_id'           => $site_id,
			'query_id'          => $query_id,
			'query_recorded_at' => $query_recorded_at,
			'recording_id'      => $recording_id,
		);

		return $this->post( 'queries/record', $data );
	}

	/**
	 * HTTP Get request
	 *
	 * @param string $endpoint
	 * @param array  $args
	 * @param array  $params
	 *
	 * @return array|bool|object
	 */
	protected function get( $endpoint, $args = array(), $params = array() ) {
		return $this->request( 'get', $endpoint, $args, $params );
	}

	/**
	 * HTTP Post request
	 *
	 * @param string $endpoint
	 * @param array  $data
	 * @param array  $params
	 *
	 * @return array|bool|object
	 */
	protected function post( $endpoint, $data = array(), $params = array() ) {
		return $this->request( 'post', $endpoint, array( 'body' => $data ), $params );
	}

	/**
	 * HTTP Request
	 *
	 * @param string $type     HTTP request type GET, POST
	 * @param string $endpoint API endpoint
	 * @param array  $args     HTTP data
	 * @param array  $params   Query string parameters
	 * @param bool   $json     Exepcting JSON response
	 *
	 * @return array|mixed|object
	 * @throws API_Exception
	 */
	protected function request( $type = 'get', $endpoint, $args = array(), $params = array(), $json = true ) {
		$error_data = array( 'endpoint' => $endpoint, 'args' => $args );
		if ( is_null( $this->key ) ) {
			throw new API_Exception( __( 'No API key supplied' ), API_Exception::$API_noKeySupplied, $error_data );
		}

		$url = $this->get_url( $endpoint, $params );

		$defaults = $this->get_default_request_args();

		$args = array_merge( $defaults, $args );
		$args = apply_filters( 'mergebot_request_args', $args, $endpoint );

		$http    = _wp_http_get_object();
		$request = $http->{$type}( $url, $args );

		if ( is_wp_error( $request ) ) {
			$error_data['wp_error'] = $request;

			throw new API_Exception( $request->get_error_message(), API_Exception::$API_HTTPRequest, $error_data );
		}

		$data = $json ? json_decode( $request['body'] ) : $request['body'];

		if ( '[]' !== $request['body'] && is_null( $data ) && $json ) {
			throw new API_Exception( $request['response']['code'] . ' - ' . $request['response']['message'], API_Exception::$API_JSONDecode, $error_data );
		}

		if ( $request['response']['code'] == 200 ) {
			return $data;
		} else {
			$message = sprintf( __( "API returned code %s, %s" ), $request['response']['code'], $request['response']['message'] );

			$error_data['code'] = $request['response']['code'];

			if ( false !== ( $error_message = $this->_get_error_message( $data ) ) ) {
				$message .= "\n" . $error_message;
				$error_data['message'] = $error_message;
			}

			throw new API_Exception( $message, API_Exception::$API_APIReturn, $error_data );
		}
	}

	/**
	 * Get error from API response
	 *
	 * @param object $data
	 *
	 * @return string|false
	 */
	protected function _get_error_message( $data ) {
		if ( isset( $data->error->message ) ) {
			return $data->error->message;
		}

		if ( is_string( $data->error ) ) {
			return $data->error;
		}

		return false;
	}

	/**
	 * Generate the API URL
	 *
	 * @param string $endpoint
	 * @param array  $params
	 *
	 * @return string
	 */
	protected function get_url( $endpoint, $params = array() ) {
		$params['api_token'] = $this->key;

		$params   = apply_filters( 'mergebot_request_query_params', $params );
		$endpoint = add_query_arg( $params, untrailingslashit( $endpoint ) );

		if ( false !== strpos( preg_replace( '(^https?://)', '', $endpoint ), preg_replace( '(^https?://)', '', $this->url ) ) ) {
			return $endpoint;
		}

		return $this->url . '/v' . $this->version . '/' . $endpoint;
	}

	/**
	 * Default request arguments passed to an HTTP request
	 *
	 * @see wp_remote_request() For more information on the available arguments.
	 *
	 * @return array
	 */
	protected function get_default_request_args() {
		return array(
			'timeout' => 60,
			'headers' => array( 'Accept' => 'application/json', 'Expect' => '' ),
		);
	}
}