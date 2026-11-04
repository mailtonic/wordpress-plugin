<?php
/**
 * Bootstrap, activation, deactivation, upgrades and uninstall.
 *
 * @package Mailtonic
 */

namespace Mailtonic;

defined( 'ABSPATH' ) || exit;

/**
 * The plugin.
 */
final class Plugin {
	const VERSION    = '1.0.0';
	const DB_VERSION = '1';
	const CRON       = array(
		'mailtonic_outbox'     => 'mailtonic_5min',
		'mailtonic_cart_sweep' => 'mailtonic_15min',
		'mailtonic_prune'      => 'daily',
	);

	/**
	 * Registers the plugin's hooks.
	 */
	public static function boot() {
		add_filter( 'cron_schedules', array( self::class, 'schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- 5 and 15 minutes are the spec's intervals.
		add_action( 'plugins_loaded', array( self::class, 'init' ) );
		add_action( 'before_woocommerce_init', array( Woo::class, 'declare_compatibility' ) );
		add_action( 'wp_initialize_site', array( self::class, 'new_site' ), 200 );
		add_action( 'mailtonic_outbox', array( self::class, 'cron_outbox' ) );
		add_action( 'mailtonic_cart_sweep', array( self::class, 'cron_sweep' ) );
		add_action( 'mailtonic_prune', array( self::class, 'cron_prune' ) );
	}

	/**
	 * Once every plugin is loaded: upgrades and the parts' hooks.
	 */
	public static function init() {
		if ( get_option( 'mailtonic_db_version' ) !== self::DB_VERSION ) {
			self::install_site();
		}
		Forms::init();
		Bridges::init();
		Woo::init();
		Carts::init();
		Privacy::init();
		Admin::init();
	}

	/**
	 * Cron intervals.
	 *
	 * @param array $schedules Schedules.
	 * @return array
	 */
	public static function schedules( $schedules ) {
		$schedules['mailtonic_5min']  = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 5 minutes', 'mailtonic' ),
		);
		$schedules['mailtonic_15min'] = array(
			'interval' => 15 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 15 minutes', 'mailtonic' ),
		);
		return $schedules;
	}

	/** Outbox cron callback. */
	public static function cron_outbox() {
		Outbox::run();
	}

	/** Cart sweep cron callback. */
	public static function cron_sweep() {
		Carts::sweep();
	}

	/** Daily prune cron callback. */
	public static function cron_prune() {
		Outbox::prune();
		Carts::prune();
	}

	/**
	 * Activation: tables, site key and cron events on this site, or on every site when network-activated.
	 * Never calls mailtonic.
	 *
	 * @param bool $network_wide Network activation.
	 */
	public static function activate( $network_wide = false ) {
		self::each_site( $network_wide, array( self::class, 'install_site' ) );
	}

	/**
	 * Deactivation: removes the cron events and keeps settings and data.
	 *
	 * @param bool $network_wide Network deactivation.
	 */
	public static function deactivate( $network_wide = false ) {
		self::each_site(
			$network_wide,
			static function () {
				foreach ( array_keys( self::CRON ) as $hook ) {
					wp_clear_scheduled_hook( $hook );
				}
			}
		);
	}

	/**
	 * Runs a callback on this site, or on every site of the network.
	 *
	 * @param bool     $network_wide All sites.
	 * @param callable $callback     Callback.
	 */
	private static function each_site( $network_wide, $callback ) {
		if ( ! is_multisite() || ! $network_wide ) {
			call_user_func( $callback );
			return;
		}
		foreach ( get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		) as $site ) {
			switch_to_blog( $site );
			call_user_func( $callback );
			restore_current_blog();
		}
	}

	/**
	 * A site added to a network where the plugin is network-active.
	 *
	 * @param \WP_Site $site New site.
	 */
	public static function new_site( $site ) {
		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( is_plugin_active_for_network( plugin_basename( MAILTONIC_FILE ) ) ) {
			switch_to_blog( $site->blog_id );
			self::install_site();
			restore_current_blog();
		}
	}

	/**
	 * Creates or upgrades this site's tables, its site key and its cron events.
	 */
	public static function install_site() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$collate = $wpdb->get_charset_collate();
		dbDelta( Outbox::schema( $collate ) );
		dbDelta( Carts::schema( $collate ) );
		Settings::site_key();
		foreach ( self::CRON as $hook => $recurrence ) {
			if ( ! wp_next_scheduled( $hook ) ) {
				wp_schedule_event( time() + MINUTE_IN_SECONDS, $recurrence, $hook );
			}
		}
		if ( false === get_option( 'mailtonic_db_version' ) ) {
			update_option( 'mailtonic_connect_notice', 1, false );
		}
		update_option( 'mailtonic_db_version', self::DB_VERSION, false );
	}

	/**
	 * Removes this site's options (key included), tables, order and user meta and cron events.
	 */
	public static function uninstall_site() {
		global $wpdb;
		foreach ( array( Settings::OPTION, 'mailtonic_bridges', Settings::IMPORT, 'mailtonic_db_version', 'mailtonic_connect_notice' ) as $option ) {
			delete_option( $option );
		}
		delete_transient( 'mailtonic_forms' );
		delete_transient( 'mailtonic_outbox_lock' );
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_mailtonic\\_%' OR option_name LIKE '\\_transient\\_timeout\\_mailtonic\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		wp_cache_delete( 'alloptions', 'options' );

		foreach ( array( Outbox::table(), Carts::table() ) as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
		}

		$keys = array( '_mailtonic_optin', '_mailtonic_placed', '_mailtonic_signup', '_mailtonic_cart_token', '_wc_other/mailtonic/optin' );
		foreach ( $keys as $key ) {
			delete_metadata( 'post', 0, $key, '', true );
			delete_metadata( 'user', 0, $key, '', true );
		}
		$orders_meta = $wpdb->prefix . 'wc_orders_meta';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $orders_meta ) ) === $orders_meta ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$orders_meta} WHERE meta_key IN (" . implode( ', ', array_fill( 0, count( $keys ), '%s' ) ) . ')', $keys ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		}
		foreach ( array_keys( self::CRON ) as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
	}
}
