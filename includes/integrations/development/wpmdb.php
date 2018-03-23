<?php

/**
 * The class for the defining compatibility with WP Migrate DB
 *
 * @since 0.1
 */

namespace DeliciousBrains\Mergebot\Integrations\Development;

use DeliciousBrains\Mergebot\Models\Excluded_Object;
use DeliciousBrains\Mergebot\Models\Plugin;
use DeliciousBrains\Mergebot\Providers\Modes\Abstract_Mode;
use DeliciousBrains\Mergebot\Services\Development\Recorder_Handler;
use  DeliciousBrains\Mergebot\Integrations\WPMDB as Base_WPMDB;
use DeliciousBrains\Mergebot\Services\Site_Register;
use DeliciousBrains\Mergebot\Utils\Multisite;

class WPMDB extends Base_WPMDB {

	/**
	 * @var Recorder_Handler
	 */
	protected $recorder_handler;

	/**
	 * @var Site_Register
	 */
	protected $site_register;

	/**
	 * @var \ReflectionClass
	 */
	protected $wpmdbpro;

	/**
	 * WPMDB constructor.
	 *
	 * @param Plugin           $bot
	 * @param Abstract_Mode    $mode
	 * @param Site_Register    $site_register
	 * @param Recorder_Handler $recorder_handler
	 */
	public function __construct( Plugin $bot, Abstract_Mode $mode, Site_Register $site_register, Recorder_Handler $recorder_handler ) {
		parent::__construct( $bot, $mode );
		$this->site_register    = $site_register;
		$this->recorder_handler = $recorder_handler;
	}

	/**
	 * Instantiate the hooks
	 */
	public function init() {
		parent::init();

		add_action( 'wpmdb_migration_complete', array( $this, 'maybe_remove_subsite_tables' ), 10, 2 );
		add_action( 'wpmdb_migration_complete', array( $this, 'maybe_truncate_excluded_objects_table' ), 10, 2 );
		add_action( 'wpmdb_migration_complete', array( $this, 'maybe_remove_changes_recorded_option' ), 10, 2 );
		add_action( 'wpmdb_cli_before_initiate_migration', array( $this, 'cli_migration_start' ) );
		add_filter( $this->bot->slug() . '_ignore_query', array( $this, 'maybe_ignored_drop_statements' ), 10, 2 );
	}

	/**
	 * Check if we are doing a database sync migration between Prod and Dev Mergebot sites.
	 *
	 * @param string $type
	 * @param string $location
	 *
	 * @return bool
	 */
	protected function is_mergebot_migration( $type, $location ) {
		if ( ! in_array( $type, array( 'push', 'pull' ) ) ) {
			return false;
		}

		$parent_admin_url = $this->site_register->get_parent_site_admin_url();
		if ( ! $parent_admin_url ) {
			return false;
		}

		if ( 'pull' === $type ) {
			return 0 === strpos( $parent_admin_url, $location );
		}

		$state_data = $this->get_wpmdbpro_property( 'state_data' );

		if ( ! isset( $state_data['action'] ) || 'wpmdb_remote_finalize_migration' !== $state_data['action'] ) {
			return false;
		}

		return 0 === strpos( $parent_admin_url, $location );
	}

	/**
	 * Helper to get the value of a protected property of the WPMDBPro global instance.
	 *
	 * @param string $key
	 *
	 * @return mixed
	 */
	protected function get_wpmdbpro_property( $key ) {
		global $wpmdbpro;
		if ( is_null( $this->wpmdbpro ) ) {
			$this->wpmdbpro = new \ReflectionClass( $wpmdbpro );
		}

		if ( isset( $this->wpmdbpro->{$key} ) ) {
			return $this->wpmdbpro->{$key};
		}

		$property = $this->wpmdbpro->getProperty( $key );
		$property->setAccessible( true );

		$value                  = $property->getValue( $wpmdbpro );
		$this->wpmdbpro->{$key} = $value;

		return $value;
	}

	/**
	 * Check to see if this a mergebot migration on MS with all tables being migrated,
	 * so we can remove all missing subsites' tables.
	 *
	 * @param string $type
	 * @param string $location
	 */
	public function maybe_remove_subsite_tables( $type, $location ) {
		if ( ! is_multisite() ) {
			return;
		}

		if ( ! $this->is_mergebot_migration( $type, $location ) ) {
			return;
		}

		$form_data = $this->get_wpmdbpro_property( 'form_data' );

		// Check the migration profile is all prefixed tables
		if ( ! isset( $form_data['table_migrate_option'] ) || 'migrate_only_with_prefix' !== $form_data['table_migrate_option'] ) {
			return;
		}

		$state_data = $this->get_wpmdbpro_property( 'state_data' );

		//$tables = str_replace( $state_data['prefix'], )
		$migrated_tables = explode( ',', $state_data['tables'] );

		$this->remove_orphaned_subsite_tables( $migrated_tables );
	}

	/**
	 * Remove all local subsite tables that haven't been migrated.
	 *
	 * @param array $migrated_tables
	 */
	protected function remove_orphaned_subsite_tables( $migrated_tables = array() ) {
		global $wpdb;
		$all_tables = $wpdb->get_all_tables();

		$sites    = Multisite::get_sites();
		$blog_ids = wp_list_pluck( $sites, 'blog_id' );
		$pattern  = '/' . $wpdb->base_prefix . '(?:[' . implode( '|', $blog_ids ) . ']_|[_\D])(\S+)/i';

		foreach ( $all_tables as $table ) {
			if ( in_array( $table, $migrated_tables ) ) {
				// Table has been migrated, ignore.
				continue;
			}

			if ( preg_match( $pattern, $table ) ) {
				// Table is network related, or for an existing subsite.
				continue;
			}

			$sql = "DROP TABLE IF EXISTS {$table}; #" . $this->bot->slug();
			$wpdb->query( $sql );
		}
	}

	/**
	 * Turn off recording queries before a CLI migration
	 */
	public function cli_migration_start() {
		$this->recorder_handler->stop_recording( 'wpmdb-cli' );
	}

	/**
	 * Clear the Excluded Objects table after a db sync.
	 *
	 * @param string $type
	 * @param string $location
	 *
	 * @return bool
	 *
	 */
	public function maybe_truncate_excluded_objects_table( $type, $location ) {
		if ( ! $this->is_mergebot_migration( $type, $location ) ) {
			return false;
		}

		Excluded_Object::delete_all();

		return true;
	}

	/**
	 * Ensure we remove the changes recorded option flag on a pull from Production.
	 * Hack fix for https://github.com/deliciousbrains/wp-mb/issues/598
	 * until WPMDB whitelists Mergebot in compatibility mode.
	 *
	 * @param string $type
	 * @param string $location
	 *
	 * @return bool
	 */
	public function maybe_remove_changes_recorded_option( $type, $location ) {
		if ( $type !== 'pull' ) {
			return false;
		}

		if ( ! $this->is_mergebot_migration( $type, $location ) ) {
			return false;
		}

		delete_site_option( $this->bot->slug() . '_changes_recorded' );

		return true;
	}

	/**
	 * Ignore WPMDB migration DROP TABLE statements.
	 *
	 * @param $ignore
	 * @param $query
	 *
	 * @return bool
	 */
	public function maybe_ignored_drop_statements( $ignore, $query ) {
		if ( $ignore ) {
			return $ignore;
		}

		if ( ! $query->is_drop_table() ) {
			return $ignore;
		}

		if ( ! isset( $_POST['migration_state_id'] ) && ! isset( $_POST['remote_state_id'] ) ) {
			return $ignore;
		}

		return true;
	}
}
