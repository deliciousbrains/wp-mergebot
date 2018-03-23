<?php

/**
 * The abstract class for asynchronous/cron requests
 *
 * @since 0.1
 */

namespace DeliciousBrains\Mergebot\Utils;

use DeliciousBrains\Mergebot\Services\Abstract_Process;
use DeliciousBrains\Mergebot\Models\Plugin;

abstract class Abstract_Request extends Abstract_Process {

	/**
	 * @var object|null The instance that the request will fire on
	 */
	protected $instance;

	/**
	 * @var string The method of the instance to use for the request
	 */
	protected $method;

	/**
	 * Abstract_Request constructor.
	 *
	 * @param Plugin      $bot
	 * @param object|null $instance
	 * @param string      $method
	 */
	public function __construct( Plugin $bot, $instance, $method ) {
		parent::__construct( $bot );
		$this->instance = $instance;
		$this->method   = $method;

		$this->init();
	}

	/**
	 * Initialize the request
	 */
	protected abstract function init();

	/**
	 * Get the identifier string
	 *
	 * @return string
	 */
	protected function get_identifier() {
		$instance_name = strtolower( $this->get_class_name( $this->instance ) );
		if ( $instance_name ) {
			$instance_name = '_' . $instance_name;
		}

		$identifier = $this->bot->slug() . $instance_name . '_' . $this->method;

		return $identifier;
	}

	/**
	 * Get the class name without namespace of an class instance
	 *
	 * @param object|null $instance
	 *
	 * @return string
	 */
	public function get_class_name( $instance = null ) {
		if ( is_null( $instance ) ) {
			return '';
		}

		$class_name = get_class( $instance );

		$parts = explode( '\\', $class_name );

		return array_pop( $parts );
	}
}