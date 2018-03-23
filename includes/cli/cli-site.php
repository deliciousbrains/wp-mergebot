<?php

/**
 * * The class for WP CLI site connection command
 *
 * This is used to control site aspects of the plugin with the CLI.
 *
 * @since 0.1
 */

namespace DeliciousBrains\Mergebot\CLI;

class CLI_Site extends Abstract_CLI_Command {

	/**
	 * Connects the site to the App.
	 *
	 * ## OPTIONS
	 *
	 * [--site]
	 * : Production site URL to connect a development site with. DEVELOPMENT mode.
	 *
	 * [--team]
	 * : Team to register the production site with. PRODUCTION mode.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mergebot connect --site=mysite.com
	 *     wp mergebot connect --team=Acme
	 *
	 * @param array $args
	 * @param array $assoc_args
	 *
	 * @return bool
	 */
	public function connect( $args, $assoc_args ) {
		if ( ! $this->is_setup() ) {
			return false;
		}

		return $this->connect_site( $args, $assoc_args );
	}

	/**
	 * Disconnects the site to the App.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mergebot disconnect
	 *
	 * @return bool
	 */
	public function disconnect() {
		if ( ! $this->is_setup() ) {
			return false;
		}

		if ( false === $this->settings_handler->get_site_id() ) {
			return $this->error( __( 'Site not connected' ) );
		}

		$this->site_register->disconnect_site();

		\WP_CLI::success( __( 'Site disconnected!' ) );

		return true;
	}

	/**
	 * Connects the site to the app
	 *
	 * @param $args
	 * @param $assoc_args
	 *
	 * @return bool
	 */
	protected function connect_site( $args, $assoc_args ) {
		if ( false !== $this->settings_handler->get_site_id() ) {
			return $this->error( __( 'Site already connected' ) );
		}

		$data = array();
		if ( $this->bot->is_dev_mode() ) {
			if ( ! isset( $assoc_args['site'] ) ) {
				return $this->error( __( 'Site argument is required' ) );
			}

			$site  = $assoc_args['site'];
			$sites = $this->site_register->get_production_sites( true );

			if ( empty( $sites ) ) {
				return $this->error( __( 'No production sites connected' ) );
			}

			$parent_site_id = false;
			foreach ( $sites as $site_id => $site_data ) {
				if ( $this->normalize_url( $site ) === $this->normalize_url( $site_data['url'] ) ) {
					$parent_site_id = $site_id;
					break;
				}
			}

			if ( ! $parent_site_id ) {
				return $this->error( sprintf( __( 'Production site %s not connected' ), $site ) );
			}

			$data['parent_site_id'] = $parent_site_id;
		} else {
			$teams = $this->site_register->get_teams( true );

			if ( empty( $teams ) ) {
				return $this->error( __( 'No teams' ) );
			}

			$team_id = $this->get_team_id( $assoc_args, $teams );
			if ( false === $team_id ) {
				return $this->error( __( 'Team not found' ) );
			}

			$data['team_id'] = $team_id;
		}

		$site = $this->site_register->register_site( $this->bot->mode(), $data );

		if ( is_wp_error( $site ) ) {
			$message = $site->is_site_limit_reached() ? $site->get_api_response_message() : __( 'Site not connected' );

			return $this->error( $message );
		}

		$this->site_register->save_site( $site, $data );

		\WP_CLI::success( __( 'Site connected!' ) );

		return true;
	}

	/**
	 * Get the team ID
	 *
	 * @param array $assoc_args
	 * @param array $teams
	 *
	 * @return int|bool
	 */
	protected function get_team_id( $assoc_args, $teams ) {
		reset( $teams );

		if ( ! isset( $assoc_args['team'] ) ) {
			// No Team supplied, just return the first one
			return key( $teams );
		}

		if ( isset( $teams[ $assoc_args['team'] ] ) ) {
			// Team ID supplied, somehow
			return $assoc_args['team'];
		}

		// Search for team name in array
		return array_search( $assoc_args['team'], $teams );
	}

	/**
	 * Normalizes a URL removing the scheme and handling relative URLs
	 *
	 * @param string $url The url to normalize.
	 *
	 * @return string $url
	 */
	protected function normalize_url( $url ) {
		$url = trim( $url );
		if ( 0 === strpos( $url, '//' ) ) {
			$url = 'http:' . $url;
		}

		$url = preg_replace( '(^https?://)', '', $url );
		$url = untrailingslashit( $url );

		return $url;
	}
}