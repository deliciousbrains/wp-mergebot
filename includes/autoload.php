<?php

/**
 * Class Mergebot_Autoloader
 *
 * @since 0.1
 */
class Mergebot_Autoloader {

	protected $vendor_prefix = 'DeliciousBrains';
	protected $project_prefix = 'Mergebot';

	/**
	 * Mergebot_Autoloader constructor.
	 */
	public function __construct() {
		spl_autoload_register( array( $this, 'autoloader' ) );
	}

	/**
	 * Autoload the Mergebot class files
	 *
	 * @param string $class_name
	 */
	public function autoloader( $class_name ) {
		if ( $this->load_vendor_class( $class_name ) ) {
			// Vendor class loaded
			return;
		}

		if ( false === ( $class_name = $this->prefix_check( $class_name ) ) ) {
			return;
		}

		if ( $this->is_namespaced_class( $class_name ) ) {
			// Internal namespaced classed
			$parts = explode( '\\', strtolower( $class_name ) );
			$parts = array_slice( $parts, 2 );
			$class = array_pop( $parts );

			return $this->build_and_load_file( $class, $parts );
		}

		$class = $this->get_class_from_non_namespaced( $class_name );

		// Non-namespaced internal classes, eg. 'DeliciousBrains_Mergebot_Compatibility'
		return $this->build_and_load_file( $class );
	}

	/**
	 * Check the class has the correct prefix/namespace structure
	 *
	 * @param string $class_name
	 *
	 * @return bool|string
	 */
	protected function prefix_check( $class_name ) {
		if ( class_exists( $class_name ) ) {
			return false;
		}

		if ( false === stripos( $class_name, $this->vendor_prefix ) ) {
			return false;
		}

		if ( false === stripos( $class_name, $this->project_prefix ) ) {
			return false;
		}

		return $class_name;
	}

	/**
	 * Is the called class a namespaced one?
	 *
	 * @param string $class_name
	 *
	 * @return bool
	 */
	protected function is_namespaced_class( $class_name ) {
		return false !== strpos( $class_name, '\\' );
	}

	/**
	 * Get class name from non-namespaced, custom prefixed class
	 *
	 * @param string $class_name
	 *
	 * @return string
	 */
	protected function get_class_from_non_namespaced( $class_name ) {
		$search = array( $this->vendor_prefix . '_', $this->project_prefix . '_' );

		return str_replace( $search, '', $class_name );
	}

	/**
	 * Load the vendor classes the plugin depends which use namespaces
	 *
	 * @param string $class
	 *
	 * @return bool
	 */
	protected function load_vendor_class( $class ) {
		$namespaces = array(
			'Pimple'            => '/vendor/pimple/pimple/src/',
			'PHPSQLParser'      => '/vendor/greenlion/php-sql-parser/src/',
			'DBI_Filesystem' => '/vendor/deliciousbrains/wp-filesystem/src/wp-filesystem.php',
		);

		$parts = explode( '\\', $class );

		if ( ! isset( $namespaces[ $parts[0] ] ) ) {
			return false;
		}

		$path = $namespaces[ $parts[0] ];

		if ( false === strpos( $path, '.php' ) ) {
			// Add filename from namespace parts if not specified
			$path .= implode( DIRECTORY_SEPARATOR, $parts ) . '.php';
		}

		return $this->load_vendor_file( $path );
	}

	/**
	 * Load a vendor file
	 *
	 * @param string $path
	 *
	 * @return bool
	 */
	protected function load_vendor_file( $path ) {
		$class_file = dirname( dirname( __FILE__ ) ) . $path;

		$this->load_file( $class_file );

		return true;
	}

	/**
	 * Build the file path and load the file
	 *
	 * @param  string $class
	 * @param array   $dir_parts
	 */
	protected function build_and_load_file( $class, $dir_parts = array() ) {
		$class      = str_replace( '_', '-', strtolower( $class ) );
		$dir_parts  = array_merge( array( dirname( __FILE__ ) ), $dir_parts );
		$dir        = implode( DIRECTORY_SEPARATOR, $dir_parts );
		$class_file = $dir . DIRECTORY_SEPARATOR . $class . '.php';

		$this->load_file( $class_file );
	}

	/**
	 * Require the class file
	 *
	 * @param string $file
	 */
	protected function load_file( $file ) {
		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}
}

// Load the autoloader!
new Mergebot_Autoloader();



