<?php

/**
 * The plugin compatibility class.
 *
 * This is used to determine if the plugin can be run on the site
 *
 * @since 0.1
 */
class DeliciousBrains_Mergebot_Compatibility {

	/**
	 * @var string
	 */
	protected $plugin_file_path;

	/**
	 * @var string
	 */
	public $minimum_php_version = '5.3.0';

	/**
	 * Mergebot_Compatibility constructor.
	 *
	 * @param string $plugin_file_path
	 */
	public function __construct( $plugin_file_path ) {
		$this->plugin_file_path = $plugin_file_path;
		$this->plugin_basename  = plugin_basename( $plugin_file_path );
	}

	/**
	 * Register notice of missing requirements
	 */
	public function register_notice() {
		add_action( 'admin_notices', array( $this, 'display_notice' ) );
	}

	/**
	 * Is the plugin compatible?
	 *
	 * @return bool
	 */
	public function is_compatible() {
		$missing_requirements = $this->get_missing_requirements();

		return empty( $missing_requirements );
	}

	/**
	 * Get the missing plugin requirements
	 *
	 * @return array|bool
	 */
	protected function get_missing_requirements() {
		$missing_requirements = array();

		if ( version_compare( PHP_VERSION, $this->minimum_php_version, '<' ) ) {
			$missing_requirements[] = sprintf( __( 'PHP version %s+', 'mergebot' ), $this->minimum_php_version );
		}

		if ( ! file_exists( dirname( $this->plugin_file_path ) . '/vendor' ) ) {
			$missing_requirements[] = __( 'its <code>vendor</code> directory, which is missing', 'mergebot' );
		}
		
		return $missing_requirements;
	}

	/**
	 * Display the admin notice with missing requirements
	 */
	public function display_notice() {
		$requirements = $this->get_missing_requirements();

		if ( empty( $requirements ) ) {
			return false;
		}

		$deactivate_url = wp_nonce_url( admin_url( 'plugins.php?action=deactivate&amp;plugin=' . $this->plugin_basename ), 'deactivate-plugin_' . $this->plugin_basename );
		$requirements   = implode( ', ', $requirements );

		return include dirname( $this->plugin_file_path ) . '/views/compatibility-notice.php';
	}
}