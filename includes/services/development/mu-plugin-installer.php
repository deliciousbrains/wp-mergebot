<?php

/**
 * The class that handles the installation of the mu-plugin.
 *
 * @since 0.1
 */

namespace DeliciousBrains\Mergebot\Services\Development;

use DBI_Filesystem;
use DeliciousBrains\Mergebot\Models\Plugin;
use DeliciousBrains\Mergebot\Utils\Error;

class MU_Plugin_Installer {

	/**
	 * @var Plugin
	 */
	protected $bot;

	/**
	 * @var DBI_Filesystem
	 */
	protected $filesystem;

	/**
	 * @var string
	 */
	protected $mu_plugin_version;

	/**
	 * @var string
	 */
	protected $mu_plugin_dir;

	/**
	 * @var string
	 */
	protected $mu_plugin_source;

	/**
	 * @var string
	 */
	protected $mu_plugin_dest;

	/**
	 * MU_Plugin_Installer constructor.
	 *
	 * @param Plugin            $bot
	 */
	public function __construct( Plugin $bot )  {
		$this->bot               = $bot;
		$this->mu_plugin_version = '1.0';
		$this->mu_plugin_dir     = ( defined( 'WPMU_PLUGIN_DIR' ) && defined( 'WPMU_PLUGIN_URL' ) ) ? WPMU_PLUGIN_DIR : trailingslashit( WP_CONTENT_DIR ) . 'mu-plugins';
		$this->mu_plugin_source  = $this->bot->dir_path() . '/includes/mu-plugin/mergebot.php';
		$this->mu_plugin_dest    = trailingslashit( $this->mu_plugin_dir ) . 'mergebot.php';
	}

	/**
	 * Initialize hooks.
	 *
	 * @param DBI_Filesystem $filesystem
	 */
	public function init( DBI_Filesystem $filesystem ) {
		$this->filesystem = $filesystem;

		add_action( $this->bot->slug() . '_deactivate', array( $this, 'delete_plugin' ) );
	}

	/**
	 * Install the mu-plugin, if necessary.
	 *
	 * @return bool|Error
	 */
	public function install() {
		if ( false === $this->should_install() ) {
			// Don't need to install
			return false;
		}

		return $this->copy_plugin();
	}

	/**
	 * Should install the mu-plugin.
	 */
	protected function should_install() {
		if ( ! file_exists( $this->mu_plugin_dest ) ) {
			// MU plugin doesn't exist at all.
			return true;
		}

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$mu_plugin_data = get_plugin_data( $this->mu_plugin_dest );
		if ( ! isset( $mu_plugin_data['Version'] ) || empty( $mu_plugin_data['Version'] ) ) {
			// MU plugin doesn't have a version, install it a fresh.
			return true;
		}

		if ( version_compare( $this->mu_plugin_version, $mu_plugin_data['Version'], '>' ) ) {
			// MU plugin outdated, install it a fresh.
			return true;
		}

		// Don't install
		return false;
	}

	/**
	 * Copy the MU plugin to the mu-plugins dir.
	 *
	 * @return bool|Error
	 */
	protected function copy_plugin() {
		// Make the mu-plugins folder if it doesn't already exist, if the folder does exist it's left as-is.
		if ( ! $this->filesystem->mkdir( $this->mu_plugin_dir ) ) {
			return new Error( Error::$MUPlugin_mupluginDirIssue, sprintf( __( 'There was an issue with the %s directory.' ), $this->mu_plugin_dir ) );
		}

		if ( ! $this->filesystem->copy( $this->mu_plugin_source, $this->mu_plugin_dest ) ) {
			return new Error( Error::$MUPlugin_mupluginCopyIssue, __( 'There was an issue copying the MU plugin.' ) );
		}

		return true;
	}

	/**
	 * Remove the MU plugin from the mu-plugins dir.
	 *
	 * @return bool
	 */
	public function delete_plugin() {
		if ( ! $this->filesystem->file_exists( $this->mu_plugin_dest ) ) {
			return false;
		}

		if ( ! $this->filesystem->unlink( $this->mu_plugin_dest ) ) {
			return false;
		}

		return true;
	}

}