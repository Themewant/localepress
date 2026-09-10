<?php
/**
 * LocalePress uninstall cleanup.
 *
 * @package LocalePress
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$localepress_delete_site_data = static function () {
	global $wpdb;

	$settings = get_option( 'localepress_settings', array() );
	$delete   = is_array( $settings )
		&& isset( $settings['advanced'] )
		&& is_array( $settings['advanced'] )
		&& isset( $settings['advanced']['delete_data_on_uninstall'] )
		&& true === $settings['advanced']['delete_data_on_uninstall'];

	if ( ! $delete ) {
		return;
	}

	$tables = array(
		$wpdb->prefix . 'localepress_translation_groups',
		$wpdb->prefix . 'localepress_post_translations',
		$wpdb->prefix . 'localepress_term_translation_groups',
		$wpdb->prefix . 'localepress_term_translations',
		$wpdb->prefix . 'localepress_strings',
		$wpdb->prefix . 'localepress_string_translations',
	);

	foreach ( $tables as $table ) {
		// Names are generated only from WordPress's trusted site prefix and fixed suffixes.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
	}

	delete_metadata( 'term', 0, '_localepress_language_id', '', true );
	delete_metadata( 'post', 0, '_localepress_switcher_settings', '', true );
	delete_metadata( 'user', 0, 'localepress_translations_per_page', '', true );
	delete_metadata( 'user', 0, 'localepress_strings_per_page', '', true );
	delete_metadata( 'user', 0, 'localepress_admin_language', '', true );

	// This uninstall-only query finds theme mods across active and inactive themes.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$theme_mod_options = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
			$wpdb->esc_like( 'theme_mods_' ) . '%'
		)
	);

	foreach ( $theme_mod_options as $option_name ) {
		$theme_mods = get_option( $option_name, array() );

		if ( is_array( $theme_mods ) && array_key_exists( 'localepress_nav_menu_locations', $theme_mods ) ) {
			unset( $theme_mods['localepress_nav_menu_locations'] );
			update_option( $option_name, $theme_mods );
		}
	}

	foreach (
		array(
			'localepress_language_registry',
			'localepress_workflow_settings',
			'localepress_version',
			'localepress_rewrite_signature',
			'localepress_setup_redirect',
			'localepress_settings',
			'localepress_seeded_default_term_languages',
		) as $option_name
	) {
		delete_option( $option_name );
		delete_transient( $option_name );
	}
};

if ( is_multisite() ) {
	$localepress_site_offset = 0;

	do {
		$localepress_site_ids = get_sites(
			array(
				'fields'  => 'ids',
				'number'  => 100,
				'offset'  => $localepress_site_offset,
				'orderby' => 'id',
				'order'   => 'ASC',
			)
		);

		foreach ( $localepress_site_ids as $localepress_site_id ) {
			switch_to_blog( $localepress_site_id );

			try {
				$localepress_delete_site_data();
			} finally {
				restore_current_blog();
			}
		}

		$localepress_site_count   = count( $localepress_site_ids );
		$localepress_site_offset += 100;
	} while ( 100 === $localepress_site_count );
} else {
	$localepress_delete_site_data();
}
