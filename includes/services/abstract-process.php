<?php

/**
 * The abstract class of a process, eg. background job
 *
 * @since 0.1
 */

namespace DeliciousBrains\Mergebot\Services;

use DeliciousBrains\Mergebot\Models\Plugin;

class Abstract_Process {

	/**
	 * @var Plugin
	 */
	protected $bot;

	/**
	 * @var string
	 */
	public $process_lock_key;

	/**
	 * Plugin constructor.
	 *
	 * @param Plugin $bot
	 */
	public function __construct( Plugin $bot ) {
		$this->bot = $bot;

		$this->process_lock_key = $this->bot->slug() . '_process_lock';
	}

	/**
	 * Memory exceeded
	 *
	 * Ensures the a process never exceeds 90% of the maximum WordPress memory.
	 *
	 * @return bool
	 */
	public function memory_exceeded() {
		$memory_limit   = $this->get_memory_limit() * 0.9; // 90% of max memory
		$current_memory = memory_get_usage( true );
		$return         = false;

		if ( $current_memory >= $memory_limit ) {
			$return = true;
		}

		return $return;
	}

	/**
	 * Get memory limit
	 *
	 * @return int
	 */
	public function get_memory_limit() {
		if ( function_exists( 'ini_get' ) ) {
			$memory_limit = ini_get( 'memory_limit' );
		} else {
			// Sensible default
			$memory_limit = '128M';
		}

		if ( ! $memory_limit || - 1 == $memory_limit ) {
			// Unlimited, set to 32GB
			$memory_limit = '32000M';
		}

		return intval( $memory_limit ) * 1024 * 1024;
	}

	/**
	 * Get the maximum execution time
	 *
	 * @return string
	 */
	public function get_max_execution_time() {
		return apply_filters( $this->bot->slug() . '_max_execution_time', ini_get( 'max_execution_time' ) );
	}

	/**
	 * Get a the time when a process should be finished by before timing out
	 *
	 * @return bool|int
	 */
	public function get_execution_finish_time() {
		$finish_time        = false;
		$max_execution_time = $this->get_max_execution_time();
		if ( $max_execution_time > 0 ) {
			$finish_time = time() + $max_execution_time;
		}

		return $finish_time;
	}

	/**
	 * Has the time exceeded a finish time
	 *
	 * @param int|bool $finish_time
	 *
	 * @return bool
	 */
	public function time_exceeded( $finish_time ) {
		return false !== $finish_time && time() >= $finish_time;
	}


	/**
	 * Is the plugin processing a cron
	 *
	 * @param string $process
	 *
	 * @return bool
	 */
	public function is_processing( $process ) {
		$lock = get_site_transient( $this->process_lock_key );

		return isset( $lock[ $process ] );
	}

	/**
	 * Set the process lock for a cron
	 *
	 * @param string $process
	 * @param bool   $on
	 */
	public function set_processing_lock( $process, $on = true ) {
		$lock = get_site_transient( $this->process_lock_key );

		if ( $on ) {
			$lock[ $process ] = 1;
		} else {
			unset( $lock[ $process ] );
		}

		if ( empty( $lock ) ) {
			delete_site_transient( $this->process_lock_key );
		} else {
			set_site_transient( $this->process_lock_key, $lock, 10 * MINUTE_IN_SECONDS );
		}
	}
}