<?php

/**
 * The class used by the mu-plugin.
 *
 * Prevents 3rd party plugins from being loaded during Mergebot specific operations.
 *
 * @since 0.1
 */

class Mergebot_MU_Plugin {

	/**
	 * Initialize hooks.
	 */
	public function init() {
		add_filter( 'option_active_plugins', array( $this, 'remove_plugins_from_loading' ) );
		add_filter( 'wpmdb_compatibility_plugin_whitelist', array( $this, 'whitelist_plugin_for_wpmdb_requests' ) );
	}

	/**
	 * Ensure Mergebot still runs during WPMDB requests.
	 *
	 * @param array $plugins
	 *
	 * @return array
	 */
	public function whitelist_plugin_for_wpmdb_requests( $plugins ) {
		$plugins[] = 'mergebot';

		return $plugins;
	}

	/**
	 * Remove plugins from being loaded during our AJAX requests.
	 *
	 * @param array $plugins Numerically keyed array of plugin names.
	 *
	 * @return array
	 */
	public function remove_plugins_from_loading( $plugins ) {
		if ( ! is_array( $plugins ) || empty( $plugins ) ) {
			return $plugins;
		}

		if ( ! $this->is_mergebot_ajax_request() ) {
			return $plugins;
		}

		$whitelisted_plugins = apply_filters( 'mergebot_request_whitelisted_plugins', array( 'mergebot/mergebot.php' ) );
		foreach ( $plugins as $key => $plugin ) {
			if ( in_array( $plugin, $whitelisted_plugins ) ) {
				continue;
			}

			unset( $plugins[ $key ] );
		}

		return array_values( $plugins );
	}

	/**
	 * Is this a Mergebot AJAX request?
	 *
	 * @return bool
	 */
	public function is_mergebot_ajax_request() {
		if ( ! defined( 'DOING_AJAX' ) || ! DOING_AJAX ) {
			return false;
		}

		if ( ! isset( $_REQUEST['action'] ) ) {
			return false;
		}

		if ( false === strpos( $_REQUEST['action'], 'mergebot' ) ) {
			return false;
		}

		return true;
	}
}

