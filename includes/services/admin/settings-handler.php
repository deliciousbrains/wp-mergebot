<?php

/**
 * The class for the plugin settings.
 *
 * This is used to define and access all the settings for the plugin.
 *
 * @since 0.1
 */

namespace DeliciousBrains\Mergebot\Services\Admin;

use DeliciousBrains\Mergebot\Models\Plugin;
use DeliciousBrains\Mergebot\Utils\Config;

class Settings_Handler {

	/**
	 * @var Plugin
	 */
	protected $bot;

	/**
	 * @var string
	 */
	public $option_name;

	/**
	 * @var array|false
	 */
	protected $settings;

	/**
	 * Settings constructor.
	 *
	 * @param Plugin $bot
	 */
	public function __construct( Plugin $bot ) {
		$this->bot         = $bot;
		$this->option_name = $this->bot->settings_key();
	}

	/**
	 * Instantiate the settings hooks
	 */
	public function init() {
		// Load settings from the database
		$this->load();

		add_action( $this->bot->slug() . '_load_plugin', array( $this, 'handle_settings_save' ) );
	}

	/**
	 * Load the settings.
	 *
	 * @return array|false|mixed
	 */
	protected function load() {
		if ( is_null( $this->settings ) ) {
			$this->settings = $this->all();
		}

		return $this->settings;
	}

	/**
	 * Get all the keys that are allowed to be set via a UI form.
	 *
	 * @param string $form
	 *
	 * @return array
	 */
	protected function get_form_settings_whitelist( $form = 'default' ) {
		$settings = array(
			'default' => array(
				'parent_site_id',
				'team_id',
			),
		);

		$settings = apply_filters( $this->bot->slug() . '_form_settings_whitelist', $settings );

		if ( ! isset( $settings[ $form ] ) ) {
			return array();
		}

		return $settings[ $form ];
	}

	/**
	 * Get all the internal settings keys that need to be
	 * preserved on save of the settings page form
	 *
	 * return array
	 */
	public function get_internal_keys() {
		$keys = array(
			'db_table_versions',
			'site',
		);

		$keys = apply_filters( $this->bot->slug() . '_internal_keys', $keys );

		return $keys;
	}

	/**
	 * Get the plugin settings
	 *
	 * @return array
	 */
	public function all() {
		$settings = get_site_option( $this->option_name );

		if ( empty( $settings ) ) {
			return array();
		}

		return $settings;
	}

	/**
	 * Get a setting
	 *
	 * @param string $key
	 * @param mixed  $default
	 *
	 * @return mixed|array|string|int|bool
	 */
	public function get( $key, $default = '' ) {
		$this->settings = apply_filters( $this->bot->slug() . '_get_setting', $this->settings, $key );

		$mode = $this->bot->mode();

		if ( isset( $this->settings[ $mode ][ $key ] ) ) {
			return $this->settings[ $mode ][ $key ];
		}

		return $default;
	}

	/**
	 * Update a setting
	 *
	 * @param string|array $key
	 * @param mixed  $value
	 *
	 * @return $this
	 */
	public function set( $key, $value = '' ) {
		$settings = $key;
		if ( ! is_array( $key ) ) {
			$settings = array( $key => $value );
		}

		foreach ( $settings as $key => $value ) {
			if ( $this->does_value_exist( $key, $value ) ) {
				continue;
			}

			$this->settings[ $this->bot->mode() ][ $key ] = $value;
		}

		return $this;
	}

	/**
	 * Check if a value already exists, so we don't bother updating again.
	 *
	 * @param string $key
	 * @param mixed  $value
	 *
	 * @return bool
	 */
	protected function does_value_exist( $key, $value ) {
		if ( ! isset( $this->settings[ $this->bot->mode() ][ $key ] ) ) {
			return false;
		}

		$existing = $this->settings[ $this->bot->mode() ][ $key ];

		$existing = $this->normalize_value( $existing );
		$value    = $this->normalize_value( $value );
		if ( $existing == $value ) {
			return true;
		}

		return false;
	}

	/**
	 * Helper for value comparison.
	 *
	 * @param mixed $value
	 *
	 * @return string
	 */
	protected function normalize_value( $value ) {
		if ( is_object( $value ) ) {
			$value = (array) $value;
		}

		if ( is_array( $value ) ) {
			ksort( $value );

			$value = serialize( $value );
		}

		return $value;
	}

	/**
	 * Delete a setting
	 *
	 * @param string|array $key
	 *
	 * @return $this
	 */
	public function delete( $key ) {
		$settings = $key;
		if ( ! is_array( $key ) ) {
			$settings = array( $key );
		}

		$mode = $this->bot->mode();

		foreach ( $settings as $setting ) {
			if ( ! isset( $this->settings[ $mode ][ $setting ] ) ) {
				continue;
			}

			unset( $this->settings[ $mode ][ $setting ] );
		}

		return $this;
	}

	/**
	 * Update the settings option
	 *
	 * @return bool
	 */
	public function save() {
		$this->settings = apply_filters( $this->bot->slug() . '_save_settings', $this->settings );

		return update_site_option( $this->option_name, $this->settings);
	}

	/**
	 * Get the registered ID for the site
	 *
	 * @param bool $default
	 *
	 * @return false|int
	 */
	public function get_site_id( $default = false ) {
		return $this->get( 'site_id', $default );
	}

	/**
	 * Get the site object
	 *
	 * @param bool $default
	 *
	 * @return false|object
	 */
	public function get_site( $default = false ) {
		return $this->get( 'site', $default );
	}

	/**
	 * Is the site connected to the app
	 *
	 * @return bool
	 */
	public function is_site_connected() {
		return ( false !== $this->get_site_id() );
	}

	/**
	 * Save the settings form.
	 */
	public function handle_settings_save() {
		$plugin = $this->bot->filter_input( 'plugin', INPUT_POST );
		if ( empty( $plugin ) || $this->bot->slug() !== $plugin ) {
			return;
		}

		$action = $this->bot->filter_input( 'action', INPUT_POST );
		if ( empty( $action ) || 'save' !== $action ) {
			return;
		}

		$form = $this->bot->filter_input( 'form', INPUT_POST );
		if ( empty( $form ) ) {
			$form = 'default';
		}

		$nonce = $this->bot->filter_input( '_wpnonce', INPUT_POST );
		if ( empty( $nonce ) || ! wp_verify_nonce( $nonce, $this->bot->slug() . '-options' ) ) {
			$this->bot->wp_die( __( "Cheatin' eh?" ) );
		}

		$internal_keys = $this->get_internal_keys();
		$settings      = $this->get_form_settings_whitelist( $form );

		foreach ( $settings as $var ) {
			if ( in_array( $var, $internal_keys ) ) {
				continue;
			}

			$post_var = $this->bot->filter_input( $var, INPUT_POST );

			if ( empty( $post_var ) ) {
				$this->delete( $var );
				continue;
			}

			$value = sanitize_text_field( $_POST[ $var ] );

			$this->set( $var, $value );
		}

		$this->settings = $this->clean_settings( $this->settings );

		$this->save();
	}

	/**
	 * Clean up settings when changing mode
	 *
	 * @param array $settings
	 *
	 * @return array
	 */
	protected function clean_settings( $settings ) {
		$mode = $this->bot->mode();

		if ( Config::MODE_PROD === $mode ) {
			unset( $settings[ $mode ]['parent_site_id'] );
		} else if ( Config::MODE_DEV === $mode ) {
			unset( $settings[ $mode ]['team_id'] );
		}

		return $settings;
	}

	/**
	 * Render the Parent Site setting
	 */
	public function render_parent_site_id() {
		if ( Config::MODE_DEV !== $this->bot->mode() ) {
			return;
		}

		// Don't use cached list of sites if not connected
		$bust_cache = ! $this->settings_handler->is_site_connected();
		$sites_data = $this->site_register->get_production_sites( $bust_cache );

		$sites = array();
		foreach ( $sites_data as $id => $site ) {
			$value = $site;
			if ( is_array( $site ) ) {
				$value = $site['url'];
			}
			$sites[ $id ] = $this->bot->url_without_scheme( $value );
		}

		$this->render_connected_select( 'parent_site_id', 'sites', 'site', $sites );
	}
}
