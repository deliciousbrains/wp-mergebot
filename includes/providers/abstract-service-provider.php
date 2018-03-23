<?php

/**
 * The class that registers all our services in the IOC container
 *
 * @since 0.1
 */

namespace DeliciousBrains\Mergebot\Providers;

use Pimple\ServiceProviderInterface;
use Pimple\Container;

abstract class Abstract_Service_Provider implements ServiceProviderInterface {

	/**
	 * Returns an array of class names to be registered.
	 *
	 * @return array
	 */
	public abstract function services();

	/**
	 * Load classes from config file
	 *
	 * @param string $name
	 *
	 * @return array
	 */
	protected function load_config( $name ) {
		$file_name = dirname( __FILE__ ) . '/config/' . $name . '.php';
		if ( file_exists( $file_name ) ) {
			return include $file_name;
		}

		return array();
	}

	/**
	 * Registers services on the container.
	 *
	 * @param Container $container
	 *
	 * @return Container
	 */
	public function register( Container $container ) {
		foreach ( $this->services() as $service ) {
			$this->register_service( $service, $container );
		}

		return $container;
	}

	/**
	 * Register an individual service and inject dependencies
	 *
	 * @param string    $class
	 * @param Container $container
	 */
	protected function register_service( $class, &$container ) {
		$service_name = $this->get_service_name( $class );
		$self         = $this;

		$container[ $service_name ] = function ( $c ) use ( $self, $class ) {
			return $self->get_service_instance( $c, $class );
		};
	}

	/**
	 * Register a factory service and inject dependencies
	 *
	 * @param string    $class
	 * @param Container $container
	 */
	protected function register_factory_service( $class, &$container ) {
		$service_name = $this->get_service_name( $class );
		$self         = $this;

		$container[ $service_name ] = $container->factory( function ( $c ) use ( $self, $class ) {
			return $self->get_service_instance( $c, $class );
		} );
	}

	/**
	 * Get the container arguments needed for the class constructor
	 *
	 * @param Container $container
	 * @param  string   $class
	 *
	 * @return array
	 */
	protected function get_constructor_args( Container $container, $class ) {
		$args = array();
		$dependencies = $this->get_constructor_dependencies( $class );
		foreach ( $dependencies as $dependency ) {
			$dependency = $this->filter_dependency( $dependency, $args );
			if ( false === $dependency ) {
				continue;
			}

			if ( ! isset( $container[ $dependency ] ) ) {
				throw new \RuntimeException( sprintf( 'Missing %s dependency in Container', $dependency ) );
			}

			// Inject the container instance of a dependency
			$args[] = $container[ $dependency ];
		}

		return $args;
	}

	/**
	 * Allow extending classes to change arguments based on the dependency being injected
	 *
	 * @param string $dependency
	 * @param string  $args
	 *
	 * @return string|false
	 */
	protected function filter_dependency( $dependency, &$args ) {
		return $dependency;
	}

	/**
	 * Instantiate a new instance of the class
	 *
	 * @param Container $container
	 * @param string    $class
	 *
	 * @return object
	 */
	public function get_service_instance( Container $container, $class ) {
		$reflect     = new \ReflectionClass( $class );
		$constructor = $reflect->getConstructor();

		if ( is_null( $constructor ) ) {
			return $reflect->newInstance();
		}

		$args = $this->get_constructor_args( $container, $class );

		return $reflect->newInstanceArgs( $args );
	}

	/**
	 * Get the formatted service name for a class
	 *
	 * @param string $class
	 *
	 * @return string
	 */
	protected function get_service_name( $class ) {
		$class_parts = explode( '\\', $class );

		return strtolower( array_pop( $class_parts ) );
	}

	/**
	 * Get all the dependencies injected into a class constructor as container service names
	 *
	 * @param string $class
	 *
	 * @return array
	 */
	protected function get_constructor_dependencies( $class ) {
		$dependencies = array();
		$reflection   = new \ReflectionClass( $class );
		$constructor  = $reflection->getConstructor();

		if ( is_null( $constructor ) ) {
			return $dependencies;
		}

		$params = $constructor->getParameters();

		foreach ( $params as $param ) {
			if ( is_null( $param->getClass() ) ) {
				$dependencies[] = $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null;
				continue;
			}

			$dependencies[] = $this->get_service_name( $param->getClass()->name );
		}

		return $dependencies;
	}
}