<?php

/**
 * The class for the WP CLI mergebot changeset commands.
 *
 * @since 0.1
 */

namespace DeliciousBrains\Mergebot\CLI;

class CLI_Changeset extends CLI_Site  {

	/**
	 * View and control the open changeset
	 *
	 * ## OPTIONS
	 *
	 * [--discard]
	 * : Discard the changeset on the app
	 *
	 * ## EXAMPLES
	 *     wp mergebot changeset
	 *     wp mergebot changeset --discard
	 *
	 * @param array $args
	 * @param array $assoc_args
	 *
	 * @return bool
	 */
	public function changeset( $args, $assoc_args ) {
		if ( ! $this->is_setup() ) {
			return false;
		}

		if ( false === ( $site_id = $this->settings_handler->get_site_id() ) ) {
			\WP_CLI::warning( __( 'Site not connected' ) );

			return false;
		}

		// Get open changeset
		$changeset = $this->changeset_synchronizer->get_changeset( $site_id );

		if ( false === $changeset ) {
			return $this->error( __( 'No open changeset' ) );
		}

		if ( isset( $assoc_args['discard'] ) ) {
			if ( false === $this->changeset_handler->reject( $changeset->changeset_id ) ) {
				return $this->error( __( 'Could not reject changeset' ) );
			}

			\WP_CLI::success( sprintf( __( 'Changeset #%s rejected' ), $changeset->changeset_id ) );

			return true;
		}

		$changeset = (array) $changeset->to_array();
		\WP_CLI\Utils\format_items( 'table', array( $changeset ), array_keys( $changeset ) );

		return true;
	}
}