<?php
/**
 * Detects the optional plugins the integrations depend on.
 *
 * @package Mailtonic
 */

namespace Mailtonic;

defined( 'ABSPATH' ) || exit;

/**
 * Which optional plugins are active.
 */
final class Integrations {

	/**
	 * Whether an optional plugin is active: woocommerce, cf7, wpforms, elementor or elementor_pro.
	 *
	 * @param string $slug Integration slug.
	 * @return bool
	 */
	public static function active( $slug ) {
		switch ( $slug ) {
			case 'woocommerce':
				$active = class_exists( 'WooCommerce' );
				break;
			case 'cf7':
				$active = class_exists( 'WPCF7_ContactForm' );
				break;
			case 'wpforms':
				$active = function_exists( 'wpforms' );
				break;
			case 'elementor':
				$active = (bool) did_action( 'elementor/loaded' );
				break;
			case 'elementor_pro':
				$active = (bool) did_action( 'elementor_pro/init' );
				break;
			default:
				$active = false;
		}
		/**
		 * Filters whether an integration's plugin counts as active.
		 *
		 * @param bool   $active Detected state.
		 * @param string $slug   woocommerce, cf7, wpforms, elementor or elementor_pro.
		 */
		return (bool) apply_filters( 'mailtonic_integration_active', $active, $slug );
	}
}
