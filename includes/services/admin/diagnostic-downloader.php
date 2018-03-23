<?php

/**
 * The class that handles downloading the diagnostic log for support
 *
 * @since 0.1
 */

namespace DeliciousBrains\Mergebot\Services\Admin;

use DeliciousBrains\Mergebot\Models\Notice;
use DeliciousBrains\Mergebot\Models\Plugin;
use DeliciousBrains\Mergebot\Models\Query;
use DeliciousBrains\Mergebot\Providers\Modes\Abstract_Mode;
use DeliciousBrains\Mergebot\Services\Site_Register;
use DeliciousBrains\Mergebot\Utils\Config;
use DeliciousBrains\Mergebot\Utils\Support;

class Diagnostic_Downloader {

	/**
	 * @var Plugin
	 */
	protected $bot;

	/**
	 * @var Site_Register
	 */
	protected $site_register;

	/**
	 * Diagnostic_Downloader constructor.
	 *
	 * @param Plugin        $bot
	 * @param Site_Register $site_register
	 */
	public function __construct( Plugin $bot, Site_Register $site_register ) {
		$this->bot           = $bot;
		$this->site_register = $site_register;
	}

	/**
	 * Initialize hooks
	 */
	public function init() {
		add_action( 'admin_init', array( $this, 'handle_diagnostic_download' ) );
	}

	/**
	 * Listen for diagnostic log requests and render it
	 *
	 * @return bool|void
	 */
	public function handle_diagnostic_download() {
		$diagnostic = $this->bot->filter_input( 'diagnostic' );
		if ( ! isset( $diagnostic ) || 'download' !== $diagnostic ) {
			return false;
		}

		$nonce = $this->bot->filter_input( 'nonce' );
		if ( ! isset( $nonce ) || ! wp_verify_nonce( $nonce, 'diagnostic-log' ) ) {
			return false;
		}

		ob_start();
		$this->format_diagnostic_info();
		$this->format_themes_list();
		$this->format_plugins_list();
		$log = ob_get_clean();

		$url      = parse_url( home_url() );
		$host     = sanitize_file_name( $url['host'] );
		$filename = sprintf( '%s-mergebot-diagnostic-log-%s.txt', $host, date( 'YmdHis' ) );

		return $this->download_log( $filename, $log );
	}

	/**
	 * Return the log contents.
	 *
	 * @param string $filename
	 * @param string $log
	 */
	protected function download_log( $filename, $log ) {
		header( 'Content-Description: File Transfer' );
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Length: ' . strlen( $log ) );
		header( 'Content-Disposition: attachment; filename=' . $filename );
		echo $log;
		exit;
	}

	/**
	 * Render the array of diagnostic log data in a string in the format
	 * Key: Value
	 *
	 * @return string
	 */
	public function format_diagnostic_info() {
		$data = $this->get_diagnostic_info();

		$keys = array_keys( $data );

		$log = array_map( function ( $v, $k ) {
			$prefix = '';
			if ( '\r\n' == $v ) {
				$prefix =  "\r\n";
				$v = '';
			}

			return $prefix . sprintf( "%s: %s", $k, esc_html( $v ) );
		}, $data, $keys );

		echo implode( "\r\n", $log );
	}

	/**
	 * Get all the diagnostic info
	 *
	 * @return array
	 */
	public function get_diagnostic_info() {
		global $wpdb;

		$hosts = 'None';
		if ( defined( 'WP_HTTP_BLOCK_EXTERNAL' ) && WP_HTTP_BLOCK_EXTERNAL ) {
			$hosts = defined( 'WP_ACCESSIBLE_HOSTS' ) && '' !== trim( WP_ACCESSIBLE_HOSTS ) ? 'Hosts - ' . WP_ACCESSIBLE_HOSTS : 'All';
		}

		$theme_info = wp_get_theme();

		if ( $this->bot->is_dev_mode() ) {
			$site_label     = 'Production Site';
			$connected_site = $this->site_register->get_parent_site_url();
		} else {
			$site_label      = 'Connected Sites';
			$connected_sites = array();
			$sites           = $this->site_register->get_connected_sites();
			foreach ( $sites as $key => $site ) {
				$connected_sites[] = $this->bot->url_without_scheme( $site['url'] );
			}
			$connected_site = implode( ', ', $connected_sites );
		}

		$data = array(
			'site_url()'                     => network_site_url(),
			'home_url()'                     => network_admin_url(),
			'Database'                       => $wpdb->dbname,
			'Table Prefix'                   => $wpdb->base_prefix,
			'WordPress'                      => ( is_multisite() ? ' Multisite (' . ( defined( 'SUBDOMAIN_INSTALL' ) && SUBDOMAIN_INSTALL ? 'Sub-domain' : 'Sub-directory' ) . ') ' : '' ) . get_bloginfo( 'version', 'display' ),
			'Web Server'                     => ! empty( $_SERVER['SERVER_SOFTWARE'] ) ? $_SERVER['SERVER_SOFTWARE'] : '',
			'PHP'                            => function_exists( 'phpversion' ) ? phpversion() : '',
			'MySQL'                          => $wpdb->db_version(),
			'ext/mysqli'                     => empty( $wpdb->use_mysqli ) ? 'no' : 'yes',
			'PHP Memory Limit'               => function_exists( 'ini_get' ) ? ini_get( 'memory_limit' ) : '',
			'WP Memory Limit'                => WP_MEMORY_LIMIT,
			'WP Locale'                      => get_locale(),
			'WP_DEBUG'                       => ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ? 'Yes' : 'No',
			'WP_DEBUG_LOG'                   => ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) ? 'Yes' : 'No',
			'WP_DEBUG_DISPLAY'               => ( defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY ) ? 'Yes' : 'No',
			'SCRIPT_DEBUG'                   => ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? 'Yes' : 'No',
			'PHP Time Limit'                 => function_exists( 'ini_get' ) ? ini_get( 'max_execution_time' ) : '',
			'PHP Error Log'                  => function_exists( 'ini_get' ) ? ini_get( 'error_log' ) : '',
			'WP Cron'                        => ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) ? 'Disabled' : 'Enabled',
			'fsockopen'                      => function_exists( 'fsockopen' ) ? 'Enabled' : 'Disabled',
			'cURL'                           => function_exists( 'curl_init' ) ? 'Enabled' : 'Disabled',
			'OpenSSL'                        => defined( 'OPENSSL_VERSION_TEXT' ) ? OPENSSL_VERSION_TEXT : 'Disabled',
			'Blocked External HTTP Requests' => $hosts,
			'Active Theme Name'              => $theme_info->Name,
			'Active Theme Folder'            => basename( $theme_info->get_stylesheet_directory() ),
			'Custom Database Drop-in'        => file_exists( WP_CONTENT_DIR . '/db.php' ) ? 'Yes' : 'No',
			'Mergebot Mode'                  => Config::get( 'plugin_mode', 'Not defined' ),
			$site_label                      => $connected_site,
			'Mergebot API URL'               => $this->bot->api_url(),
			'Mergebot App URL'               => $this->bot->app_url(),
		);

		$data = apply_filters( $this->bot->slug() . '_diagnostic_data', $data );

		$unprocessed_queries    = Query::fetch_unprocessed_blocking();
		$unprocessed_queries    = wp_list_pluck( $unprocessed_queries, 'id' );
		$count_blocking_queries = count( $unprocessed_queries );
		$blocking_queries_text  = number_format( $count_blocking_queries );
		if ( $count_blocking_queries > 0 ) {
			$blocking_queries_text .= ' (' . implode( ',', $unprocessed_queries ) . ')';
		}
		$data['Blocking Queries'] = $blocking_queries_text;

		$data['Notices'] = '\r\n';
		$notices         = Notice::all();
		foreach ( $notices as $notice ) {
			$data[ $notice->title() ] = strip_tags( $notice->message() );
		}

		return $data;
	}

	/**
	 * Render list of active themes for the site.
	 */
	protected function format_themes_list() {
		echo "\r\n\r\n";
		echo "Active Themes:\r\n";
		$active_themes = Support::get_active_themes();
		foreach ( $active_themes as $theme ) {
			$dir = basename( $theme->get_stylesheet_directory() );
			echo "Name: $theme->Name\r\n";
			echo "Folder: $dir\r\n";
		}
	}

	/**
	 * Render list of plugins
	 */
	protected function format_plugins_list() {
		echo "\r\nActive Plugins:\r\n";
		$active_plugins = Support::get_active_plugins();
		$plugin_details = array();

		foreach ( $active_plugins as $plugin ) {
			$plugin_details[] = $this->get_plugin_details( WP_PLUGIN_DIR . '/' . $plugin );
		}

		asort( $plugin_details );
		echo implode( "\r\n", $plugin_details );

		$mu_plugins = wp_get_mu_plugins();
		if ( $mu_plugins ) {
			$mu_plugin_details = array();
			echo "\r\n\r\n";
			echo "Must-use Plugins:\r\n";

			foreach ( $mu_plugins as $mu_plugin ) {
				$mu_plugin_details[] = $this->get_plugin_details( $mu_plugin );
			}

			asort( $mu_plugin_details );
			echo implode( "\r\n", $mu_plugin_details );
		}
	}

	/**
	 * Helper to display plugin details
	 *
	 * @param string $plugin_path
	 * @param string $suffix
	 *
	 * @return string
	 */
	protected function get_plugin_details( $plugin_path, $suffix = '' ) {
		$plugin_data = get_plugin_data( $plugin_path );
		if ( empty( $plugin_data['Name'] ) ) {
			return basename( $plugin_path );
		}

		return sprintf( "%s%s (v%s) by %s", $plugin_data['Name'], $suffix, $plugin_data['Version'], strip_tags( $plugin_data['AuthorName'] ) );
	}
}