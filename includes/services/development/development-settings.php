<?php

/**
 * The class for the Development mode settings.
 *
 * This is used for the Development mode specific settings functionality.
 *
 * @since 0.1
 */

namespace DeliciousBrains\Mergebot\Services\Development;

use DeliciousBrains\Mergebot\Services\Admin\Settings_Handler;
use DeliciousBrains\Mergebot\Services\Site_Synchronizer;
use DeliciousBrains\Mergebot\Models\Plugin;

class Development_Settings {

	/**
	 * @var Plugin
	 */
	protected $bot;

	/**
	 * @var Settings_Handler
	 */
	protected $settings_handler;

	/**
	 * @var Site_Synchronizer
	 */
	protected $site_synchronizer;

	/**
	 * Settings constructor.
	 *
	 * @param Plugin            $bot
	 * @param Settings_Handler $settings_handler
	 * @param Site_Synchronizer $site_synchronizer
	 */
	public function __construct( Plugin $bot, Settings_Handler $settings_handler, Site_Synchronizer $site_synchronizer ) {
		$this->bot               = $bot;
		$this->settings_handler  = $settings_handler;
		$this->site_synchronizer = $site_synchronizer;
	}

	/**
	 * Instantiate the settings hooks
	 */
	public function init() {
		add_filter( $this->bot->slug() . '_get_setting', array( $this, 'get_setting' ) );
		add_action( $this->bot->slug() . '_save_settings', array( $this, 'update_settings_on_app' ), 10, 2 );
		add_filter( $this->bot->slug() . '_internal_keys', array( $this, 'add_internal_keys' ) );
	}

	/**
	 * Get the internal setting keys for the mode
	 *
	 * @return array
	 */
	protected function get_internal_keys() {
		return array(
			'recording_id',
			'recording_user_id',
		);
	}

	/**
	 * Add the internal setting keys for the mode
	 *
	 * @param array $settings
	 *
	 * @return array
	 */
	public function add_internal_keys( $settings ) {
		return array_merge( $settings, $this->get_internal_keys() );
	}

	/**
	 * If the Development settings don't exist in the settings
	 * attempt to grab from the app for previously registered sites.
	 *
	 * @param array $settings
	 *
	 * @return array
	 */
	public function get_setting( $settings ) {
		if ( ! isset( $settings[ $this->bot->mode() ] ) ) {
			// See if the Development mode settings have been stored on the app
			$site_settings = $this->site_synchronizer->get_site_settings();

			if ( ! empty( $site_settings ) ) {
				// Inject the app stored settings into the plugin
				$settings[ $this->bot->mode() ] = $site_settings;
				update_site_option( $this->bot->settings_key(), $settings );
			}
		}

		return $settings;
	}

	/**
	 * Hook into the update option settings method
	 * to send Development settings to the app
	 *
	 * @param array $settings
	 *
	 * @return array
	 */
	public function update_settings_on_app( $settings ) {
		if ( ! isset( $settings[ $this->bot->mode() ]['site_id'] ) ) {
			// Site not registered with app, bail
			return $settings;
		}

		// Just get the development settings to send.
		$app_settings = $settings[ $this->bot->mode() ];

		// Update the app with the new plugin development mode settings
		$this->site_synchronizer->post_site_settings( array( 'settings' => $app_settings ) );

		return $settings;
	}
}