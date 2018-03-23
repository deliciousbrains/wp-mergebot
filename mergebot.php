<?php
/*
Plugin Name: Mergebot
Plugin URI: http://wordpress.org/extend/plugins/mergebot/
Description: WordPress database merging made easy
Author: Delicious Brains
Version: 1.1.6
Author URI: http://deliciousbrains.com/
Network: True
Text Domain: mergebot
Domain Path: /languages/

// Copyright (c) 2016 Delicious Brains. All rights reserved.
//
// Released under the GPL license
// http://www.opensource.org/licenses/gpl-license.php
//
// **********************************************************************
// This program is distributed in the hope that it will be useful, but
// WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
// **********************************************************************
*/

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Bootstrap our autoloader
require_once dirname( __FILE__ ) . '/includes/autoload.php';

/**
 * The main function responsible for returning the one true Mergebot
 * instance to functions everywhere.
 */
function mergebot() {
	// Load our main plugin class
	require_once dirname( __FILE__ ) . '/includes/mergebot.php';
	// Namespaced class name as variable so it can be parsed in < PHP 5.3
	$class   = 'DeliciousBrains\\Mergebot\\Mergebot';
	$version = '1.1.6';

	return call_user_func( array( $class, 'get_instance' ), __FILE__, $version );
}

/**
 * Load the plugin if it is compatible with the site.
 */
function mergebot_init() {
	$plugin_check = new DeliciousBrains_Mergebot_Compatibility( __FILE__ );
	if ( ! $plugin_check->is_compatible() ) {
		// Plugin does not meet requirements, display notice and bail
		$plugin_check->register_notice();

		return;
	}

	// Start it up
	mergebot();
}

// Initialize the plugin
mergebot_init();
