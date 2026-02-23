<?php
/**
 * Rules CRUD — load, save, validate, sort.
 *
 * All data stored in a single wp_option (DCSN_OPTION_KEY).
 *
 * @package DC_Shipping_Notices
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DCSN_Rules {

	/* ------------------------------------------------------------------ */
	/*  Read                                                               */
	/* ------------------------------------------------------------------ */

	/**
	 * Full option payload (settings + rules).
	 *
	 * @return array{settings: array, rules: array}
	 */
	public static function get_all(): array {
		$data = get_option( DCSN_OPTION_KEY, [] );

		if ( ! is_array( $data ) ) {
			$data = [];
		}

		return wp_parse_args( $data, [
			'settings' => [ 'fallback_to_billing' => true ],
			'rules'    => [],
		] );
	}

	/**
	 * Return settings array.
	 *
	 * Reads from WooCommerce-managed options (stored individually).
	 */
	public static function get_settings(): array {
		return [
			'fallback_to_billing' => get_option( 'dcsn_fallback_to_billing', 'yes' ) === 'yes',
		];
	}

	/**
	 * Return all rules sorted by priority ASC.
	 *
	 * @return array[]
	 */
	public static function get_rules(): array {
		$rules = self::get_all()['rules'];
		self::sort_rules( $rules );
		return $rules;
	}

	/**
	 * Return enabled rules sorted by priority ASC.
	 *
	 * @return array[]
	 */
	public static function get_enabled_rules(): array {
		return array_values( array_filter( self::get_rules(), fn( $r ) => ! empty( $r['enabled'] ) ) );
	}

	/**
	 * Find a single rule by ID.
	 */
	public static function get_rule( string $id ): ?array {
		foreach ( self::get_rules() as $rule ) {
			if ( ( $rule['id'] ?? '' ) === $id ) {
				return $rule;
			}
		}
		return null;
	}

	/* ------------------------------------------------------------------ */
	/*  Write                                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Save (upsert) a rule.
	 *
	 * @param array $data Rule data (must include 'id' for update, empty for new).
	 * @return array{ok: bool, errors: string[], rule: array}
	 */
	public static function save_rule( array $data ): array {
		$rule   = self::normalize_rule( $data );
		$errors = self::validate_rule( $rule );

		if ( ! empty( $errors ) ) {
			return [ 'ok' => false, 'errors' => $errors, 'rule' => $rule ];
		}

		$all = self::get_all();

		// New rule.
		if ( empty( $rule['id'] ) ) {
			$rule['id'] = dcsn_generate_id();
			$all['rules'][] = $rule;
		} else {
			// Update existing.
			$found = false;
			foreach ( $all['rules'] as $i => $existing ) {
				if ( ( $existing['id'] ?? '' ) === $rule['id'] ) {
					$all['rules'][ $i ] = $rule;
					$found = true;
					break;
				}
			}
			if ( ! $found ) {
				$all['rules'][] = $rule;
			}
		}

		self::sort_rules( $all['rules'] );
		update_option( DCSN_OPTION_KEY, $all, false );

		return [ 'ok' => true, 'errors' => [], 'rule' => $rule ];
	}

	/**
	 * Delete a rule by ID.
	 */
	public static function delete_rule( string $id ): bool {
		$all   = self::get_all();
		$count = count( $all['rules'] );

		$all['rules'] = array_values( array_filter(
			$all['rules'],
			fn( $r ) => ( $r['id'] ?? '' ) !== $id
		) );

		if ( count( $all['rules'] ) === $count ) {
			return false; // not found
		}

		update_option( DCSN_OPTION_KEY, $all, false );
		return true;
	}

	/**
	 * Duplicate a rule.
	 */
	public static function duplicate_rule( string $id ): ?array {
		$rule = self::get_rule( $id );
		if ( ! $rule ) {
			return null;
		}

		$rule['id']       = dcsn_generate_id();
		$rule['label']    = $rule['label'] . ' (copy)';
		$rule['priority'] = (int) $rule['priority'] + 10;

		$all = self::get_all();
		$all['rules'][] = $rule;
		self::sort_rules( $all['rules'] );
		update_option( DCSN_OPTION_KEY, $all, false );

		return $rule;
	}

	/* ------------------------------------------------------------------ */
	/*  Validation                                                         */
	/* ------------------------------------------------------------------ */

	/**
	 * Validate a rule. Returns array of error messages (empty = valid).
	 *
	 * @return string[]
	 */
	public static function validate_rule( array $rule ): array {
		$errors = [];

		if ( empty( $rule['countries'] ) ) {
			$errors[] = __( 'At least one country is required.', 'dc-shipping-notices' );
		}

		if ( dcsn_message_is_empty( $rule['message'] ?? '' ) ) {
			$errors[] = __( 'Message is required (at least one language).', 'dc-shipping-notices' );
		}

		if ( ! in_array( $rule['mode'] ?? '', [ 'ALLOW_WITH_MESSAGE', 'BLOCK_WITH_MESSAGE' ], true ) ) {
			$errors[] = __( 'Invalid mode.', 'dc-shipping-notices' );
		}

		if ( ! empty( $rule['states'] ) && ! in_array( 'US', $rule['countries'] ?? [], true ) ) {
			$errors[] = __( 'US states can only be set if countries include US.', 'dc-shipping-notices' );
		}

		return $errors;
	}

	/* ------------------------------------------------------------------ */
	/*  Helpers                                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * Normalize raw input to a clean rule array.
	 */
	public static function normalize_rule( array $data ): array {
		$defaults = dcsn_default_rule();

		return [
			'id'            => sanitize_text_field( $data['id'] ?? $defaults['id'] ),
			'enabled'       => ! empty( $data['enabled'] ),
			'label'         => sanitize_text_field( $data['label'] ?? $defaults['label'] ),
			'countries'     => dcsn_sanitize_countries( $data['countries'] ?? [] ),
			'states'        => dcsn_sanitize_states( $data['states'] ?? [] ),
			'mode'          => sanitize_text_field( $data['mode'] ?? $defaults['mode'] ),
			'notice_type'   => in_array( $data['notice_type'] ?? '', [ 'notice', 'warning' ], true )
				? $data['notice_type']
				: 'warning',
			'message'       => dcsn_sanitize_message( $data['message'] ?? $defaults['message'] ),
			'priority'      => (int) ( $data['priority'] ?? $defaults['priority'] ),
			'stop_on_match' => ! empty( $data['stop_on_match'] ),
		];
	}

	/**
	 * Sort rules array by priority ASC (in place).
	 */
	public static function sort_rules( array &$rules ): void {
		usort( $rules, fn( $a, $b ) => ( $a['priority'] ?? 0 ) <=> ( $b['priority'] ?? 0 ) );
	}

	/**
	 * Get the next suggested priority (max + 10).
	 */
	public static function next_priority(): int {
		$rules = self::get_rules();
		if ( empty( $rules ) ) {
			return 10;
		}
		$max = max( array_map( fn( $r ) => (int) ( $r['priority'] ?? 0 ), $rules ) );
		return $max + 10;
	}
}
