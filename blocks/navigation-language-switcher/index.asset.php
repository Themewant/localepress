<?php
// phpcs:ignoreFile -- WordPress requires the generated block asset filename.
/**
 * Navigation language switcher block editor asset metadata.
 *
 * @package LocalePress
 */

return array(
	'dependencies' => array(
		'wp-blocks',
		'wp-block-editor',
		'wp-components',
		'wp-element',
		'wp-i18n',
	),
	/** This versioning is explained in blocks/language-switcher/index.asset.php */
	'version'      => class_exists( 'LocalePress\Assets' )
		? LocalePress\Assets::version( 'blocks/navigation-language-switcher/index.js' )
		: '1.0.0',
);
