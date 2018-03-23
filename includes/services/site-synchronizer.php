<?php

/**
 * The class for synchronizing the site data with the app
 *
 * @since 0.1
 */

namespace DeliciousBrains\Mergebot\Services;

use DeliciousBrains\Mergebot\Services\Admin\Settings_Handler;
use DeliciousBrains\Mergebot\Models\Plugin;

class Site_Synchronizer {

	/**
	 * @var Plugin
	 */
	protected $bot;

	/**
	 * @var Site_Register
	 */
	protected $site_register;

	/**
	 * @var App_Interface
	 */
	protected $app;

	/**
	 * @var Settings_Handler
	 */
	protected $settings_handler;

	/**
	 * Site_Synchronizer constructor.
	 *
	 * @param Plugin           $bot
	 * @param Site_Register    $site_register
	 * @param App_Interface    $app
	 * @param Settings_Handler $settings_handler
	 */
	public function __construct( Plugin $bot, Site_Register $site_register, App_Interface $app, Settings_Handler $settings_handler ) {
		$this->bot              = $bot;
		$this->site_register    = $site_register;
		$this->app              = $app;
		$this->settings_handler = $settings_handler;
	}


	/**
	 * Initialise the hooks
	 */
	public function init() {
		add_action( 'admin_init', array( $this, 'upgrade_save_site' ) );
		/*
		 * Update_table_prefix is hooked earlier than our install process,
		 * so we can reinstall tables with new prefix if needed.
		 */
		add_action( 'admin_init', array( $this, 'update_table_prefix' ), 9 );
		add_action( 'admin_init', array( $this, 'update_wp_version' ) );
		add_action( 'admin_init', array( $this, 'update_is_multisite' ) );
		add_action( 'upgrader_process_complete', array( $this, 'upgrade_wp_version' ), 20, 2 );
		add_action( 'update_option_active_plugins', array( $this, 'update_plugins_for_site' ) );
		add_action( 'update_option_active_sitewide_plugins', array( $this, 'update_plugins_for_site' ) );
		add_action( 'switch_theme', array( $this, 'update_themes_for_site' ), 10, 2 );
		add_action( 'upgrader_process_complete', array( $this, 'update_plugin_theme_version' ), 10, 2 );
	}

	/**
	 * Save the whole app site object in the database if it doesn't exist.
	 */
	public function upgrade_save_site() {
		if ( false === ( $site_id = $this->can_perform_update_on_site() ) ) {
			return false;
		}

		if ( false !== $this->settings_handler->get_site() ) {
			// We already have the site object stored.
			return false;
		}

		$site = $this->get_site( $site_id );
		if ( false === $site ) {
			return false;
		}

		return $this->site_register->save_site( $site );
	}

	/**
	 * Notify the App of a WP core version upgrade for the site.
	 *
	 * @param Core_Upgrader $core_upgrader
	 * @param array         $args
	 *
	 * @return bool
	 */
	public function upgrade_wp_version( $core_upgrader, $args ) {
		if ( ! isset( $args['action'] ) || 'update' !== $args['action'] ) {
			return false;
		}

		if ( ! isset( $args['type'] ) || 'core' !== $args['type'] ) {
			return false;
		}

		if ( false === $this->settings_handler->get_site_id() ) {
			// Site not registered with app, bail
			return false;
		}

		// Update version on app
		return (bool) $this->update_site_wp_version();
	}

	/**
	 * Get the current value of a site setting registered on the app.
	 *
	 * @param string $key
	 *
	 * @return bool|mixed
	 */
	protected function get_current_setting( $key ) {
		$site = $this->settings_handler->get_site();
		if ( false === $site ) {
			return false;
		}

		if ( ! isset( $site->{$key} ) ) {
			return false;
		}

		return $site->{$key};
	}

	/**
	 * Check if a site setting has changed locally and update the app.
	 *
	 * @param string $key
	 * @param mixed  $new_value
	 *
	 * @return array|bool|mixed|object
	 */
	protected function maybe_update_site_setting( $key, $new_value ) {
		$existing_value = $this->get_current_setting( $key);

		if ( false !== $existing_value && $existing_value == $new_value ) {
			// Existing value is still current.
			return false;
		}

		// Update the app with the new value.
		return $this->update_site_setting( $key, $new_value );
	}

	/**
	 * Ensure the table prefix is up to date in the app
	 */
	public function update_table_prefix() {
		if ( false === $this->can_perform_update_on_site() ) {
			return false;
		}

		global $wpdb;

		if ( false === $this->maybe_update_site_setting( 'table_prefix', $wpdb->base_prefix ) ) {
			return false;
		}

		// If current prefix has changed, wipe the table version numbers so a reinstall is attempted
		$this->settings_handler->delete( 'db_table_versions' )->save();

		return true;
	}

	/**
	 * Ensure the WP version is up to date in the app, in case the files have been manually upgraded.
	 */
	public function update_wp_version() {
		if ( false === $this->can_perform_update_on_site() ) {
			return false;
		}

		global $wp_version;

		return $this->maybe_update_site_setting( 'wp_version', $wp_version );
	}

	/**
	 * Ensure the site multisite status is up to date in the app
	 */
	public function update_is_multisite() {
		if ( false === $this->can_perform_update_on_site() ) {
			return false;
		}

		return $this->maybe_update_site_setting( 'is_multisite', (int) $this->bot->is_multisite() );
	}

	/**
	 * Update the site WP version stored on the app
	 *
	 * @return array|bool|mixed|object
	 */
	protected function update_site_wp_version() {
		global $wp_version;

		$args = array(
			'unique_id'  => $this->site_register->get_site_unique_id(),
			'wp_version' => $wp_version,
		);

		$site = $this->app->post_site_settings( $args );

		if ( is_wp_error( $site ) ) {
			return false;
		}

		return $site;
	}

	/**
	 * Update the plugins on the app when they change on site
	 *
	 * @param array $plugins
	 *
	 * @return bool
	 */
	public function update_plugins_for_site( $plugins ) {
		$site_id = $this->settings_handler->get_site_id();
		if ( false === $site_id ) {
			return false;
		}

		return (bool) $this->update_site_plugins( $site_id );
	}

	/**
	 * Update the app when a theme or plugins get upgraded.
	 *
	 * @param \WP_Upgrader $upgrader
	 * @param array        $options
	 *
	 * @return bool
	 */
	public function update_plugin_theme_version( $upgrader, $options ) {
		if ( ! isset( $options['type'] ) ) {
			return false;
		}

		if ( 'plugin' === $options['type'] ) {
			return $this->update_plugins_for_site( array() );
		}

		if ( 'theme' !== $options['type'] || ! isset( $options['themes'] ) ) {
			return false;
		}

		if ( $this->bot->is_multisite() ) {
			return $this->update_themes_for_site();
		}

		$theme = wp_get_theme();
		foreach ( $options['themes'] as $theme_name ) {
			if ( $theme->get_stylesheet() !== $theme_name ) {
				continue;
			}

			return $this->update_themes_for_site();
		}

		return false;
	}

	/**
	 * Update the site plugins stored on the app
	 *
	 * @param int $site_id
	 *
	 * @return array|bool|mixed|object
	 */
	protected function update_site_plugins( $site_id ) {
		$args = array(
			'site_id' => $site_id,
			'plugins' => serialize( $this->site_register->get_plugins() ),
		);

		$site = $this->app->post_site_plugins( $args );

		if ( is_wp_error( $site ) ) {
			return false;
		}

		return $site;
	}

	/**
	 * Update the plugins on the app when they change on site
	 *
	 * @return bool
	 */
	public function update_themes_for_site() {
		$site_id = $this->settings_handler->get_site_id();
		if ( false === $site_id ) {
			return false;
		}

		return (bool) $this->update_site_themes( $site_id );
	}

	/**
	 * Update the site theme stored on the app
	 *
	 * @param int $site_id
	 *
	 * @return array|bool|mixed|object
	 */
	protected function update_site_themes( $site_id ) {
		$args = array(
			'site_id' => $site_id,
			'themes'  => serialize( $this->site_register->get_themes() ),
		);

		$site = $this->app->post_site_themes( $args );

		if ( is_wp_error( $site ) ) {
			return false;
		}

		return $site;
	}

	/**
	 * Get the settings for a site already stored on app
	 *
	 * @return mixed
	 */
	public function get_site_settings() {
		$unique_id = $this->site_register->get_site_unique_id();

		$site = $this->app->silent()->get_site_settings( $unique_id );

		if ( is_wp_error( $site ) ) {
			// The site doesn't exist on the app
			return false;
		}

		$settings = maybe_unserialize( $site->settings );
		if ( ! isset( $settings['site_id'] ) ) {
			$settings['site_id'] = $site->id;
		}

		if ( ! isset( $settings['parent_site_id'] ) ) {
			$settings['parent_site_id'] = $site->parent_site;
		}

		if ( ! isset( $settings['site'] ) ) {
			$settings['site'] = $this->site_register->format_site( $site );;
		}

		return $settings;
	}

	/**
	 * Get the site object from the app.
	 *
	 * @param int $site_id
	 *
	 * @return mixed
	 */
	public function get_site( $site_id ) {
		$site = $this->app->silent()->get_site_settings( $site_id );

		if ( is_wp_error( $site ) ) {
			// The site doesn't exist on the app
			return false;
		}

		return $site;
	}

	/**
	 * Update a site setting stored on the app.
	 *
	 * @param string $key
	 * @param mixed  $value
	 *
	 * @return array|bool|mixed|object
	 */
	public function update_site_setting( $key, $value) {
		$site = $this->post_site_settings( array( $key => $value ) );
		if ( false === $site ) {
			return $site;
		}

		return $this->site_register->save_site( $site );
	}

	/**
	 * Update site settings
	 *
	 * @param array $args
	 *
	 * @return array|bool|mixed|object
	 */
	public function post_site_settings( $args = array() ) {
		$defaults = array(
			'unique_id' => $this->site_register->get_site_unique_id(),
		);

		$args = array_merge( $defaults, $args );

		$site = $this->app->post_site_settings( $args );

		if ( is_wp_error( $site ) ) {
			$site = false;
		}

		return $site;
	}

	/**
	 * Can we perform an update of site data to the app.
	 *
	 * @return bool|int
	 */
	protected function can_perform_update_on_site() {
		if ( $this->bot->doing_ajax() || $this->bot->doing_cron() ) {
			return false;
		}

		if ( $this->bot->is_multisite() && ! $this->bot->is_network_admin() ) {
			// Don't perform updates in MS subsite's admin
			return false;
		}

		if ( false === ( $site_id = $this->settings_handler->get_site_id() ) ) {
			// Site not registered with app, bail
			return false;
		}

		return $site_id;
	}
}