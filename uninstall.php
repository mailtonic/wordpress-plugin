<?php
/**
 * Uninstall: removes the plugin's options, tables, order and user meta and cron events on every site.
 * Nothing in mailtonic is deleted.
 *
 * @package Mailtonic
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/class-settings.php';
require_once __DIR__ . '/includes/class-outbox.php';
require_once __DIR__ . '/includes/class-carts.php';
require_once __DIR__ . '/includes/class-plugin.php';

if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $mailtonic_site ) {
		switch_to_blog( $mailtonic_site );
		Mailtonic\Plugin::uninstall_site();
		restore_current_blog();
	}
} else {
	Mailtonic\Plugin::uninstall_site();
}
