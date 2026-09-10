<?php
/**
 * Registered string validation.
 *
 * @package LocalePress
 */

namespace LocalePress\StringTranslation;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Validates the plain-text registered-string contract.
 */
final class RegisteredStringValidator {

	/**
	 * Maximum group length in bytes.
	 *
	 * @var int
	 */
	const MAX_GROUP_LENGTH = 100;

	/**
	 * Maximum key length in bytes.
	 *
	 * @var int
	 */
	const MAX_KEY_LENGTH = 191;

	/**
	 * Maximum original or translated value length in bytes.
	 *
	 * @var int
	 */
	const MAX_STRING_LENGTH = 10000;

	/**
	 * Validates one developer registration.
	 *
	 * @param mixed $group           Developer-defined group.
	 * @param mixed $key             Stable key within the group.
	 * @param mixed $original_string Original plain-text value.
	 * @return array<string, string>|WP_Error
	 */
	public function validate_registration( $group, $key, $original_string ) {
		$identity = $this->validate_identity( $group, $key );

		if ( is_wp_error( $identity ) ) {
			return $identity;
		}

		if ( ! is_scalar( $original_string ) ) {
			return new WP_Error( 'invalid_registered_string', __( 'The original string must be plain text.', 'localepress' ) );
		}

		$original_string = $this->sanitize_value( (string) $original_string );

		if ( '' === $original_string ) {
			return new WP_Error( 'empty_registered_string', __( 'The original string cannot be empty.', 'localepress' ) );
		}

		if ( self::MAX_STRING_LENGTH < strlen( $original_string ) ) {
			return new WP_Error( 'registered_string_too_long', __( 'The original string is too long.', 'localepress' ) );
		}

		$identity['original_string'] = $original_string;

		return $identity;
	}

	/**
	 * Validates a group and key used for lookup.
	 *
	 * @param mixed $group Developer-defined group.
	 * @param mixed $key   Stable key within the group.
	 * @return array<string, string>|WP_Error
	 */
	public function validate_identity( $group, $key ) {
		if ( ! is_scalar( $group ) || ! is_scalar( $key ) ) {
			return new WP_Error( 'invalid_string_identity', __( 'String groups and keys must be plain text.', 'localepress' ) );
		}

		$group = sanitize_text_field( (string) $group );
		$key   = sanitize_text_field( (string) $key );

		if ( '' === $group ) {
			return new WP_Error( 'empty_string_group', __( 'A string group is required.', 'localepress' ) );
		}

		if ( '' === $key ) {
			return new WP_Error( 'empty_string_key', __( 'A string key is required.', 'localepress' ) );
		}

		if ( self::MAX_GROUP_LENGTH < strlen( $group ) ) {
			return new WP_Error( 'string_group_too_long', __( 'The string group is too long.', 'localepress' ) );
		}

		if ( self::MAX_KEY_LENGTH < strlen( $key ) ) {
			return new WP_Error( 'string_key_too_long', __( 'The string key is too long.', 'localepress' ) );
		}

		return array(
			'string_group' => $group,
			'string_key'   => $key,
		);
	}

	/**
	 * Sanitizes an editable translation. Empty values remain empty for deletion.
	 *
	 * @param mixed $translation Submitted translation.
	 * @return string|WP_Error
	 */
	public function validate_translation( $translation ) {
		if ( ! is_scalar( $translation ) ) {
			return new WP_Error( 'invalid_string_translation', __( 'A translation must be plain text.', 'localepress' ) );
		}

		$translation = $this->sanitize_value( (string) $translation );

		if ( self::MAX_STRING_LENGTH < strlen( $translation ) ) {
			return new WP_Error( 'string_translation_too_long', __( 'The translation is too long.', 'localepress' ) );
		}

		return $translation;
	}

	/**
	 * Sanitizes a plain-text multiline value.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private function sanitize_value( $value ) {
		return sanitize_textarea_field( wp_check_invalid_utf8( $value ) );
	}
}
