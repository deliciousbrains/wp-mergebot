<?php

/**
 * The class for the plugin page.
 *
 * This is used to create the admin pages for the plugin.
 *
 * @since 0.1
 */

namespace DeliciousBrains\Mergebot\Services\Admin;

use DeliciousBrains\Mergebot\Services\App_Interface;
use DeliciousBrains\Mergebot\Services\Site_Register;
use DeliciousBrains\Mergebot\Models\Plugin;
use DeliciousBrains\Mergebot\Utils\Config;
use DeliciousBrains\Mergebot\Utils\Support;

class Page_Presenter {

	/**
	 * @var Plugin
	 */
	protected $bot;

	/**
	 * @var Settings_Handler
	 */
	protected $settings_handler;

	/**
	 * @var App_Interface
	 */
	protected $app;

	/**
	 * @var Site_Register
	 */
	protected $site_register;

	/**
	 * Admin constructor.
	 *
	 * @param Plugin           $bot
	 * @param Settings_Handler $settings_handler
	 * @param App_Interface    $app
	 * @param Site_Register    $site_register
	 */
	public function __construct( Plugin $bot, Settings_Handler $settings_handler, App_Interface $app, Site_Register $site_register ) {
		$this->bot              = $bot;
		$this->settings_handler = $settings_handler;
		$this->app              = $app;
		$this->site_register    = $site_register;
	}

	/**
	 * Instantiate the admin hooks
	 */
	public function init() {
		if ( is_multisite() ) {
			add_action( 'network_admin_menu', array( $this, 'register_network_menu_item' ) );
			add_action( 'admin_menu', array( $this, 'register_subsite_menu_item' ) );
			add_action( 'admin_init', array( $this, 'redirect_subsite_to_network_settings' ) );
		} else {
			add_action( 'admin_menu', array( $this, 'register_menu_item' ) );
		}

		add_filter( 'plugin_action_links_' . plugin_basename( $this->bot->file_path()), array( $this, 'plugin_action_links' ) );
		add_action( $this->bot->slug() . '_view_admin', array( $this, 'render_settings_form' ) );
		add_action( $this->bot->slug() . '_view_post_admin', array( $this, 'render_sidebar' ) );
		add_action( $this->bot->slug() . '_view_app_data', array( $this, 'render_connected_sites' ) );
	}

	/**
	 * Register a page under the Tools menu for the plugin settings
	 */
	public function register_menu_item() {
		$hook_suffix = add_management_page( $this->bot->name(), $this->bot->name(), $this->bot->capability(), $this->bot->slug(), array( $this, 'render_page' ) );

		$this->after_menu_item_load( $hook_suffix );
	}

	/**
	 * Register a page under the Settings menu for the plugin settings in a MS install.
	 */
	public function register_network_menu_item() {
		$hook_suffix = add_submenu_page( 'settings.php', $this->bot->name(), $this->bot->name(), $this->bot->capability(), $this->bot->slug(), array( $this, 'render_page' ) );

		$this->after_menu_item_load( $hook_suffix );
	}

	/**
	 * Register a page under the Tools menu for the plugin settings
	 */
	public function register_subsite_menu_item() {
		add_management_page( $this->bot->name(), $this->bot->name(), $this->bot->capability(), $this->bot->slug(), array( $this, 'render_subsite_page' ) );
	}

	/**
	 * Add a warning and link to the subsite tools page if the redirect hasn't fired.
	 */
	public function render_subsite_page() {
		$message = sprintf( esc_html__( '%1$s only runs at the Network Admin level. As there is no Tools menu in the Network Admin, the %2$s menu item is located under Settings.' ), esc_html( $this->bot->name() ), sprintf( '"<a href="%s">%s</a>"', esc_url( network_admin_url( 'settings.php?page=' . $this->bot->slug() ) ), esc_html( $this->bot->name() ) ) );

		echo '<p>' . $message .'</p>';
	}

	/**
	 * Redirect MS subsite tools page to the network settings page.
	 */
	public function redirect_subsite_to_network_settings() {
		global $pagenow;

		if ( 'tools.php' !== $pagenow ) {
			return;
		}

		if ( $this->bot->slug() !== $this->bot->filter_input( 'page' ) ) {
			return;
		}

		$url = network_admin_url( 'settings.php?page=' . $this->bot->slug() );
		$this->bot->redirect( $url );
	}

	/**
	 * Bootstrap to plugin page load.
	 *
	 * @param string $hook_suffix
	 */
	protected function after_menu_item_load( $hook_suffix ) {
		if ( false === $hook_suffix || $hook_suffix !== $this->bot->hook_suffix() ) {
			return;
		}

		// Load settings page assets
		add_action( 'load-' . $this->bot->hook_suffix(), array( $this, 'load_plugin' ) );
	}

	/**
	 * Load the plugin page hook
	 */
	public function load_plugin() {
		$this->enqueue_assets();

		do_action( $this->bot->slug() . '_load_plugin' );
	}

	/**
	 * Load the assets for the settings page
	 */
	protected function enqueue_assets() {
		$version     = $this->bot->get_asset_version();
		$suffix      = $this->bot->get_asset_suffix();
		$plugins_url = $this->bot->get_asset_base_url();

		// css
		$src = $plugins_url . 'assets/css/settings.css';
		wp_enqueue_style( $this->bot->slug() . '-settings-styles', $src, array(), $version );

		// js
		$src = $plugins_url . 'assets/js/settings' . $suffix . '.js';
		wp_enqueue_script( $this->bot->slug() . '-settings-script', $src, array( 'jquery' ), $version, true );

		wp_localize_script( $this->bot->slug() . '-settings-script', $this->bot->slug() . '_admin', array(
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'strings'  => array(
				'reject_changeset_confirm' => __( 'Are you sure you want to discard all changes?' ),
			),
		) );
	}

	/**
	 * Add a settings link to the plugins page row
	 *
	 * @param array $links
	 *
	 * @return array
	 */
	public function plugin_action_links( $links ) {
		if ( ! current_user_can( $this->bot->capability() ) ) {
			return $links;
		}

		$link = sprintf( '<a href="%s">%s</a>', $this->bot->get_url(), __( 'Settings' ) );
		array_unshift( $links, $link );

		return $links;
	}

	/**
	 * Render the settings page
	 */
	public function render_page() {
		$is_setup = $this->bot->is_setup();

		if ( is_wp_error( $is_setup ) ) {
			$is_setup = false;
		}

		$site_id = apply_filters( $this->bot->slug() . '_pre_render_page_site_id', $this->settings_handler->get_site_id() );

		$data = array(
			'app_url'        => $this->bot->app_url(),
			'app_link'       => $this->bot->url_without_scheme( $this->bot->app_url() ),
			'site_connected' => false !== $site_id,
			'mode'           => Config::mode(),
			'is_setup'       => $is_setup,
		);

		$this->bot->render_view( 'admin', $data );
	}

	/**
	 * Render the settings form on the plugin page if not connected to app.
	 */
	public function render_settings_form() {
		if ( $this->settings_handler->is_site_connected() ) {
			return;
		}

		$data = array(
			'page'        => $this,
			'slug'        => $this->bot->slug(),
			'is_dev_mode' => $this->bot->is_dev_mode(),
		);

		$this->bot->render_view( 'settings', $data );
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

		$this->render_select( 'parent_site_id', 'sites', 'site', $sites );
	}

	/**
	 * Render the Team setting
	 */
	public function render_team_id() {
		if ( Config::MODE_PROD !== $this->bot->mode() ) {
			return;
		}

		$key = 'team_id';

		$teams = $this->site_register->get_teams();

		if ( count( $teams ) <= 1 ) {
			$selected = $this->settings_handler->get( $key, key( $teams ) );

			$args = array(
				'key'   => $key,
				'value' => $selected,
				'class' => 'hidden',
			);

			$this->bot->render_view( 'settings/hidden', $args );

			return;
		}

		$this->render_select( $key, 'teams', 'team', $teams );
	}

	/**
	 * Wrapper for rendering a select
	 *
	 * @param string     $key
	 * @param string     $plural
	 * @param string     $singular
	 * @param null|array $objects
	 */
	protected function render_select( $key, $plural, $singular, $objects = null ) {
		$selected = $this->settings_handler->get( $key, false );

		if ( is_null( $objects ) ) {
			$method  = 'get_' . $plural;
			$objects = $this->site_register->{$method}();
		}

		$args = array(
			'key'         => $key,
			'options'     => $objects,
			'selected'    => $selected,
			'option_text' => $singular,
		);

		$this->bot->render_view( 'settings/select', $args );
	}

	/**
	 * Can we connect the site with the app
	 *
	 * @return bool
	 */
	public function can_connect_site() {
		if ( $this->app->is_api_down() ) {
			// App API down
			return false;
		}

		if ( ! $this->bot->is_dev_mode() ) {
			// Always can connect in Prod mode
			return ! $this->site_register->is_site_limit_reached();
		}

		$sites = $this->site_register->get_production_sites();
		if ( empty( $sites ) ) {
			// No production sites to connect to
			return false;
		}

		return true;
	}

	/**
	 * Render the connected sites in the app metabox
	 */
	public function render_connected_sites() {
		$data = array(
			'title'           => 'Development',
			'empty_msg'       => 'No sites connected',
			'connected_sites' => $this->site_register->get_connected_sites()
		);

		if ( $this->bot->is_dev_mode() ) {
			$connected_sites = array();
			$parent_url      = $this->site_register->get_parent_site_url();
			if ( ! empty( $parent_url ) ) {
				$connected_sites = array(
					array(
						'url'       => $parent_url,
						'admin_url' => $this->site_register->get_parent_site_admin_url(),
					)
				);
			}
			$data['title']           = 'Production';
			$data['empty_msg']       = 'Not connected';
			$data['connected_sites'] = $connected_sites;
		}

		if ( is_array( $data['connected_sites'] ) ) {
			// Format the data for the view
			foreach ( $data['connected_sites'] as $key => $site ) {
				$data['connected_sites'][ $key ]['admin_url'] = $this->site_register->get_site_settings_url( $site['admin_url'] );
				$data['connected_sites'][ $key ]['url']       = $this->bot->url_without_scheme( $site['url'] );
			}
		}

		$this->bot->render_view( 'connected-sites', $data );
	}

	/**
	 * Get the URL to view the diagnostic log
	 *
	 * @return string
	 */
	protected function get_diagnostic_info_url() {
		$args = array(
			'nonce'      => wp_create_nonce( 'diagnostic-log' ),
			'diagnostic' => 'download',
		);

		return $this->bot->get_url( $args );
	}

	/**
	 * Render the sidebar
	 */
	public function render_sidebar() {
		$data = array(
			'support_url'         => Support::get_support_url( $this->settings_handler->get_site_id() ),
			'diagnostic_info_url' => $this->get_diagnostic_info_url(),
		);

		$this->bot->render_view( 'sidebar', $data );
	}
}