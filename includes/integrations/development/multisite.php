<?php

/**
 * The class for adding WordPress Multisite integration.
 *
 * @since 0.1
 */

namespace DeliciousBrains\Mergebot\Integrations\Development;

class Multisite {

	/**
	 * Instantiate the hooks
	 */
	public function init() {
		add_action( 'populate_options', array( $this, 'suppress_ignoring_queries' ) );
	}

	/**
	 * Set the $wpdb suppress_ignore_query_check flag.
	 * This is so we don't check for ignore queries when recording adding a new blog,
	 * as the options are populated though one big INSERT statement, but the ignore check
	 * stops it from being recorded.
	 */
	public function suppress_ignoring_queries() {
		global $wpdb;
		$wpdb->suppress_ignore_query_check = true;
	}
}