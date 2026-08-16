<?php
/**
 * Plugin Name:       Essential Support for Gravity Forms
 * Plugin URI:        https://essential.support/integrations/gravity-forms/
 * Description:        Turn any Gravity Form into an Essential Support ticket form. Submissions open a verify-first support request (the customer confirms by email before anything is created), map your form fields to the ticket, auto-populate the ticket type, and — when your workspace has Images & Files enabled — attach uploaded files after the request is verified.
 * Version:           1.0.2
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Author:            Coywolf
 * Author URI:        https://coywolf.com/jon-henshaw/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       essential-support-gravityforms
 * Update URI:        https://github.com/coywolf-llc/essential-support-gravityforms
 *
 * @package EssentialSupportGravityForms
 *
 * Essential Support for Gravity Forms
 * Copyright (C) 2026 Coywolf LLC
 *
 * This program is free software; you can redistribute it and/or modify it
 * under the terms of the GNU General Public License, version 2, as published
 * by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for
 * more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, see https://www.gnu.org/licenses/gpl-2.0.html.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ESGF_VERSION', '1.0.2' );
define( 'ESGF_FILE', __FILE__ );
define( 'ESGF_PATH', plugin_dir_path( __FILE__ ) );
define( 'ESGF_URL', plugin_dir_url( __FILE__ ) );
define( 'ESGF_SLUG', 'essential-support-gravityforms' );
define( 'ESGF_BASENAME', plugin_basename( __FILE__ ) );

/* wporg-strip:start — GitHub self-updater (removed from the WordPress.org build) */
require_once ESGF_PATH . 'includes/class-github-updater.php';
// Wire in the GitHub self-updater so releases show up on Dashboard → Updates. Stripped
// from the WordPress.org build (which updates through .org); needs a PUBLIC repo to fetch.
( new ESGF_GitHub_Updater( __FILE__, ESGF_VERSION ) )->init();
/* wporg-strip:end */

// The webhook receiver is a REST route that must answer regardless of whether a Gravity
// Forms admin page is loaded, so it's registered independently of the GF feed framework.
require_once ESGF_PATH . 'includes/class-esgf-webhook.php';
add_action( 'rest_api_init', array( 'ESGF_Webhook', 'register_routes' ) );

/**
 * Register the Feed Add-On once Gravity Forms has loaded (it provides the framework the
 * add-on extends). If GF isn't active, the plugin does nothing but surface an admin notice.
 */
add_action( 'gform_loaded', 'esgf_bootstrap', 5 );

/**
 * Bootstrap the add-on.
 *
 * @return void
 */
function esgf_bootstrap() {
	if ( ! method_exists( 'GFForms', 'include_feed_addon_framework' ) ) {
		return;
	}
	require_once ESGF_PATH . 'includes/class-esgf-client.php';
	require_once ESGF_PATH . 'includes/class-esgf-addon.php';
	GFAddOn::register( 'ESGF_AddOn' );
}

/**
 * Read the add-on's saved plugin settings without needing a GFAddOn instance (used by the
 * webhook receiver, which runs on the REST stack). Gravity Forms stores plugin settings
 * under this option key.
 *
 * @return array
 */
function esgf_settings() {
	$opt = get_option( 'gravityformsaddon_' . ESGF_SLUG . '_settings', array() );
	return is_array( $opt ) ? $opt : array();
}

/**
 * Admin notice when Gravity Forms isn't active — the add-on needs it.
 *
 * @return void
 */
add_action( 'admin_notices', 'esgf_maybe_gf_notice' );

/**
 * Render the "install Gravity Forms" admin notice.
 *
 * @return void
 */
function esgf_maybe_gf_notice() {
	if ( method_exists( 'GFForms', 'include_feed_addon_framework' ) ) {
		return;
	}
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	echo '<div class="notice notice-warning"><p>';
	echo esc_html__( 'Essential Support for Gravity Forms needs Gravity Forms (2.5+) active to work.', 'essential-support-gravityforms' );
	echo '</p></div>';
}
