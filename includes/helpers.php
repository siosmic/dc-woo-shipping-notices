<?php
/**
 * Shared helper functions.
 *
 * @package DC_Woo_Shipping_Notices
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generate a unique rule ID.
 */
function dcsn_generate_id(): string {
	return 'r_' . wp_generate_password( 8, false, false );
}

/**
 * Sanitize an array (or comma-string) of ISO-2 country codes.
 *
 * @param mixed $input Array or string.
 * @return string[]
 */
function dcsn_sanitize_countries( $input ): array {
	if ( is_string( $input ) ) {
		$input = explode( ',', $input );
	}
	if ( ! is_array( $input ) ) {
		return [];
	}
	return array_values( array_unique( array_filter( array_map( function ( $v ) {
		return strtoupper( trim( sanitize_text_field( (string) $v ) ) );
	}, $input ) ) ) );
}

/**
 * Sanitize US state codes from a comma-separated string or array.
 *
 * @param mixed $input String or array.
 * @return string[]
 */
function dcsn_sanitize_states( $input ): array {
	if ( is_string( $input ) ) {
		$input = explode( ',', $input );
	}
	if ( ! is_array( $input ) ) {
		return [];
	}
	return array_values( array_unique( array_filter( array_map( function ( $v ) {
		return strtoupper( trim( sanitize_text_field( (string) $v ) ) );
	}, $input ) ) ) );
}

/**
 * Mode labels for display.
 *
 * @return array<string, string>
 */
function dcsn_get_mode_labels(): array {
	return [
		'ALLOW_WITH_MESSAGE' => __( 'Allow with message', 'dc-woo-shipping-notices' ),
		'BLOCK_WITH_MESSAGE' => __( 'Block with message', 'dc-woo-shipping-notices' ),
	];
}

/**
 * Notice type labels.
 *
 * @return array<string, string>
 */
function dcsn_get_notice_type_labels(): array {
	return [
		'notice'  => __( 'Info', 'dc-woo-shipping-notices' ),
		'warning' => __( 'Warning', 'dc-woo-shipping-notices' ),
	];
}

/**
 * Default rule schema (used for normalization).
 *
 * @return array<string, mixed>
 */
function dcsn_default_rule(): array {
	return [
		'id'            => '',
		'enabled'       => true,
		'label'         => '',
		'countries'     => [],
		'states'        => [],
		'mode'          => 'ALLOW_WITH_MESSAGE',
		'notice_type'   => 'warning',
		'message'       => '',
		'priority'      => 10,
		'stop_on_match' => false,
	];
}

/* ================================================================== */
/*  WPML helpers                                                       */
/* ================================================================== */

/**
 * Check whether WPML (or compatible) is active with multiple languages.
 */
function dcsn_is_wpml_active(): bool {
	return has_filter( 'wpml_active_languages' );
}

/**
 * Return the current front-end language code (e.g. "fr", "en", "de").
 */
function dcsn_current_language(): string {
	return (string) apply_filters( 'wpml_current_language', '' );
}

/**
 * Return the WPML default language code.
 */
function dcsn_default_language(): string {
	return (string) apply_filters( 'wpml_default_language', '' );
}

/**
 * Return WPML active languages as [ code => [ 'code', 'native_name', ... ] ].
 *
 * @return array<string, array>
 */
function dcsn_get_active_languages(): array {
	$languages = apply_filters( 'wpml_active_languages', [] );
	if ( ! is_array( $languages ) || count( $languages ) < 2 ) {
		return [];
	}
	return $languages;
}

/**
 * Translate a registered WPML string.
 *
 * Uses WPML's String Translation API. Falls back to the original value
 * when WPML is not active (no .mo files needed).
 *
 * @param string $name     The string name as registered with wpml_register_single_string.
 * @param string $original The original (default language) value.
 * @return string Translated string, or original if WPML is not active.
 */
function dcsn_translate_string( string $name, string $original ): string {
	return (string) apply_filters(
		'wpml_translate_single_string',
		$original,
		'dc-woo-shipping-notices',
		$name
	);
}

/**
 * Resolve a message (string or lang-keyed array) to the requested language.
 *
 * @param string|array $message Single string or [ 'fr' => '…', 'en' => '…' ].
 * @param string       $lang    Optional explicit language code. Falls back to
 *                               WPML's current language when empty.
 * @return string
 */
function dcsn_resolve_message( $message, string $lang = '' ): string {
	if ( is_string( $message ) ) {
		return $message;
	}

	if ( ! is_array( $message ) ) {
		return '';
	}

	// Use the explicit language, or ask WPML.
	if ( ! $lang ) {
		$lang = dcsn_current_language();
	}

	// Pick requested language.
	if ( $lang && isset( $message[ $lang ] ) && trim( $message[ $lang ] ) !== '' ) {
		return $message[ $lang ];
	}

	// Fallback to default language.
	$default = dcsn_default_language();
	if ( $default && isset( $message[ $default ] ) && trim( $message[ $default ] ) !== '' ) {
		return $message[ $default ];
	}

	// Fallback to first non-empty value.
	foreach ( $message as $val ) {
		if ( is_string( $val ) && trim( $val ) !== '' ) {
			return $val;
		}
	}

	return '';
}

/**
 * Sanitize the message field — handles both string and multilingual array.
 *
 * @param string|array $input
 * @return string|array
 */
function dcsn_sanitize_message( $input ) {
	if ( is_array( $input ) ) {
		$result = [];
		foreach ( $input as $lang => $msg ) {
			$result[ sanitize_text_field( (string) $lang ) ] = wp_kses_post( (string) $msg );
		}
		return $result;
	}
	return wp_kses_post( (string) $input );
}

/**
 * Check whether a message (string or array) has at least one non-empty value.
 */
function dcsn_message_is_empty( $message ): bool {
	if ( is_string( $message ) ) {
		return trim( $message ) === '';
	}
	if ( is_array( $message ) ) {
		foreach ( $message as $val ) {
			if ( is_string( $val ) && trim( $val ) !== '' ) {
				return false;
			}
		}
	}
	return true;
}
