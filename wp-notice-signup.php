<?php
/**
 * Plugin Name: WP Notice Signup
 * Plugin URI: [FILL-IN: repo URL]
 * Description: Small demo plugin for the WCAG in CI/CD talk: an announcement bar, a modal and a signup form. The baseline is clean; deliberate failures are switched on one at a time for a demo. The marketing site it runs on lives in the separate corveto-site plugin.
 * Version: 0.2.0
 * Author: William Patton
 * License: GPL-2.0-or-later
 * Text Domain: wp-notice-signup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WP_NOTICE_SIGNUP_FILE', __FILE__ );
define( 'WP_NOTICE_SIGNUP_URL', plugin_dir_url( __FILE__ ) );

require_once __DIR__ . '/includes/class-wp-notice-signup-plugin.php';

register_activation_hook( __FILE__, array( 'WP_Notice_Signup_Plugin', 'activate' ) );

WP_Notice_Signup_Plugin::get_instance();
