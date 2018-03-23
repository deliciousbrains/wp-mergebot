<?php

/**
 * The class for dealing with the site on the app.
 *
 * @since 0.1
 */

namespace DeliciousBrains\Mergebot\Services;

use DeliciousBrains\Mergebot\Models\Notice;
use DeliciousBrains\Mergebot\Services\Admin\Settings_Handler;
use DeliciousBrains\Mergebot\Utils\Error;
use DeliciousBrains\Mergebot\Models\Plugin;
use DeliciousBrains\Mergebot\Utils\Config;
use DeliciousBrains\Mergebot\Utils\Support;

class Site_Register {

	/**
	 * @var Plugin
	 */
	protected $bot;

	/**
	 * @var App_Interface
	 */
	protected $app;

	/**
	 * @var Settings_Handler
	 */
	protected $settings_handler;

	/**
	 * Site_Register constructor.
	 *
	 * @param Plugin           $bot
	 * @param App_Interface    $app
	 * @param Settings_Handler $settings_handler
	 */
	public function __construct( Plugin $bot, App_Interface $app, Settings_Handler $settings_handler ) {
		$this->bot              = $bot;
		$this->app              = $app;
		$this->settings_handler = $settings_handler;
	}

	/**
	 * Initialise the hooks
	 */
	public function init() {
		add_action( 'admin_init', array( $this, 'handle_check_again_site_limit' ) );
		add_filter( $this->bot->slug() . '_save_settings', array( $this, 'maybe_register_site_on_setting_save' ) );
	}

	/**
	 * Get the teams the user belongs to
	 *
	 * @param bool $ignore_transient
	 *
	 * @return array|bool|mixed|object
	 */
	public function get_teams( $ignore_transient = false ) {
		return $this->get_objects( 'teams', $ignore_transient );
	}

	/**
	 * Get the production sites registered for the API key
	 *
	 * @param bool $ignore_transient
	 *
	 * @return array|bool|mixed|object
	 */
	public function get_production_sites( $ignore_transient = false ) {
		$site_id = $this->settings_handler->get_site_id( 0 );

		return $this->get_objects( 'sites', $ignore_transient, array( 'site_id' => $site_id ) );
	}

	/**
	 * Wrapper to get and cache an array of objects for a dropdown
	 *
	 * @param string $object
	 * @param bool   $ignore_transient
	 * @param array  $params
	 *
	 * @return array
	 */
	protected function get_objects( $object, $ignore_transient = false, $params = array() ) {
		$objects = false;
		if ( false == $ignore_transient ) {
			$objects = get_site_transient( $this->bot->slug() . '_' . $object );
		}

		if ( $ignore_transient || false === $objects || empty( $objects ) ) {

			$objects = array();
			$method  = 'get_' . $object;

			$response = call_user_func_array( array( $this->app, $method ), $params );

			if ( ! is_wp_error( $response ) && is_array( $response ) ) {
				foreach ( $response as $item ) {
					$objects[ $item->id ] = $this->get_object( $object, $item );
				}

				set_site_transient( $this->bot->slug() . '_' . $object, $objects );
			}
		}

		return $objects;
	}

	/**
	 * Get object item from a response
	 *
	 * @param string $object
	 * @param object $item
	 *
	 * @return mixed
	 */
	protected function get_object( $object, $item ) {
		$method = 'get_object_' . $object;
		if ( method_exists( $this, $method ) ) {
			return $this->{$method}( $item );
		}

		return $item->name;
	}

	/**
	 * Get the args for the site registration
	 *
	 * @param array $settings
	 *
	 * @return array
	 */
	protected function site_registration_args( $settings ) {
		global $wpdb, $wp_version;

		$args = array(
			'unique_id'    => $this->get_site_unique_id(),
			'title'        => get_bloginfo( 'name' ),
			'url'          => rtrim( network_home_url(), '\\/' ),
			'admin_url'    => network_admin_url(),
			'path'         => $this->get_absolute_root_file_path(),
			'table_prefix' => $wpdb->base_prefix,
			'wp_version'   => $wp_version,
			'is_multisite' => is_multisite(),
			'plugins'      => serialize( $this->get_plugins() ),
			'themes'       => serialize( $this->get_themes() ),
		);

		if ( $this->bot->is_dev_mode() ) {
			// Add parent site
			$args['parent_site'] = $settings['parent_site_id'];

			// Add plugin settings
			$args['settings'] = serialize( $this->settings_handler->all() );
		}

		return $args;
	}

	/**
	 * Is the site limit for the plan reached?
	 *
	 * @return bool
	 */
	public function is_site_limit_reached() {
		return Notice::exists( 'site-limit' );
	}

	/**
	 * Register this site with the app
	 *
	 * @param string $mode
	 * @param array  $data
	 *
	 * @return array|Error
	 */
	public function register_site( $mode, $data ) {
		$args = $this->site_registration_args( $data );

		if ( Config::MODE_PROD === $mode ) {
			$args['team_id'] = $data['team_id'];
		}

		$site = $this->app->post_site( $args );

		if ( is_wp_error( $site ) && $site->is_site_limit_reached() ) {
			// Site limit reached
			$check_again_link          = sprintf( '<a href="%s">%s</a>', $this->get_check_again_link(), __( 'Check Again' ) );
			$disconnect_sites_app_link = sprintf( '<a target="_blank" href="%s">%s</a>', $this->bot->get_app_url( 'sites' ), __( 'disconnect' ) );
			$upgrade_plan_app_link     = sprintf( '<a target="_blank" href="%s">%s</a>', $this->bot->get_app_url( 'settings/subscription' ), __( 'upgrade' ) );
			$error_msg                 = sprintf( 'The number of connected sites for your plan has been reached. Please %s some production sites or %s your plan. %s.', $disconnect_sites_app_link, $upgrade_plan_app_link, $check_again_link );

			$notice_args = array(
				'id'                    => 'site-limit',
				'type'                  => 'error',
				'message'               => $error_msg,
				'title'                 => __( 'Site Limit Reached' ),
				'dismissible'           => false,
				'only_show_to_user'     => false,
				'only_show_in_settings' => true,
				'flash'                 => false,
			);

			Notice::create( $notice_args )->save();
		}

		return $site;
	}

	/**
	 * Get the check again link for the site limit.
	 *
	 * @return string
	 */
	protected function get_check_again_link() {
		$args = array(
			'check' => 'site-limit',
		);

		return $this->bot->get_url( $args );
	}

	/**
	 * Listen for requests to retry the job
	 *
	 */
	public function handle_check_again_site_limit() {
		if ( $this->bot->doing_ajax() || $this->bot->doing_cron() ) {
			return;
		}

		if ( ! $this->bot->is_page() ) {
			return;
		}

		$check = $this->bot->filter_input( 'check' );
		if ( empty( $check ) || 'site-limit' !== $check ) {
			return;
		}

		Notice::delete_by_id( 'site-limit' );

		return $this->bot->redirect();
	}

	/**
	 * Get the absolute path of the install
	 *
	 * @return string
	 */
	protected function abspath() {
		return ABSPATH;
	}

	/**
	 * Returns the absolute path to the root of the website.
	 *
	 * @return string
	 */
	protected function get_absolute_root_file_path() {
		$absolute_path = rtrim( $this->abspath(), '\\/' );
		$site_url      = rtrim( network_site_url( '', 'http' ), '\\/' );
		$home_url      = rtrim( network_home_url( '', 'http' ), '\\/' );

		if ( $site_url === $home_url ) {
			return $absolute_path;
		}

		// If site is in a different directory ensure the abs path has the directory removed
		$difference = str_replace( $home_url, '', $site_url );
		if ( false !== strpos( $absolute_path, $difference ) ) {
			$absolute_path = rtrim( substr( $absolute_path, 0, - strlen( $difference ) ), '\\/' );
		}

		return $absolute_path;
	}

	/**
	 * Get active plugins on the site
	 *
	 * @return array
	 */
	public function get_plugins() {
		$active_plugins = Support::get_active_plugins();
		$plugins        = array();

		foreach ( $active_plugins as $plugin ) {
			$plugin_file = WP_PLUGIN_DIR . '/' . $plugin;
			if ( ! file_exists( $plugin_file ) ) {
				continue;
			}

			$plugin_data = get_plugin_data( $plugin_file );

			$name    = empty( $plugin_data['Name'] ) ? basename( $plugin ) : $plugin_data['Name'];
			$version = empty( $plugin_data['Version'] ) ? filemtime( $plugin_file ) : $plugin_data['Version'];

			$plugins[ $plugin ] = array(
				'name'    => $name,
				'version' => $version,
			);
		}

		return $plugins;
	}

	/**
	 * Get the active themes for the site
	 *
	 * @return array
	 */
	public function get_themes() {
		$all_themes = Support::get_active_themes();
		$themes     = array();

		foreach ( $all_themes as $theme ) {
			$themes[] = array(
				'name'    => $theme->get( 'Name' ),
				'version' => $theme->get( 'Version' ),
				'base'    => $theme->get_stylesheet(),
				'parent'  => $theme->get_template(),
			);
		}

		return $themes;
	}

	/**
	 * Get connected development sites for a production site
	 *
	 * @return bool|array
	 */
	public function get_connected_sites() {
		if ( $this->bot->is_dev_mode() ) {
			return false;
		}

		$sites = $this->get_objects( 'connected_sites', false, array( 'site_id' => $this->settings_handler->get_site_id() ) );

		return $sites;
	}

	/**
	 * Get the sites object from response
	 *
	 * @param object $item
	 *
	 * @return array
	 */
	protected function get_object_sites( $item ) {
		return array(
			'url'       => $item->url,
			'admin_url' => $item->admin_url,
		);
	}

	/**
	 * Get the connected sites object from response
	 *
	 * @param object $item
	 *
	 * @return array
	 */
	public function get_object_connected_sites( $item ) {
		return $this->get_object_sites( $item );
	}

	/**
	 * Get the URL of the Mergebot settings page on a site
	 *
	 * @param string $site_admin_url
	 *
	 * @return string
	 */
	public function get_site_settings_url( $site_admin_url ) {
		$url = trailingslashit( $site_admin_url ) . 'tools.php';

		return $this->bot->get_url( array(), $url );
	}

	/**
	 * Disconnect the site on the plugin if it has been removed from the app
	 *
	 * @param mixed|Error|\WP_Error $request
	 *
	 * @return bool
	 */
	public function maybe_disconnect_site( $request ) {
		if ( ! is_wp_error( $request ) ) {
			return false;
		}

		if ( 404 !== $request->get_api_response_code() ) {
			return false;
		}

		$error = $request->get_error_data();

		if ( ! isset( $error['message'] ) || 'Site not found' !== $error['message'] ) {
			return false;
		}

		$this->disconnect_site();

		return true;
	}

	/**
	 * Disconnect the site from the plugin settings.
	 */
	public function disconnect_site() {
		// Remove site ID and related data
		$settings = array(
			'site_id',
			'team_id',
			'parent_site_id',
		);

		$this->settings_handler->delete( $settings )->save();

		// Remove connected sites/parent site transients
		delete_site_transient( $this->bot->slug() . '_sites' );
		delete_site_transient( $this->bot->slug() . '_teams' );
		delete_site_transient( $this->bot->slug() . '_connected_sites' );
		delete_site_transient( $this->bot->slug() . '_schema_primary_keys' );
		delete_site_transient( $this->bot->slug() . '_schema_ignored_queries' );
		delete_site_transient( $this->bot->slug() . '_schema_auto_increment_columns' );
		delete_site_transient( $this->bot->slug() . '_schema_meta_keys' );

		// Maybe auto register site (only for prod mode)
		do_action( $this->bot->slug() . '_disconnect_site' );
	}

	/**
	 * Maybe register the site with the app
	 *
	 * @param array $input
	 *
	 * @return array
	 */
	public function maybe_register_site_on_setting_save( $input ) {
		$mode = $this->bot->mode();

		if ( isset( $input[ $mode ]['site_id'] ) ) {
			// Already registered, bail
			return $input;
		}

		if ( Config::MODE_DEV === $mode && ( ! isset( $input[ $mode ]['parent_site_id'] ) || empty( $input[ $mode ]['parent_site_id'] ) ) ) {
			// No parent site selected, bail
			unset( $input[ $mode ]['parent_site_id'] );

			return $input;
		}

		if ( Config::MODE_PROD === $mode && ! isset( $input[ $mode ]['team_id'] ) ) {
			// No organization selected, bail
			return $input;
		}

		if ( $this->is_site_limit_reached() ) {
			return $input;
		}

		// Register the site
		$site = $this->register_site( $mode, $input[ $mode ] );

		if ( ! is_wp_error( $site ) ) {
			// Save the site ID
			$input[ $mode ] = $this->save_site( $site, $input[ $mode ], false );

			return $input;
		}

		if ( Config::MODE_DEV === $mode ) {
			// Don't save the parent ID selected as not valid
			delete_site_transient( $this->bot->slug() . '_sites' );
			unset( $input[ $mode ]['parent_site_id'] );
		}

		return $input;
	}

	/**
	 * Generate a unique site identifier to be used in the app
	 *
	 * @return string
	 */
	public function get_site_unique_id() {
		$url = $this->bot->url_without_scheme( network_home_url() );
		$uid = md5( $url );

		return $uid;
	}

	/**
	 * Get the registered ID for the site
	 *
	 * @return string|false
	 */
	public function get_parent_site_id() {
		return $this->settings_handler->get( 'parent_site_id', false );
	}

	/**
	 * Get the parent site URL
	 *
	 * @param bool $protocol
	 *
	 * @return bool|string
	 */
	public function get_parent_site_url( $protocol = false ) {
		if ( false === ( $site_id = $this->get_parent_site_id() ) ) {
			return false;
		}

		$sites = $this->get_production_sites();

		if ( is_array( $sites ) && isset( $sites[ $site_id ] ) ) {
			$url = isset( $sites[ $site_id ]['url'] ) ? $sites[ $site_id ]['url'] : $sites[ $site_id ];

			if ( false === $protocol ) {
				$url = $this->bot->url_without_scheme( $url );
			}

			return $url;
		}

		return false;
	}

	/**
	 * Get the parent site URL
	 *
	 * @return bool|string
	 */
	public function get_parent_site_admin_url() {
		if ( false === ( $site_id = $this->get_parent_site_id() ) ) {
			return false;
		}

		$sites = $this->get_production_sites();

		if ( is_array( $sites ) && isset( $sites[ $site_id ] ) ) {
			$url = isset( $sites[ $site_id ]['url'] ) ? $sites[ $site_id ]['url'] : $sites[ $site_id ];

			return isset( $sites[ $site_id ]['admin_url'] ) ? $sites[ $site_id ]['admin_url'] : $url;
		}

		return false;
	}

	/**
	 * Format the site object ready for saving.
	 *
	 * @param object $site
	 *
	 * @return object
	 */
	public function format_site( $site ) {
		unset( $site->settings );
		unset( $site->is_recording );
		unset( $site->remaining_queries );

		return $site;
	}

	/**
	 * Save the site after registering with the app.
	 *
	 * @param object $site
	 * @param array  $settings
	 * @param bool   $save
	 *
	 * @return array|bool
	 */
	public function save_site( $site, $settings = array(), $save = true ) {
		if ( ! isset( $site->id ) ) {
			return false;
		}

		$settings['site_id'] = $site->id;
		$settings['site']    = $this->format_site( $site );

		if ( $save ) {
			return $this->settings_handler->set( $settings )->save();
		}

		return $settings;
	}
}