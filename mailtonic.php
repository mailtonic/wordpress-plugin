<?php
/**
 * Plugin Name:       mailtonic
 * Plugin URI:        https://mailtonic.in
 * Description:       Embed mailtonic forms and popups, send Contact Form 7, WPForms and Elementor Pro sign-ups to your lists, and sync opted-in WooCommerce customers, orders and abandoned carts.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Author:            mailtonic
 * Author URI:        https://mailtonic.in
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       mailtonic
 * WC requires at least: 8.6
 * WC tested up to:   11.2
 *
 * @package Mailtonic
 */

/*
 * Copyright (C) 2026 mailtonic.
 *
 * This program is free software; you can redistribute it and/or modify it under the terms of the
 * GNU General Public License as published by the Free Software Foundation; either version 2 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without
 * even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU
 * General Public License for more details.
 */

defined( 'ABSPATH' ) || exit;

define( 'MAILTONIC_FILE', __FILE__ );

foreach ( array( 'settings', 'api', 'connection', 'lists', 'outbox', 'integrations', 'forms', 'bridges', 'bridge-cf7', 'bridge-wpforms', 'bridge-elementor', 'woo', 'carts', 'privacy', 'admin', 'plugin' ) as $mailtonic_file ) {
	require_once __DIR__ . '/includes/class-' . $mailtonic_file . '.php';
}
unset( $mailtonic_file );

register_activation_hook( __FILE__, array( Mailtonic\Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Mailtonic\Plugin::class, 'deactivate' ) );
Mailtonic\Plugin::boot();
