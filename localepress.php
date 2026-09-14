<?php
/**
 * Plugin Name:       LocalePress
 * Description:       A lightweight multilingual foundation for WordPress.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            LocalePress
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       localepress
 * Domain Path:       /languages
 *
 * @package LocalePress
 */

defined( 'ABSPATH' ) || exit;

defined( 'LOCALEPRESS_VERSION' ) || define( 'LOCALEPRESS_VERSION', '1.0.0' );
defined( 'LOCALEPRESS_MINIMUM_PHP_VERSION' ) || define( 'LOCALEPRESS_MINIMUM_PHP_VERSION', '7.4' );
defined( 'LOCALEPRESS_MINIMUM_WP_VERSION' ) || define( 'LOCALEPRESS_MINIMUM_WP_VERSION', '6.4' );
defined( 'LOCALEPRESS_FILE' ) || define( 'LOCALEPRESS_FILE', __FILE__ );
defined( 'LOCALEPRESS_PATH' ) || define( 'LOCALEPRESS_PATH', plugin_dir_path( __FILE__ ) );
defined( 'LOCALEPRESS_URL' ) || define( 'LOCALEPRESS_URL', plugin_dir_url( __FILE__ ) );
defined( 'LOCALEPRESS_BASENAME' ) || define( 'LOCALEPRESS_BASENAME', plugin_basename( __FILE__ ) );


defined( 'LOCALEPRESS_LOCAL_DIR' ) || define( 'LOCALEPRESS_LOCAL_DIR', WP_CONTENT_DIR . '/localepress' );

if ( version_compare( PHP_VERSION, LOCALEPRESS_MINIMUM_PHP_VERSION, '<' ) ) {
	add_action(
		'admin_notices',
		static function () {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: required PHP version, 2: current PHP version. */
						__( 'LocalePress requires PHP %1$s or newer. This site is running PHP %2$s.', 'localepress' ),
						LOCALEPRESS_MINIMUM_PHP_VERSION,
						PHP_VERSION
					)
				)
			);
		}
	);

	return;
}

require_once LOCALEPRESS_PATH . 'src/class-autoloader.php';

( new LocalePress\Autoloader( LOCALEPRESS_PATH . 'src/' ) )->register();
require_once LOCALEPRESS_PATH . 'includes/functions.php';
require_once LOCALEPRESS_PATH . 'includes/api.php';

register_activation_hook( LOCALEPRESS_FILE, array( LocalePress\Lifecycle\Activator::class, 'activate' ) );
register_deactivation_hook( LOCALEPRESS_FILE, array( LocalePress\Lifecycle\Deactivator::class, 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function () {
		global $wp_version;

		if ( version_compare( $wp_version, LOCALEPRESS_MINIMUM_WP_VERSION, '<' ) ) {
			add_action(
				'admin_notices',
				static function () use ( $wp_version ) {
					printf(
						'<div class="notice notice-error"><p>%s</p></div>',
						esc_html(
							sprintf(
								/* translators: 1: required WordPress version, 2: current WordPress version. */
								__( 'LocalePress requires WordPress %1$s or newer. This site is running WordPress %2$s.', 'localepress' ),
								LOCALEPRESS_MINIMUM_WP_VERSION,
								$wp_version
							)
						)
					);
				}
			);

			return;
		}

		LocalePress\Plugin::instance()->boot();
	}
);
