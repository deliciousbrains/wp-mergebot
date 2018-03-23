<?php

/**
 * The Changeset Model class
 *
 * @since 0.1
 */

namespace DeliciousBrains\Mergebot\Models;

class Changeset extends Abstract_Database_Model {

	protected static $table_name = 'changesets';

	/**
	 * Get the current changeset
	 *
	 * @return false|array
	 */
	public static function get() {
		$result = static::query()->limit( 1 )->find();

		if ( is_array( $result ) && isset( $result[0] ) ) {
			return $result[0];
		}

		return false;
	}

	/**
	 * Delete changeset and remove conflicts based on the App changeset ID
	 *
	 * @return false|int
	 */
	public function clear() {
		Conflict::delete_by( 'changeset_id', $this->changeset_id );
		$this->delete();
	}

	/**
	 * Generate a URL to reject a changeset
	 *
	 * @param Plugin $bot
	 *
	 * @return string
	 */
	public function get_reject_url( Plugin $bot ) {
		$args = array(
			'nonce'  => wp_create_nonce( 'reject' ),
			'reject' => $this->changeset_id,
		);

		return $bot->get_url( $args );
	}

	/**
	 * Generate a deployment URL for a changeset
	 *
	 * @param Plugin $bot
	 * @param bool   $only_conflicts
	 *
	 * @return string
	 */
	public function get_deploy_url( Plugin $bot, $only_conflicts = false ) {
		$args = array(
			'nonce'    => wp_create_nonce( 'generate' ),
			'generate' => $this->changeset_id,
		);

		if ( $only_conflicts ) {
			$args['conflicts'] = 1;
		}

		return $bot->get_url( $args );
	}

	/**
	 * Get the deployment errors for the changeset.
	 *
	 * @return bool|array
	 */
	public function deploy_errors() {
		$errors = $this->can_deploy_errors;
		if ( empty( $errors ) ) {
			return false;
		}

		$all_errors = maybe_unserialize( $errors );
		if ( $errors === $all_errors ) {
			return false;
		}

		if ( ! is_array( $all_errors ) || empty( $all_errors ) ) {
			return false;
		}

		return $all_errors;
	}
}