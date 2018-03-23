<?php

/**
 * The class to deal with bootstrapping our own database class
 *
 * @since 0.1
 */

namespace DeliciousBrains\Mergebot\Services\Development;

use DeliciousBrains\Mergebot\Providers\Db;

class WPDB_Switcher {

	/**
	 * @var Schema_Handler
	 */
	protected $schema_handler;

	/**
	 * WPDB_Switcher constructor.
	 *
	 * @param Schema_Handler $schema_handler
	 */
	public function __construct( Schema_Handler $schema_handler ) {
		$this->schema_handler = $schema_handler;
	}

	/**
	 * Initialize the hooks
	 */
	public function init() {
		spl_autoload_register( array( $this, 'alias_db_facade' ), true, true );
		add_action( 'plugins_loaded', array( $this, 'handle_replace_wpdb' ), 9999 );
	}

	/**
	 * Create an alias of the core wpdb / custom db class as our fake db parent class
	 * which we extend from in DeliciousBrains\Mergebot\Services\Db
	 *
	 * @param string $class
	 *
	 * @return bool
	 */
	public function alias_db_facade( $class ) {
		$alias = 'DeliciousBrains\Mergebot\Providers\Db_Facade';
		if ( 0 !== strcasecmp( $class, $alias ) ) {
			// Not our Db_Facade being autoloaded, ignore
			return false;
		}

		global $wpdb;
		if ( $wpdb instanceof Db ) {
			// The global $wpdb instance has already been switched out, ignore
			return false;
		}

		// Get the class of the $wpdb instance, this could be \wpdb or a dropin db class
		$parent_class = get_class( $wpdb );
		// Create an alias of this class as Db_Facade, so our custom Db class will always inherit from $wpdb
		class_alias( $parent_class, $alias );

		return true;
	}

	/**
	 * Callback to replace the $wpdb
	 */
	public function handle_replace_wpdb() {
		$db = new Db( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$this->replace_wpdb( $db );
	}

	/**
	 * Load our own database class instance as the $wpdb global
	 *
	 * @param Db $db
	 */
	public function replace_wpdb( Db $db ) {
		global $wpdb;
		$existing_wpdb = $wpdb;

		$wpdb = $db;

		foreach ( $existing_wpdb as $key => $value ) {
			$wpdb->$key = $value;
		}

		$wpdb->unique_meta_tables = $this->schema_handler->get_unique_meta_tables();
	}

	/**
	 * The allowable classes for $wpdb
	 *
	 * @return array
	 */
	protected function class_whitelist() {
		return array(
			'wpdb',
			'DeliciousBrains\Mergebot\Providers\Db',
		);
	}

	/**
	 * Plugins and there db.php classes that Mergebot isn't compatible with.
	 *
	 * The array of data controls the notice displayed to the user.
	 * If a setting can be toggled to still use the plugin but make it stop using a db.php file
	 * then use 'setting_message' and subsequent keys.
	 *
	 * If nothing can be done, it will need a basename so we can show a deactivate message.
	 *
	 * @return array
	 */
	protected function class_blacklist() {
		return array(
			'W3TC\DbCache_Wpdb' => array(
				'plugin'            => 'W3 Total Cache',
				'basename'          => 'w3-total-cache/w3-total-cache.php',
				'setting_message'   => __( 'Please turn off the database caching setting to continue using Mergebot.' ),
				'settings_url'      => 'admin.php?page=w3tc_general#database_cache',
				'settings_url_text' => __( 'Turn off' ),
			),
		);
	}

	/**
	 * Is the global $wpdb instance compatible with out db provider
	 *
	 * @param $wpdb
	 *
	 * @return bool
	 */
	public function is_wpdb_compatible( $wpdb ) {
		$class_name = get_class( $wpdb );
		$whitelist  = $this->class_whitelist();

		if ( in_array( $class_name, $whitelist ) ) {
			return true;
		}

		$blacklist = $this->class_blacklist();
		if ( isset( $blacklist[ $class_name ] ) ) {
			// Handle specific plugins we just can't work with.
			return $blacklist[ $class_name ];
		}

		if ( 'wpdb' === $this->get_parent_class( $wpdb ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Get the highest parent class of an instance.
	 *
	 * @param mixed $class
	 *
	 * @return string
	 */
	protected function get_parent_class( $class ) {
		$ancestors = array( get_class( $class ) );
		while ( $class = get_parent_class( $class ) ) {
			$ancestors[] = $class;
		}

		return array_pop( $ancestors );
	}

	/**
	 * Has the wpdb database class been switched with our version?
	 *
	 * @param object $wpdb
	 *
	 * @return bool
	 */
	public static function is_switched( $wpdb ) {
		$class_name = get_class( $wpdb );

		return 'DeliciousBrains\Mergebot\Providers\Db' === $class_name;
	}
}