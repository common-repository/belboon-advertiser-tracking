<?php
	/**
	* Plugin Name: Belboon Advertiser Tracking
	* Plugin URI: https://belboon.com/
	* Description: Adds the affiliate marketing tracking of the belboon network for advertisers
	* Version: 2.8.0
	* Author: belboon GmbH
	* Author URI: https://belboon.com/en/imprint/
	* Text Domain: belboon-advertiser-tracking
	* Domain Path: /languages
	*/

	if (!defined('ABSPATH')) exit;

	define('BELBOON_TACKING_VERSION', '2.8.0');
	define('BELBOON_TACKING_TEXTDOMAIN', 'belboon-advertiser-tracking');
	require_once 'classes/belboon-advertiser-tracking-plugin.php';

	$path = plugin_dir_path(__FILE__);

	global $wpdb;
	$belboon_advertiser_tracking_plugin = new BelboonAdvertiserTrackingPlugin($wpdb, $path);
	$belboon_advertiser_tracking_plugin->initPlugin();
