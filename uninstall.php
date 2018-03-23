<?php
/**
 * Uninstall Mergebot
 *
 * @package     Mergebot
 * @subpackage  Uninstall
 * @since       0.1
 */

// Exit if accessed directly
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once dirname( __FILE__ ) . '/includes/autoload.php';

call_user_func( array( 'DeliciousBrains\\Mergebot\\Uninstall', 'init' ) );