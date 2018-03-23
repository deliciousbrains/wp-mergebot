<?php

namespace DeliciousBrains\Mergebot\Utils;


class Multisite {

	/**
	 * Get sites in the network with plugin activated.
	 *
	 * @return array
	 */
	public static function get_sites() {
		if ( ! is_multisite() ) {
			return array();
		}

		// Is plugin network activated? Get all blogs in network.
		$args = array(
			'number'   => null,
			'spam'     => 0,
			'deleted'  => 0,
			'archived' => 0,
		);

		$sites = get_sites( $args );

		return $sites;
	}

	/**
	 * Get all plugins activated for a MS install.
	 */
	public static function get_active_plugins() {
		$network_active_plugins = wp_get_active_network_plugins();
		$active_plugins         = array();

		foreach ( $network_active_plugins as $plugin ) {
			$active_plugins[] = Support::remove_wp_plugin_dir( $plugin );
		}

		$sites_in_network = self::get_sites();

		foreach( $sites_in_network as $site ) {
			$site_active_plugins = get_blog_option( $site->blog_id, 'active_plugins', array() );

			$active_plugins = array_merge( $active_plugins, $site_active_plugins );
		}

		return array_unique( $active_plugins );
	}

	/**
	 * Get all plugins activated for a MS install.
	 */
	public static function get_active_themes() {
		$themes = array();
		global $wp_theme_directories;
		$sites_in_network = self::get_sites();
		foreach ( $sites_in_network as $site ) {
			$stylesheet = get_blog_option( $site->blog_id, 'stylesheet' );
			if ( isset( $themes[ $stylesheet ] ) ) {
				continue;
			}
			$theme_root = get_raw_theme_root( $stylesheet );
			if ( ! in_array( $theme_root, (array) $wp_theme_directories ) ) {
				$theme_root = WP_CONTENT_DIR . $theme_root;
			}
			$themes[ $stylesheet ] = new \WP_Theme( $stylesheet, $theme_root );
		}

		return array_values( $themes );
	}
}