<?php

/**
 * The class that copies the changeset from the app to the plugin and keeps it in sync
 *
 * @since 0.1
 */

namespace DeliciousBrains\Mergebot\Services;

use DeliciousBrains\Mergebot\Models\App_Changeset;
use DeliciousBrains\Mergebot\Models\Changeset;
use DeliciousBrains\Mergebot\Models\Notice;
use DeliciousBrains\Mergebot\Models\Plugin;
use DeliciousBrains\Mergebot\Utils\Error;

class Changeset_Synchronizer {

	/**
	 * @var Plugin;
	 */
	protected $bot;

	/**
	 * @var Site_Register
	 */
	protected $site_register;

	/**
	 * @var Conflict_Receiver
	 */
	protected $conflict_receiver;

	/**
	 * @var App_Interface
	 */
	protected $app;

	/**
	 * Changeset_Synchronizer constructor.
	 *
	 * @param Plugin            $bot
	 * @param Site_Register     $site_register
	 * @param Conflict_Receiver $conflict_receiver
	 * @param App_Interface     $app
	 */
	public function __construct( Plugin $bot, Site_Register $site_register, Conflict_Receiver $conflict_receiver, App_Interface $app ) {
		$this->bot               = $bot;
		$this->site_register     = $site_register;
		$this->conflict_receiver = $conflict_receiver;
		$this->app               = $app;
	}

	/**
	 * Get the current changeset for the site
	 *
	 * @param int $site_id
	 *
	 * @return Changeset|false
	 */
	public function get_changeset( $site_id ) {
		// Have we got a changeset in our plugin table?
		$changeset = Changeset::get();

		if ( false !== $changeset ) {
			$changeset = $this->verify_changeset_with_app( $changeset, $site_id );
		}

		if ( false !== $changeset ) {
			return $changeset;
		}

		// No local valid changeset, get the open one from app
		$open_changeset = App_Changeset::get( $this->app, $site_id );

		if ( false === $open_changeset ) {
			// No open deployment on the app, bail.
			return false;
		}

		if ( is_wp_error( $open_changeset ) ) {
			// Error with the changeset or site doesn't exist on app, clean up
			$this->site_register->maybe_disconnect_site( $open_changeset );

			return false;
		}

		$changeset = $this->insert( $open_changeset );

		return $changeset;
	}

	/**
	 * Insert Changeset
	 *
	 * @param App_Changeset $changeset
	 *
	 * @return Changeset|false
	 */
	protected function insert( App_Changeset $changeset ) {
		$data = $changeset->to_plugin();

		$changeset = Changeset::create( $data )->save();

		if ( is_wp_error( $changeset ) ) {
			return false;
		}

		return $changeset;
	}

	/**
	 * Verify the local changeset still exists on the app
	 *
	 * @param Changeset $changeset
	 * @param int       $site_id
	 *
	 * @return bool|Changeset
	 */
	protected function verify_changeset_with_app( Changeset $changeset, $site_id ) {
		// Check the status of an existing changeset, ie. make sure not rejected
		$app_changeset = App_Changeset::find( $this->app, $changeset->changeset_id, $site_id );

		if ( false === $app_changeset || ! $app_changeset->is_open() ) {
			$this->remove( $changeset );

			return false;
		}

		$this->refresh_changeset( $changeset, $app_changeset, false );

		return $changeset;
	}

	/**
	 * Update conflicts for a changeset and changeset meta data
	 *
	 * @param Changeset     $old_changeset
	 * @param App_Changeset $new_changeset
	 *
	 * @return Changeset
	 */
	public function refresh_changeset( Changeset $old_changeset, App_Changeset $new_changeset ) {
		// Update changeset meta
		$changeset = $this->update_meta_data( $old_changeset, $new_changeset );

		// Update query limit for changeset ID
		$this->update_query_limit( $new_changeset->id );

		do_action( $this->bot->slug() . '_refresh_changeset', $old_changeset, $new_changeset );

		return $changeset;
	}

	/**
	 * Update the meta data for the saved changeset with the latest app changeset
	 *
	 * @param Changeset     $existing
	 * @param App_Changeset $latest
	 *
	 * @return Changeset
	 */
	protected function update_meta_data( Changeset $existing, App_Changeset $latest ) {
		$meta_keys = $existing->get_meta_columns();
		$count     = 0;

		foreach ( $meta_keys as $key ) {
			$new_value = $latest->{$key};
			if ( is_array( $latest->{$key} ) ) {
				$new_value = serialize( $new_value );
			}

			if ( $new_value != $existing->{$key} ) {
				$count ++;
				$existing->{$key} = $new_value;
			}
		}

		if ( 0 === $count ) {
			return $existing;
		}

		$existing->save();

		return $existing;
	}

	/**
	 * Check the changeset is valid and refresh conflicts and other data
	 *
	 * @param Changeset $changeset
	 * @param int       $site_id
	 *
	 * @return Changeset|Error
	 */
	public function maybe_check_and_refresh_changeset( Changeset $changeset, $site_id ) {
		// Status check with conflict data
		$app_changeset = App_Changeset::find( $this->app, $changeset->changeset_id, $site_id, true );

		if ( false === $app_changeset ) {
			return new Error( Error::$Changeset_missingOnApp, sprintf( __( 'There was an error generating the deployment for Changeset %s.' ), $changeset->changeset_id ) );
		}

		if ( 'rejected' === $app_changeset->status ) {
			return new Error( Error::$Changeset_rejected, sprintf( __( 'Changeset %s cannot be deployed as it has been rejected.' ), $changeset->changeset_id ) );
		}

		if ( $this->bot->is_dev_mode() && 'deployed-prod' === $app_changeset->status ) {
			return new Error( Error::$Changeset_deployedProd, sprintf( __( 'Changeset %s cannot be deployed as it has already been deployed on %s.' ), $changeset->changeset_id, $this->site_register->get_parent_site_url() ) );
		}

		if ( ! $this->bot->is_dev_mode() && false === $app_changeset->can_deploy ) {
			return new Error( Error::$Changeset_cantDeployOnProd, sprintf( __( 'Changeset %s cannot be deployed as there are issues on the Development site.' ), $changeset->changeset_id ), $app_changeset->can_deploy_errors );
		}

		// Update conflict data from app and refresh changeset
		$this->conflict_receiver->receive( $app_changeset );

		$changeset = $this->refresh_changeset( $changeset, $app_changeset );

		return $changeset;
	}

	/**
	 * Remove changeset from the plugin database
	 *
	 * @param Changeset $changeset
	 */
	public function remove( $changeset ) {
		$changeset_id = $changeset->changeset_id;
		// Delete the changeset and conflict data
		$changeset->clear();

		$query_limit = get_site_option( $this->bot->slug() . '_query_limit' );
		if ( false !== $query_limit && $changeset_id == $query_limit ) {
			// Remove query limit flag if we are closing that changeset
			delete_site_option( $this->bot->slug() . '_query_limit' );
			Notice::delete_by_id( 'query-limit' );
		}
	}

	/**
	 * Update the query limit option with the Changeset ID
	 *
	 * @param int $id
	 */
	protected function update_query_limit( $id ) {
		$query_limit = get_site_option( $this->bot->slug() . '_query_limit' );

		if ( false !== $query_limit && 0 === (int) $query_limit ) {
			// Update the changeset of the query limit
			update_site_option( $this->bot->slug() . '_query_limit', $id );
		}
	}

}