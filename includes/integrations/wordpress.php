<?php

/**
 * The class for the defining compatibility with WordPress
 *
 * @since      0.1
 * @package    Mergebot
 * @subpackage Mergebot/classes/integrations
 */

namespace DeliciousBrains\Mergebot\Integrations;

use DeliciousBrains\Mergebot\Models\Plugin;

class WordPress {

	/**
	 * @var Plugin
	 */
	protected $bot;

	/**
	 * WPMDB constructor.
	 *
	 * @param Plugin $bot
	 */
	public function __construct( Plugin $bot ) {
		$this->bot = $bot;
	}

	/**
	 * Instantiate the hooks
	 */
	public function init() {
		add_action( $this->bot->slug() . '_post_deployment', 'wp_cache_flush' );
		add_action( $this->bot->slug() . '_post_deployment', array( $this, 'flush_rewrite_rules' ), 11 );
		add_filter( $this->bot->slug() . '_ignore_excluded_queries', array( $this, 'ignore_excluded_queries' ) );
	}

	/**
	 * Flush rewrite rules after changeset is deployed
	 */
	public function flush_rewrite_rules() {
		global $wp_rewrite;
		$wp_rewrite->init();
		flush_rewrite_rules();
	}

	/**
	 * Don't store the excluded queries that we really don't care about
	 *
	 * @param array $queries
	 *
	 * @return array
	 */
	public function ignore_excluded_queries( $queries ) {
		$queries[] = '_transient_(.*)';
		$queries[] = '_site_transient_(.*)';

		return $queries;
	}
}
