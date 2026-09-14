<?php
/**
 * LocalePress admin notice gate.
 *
 * @package LocalePress
 */

namespace LocalePress\Admin;

use Closure;
use LocalePress\Contracts\ModuleInterface;
use WP_Hook;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps unrelated admin notices off LocalePress screens.
 *
 * Other plugins and themes print promotions, review requests and opt-in
 * banners on every admin page. On LocalePress screens they push the interface
 * down and compete with the plugin's own feedback, so they are removed.
 *
 * Two layers are used because notices arrive two different ways:
 *
 * 1. PHP - callbacks on the four core notice hooks are detached before those
 *    hooks run. Callbacks owned by LocalePress are kept.
 * 2. CSS - a small scoped rule hides notice markup that scripts inject into
 *    the page after PHP has finished, which the first layer cannot reach.
 *
 * LocalePress prints its own notices with the `localepress-notice` class so
 * the CSS layer can tell them apart. Any new LocalePress notice markup must
 * carry that class or it will be hidden along with the foreign ones.
 */
final class AdminNoticeGate implements ModuleInterface {

	/**
	 * Core hooks that print admin notices.
	 *
	 * @var array<int, string>
	 */
	const NOTICE_HOOKS = array(
		'admin_notices',
		'all_admin_notices',
		'user_admin_notices',
		'network_admin_notices',
	);

	/**
	 * Class prefix identifying callbacks owned by the plugin.
	 *
	 * @var string
	 */
	const OWN_NAMESPACE = 'LocalePress\\';

	/**
	 * Function prefix identifying callbacks owned by the plugin.
	 *
	 * @var string
	 */
	const OWN_FUNCTION_PREFIX = 'localepress';

	/**
	 * {@inheritdoc}
	 */
	public function register() {
		// Runs after every plugin has registered its notices and before the
		// notice hooks fire, which is where almost everything is caught.
		add_action( 'in_admin_header', array( $this, 'detach_foreign_notices' ), PHP_INT_MAX );

		// Second pass for callbacks registered after `in_admin_header`, for
		// example from another plugin's own header or screen callbacks.
		foreach ( self::NOTICE_HOOKS as $hook ) {
			add_action( $hook, array( $this, 'detach_foreign_notices' ), PHP_INT_MIN );
		}

		add_action( 'admin_head', array( $this, 'print_styles' ), PHP_INT_MAX );
	}

	/**
	 * Removes foreign callbacks from the core notice hooks.
	 *
	 * @return void
	 */
	public function detach_foreign_notices() {
		if ( ! $this->is_gated_screen() ) {
			return;
		}

		foreach ( self::NOTICE_HOOKS as $hook ) {
			$this->detach_hook( $hook );
		}
	}

	/**
	 * Hides notice markup injected by scripts after PHP has run.
	 *
	 * @return void
	 */
	public function print_styles() {
		if ( ! $this->is_gated_screen() ) {
			return;
		}

		$selectors = array(
			'#wpbody-content .notice:not(.localepress-notice)',
			'#wpbody-content div.updated:not(.localepress-notice)',
			'#wpbody-content div.error:not(.localepress-notice)',
			'#wpbody-content .update-nag',
		);

		printf(
			'<style id="localepress-notice-gate">%s{display:none!important;}</style>',
			esc_html( implode( ',', $selectors ) )
		);
	}

	/**
	 * Determines whether foreign notices should be removed from this request.
	 *
	 * @return bool
	 */
	private function is_gated_screen() {
		$page = $this->current_admin_page();

		if ( '' === $page ) {
			return false;
		}

		/**
		 * Filters whether LocalePress hides unrelated notices on its own screens.
		 *
		 * @param bool   $hide Whether unrelated admin notices should be removed.
		 * @param string $page Current LocalePress admin page slug.
		 */
		return (bool) apply_filters( 'localepress_hide_foreign_admin_notices', true, $page );
	}

	/**
	 * Returns the LocalePress admin page slug for the current request.
	 *
	 * The page query variable is used instead of the screen identifier because
	 * WordPress derives that identifier from the translated menu title.
	 *
	 * @return string Empty when this is not a LocalePress screen.
	 */
	private function current_admin_page() {
		if ( wp_doing_ajax() || is_network_admin() || is_user_admin() ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen check.
		$page = isset( $_GET['page'] ) && is_scalar( $_GET['page'] )
			? sanitize_key( wp_unslash( $_GET['page'] ) )
			: '';

		return 'localepress' === $page || 0 === strpos( $page, 'localepress-' ) ? $page : '';
	}

	/**
	 * Removes every callback on one hook that LocalePress does not own.
	 *
	 * `remove_all_actions()` is not used because it would also drop the
	 * plugin's own notices and this gate's second pass.
	 *
	 * @param string $hook Hook name.
	 * @return void
	 */
	private function detach_hook( $hook ) {
		global $wp_filter;

		if ( ! isset( $wp_filter[ $hook ] ) || ! $wp_filter[ $hook ] instanceof WP_Hook ) {
			return;
		}

		// Copied so the hook can be modified while the copy is walked.
		$registered = $wp_filter[ $hook ]->callbacks;

		foreach ( $registered as $priority => $callbacks ) {
			foreach ( $callbacks as $callback ) {
				if ( ! isset( $callback['function'] ) || $this->is_own_callback( $callback['function'] ) ) {
					continue;
				}

				remove_action( $hook, $callback['function'], $priority );
			}
		}
	}

	/**
	 * Determines whether a callback belongs to LocalePress.
	 *
	 * Closures cannot be attributed to an owner, so they are treated as
	 * foreign. LocalePress registers its notice callbacks as class methods.
	 *
	 * @param mixed $function Registered callback.
	 * @return bool
	 */
	private function is_own_callback( $function ) {
		if ( $function instanceof Closure ) {
			return false;
		}

		if ( is_string( $function ) ) {
			return 0 === strpos( $function, self::OWN_FUNCTION_PREFIX )
				|| 0 === strpos( $function, self::OWN_NAMESPACE );
		}

		if ( is_object( $function ) ) {
			return 0 === strpos( get_class( $function ), self::OWN_NAMESPACE );
		}

		if ( ! is_array( $function ) || ! isset( $function[0] ) ) {
			return false;
		}

		$owner = is_object( $function[0] ) ? get_class( $function[0] ) : $function[0];

		return is_string( $owner ) && 0 === strpos( $owner, self::OWN_NAMESPACE );
	}
}
