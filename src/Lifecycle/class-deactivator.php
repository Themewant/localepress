<?php
/**
 * Plugin deactivation.
 *
 * @package LocalePress
 */

namespace LocalePress\Lifecycle;

use LocalePress\Routing\RoutingModule;

defined( 'ABSPATH' ) || exit;

/**
 * Performs non-destructive deactivation tasks.
 */
final class Deactivator {

	/**
	 * Deactivates LocalePress without deleting language data.
	 *
	 * @param bool $network_wide Whether the plugin is being network deactivated.
	 * @return void
	 */
	public static function deactivate( $network_wide = false ) {
		$deactivate_site = static function () {
			delete_transient( Activator::SETUP_REDIRECT_TRANSIENT );
			delete_option( RoutingModule::REWRITE_SIGNATURE_OPTION );
			delete_option( 'rewrite_rules' );
		};

		if ( $network_wide && is_multisite() ) {
			Installer::for_each_site( $deactivate_site );
		} else {
			$deactivate_site();
		}

		do_action( 'localepress_deactivated' );
	}
}
