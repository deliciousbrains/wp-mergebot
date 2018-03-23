<?php

/**
 * The Changeset Model class representing the application's changeset
 *
 * @since 0.1
 */

namespace DeliciousBrains\Mergebot\Models;

use DeliciousBrains\Mergebot\Services\App_Interface;
use DeliciousBrains\Mergebot\Utils\Config;
use DeliciousBrains\Mergebot\Utils\Error;

class App_Changeset extends Base_Model {

	/**
	 * @var int
	 */
	protected $id;

	/**
	 * @var string
	 */
	protected $status;

	/**
	 * @var string
	 */
	protected $link;

	/**
	 * @var int
	 */
	protected $total_queries;

	/**
	 * @var string
	 */
	protected $queries_link;

	/**
	 * @var
	 */
	protected $created_at;

	/**
	 * @var
	 */
	protected $conflicts;

	/**
	 * @var
	 */
	protected $can_deploy;

	/**
	 * @var
	 */
	protected $can_deploy_errors;

	/**
	 * @var
	 */
	protected $is_site_recording;

	/**
	 * @var
	 */
	protected $remaining_queries;

	/**
	 * @param string $mode
	 *
	 * @return array
	 */
	public static function get_deployed_status( $mode = Config::MODE_DEV ) {
		$statuses = array(
			Config::MODE_DEV  => 'deployed',
			Config::MODE_PROD => 'deployed-prod'
		);

		return $statuses[ $mode ];
	}

	/**
	 * Get the statuses that mean a deployment is closed
	 *
	 * @return array
	 */
	protected function get_closed_statuses() {
		$prod_deployed = self::get_deployed_status( Config::MODE_PROD );

		return array(
			'rejected',
			'closed',
			$prod_deployed,
		);
	}

	/**
	 * Is the changeset still open?
	 *
	 * @return bool
	 */
	public function is_open() {
		return ! in_array( $this->status, $this->get_closed_statuses() );
	}

	/**
	 * Has the changeset been rejected?
	 *
	 * @return bool
	 */
	public function is_rejected() {
		return'rejected' === $this->status;
	}

	/**
	 * Has the changeset been deployed on production?
	 *
	 * @return bool
	 */
	public function is_deployed_on_prod() {
		return self::get_deployed_status( Config::MODE_PROD ) === $this->status;
	}

	/**
	 * Get the open changeset for the site
	 *
	 * @param App_Interface $app
	 * @param int           $site_id
	 *
	 * @return bool
	 */
	public static function get( App_Interface $app, $site_id ) {
		$current_changeset = $app->get_site_changeset( $site_id );

		if ( empty( $current_changeset ) ) {
			return false;
		}

		if ( is_wp_error( $current_changeset ) ) {
			return $current_changeset;
		}

		return static::create( (array) $current_changeset );
	}

	/**
	 * Find a specific changeset by its ID
	 *
	 * @param App_Interface $app
	 * @param int           $id
	 * @param int           $site_id
	 * @param bool          $with_conflicts
	 *
	 * @return false|static
	 */
	public static function find( App_Interface $app, $id, $site_id = 1, $with_conflicts = false ) {
		$changeset = $app->get_changeset( $id, $site_id, $with_conflicts );

		if ( is_wp_error( $changeset ) && 404 === $changeset->get_api_response_code() ) {
			// Deployment since delete from app
			return false;
		}

		if ( ! isset( $changeset->status ) ) {
			new Error( Error::$Changeset_getFailed, sprintf( __( 'Could not get the changeset #%s' ), $id ), $changeset );

			return false;
		}

		return static::create( (array) $changeset );
	}

	/**
	 * Map the app data to the plugin model format
	 *
	 * @return array
	 */
	public function to_plugin() {
		$data = $this->to_array();

		$data['changeset_id']      = $data['id'];
		$data['date_created']      = $data['created_at'];
		$data['deployed']          = 0;
		$data['can_deploy_errors'] = maybe_serialize( $data['can_deploy_errors'] );

		unset( $data['id'] );
		unset( $data['created_at'] );
		unset( $data['conflicts'] );

		return $data;
	}
}
