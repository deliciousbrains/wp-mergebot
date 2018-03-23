<?php

namespace DeliciousBrains\Mergebot;

use DeliciousBrains\Mergebot\Services\Admin\Plugin_Installer;

/**
 * The class for the uninstalling the plugin.
 *
 * @since 0.1
 */
class Uninstall {

	/**
	 * Uninstall the plugin
	 */
	public static function init() {
		// Remove settings
		self::delete_site_option_by_partial_key( 'mergebot_%' );

		// Remove transients
		self::delete_site_option_by_partial_key( '_site_transient_timeout_mergebot_%' );
		self::delete_site_option_by_partial_key( '_site_transient_mergebot_%' );

		// Remove user meta
		self::delete_user_meta_by_partial_key( 'mergebot_%' );

		// Remove database tables
		global $wpdb;

		$migrations = Plugin_Installer::get_migrations( dirname( dirname( __FILE__ ) ) );
		$prefix     = $wpdb->base_prefix . 'mergebot_';

		foreach ( $migrations as $migration ) {
			$wpdb->query( "DROP TABLE IF EXISTS " . $migration->get_table_name( $prefix ) );
		}

		wp_cache_flush();
	}

	/**
	 * Delete site options by a partial key.
	 * Works for single site and multisite installs.
	 *
	 * @param string $key
	 */
	public static function delete_site_option_by_partial_key( $key ) {
		global $wpdb;

		$table_name  = 'options';
		$column_name = 'option_name';
		if ( is_multisite() ) {
			$table_name  = 'sitemeta';
			$column_name = 'meta_key';
		}

		// Remove deployment keys
		$statement = "DELETE FROM {$wpdb->$table_name} WHERE `{$column_name}` LIKE %s";

		$wpdb->query( $wpdb->prepare( $statement, $key ) );
	}

	/**
	 * Delete user meta by a partial key.
	 *
	 * @param $key
	 */
	protected static function delete_user_meta_by_partial_key( $key ) {
		global $wpdb;

		// Remove user meta
		$statement = "DELETE FROM {$wpdb->usermeta} WHERE `meta_key` LIKE %s";

		$wpdb->query( $wpdb->prepare( $statement, $key ) );
	}
}