<?php

namespace DeliciousBrains\Mergebot\Models;

abstract class Base_Model {

	/**
	 * Base_Model constructor.
	 *
	 * @param array $properties
	 */
	public function __construct( array $properties = array() ) {
		$this->set_properties( $properties );
	}

	/**
	 * Get all of the properties of this model as an array.
	 *
	 * @return array
	 */
	public function to_array() {
		return $this->properties();
	}

	/**
	 * Make all properties a string for easy comparison
	 *
	 * @return array
	 */
	public function normalize_to_string() {
		foreach( $this->properties() as $key => $value ) {
			$this->{$key} = (string ) $value;
		}

		return $this;
	}

	/**
	 * Return an array of all the properties for this model.
	 *
	 * @return array
	 */
	protected function properties() {
		return get_object_vars( $this );
	}

	/**
	 * Get a property via a method the model
	 *
	 * @param string $function
	 * @param array  $arguments
	 *
	 * @return bool|mixed
	 */
	public function __call( $function, $arguments ) {
		$properties = $this->properties();
		if ( array_key_exists( $function, $properties ) ) {
			return $this->{$function};
		}
	}

	/**
	 * Allow isset() to work on our properties
	 *
	 * @param string $key
	 *
	 * @return bool
	 */
	public function __isset( $key ) {
		return isset( $this->{$key} );
	}

	/**
	 * Get a property for the model
	 *
	 * @param string $key
	 *
	 * @return bool|mixed
	 */
	public function __get( $key ) {
		return $this->{$key};
	}

	/**
	 * Set a property for the model
	 *
	 * @param string $key
	 * @param mixed  $value
	 */
	public function __set( $key, $value ) {
		$this->{$key} = $value;
	}

	/**
	 * Set the properties from an array
	 *
	 * @param array $properties
	 */
	protected function set_properties( $properties ) {
		$model_properties = $this->properties();

		$properties = array_intersect_key( $properties, $model_properties );

		foreach ( $properties as $property => $value ) {
			$this->{$property} = $value;
		}
	}

	/**
	 * Create a new model with the given data
	 *
	 * @param array $properties
	 *
	 * @return static
	 */
	public static function create( $properties = array() ) {
		return new static( $properties );
	}

}