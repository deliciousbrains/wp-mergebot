<?php

/**
 * The class for the Development mode of the plugin
 *
 * This is used to load and control all the Development mode related classes and code.
 *
 * @since 0.1
 */

namespace DeliciousBrains\Mergebot\Providers\Modes;

use DeliciousBrains\Mergebot\Models\Notice;
use DeliciousBrains\Mergebot\Services\Admin\Settings_Handler;
use DeliciousBrains\Mergebot\Services\App_Interface;
use DeliciousBrains\Mergebot\Services\Development\Schema_Handler;
use DeliciousBrains\Mergebot\Services\Development\WPDB_Switcher;
use DeliciousBrains\Mergebot\Services\Site_Register;
use DeliciousBrains\Mergebot\Models\Plugin;
use DeliciousBrains\Mergebot\Utils\Async_Request;
use DeliciousBrains\Mergebot\Utils\Cron;
use DeliciousBrains\Mergebot\Utils\Error;
use DeliciousBrains\Mergebot\Utils\Multisite;
use DeliciousBrains\Mergebot\Utils\Support;
use Pimple\Container;
use DBI_Filesystem;

class Development extends Abstract_Mode {

	/**
	 * @var App_Interface
	 */
	protected $app;

	/**
	 * @var Site_Register
	 */
	protected $site_register;

	/**
	 * @var Settings_Handler
	 */
	protected $settings_handler;

	/**
	 * @var Schema_Handler
	 */
	protected $schema_handler;

	/**
	 * Development constructor.
	 *
	 * @param Plugin           $bot
	 * @param App_Interface    $app
	 * @param Site_Register    $site_register
	 * @param Settings_Handler $settings_handler
	 */
	public function __construct( Plugin $bot, App_Interface $app, Site_Register $site_register, Settings_Handler $settings_handler ) {
		parent::__construct( $bot );
		$this->app              = $app;
		$this->site_register    = $site_register;
		$this->settings_handler = $settings_handler;
	}

	/**
	 * @var Async_Request
	 */
	public $close_changesets_request;

	/**
	 * @var WPDB_Switcher
	 */
	protected $wpdb_switcher;

	/**
	 * Services to register in the container.
	 *
	 * @return array
	 */
	public function services() {
		$services = $this->load_config( 'development' );

		return array_merge( parent::services(), $services );
	}

	/**
	 * Register the services and initialize the settings early
	 *
	 * @param Container $container
	 *
	 * @return Container
	 */
	public function register( Container $container ) {
		$container = parent::register( $container );

		$container['development_settings']->init();
		$container['mu_plugin_installer']->init( new DBI_Filesystem() );

		return $container;
	}

	/**
	 * Can we fire it up
	 *
	 * @param Container $container
	 *
	 * @return bool
	 */
	protected function is_setup( Container $container ) {
		if ( false === parent::is_setup( $container ) ) {
			return false;
		}

		global $wpdb;
		$compatible = $container['wpdb_switcher']->is_wpdb_compatible( $wpdb );
		if ( true !== $compatible ) {
			$this->render_wpdb_compatibility_notice( $wpdb, $compatible );

			return false;
		}

		Notice::delete_by_id( 'wpdb-compatibility', true );

		$install = $container['mu_plugin_installer']->install();
		if ( is_wp_error( $install ) ) {
			$this->render_mu_plugin_notice( $install );

			return false;
		}

		if ( $container['basic_auth_handler']->maybe_render_basic_auth_notice() ) {
			$container['basic_auth_handler']->init_template();

			return false;
		}

		return true;
	}

	/**
	 * Instantiate the mode
	 *
	 * @param Container $container
	 */
	protected function init_mode( Container $container ) {
		parent::init_mode( $container);

		$container['changeset_handler']->init();
		$container['recorder_handler']->init();
		$container['recorder_presenter']->init();
		$container['recorder_reminder']->init();
		$container['wpmdb']->init();
		$container['basic_auth_handler']->init();
		if ( is_multisite() ) {
			$container['multisite']->init();
		}

		if ( $container['settings_handler']->is_site_connected() && ! ( $is_recording_disabled = $container['recorder_handler']->is_recording_disabled() ) ) {
			$container['wpdb_switcher']->init();
			$container['query_recorder']->init();
			$container['query_responder']->init( $container['send_queries'] );

			add_filter( 'admin_init', array( $this, 'check_db_class_has_been_switched' ) );
			add_action( 'admin_init', array( $this, 'detect_custom_tables' ) );
		}

		if ( isset( $is_recording_disabled ) && $is_recording_disabled ) {
			add_action( $this->bot->slug() . '_load_plugin', array( $this, 'render_recording_disabled_notice' ) );
		}

		$this->schema_handler = $container['schema_handler'];
		$this->schema_handler->init();

		$this->close_changesets_request = new Async_Request( $this->bot, $container['changeset_handler'], 'close' );
		add_action( $this->bot->slug() . '_view_post_admin', array( $this, 'close_changesets' ) );

		$close_changesets_cron = new Cron( $this->bot, $container['changeset_handler'], 'close' );
		$close_changesets_cron->fire();

		add_action( $this->bot->slug() . '_load_plugin', array( $this, 'maybe_render_no_connected_prod_sites_notice' ) );
	}

	/**
	 * Initiate the close changeset async request
	 */
	public function close_changesets() {
		$this->close_changesets_request->dispatch();
	}

	/**
	 * Drop in WPDB class the site is using doesn't extend \wpdb
	 *
	 * @param $wpdb
	 */
	protected function render_wpdb_compatibility_notice( $wpdb, $compatible = false ) {
		if ( is_array( $compatible ) ) {
			$error_message = $this->get_plugin_db_class_issue_message( $compatible );
		} else {
			$error_message = __( 'You cannot record queries as the site has an incompatible database class.' );
			$contact_link  = Support::generate_link( $this->settings_handler->get_site_id(), 'Incompatible Database Class', $error_message, sprintf( '%s does not extend wpdb', get_class( $wpdb ) ) );
			$error_message .= ' ' . $contact_link;
		}

		$notice_args = array(
			'id'                => 'wpdb-compatibility',
			'type'              => 'error',
			'message'           => $error_message,
			'title'             => sprintf( __( '%s Database Issue' ), $this->bot->name() ),
			'only_show_to_user' => false,
		);

		Notice::create( $notice_args )->save();
	}

	/**
	 * Generate a plugin specific error message when dealing with issues with db.php files for other plugins.
	 *
	 * @param array $data
	 *
	 * @return string
	 */
	protected function get_plugin_db_class_issue_message( $data ) {
		$error_message = sprintf( 'It looks like the plugin %s has an incompatible database class.', $data['plugin'] );

		if ( ! empty( $data['setting_message'] ) ) {
			$error_message .= ' ' . $data['setting_message'];
			$error_message .= ' ' . sprintf( '<a href="%s">%s&nbsp;&raquo;</a>', admin_url( $data['settings_url'] ), str_replace( ' ', '&nbsp;', $data['settings_url_text'] ) );

			return $error_message;
		}

		require_once ABSPATH . 'wp-includes/pluggable.php';
		$deactivate_url  = wp_nonce_url( admin_url( 'plugins.php?action=deactivate&amp;plugin=' . $data['basename'] ), 'deactivate-plugin_' . $data['basename'] );
		$deactivate_link = sprintf( '<a href="%s">%s</a>', $deactivate_url, str_replace( ' ', '&nbsp;', __( 'deactivate the plugin' ) ) );
		$support_link    = sprintf( '<a href="%s">%s</a>', Support::generate_url( $this->settings_handler->get_site_id(), 'Incompatible Database Class', $error_message, $data['plugin'] ), __( 'contact support' ) );
		$error_message   .= ' ' . sprintf( __( 'To continue using Mergebot %s or please %s.' ), $deactivate_link, $support_link );

		return $error_message;
	}

	/**
	 * Check our Db class is being used otherwise show an error
	 */
	public function check_db_class_has_been_switched() {
		if ( $this->bot->doing_ajax() ) {
			return;
		}

		if ( ! $this->settings_handler->is_site_connected() ) {
			// Don't show the notice if site isn't connected.
			return;
		}

		global $wpdb;
		if ( WPDB_Switcher::is_switched( $wpdb ) ) {
			return;
		}

		$this->render_wpdb_switch_failed_notice( $wpdb );
	}

	/**
	 * Drop in WPDB class the site is using doesn't extend \wpdb
	 *
	 * @param $wpdb
	 *
	 * @return bool
	 */
	protected function render_wpdb_switch_failed_notice( $wpdb ) {
		if ( Notice::exists( 'wpdb-switch-fail' ) ) {
			return false;
		}

		$error_message = __( 'You cannot record queries as the site has an incompatible database class.' );
		$contact_link  = Support::generate_link( $this->settings_handler->get_site_id(), 'Incompatible Database Class', $error_message, sprintf( '%s has not been switched with internal class', get_class( $wpdb ) ) );
		$error_message .= ' ' . $contact_link;

		$notice_args = array(
			'id'                => 'wpdb-switch-fail',
			'type'              => 'error',
			'message'           => $error_message,
			'title'             => sprintf( __( '%s Database Issue' ), $this->bot->name() ),
			'only_show_to_user' => false,
		);

		Notice::create( $notice_args )->save();

		return true;
	}

	/**
	 * Display a notice about issues with the MU plugin.
	 *
	 * @param Error $error
	 */
	protected function render_mu_plugin_notice( $error ) {
		$error_message = $error->get_error_message();
		$contact_link  = $this->bot->get_more_info_doc_link( 'troubleshooting', __( 'Click here to troubleshoot' ) );
		$error_message .= ' ' . $contact_link;

		$notice_args = array(
			'type'              => 'error',
			'message'           => $error_message,
			'title'             => __( 'MU Plugin Issue' ),
			'only_show_to_user' => false,
		);

		Notice::create( $notice_args )->save();
	}

	/**
	 * Detect if the site has custom plugin tables
	 */
	public function detect_custom_tables() {
		if ( $this->bot->doing_ajax() ) {
			// Bail on AJAX
			return;
		}

		if ( ! $this->settings_handler->is_site_connected() ) {
			// Don't show the notice if site isn't connected.
			return;
		}

		$notice_id = $this->bot->slug() . '-notice-custom-tables';
		$notice = Notice::find( $notice_id );
		if ( $notice && $notice->is_dismissed( get_current_user_id() ) ) {
			return;
		}

		$custom_tables = $this->get_custom_tables_not_defined_in_schema();
		if ( empty( $custom_tables ) ) {
			return;
		}

		$read_more_link = $this->bot->get_more_info_doc_link( 'schemas' );

		$notice_args = array(
			'id'                => $notice_id,
			'message'           => sprintf( __( 'It looks like you have plugins installed that use custom tables. You can map these tables in the app.%s' ), $read_more_link ),
			'title'             => sprintf( __( '%s Custom Tables' ), $this->bot->name() ),
			'only_show_to_user' => false,
			'flash'             => false,
		);

		Notice::create( $notice_args )->save();
	}

	/**
	 * Get all the custom tables not defined in schema.
	 * Cached in a transient for 5 hours.
	 *
	 * @return array|mixed
	 */
	protected function get_custom_tables_not_defined_in_schema() {
		if ( false !== ( $custom_tables = get_site_transient( $this->bot->slug() . '_custom_tables_undefined' ) ) ) {
			return $custom_tables;
		}

		global $wpdb;
		$all_tables    = $wpdb->get_all_tables();
		$plugin_tables = $this->get_tables();
		$custom_tables = array();

		$schema_primary_keys = $this->schema_handler->get_primary_keys();

		$sites  = Multisite::get_sites();
		$search = array();
		foreach ( $sites as $site ) {
			$search[] = $wpdb->base_prefix . $site->blog_id . '_';
		}
		$search[] = $wpdb->base_prefix;

		foreach ( $all_tables as $table_name ) {
			$table_name_no_prefix = str_replace( $search, '', $table_name );

			if ( isset( $wpdb->{$table_name_no_prefix} ) ) {
				// WP table, ignore
				continue;
			}

			if ( in_array( $table_name, $plugin_tables ) ) {
				// Internal plugin table, ignore
				continue;
			}

			if ( isset( $schema_primary_keys[ $table_name_no_prefix ] ) ) {
				// Table already defined in schema.
				continue;
			}

			$custom_tables[] = $table_name;
		}

		set_site_transient( $this->bot->slug() . '_custom_tables_undefined', $custom_tables, 5 * HOUR_IN_SECONDS );

		return $custom_tables;
	}

	/**
	 * Maybe display a notice if there are no production sites to connect to,
	 * reminding the user to connect the production plugin.
	 */
	public function maybe_render_no_connected_prod_sites_notice() {
		$production_sites = $this->site_register->get_production_sites();

		if ( ! empty( $production_sites ) || $this->app->is_api_down() ) {
			return;
		}

		$read_more_link = $this->bot->get_more_info_doc_link( 'installation' );

		$notice_args = array(
			'message'               => sprintf( __( 'It looks like there are no production sites for this site to connect to.%s' ), $read_more_link ),
			'title'                 => __( 'No Production Sites' ),
			'only_show_to_user'     => false,
			'flash'                 => true,
			'only_show_in_settings' => true,
			'dismissible'           => false,
		);

		Notice::create( $notice_args )->save();
	}

	/**
	 * Show an error if Mergebot is disabled due to a blocked query and too many backed up queries.
	 */
	public function render_recording_disabled_notice() {
		$support_link = Support::generate_link( $this->settings_handler->get_site_id(), 'Mergebot Recording Disabled', '' );

		$notice_args = array(
			'message'           => sprintf( __( 'Mergebot\'s recording has been disabled as there is an issue with a query being sent to the app, %s' ), $support_link ),
			'type'              => 'error',
			'title'             => __( 'Mergebot Disabled' ),
			'only_show_to_user' => false,
			'flash'             => true,
			'dismissible'       => false,
		);

		Notice::create( $notice_args )->save();
	}
}
