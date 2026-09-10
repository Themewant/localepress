<?php
/**
 * Module contract.
 *
 * @package LocalePress
 */

namespace LocalePress\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Contract implemented by bootable LocalePress modules.
 */
interface ModuleInterface {

	/**
	 * Registers the module's WordPress hooks.
	 *
	 * @return void
	 */
	public function register();
}
