<?php
/**
 * Checkout rule evaluator — display notices, block orders, show modal.
 *
 * Supports both classic shortcode checkout and WooCommerce Block checkout.
 *
 * @package DC_Woo_Shipping_Notices
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DCSN_Checkout {

	/**
	 * Per-request dedupe: rule IDs already shown.
	 *
	 * @var string[]
	 */
	private static array $shown = [];

	public function __construct() {
		/* ---- Classic checkout hooks ---- */
		add_action( 'woocommerce_before_checkout_form', [ $this, 'display_notices' ], 5 );
		add_action( 'woocommerce_checkout_update_order_review', [ $this, 'on_order_review_update' ] );
		add_action( 'woocommerce_after_checkout_validation', [ $this, 'validate_checkout' ], 10, 2 );

		/* ---- Block checkout: Store API validation ---- */
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', [ $this, 'validate_store_api_checkout' ], 10, 2 );

		/* ---- Modal: AJAX endpoint ---- */
		add_action( 'wp_ajax_dcsn_check_destination', [ $this, 'ajax_check_destination' ] );
		add_action( 'wp_ajax_nopriv_dcsn_check_destination', [ $this, 'ajax_check_destination' ] );

		/* ---- Modal: enqueue frontend assets ---- */
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_checkout_assets' ] );
	}

	/* ================================================================== */
	/*  AJAX endpoint for the modal                                        */
	/* ================================================================== */

	/**
	 * AJAX handler: evaluate rules for a given country/state.
	 *
	 * Returns JSON consumed by assets/checkout.js to build the modal.
	 */
	public function ajax_check_destination(): void {
		check_ajax_referer( 'dcsn_checkout', 'nonce' );

		// Explicit language from the front-end (reliable — admin-ajax.php
		// doesn't always inherit the WPML front-end language).
		$lang = sanitize_text_field( wp_unslash( $_POST['lang'] ?? '' ) );

		// Also try to switch WPML context (belt-and-suspenders).
		if ( $lang ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML API hook.
			do_action( 'wpml_switch_language', $lang );
		}

		$country = sanitize_text_field( wp_unslash( $_POST['country'] ?? '' ) );
		$state   = sanitize_text_field( wp_unslash( $_POST['state'] ?? '' ) );

		if ( empty( $country ) ) {
			wp_send_json_success( [ 'rules' => [], 'has_block' => false ] );
		}

		$matched   = self::evaluate_rules( $country, $state );
		$has_block = false;
		$rules_out = [];

		foreach ( $matched as $rule ) {
			if ( $rule['mode'] === 'BLOCK_WITH_MESSAGE' ) {
				$has_block = true;
			}
			$rules_out[] = [
				'mode'        => $rule['mode'],
				'message'     => wp_kses_post( dcsn_resolve_message( $rule['message'] ?? '', $lang ) ),
				'notice_type' => $rule['notice_type'] ?? 'warning',
			];
		}

		wp_send_json_success( [
			'rules'     => $rules_out,
			'has_block' => $has_block,
		] );
	}

	/* ================================================================== */
	/*  Frontend asset enqueuing                                           */
	/* ================================================================== */

	/**
	 * Enqueue modal JS + CSS on the checkout page.
	 */
	public function enqueue_checkout_assets(): void {
		if ( ! is_checkout() ) {
			return;
		}

		// Respect the admin toggle.
		if ( get_option( 'dcsn_enable_modal', 'yes' ) !== 'yes' ) {
			return;
		}

		$settings = DCSN_Rules::get_settings();

		// Pre-resolve rules in the current language (WPML context is reliable here,
		// unlike admin-ajax.php). Eliminates AJAX round-trip and language issues.
		$enabled  = DCSN_Rules::get_enabled_rules();
		$js_rules = [];

		foreach ( $enabled as $rule ) {
			$js_rules[] = [
				'countries'     => $rule['countries'] ?? [],
				'states'        => $rule['states'] ?? [],
				'mode'          => $rule['mode'],
				'message'       => wp_kses_post( dcsn_resolve_message( $rule['message'] ?? '' ) ),
				'notice_type'   => $rule['notice_type'] ?? 'warning',
				'stop_on_match' => ! empty( $rule['stop_on_match'] ),
			];
		}

		wp_enqueue_style(
			'dcsn-checkout',
			DCSN_PLUGIN_URL . 'assets/checkout.css',
			[],
			DCSN_VERSION
		);

		wp_enqueue_script(
			'dcsn-checkout',
			DCSN_PLUGIN_URL . 'assets/checkout.js',
			[ 'wp-data' ],
			DCSN_VERSION,
			true
		);

		wp_localize_script( 'dcsn-checkout', 'dcsnCheckout', [
			'fallback_to_billing' => $settings['fallback_to_billing'],
			'rules'               => $js_rules,
			'i18n'                => [
				'title_block'  => dcsn_translate_string( 'Modal title - block', 'Livraison impossible' ),
				'title_allow'  => dcsn_translate_string( 'Modal title - allow', 'Information de livraison' ),
				'btn_continue' => dcsn_translate_string( 'Modal button - continue', 'Continuer' ),
				'btn_change'   => dcsn_translate_string( 'Modal button - change country', 'Changer de pays' ),
			],
		] );
	}

	/* ================================================================== */
	/*  Classic checkout: display notices                                   */
	/* ================================================================== */

	/**
	 * Hook: woocommerce_before_checkout_form.
	 */
	public function display_notices(): void {
		[ $country, $state ] = $this->get_destination();

		if ( empty( $country ) ) {
			return;
		}

		$matched = self::evaluate_rules( $country, $state );

		foreach ( $matched as $rule ) {
			$this->add_notice( $rule );
		}
	}

	/* ================================================================== */
	/*  Classic checkout: AJAX order review update                         */
	/* ================================================================== */

	/**
	 * Hook: woocommerce_checkout_update_order_review.
	 *
	 * @param string $posted_data URL-encoded form data.
	 */
	public function on_order_review_update( string $posted_data ): void {
		self::$shown = [];

		parse_str( $posted_data, $fields );

		$settings = DCSN_Rules::get_settings();

		$country = sanitize_text_field( $fields['shipping_country'] ?? '' );
		$state   = sanitize_text_field( $fields['shipping_state'] ?? '' );

		if ( empty( $country ) && ( $settings['fallback_to_billing'] ?? true ) ) {
			$country = sanitize_text_field( $fields['billing_country'] ?? '' );
			$state   = sanitize_text_field( $fields['billing_state'] ?? '' );
		}

		if ( empty( $country ) ) {
			return;
		}

		$matched = self::evaluate_rules( $country, $state );

		foreach ( $matched as $rule ) {
			$this->add_notice( $rule );
		}
	}

	/* ================================================================== */
	/*  Classic checkout: validation                                       */
	/* ================================================================== */

	/**
	 * Hook: woocommerce_after_checkout_validation.
	 *
	 * @param array    $data   Posted checkout data.
	 * @param \WP_Error $errors Validation errors.
	 */
	public function validate_checkout( array $data, \WP_Error $errors ): void {
		$settings = DCSN_Rules::get_settings();

		$country = $data['shipping_country'] ?? '';
		$state   = $data['shipping_state'] ?? '';

		if ( empty( $country ) && ( $settings['fallback_to_billing'] ?? true ) ) {
			$country = $data['billing_country'] ?? '';
			$state   = $data['billing_state'] ?? '';
		}

		if ( empty( $country ) ) {
			return;
		}

		$matched = self::evaluate_rules( $country, $state );

		foreach ( $matched as $rule ) {
			if ( $rule['mode'] === 'BLOCK_WITH_MESSAGE' ) {
				$errors->add(
					'dcsn_shipping_blocked_' . $rule['id'],
					wp_kses_post( dcsn_resolve_message( $rule['message'] ) )
				);
			}
		}
	}

	/* ================================================================== */
	/*  Block checkout: Store API validation                               */
	/* ================================================================== */

	/**
	 * Hook: woocommerce_store_api_checkout_update_order_from_request.
	 *
	 * Enforce BLOCK rules during the WooCommerce Block checkout flow.
	 * Throws a RouteException to prevent the order from being placed.
	 *
	 * @param \WC_Order        $order   The order being processed.
	 * @param \WP_REST_Request $request The Store API request.
	 */
	public function validate_store_api_checkout( \WC_Order $order, \WP_REST_Request $request ): void {
		$settings = DCSN_Rules::get_settings();

		$country = $order->get_shipping_country();
		$state   = $order->get_shipping_state();

		if ( empty( $country ) && $settings['fallback_to_billing'] ) {
			$country = $order->get_billing_country();
			$state   = $order->get_billing_state();
		}

		if ( empty( $country ) ) {
			return;
		}

		$matched = self::evaluate_rules( $country, $state );

		foreach ( $matched as $rule ) {
			if ( $rule['mode'] === 'BLOCK_WITH_MESSAGE' ) {
				if ( class_exists( '\\Automattic\\WooCommerce\\StoreApi\\Exceptions\\RouteException' ) ) {
					throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
						'dcsn_shipping_blocked',
						wp_kses_post( dcsn_resolve_message( $rule['message'] ) ),
						403
					);
				}
			}
		}
	}

	/* ================================================================== */
	/*  Rule evaluation engine (public static for AJAX + hooks)            */
	/* ================================================================== */

	/**
	 * Evaluate enabled rules against a destination.
	 *
	 * @param string $country ISO-2 country code.
	 * @param string $state   State code (optional).
	 * @return array[] Matched rules.
	 */
	public static function evaluate_rules( string $country, string $state ): array {
		$rules   = DCSN_Rules::get_enabled_rules();
		$matched = [];
		$country = strtoupper( trim( $country ) );
		$state   = strtoupper( trim( $state ) );

		foreach ( $rules as $rule ) {
			$rule_countries = $rule['countries'] ?? [];

			if ( ! in_array( $country, $rule_countries, true ) ) {
				continue;
			}

			$rule_states = $rule['states'] ?? [];
			if ( $country === 'US' && ! empty( $rule_states ) ) {
				if ( ! in_array( $state, $rule_states, true ) ) {
					continue;
				}
			}

			$matched[] = $rule;

			if ( ! empty( $rule['stop_on_match'] ) ) {
				break;
			}
		}

		return $matched;
	}

	/* ================================================================== */
	/*  Destination detection (classic checkout)                           */
	/* ================================================================== */

	/**
	 * @return array{0: string, 1: string} [ country, state ]
	 */
	private function get_destination(): array {
		if ( ! WC()->customer ) {
			return [ '', '' ];
		}

		$settings = DCSN_Rules::get_settings();

		$country = WC()->customer->get_shipping_country();
		$state   = WC()->customer->get_shipping_state();

		if ( empty( $country ) && ( $settings['fallback_to_billing'] ?? true ) ) {
			$country = WC()->customer->get_billing_country();
			$state   = WC()->customer->get_billing_state();
		}

		return [ (string) $country, (string) $state ];
	}

	/* ================================================================== */
	/*  Notice helper (classic checkout)                                   */
	/* ================================================================== */

	private function add_notice( array $rule ): void {
		$id = $rule['id'] ?? '';

		if ( in_array( $id, self::$shown, true ) ) {
			return;
		}
		self::$shown[] = $id;

		$message = wp_kses_post( dcsn_resolve_message( $rule['message'] ?? '' ) );

		if ( $rule['mode'] === 'BLOCK_WITH_MESSAGE' ) {
			wc_add_notice( $message, 'error' );
		} else {
			wc_add_notice( $message, 'notice' );
		}
	}
}
