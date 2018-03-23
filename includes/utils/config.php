<?php

/**
 * The Config utility class.
 *
 * @since 0.1
 */

namespace DeliciousBrains\Mergebot\Utils;

class Config {

	/**
	 * Whitelist of constants
	 *
	 * @var array
	 */
	static $keys = array(
		'api_key',
		'plugin_mode',
		'api_url',
		'app_url',
	);

	const MODE_DEV = 'development';
	const MODE_PROD = 'production';

	/**
	 * Get a config value from a constant
	 *
	 * @param string    $key
	 * @param bool|mixed $default
	 *
	 * @return bool|mixed
	 */
	public static function get( $key, $default = false ) {
		$key = 'MERGEBOT_' . strtoupper( $key );

		if ( defined( $key ) ) {
			return constant( $key );
		}

		return $default;
	}

	/**
	 * Magic method to get config value from name
	 *
	 * @param string $name
	 * @param array  $arguments
	 *
	 * @return bool|mixed
	 */
	public static function __callStatic( $name, $arguments ) {
		if ( ! in_array( $name, self::$keys ) ) {
			return false;
		}

		$default = isset( $arguments[0] ) ? $arguments[0] : false;

		return self::get( $name, $default );
	}

	/**
	 * Get the mode and ensure it is valid
	 *
	 * @return bool|string
	 */
	public static function mode() {
		$mode = self::get( 'plugin_mode' );

		if ( false === $mode ) {
			return false;
		}

		$mode = strtolower( $mode );

		if ( ! in_array( $mode, array( self::MODE_DEV, self::MODE_PROD ) ) ) {
			// Not in whitelist of modes
			return false;
		}

		return $mode;
	}

}