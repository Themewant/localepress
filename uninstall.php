<?php
/**
 * LocalePress uninstall cleanup.
 *
 * @package LocalePress
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$localepress_delete_site_data = static function () {
	global $wpdb;

	/*
	 * The same question PluginSettings::should_delete_data_on_uninstall() asks,
	 * asked the same way. Settings are normalised to real booleans on save, but
	 * an imported or hand-edited record can hold `1` instead, and a stricter
	 * test here than the plugin applies everywhere else would quietly keep the
	 * data of someone who ticked the box.
	 */
	$settings = get_option( 'localepress_settings', array() );
	$advanced = is_array( $settings ) && isset( $settings['advanced'] ) && is_array( $settings['advanced'] )
		? $settings['advanced']
		: array();

	if ( empty( $advanced['delete_data_on_uninstall'] ) ) {
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

	/*
	 * A widget keeps its instances in `widget_` plus its own base, and the
	 * sidebar it sits in keeps the instance identifiers separately. Removing
	 * one without the other leaves either settings nothing can reach or slots
	 * naming a widget that no longer exists.
	 */
	delete_option( 'widget_localepress_language_switcher' );

	$sidebars_widgets = get_option( 'sidebars_widgets', array() );

	if ( is_array( $sidebars_widgets ) ) {
		$sidebars_changed = false;

		foreach ( $sidebars_widgets as $sidebar_id => $widget_ids ) {
			if ( ! is_array( $widget_ids ) ) {
				continue;
			}

			$remaining = array_values(
				array_filter(
					$widget_ids,
					static function ( $widget_id ) {
						return ! is_string( $widget_id )
							|| ! str_starts_with( $widget_id, 'localepress_language_switcher-' );
					}
				)
			);

			if ( count( $remaining ) !== count( $widget_ids ) ) {
				$sidebars_widgets[ $sidebar_id ] = $remaining;
				$sidebars_changed                = true;
			}
		}

		if ( $sidebars_changed ) {
			update_option( 'sidebars_widgets', $sidebars_widgets );
		}
	}

	/*
	 * Admin notices are held in transients named after the user who triggered
	 * them, so there is no finite list to walk. They expire within the minute,
	 * but an uninstall should not leave rows behind for a scheduled sweep to
	 * find later.
	 */
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_localepress_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_localepress_' ) . '%'
		)
	);

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
