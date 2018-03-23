<?php
/**
 * The class for the Production mode of the plugin
 *
 * This is used to load and control all the Production mode related classes and code.
 *
 * @since 0.1
 */

namespace DeliciousBrains\Mergebot\Providers\Modes;

use DeliciousBrains\Mergebot\Models\Plugin;
use DeliciousBrains\Mergebot\Services\Admin\Settings_Handler;
use DeliciousBrains\Mergebot\Services\Site_Register;
use DeliciousBrains\Mergebot\Utils\Config;
use Pimple\Container;

class Production extends Abstract_Mode {

	/**
	 * @var Settings_Handler
	 */
	protected $settings_handler;

	/**
	 * @var Site_Register
	 */
	protected $site_register;

	/**
	 * Production constructor.
	 *
	 * @param Plugin           $bot
	 * @param Settings_Handler $settings_handler
	 * @param Site_Register    $site_register
	 */
	public function __construct( Plugin $bot, Settings_Handler $settings_handler, Site_Register $site_register ) {
		parent::__construct( $bot );
		$this->settings_handler = $settings_handler;
		$this->site_register    = $site_register;
	}

	/**
	 * Services to register in the container.
	 *
	 * @return array
	 */
	public function services() {
		$services = $this->load_config( 'production' );

		return array_merge( parent::services(), $services );
	}

	/**
	 * Instantiate the mode
	 *
	 * @param Container $container
	 */
	protected function init_mode( Container $container ) {
		parent::init_mode( $container );

		$container['changeset_handler']->init();
		$container['wpmdb']->init();

		if ( ! $this->bot->doing_ajax() && ! $this->bot->doing_cron() ) {
			add_action( 'admin_init', array( $this, 'maybe_auto_register_site' ), 11 );
		}

		add_action( $this->bot->slug() . '_disconnect_site', array( $this, 'maybe_auto_register_site' ) );
	}

	/**
	 * Maybe automatically register the site with the app
	 */
	public function maybe_auto_register_site() {
		if ( is_wp_error( $this->bot->is_setup() ) ) {
			// Plugin not setup, abort.
			return;
		}

		if ( $this->settings_handler->is_site_connected() ) {
			// Site already registered, abort.
			return;
		}

		if ( false === ( $teams = $this->site_register->get_teams() ) ) {
			// Can't retrieve teams, abort.
			return;
		}

		if ( 1 !== count( $teams ) ) {
			// Not just one team, abort.
			return;
		}

		if ( $this->site_register->is_site_limit_reached() ) {
			// Site limit reached.
			return;
		}

		$settings = array( 'team_id' => key( $teams ) );
		$site     = $this->site_register->register_site( Config::MODE_PROD, $settings );

		if ( is_wp_error( $site ) ) {
			// Error registering site, abort.
			return;
		}

		// Save the site ID, now registered with app.
		$this->site_register->save_site( $site, $settings );
	}
}