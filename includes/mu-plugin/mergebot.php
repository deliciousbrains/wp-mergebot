<?php
/*
Plugin Name: Mergebot mu-plugin
Plugin URI: http://wordpress.org/extend/plugins/mergebot/
Description: Performs Mergebot actions early as a mu-plugin
Author: Delicious Brains
Version: 1.0
Author URI: https://deliciousbrains.com
*/

$plugin_path = 'mergebot/includes/mu-plugin/mu-plugin.php';

$mergebot_mu_plugin_class = plugin_dir_path( __FILE__ ) . '../plugins/' . $plugin_path;
if ( defined( 'WP_PLUGIN_DIR' ) ) {
	$mergebot_mu_plugin_class = trailingslashit( WP_PLUGIN_DIR ) . $plugin_path;
} else if ( defined( 'WPMU_PLUGIN_DIR' ) ) {
	$mergebot_mu_plugin_class = trailingslashit( WPMU_PLUGIN_DIR ) . $plugin_path;
} else if ( defined( 'WP_CONTENT_DIR' ) ) {
	$mergebot_mu_plugin_class = trailingslashit( WP_CONTENT_DIR ) . 'plugins/' . $plugin_path;
}

if ( ! file_exists( $mergebot_mu_plugin_class ) ) {
	return;
}

include_once $mergebot_mu_plugin_class;

if ( ! class_exists( 'Mergebot_MU_Plugin' ) ) {
	return;
}

$mu_plugin = new Mergebot_MU_Plugin();
$mu_plugin->init();