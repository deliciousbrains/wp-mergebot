<?php

namespace DeliciousBrains\Mergebot\App\API;

class API_Response {

	public static $api_responses = array(
		'unauthenticated'       => array( 401, 'Unauthenticated.' ),
		'subscription_required' => array( 402, 'Subscription Required.' ),
		'site_limit_reached'    => array( 402, 'Cannot create site: Plan limit reached' ),
	);

	/**
	 * Magic method to support checks for API response.
	 * Ie. is_unauthenticated()
	 *
	 * @param string $name
	 * @param array  $arguments
	 *
	 * @return bool
	 */
	public static function __callStatic( $name, $arguments ) {
		if ( 0 !== strpos( $name, 'is_' ) ) {
			return false;
		}

		$name = str_replace( 'is_', '', $name );

		if ( ! isset( self::$api_responses[ $name ] ) ) {
			return false;
		}

		if ( ! isset( $arguments[0] ) || ! isset( $arguments[1] ) ) {
			return false;
		}

		$response = self::$api_responses[ $name ];

		if ( $response[0] === $arguments[0] && $response[1] === $arguments[1] ) {
			return true;
		}

		return false;
	}
}
