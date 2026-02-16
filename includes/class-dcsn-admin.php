<?php
/**
 * Admin UI — WooCommerce Settings tab for shipping notice rules.
 *
 * Extends WC_Settings_Page for native integration with the WooCommerce
 * settings framework.
 *
 * @package DC_Woo_Shipping_Notices
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DCSN_Admin extends WC_Settings_Page {

	public function __construct() {
		$this->id    = 'shipping_notices';
		$this->label = __( 'Shipping Notices', 'dc-woo-shipping-notices' );

		parent::__construct();

		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	/* ------------------------------------------------------------------ */
	/*  Settings fields (global options rendered by WooCommerce)            */
	/* ------------------------------------------------------------------ */

	/**
	 * Return the settings fields for the default (list) section.
	 *
	 * WooCommerce renders these automatically via WC_Admin_Settings::output_fields()
	 * and saves them via WC_Admin_Settings::save_fields().
	 *
	 * @return array[]
	 */
	protected function get_settings_for_default_section(): array {
		return [
			[
				'title' => __( 'Shipping Destination Notices', 'dc-woo-shipping-notices' ),
				'type'  => 'title',
				'desc'  => __( 'Rules are evaluated in priority order at checkout. Blocking rules prevent the order from being placed.', 'dc-woo-shipping-notices' ),
				'id'    => 'dcsn_options',
			],
			[
				'title'   => __( 'Fallback to billing address', 'dc-woo-shipping-notices' ),
				'desc'    => __( 'Use billing address when shipping address is empty.', 'dc-woo-shipping-notices' ),
				'id'      => 'dcsn_fallback_to_billing',
				'default' => 'yes',
				'type'    => 'checkbox',
			],
			[
				'title'   => __( 'Country modal', 'dc-woo-shipping-notices' ),
				'desc'    => __( 'Show a modal dialog when the customer selects a destination matching a rule.', 'dc-woo-shipping-notices' ),
				'id'      => 'dcsn_enable_modal',
				'default' => 'yes',
				'type'    => 'checkbox',
			],
			[
				'type' => 'sectionend',
				'id'   => 'dcsn_options',
			],
		];
	}

	/* ------------------------------------------------------------------ */
	/*  Assets                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Enqueue admin CSS only on our tab.
	 */
	public function enqueue_assets( string $hook ): void {
		if ( $hook !== 'woocommerce_page_wc-settings' ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ( $_GET['tab'] ?? '' ) !== $this->id ) {
			return;
		}

		wp_enqueue_style(
			'dcsn-admin',
			DCSN_PLUGIN_URL . 'assets/admin.css',
			[],
			DCSN_VERSION
		);
	}

	/* ------------------------------------------------------------------ */
	/*  Output (routing)                                                   */
	/* ------------------------------------------------------------------ */

	/**
	 * Render the tab content.
	 *
	 * - List screen: WooCommerce standard settings + rules table.
	 * - Edit screen: rule editor form (fields inside WC's #mainform).
	 */
	public function output(): void {
		global $hide_save_button;

		// Handle GET actions (delete, duplicate) before rendering.
		$this->handle_get_actions();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$action = sanitize_text_field( $_GET['action'] ?? '' );

		if ( $action === 'edit' || $action === 'add' ) {
			// Edit screen: hide WC "Save changes" button, we add our own.
			$hide_save_button = true;
			$this->render_edit_screen();
		} else {
			// List screen: WooCommerce settings fields + rules table.
			$this->maybe_show_notice();
			parent::output();
			$this->render_rules_table();
		}
	}

	/* ------------------------------------------------------------------ */
	/*  Save                                                               */
	/* ------------------------------------------------------------------ */

	/**
	 * Handle save — routes to rule save or standard WC settings save.
	 */
	public function save(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( isset( $_POST['dcsn_screen'] ) && sanitize_text_field( wp_unslash( $_POST['dcsn_screen'] ) ) === 'edit' ) {
			$this->save_rule_from_post();
			// ^ Always redirects (exit).
		}

		// Standard WooCommerce settings save (global settings).
		parent::save();
	}

	/* ------------------------------------------------------------------ */
	/*  GET actions (delete / duplicate)                                   */
	/* ------------------------------------------------------------------ */

	private function handle_get_actions(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$action = sanitize_text_field( $_GET['dcsn_action'] ?? '' );
		if ( ! $action ) {
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'dcsn_action' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'dc-woo-shipping-notices' ) );
		}

		$rule_id = sanitize_text_field( $_GET['rule_id'] ?? '' );

		if ( $action === 'delete' && $rule_id ) {
			DCSN_Rules::delete_rule( $rule_id );
			$this->redirect_to_list( 'deleted' );
		}

		if ( $action === 'duplicate' && $rule_id ) {
			DCSN_Rules::duplicate_rule( $rule_id );
			$this->redirect_to_list( 'duplicated' );
		}
	}

	/* ------------------------------------------------------------------ */
	/*  Save rule from POST                                                */
	/* ------------------------------------------------------------------ */

	/**
	 * Validate and save a rule from POST data, then redirect.
	 * Always exits (redirect).
	 */
	private function save_rule_from_post(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$data = [
			'id'            => sanitize_text_field( $_POST['rule_id'] ?? '' ),
			'enabled'       => ! empty( $_POST['enabled'] ),
			'label'         => $_POST['label'] ?? '',
			'countries'     => $_POST['countries'] ?? [],
			'states'        => $_POST['states'] ?? '',
			'mode'          => $_POST['mode'] ?? '',
			'notice_type'   => $_POST['notice_type'] ?? 'warning',
			'message'       => $_POST['message'] ?? '',
			'priority'      => $_POST['priority'] ?? DCSN_Rules::next_priority(),
			'stop_on_match' => ! empty( $_POST['stop_on_match'] ),
		];
		// phpcs:enable

		$result = DCSN_Rules::save_rule( $data );

		if ( $result['ok'] ) {
			$this->redirect_to_list( 'saved' );
		} else {
			// Redirect back to the edit screen with errors.
			set_transient( 'dcsn_form_errors', $result['errors'], 60 );
			set_transient( 'dcsn_form_data', $result['rule'], 60 );

			$rule_id = $data['id'] ?? '';
			$url     = admin_url( 'admin.php?page=wc-settings&tab=' . $this->id );
			$url    .= $rule_id ? '&action=edit&rule_id=' . urlencode( $rule_id ) : '&action=add';

			wp_safe_redirect( $url );
			exit;
		}
	}

	/* ------------------------------------------------------------------ */
	/*  List screen: rules table                                           */
	/* ------------------------------------------------------------------ */

	/**
	 * Render the rules table below the WooCommerce settings fields.
	 */
	private function render_rules_table(): void {
		$rules   = DCSN_Rules::get_rules();
		$tab_url = admin_url( 'admin.php?page=wc-settings&tab=' . $this->id );
		$add_url = $tab_url . '&action=add';
		$modes   = dcsn_get_mode_labels();
		?>

		<h2><?php esc_html_e( 'Rules', 'dc-woo-shipping-notices' ); ?></h2>

		<p>
			<a href="<?php echo esc_url( $add_url ); ?>" class="button button-primary"><?php esc_html_e( 'Add rule', 'dc-woo-shipping-notices' ); ?></a>
		</p>

		<?php if ( empty( $rules ) ) : ?>
			<p><em><?php esc_html_e( 'No rules yet.', 'dc-woo-shipping-notices' ); ?></em></p>
		<?php else : ?>
			<table class="widefat dcsn-rules-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Enabled', 'dc-woo-shipping-notices' ); ?></th>
						<th><?php esc_html_e( 'Label', 'dc-woo-shipping-notices' ); ?></th>
						<th><?php esc_html_e( 'Countries', 'dc-woo-shipping-notices' ); ?></th>
						<th><?php esc_html_e( 'US States', 'dc-woo-shipping-notices' ); ?></th>
						<th><?php esc_html_e( 'Mode', 'dc-woo-shipping-notices' ); ?></th>
						<th><?php esc_html_e( 'Priority', 'dc-woo-shipping-notices' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'dc-woo-shipping-notices' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rules as $rule ) :
						$edit_url   = $tab_url . '&action=edit&rule_id=' . urlencode( $rule['id'] );
						$del_url    = wp_nonce_url( $tab_url . '&dcsn_action=delete&rule_id=' . urlencode( $rule['id'] ), 'dcsn_action' );
						$dup_url    = wp_nonce_url( $tab_url . '&dcsn_action=duplicate&rule_id=' . urlencode( $rule['id'] ), 'dcsn_action' );
						$mode_class = $rule['mode'] === 'BLOCK_WITH_MESSAGE' ? 'dcsn-mode-block' : 'dcsn-mode-allow';
					?>
					<tr>
						<td>
							<span class="dcsn-dot <?php echo $rule['enabled'] ? 'dcsn-enabled' : 'dcsn-disabled'; ?>"></span>
						</td>
						<td><strong><a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $rule['label'] ?: '—' ); ?></a></strong></td>
						<td><?php echo esc_html( implode( ', ', $rule['countries'] ?? [] ) ); ?></td>
						<td><?php echo esc_html( implode( ', ', $rule['states'] ?? [] ) ?: '—' ); ?></td>
						<td><span class="dcsn-mode <?php echo esc_attr( $mode_class ); ?>"><?php echo esc_html( $modes[ $rule['mode'] ] ?? $rule['mode'] ); ?></span></td>
						<td><?php echo (int) $rule['priority']; ?></td>
						<td class="dcsn-actions">
							<a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'dc-woo-shipping-notices' ); ?></a>
							<a href="<?php echo esc_url( $dup_url ); ?>"><?php esc_html_e( 'Duplicate', 'dc-woo-shipping-notices' ); ?></a>
							<a href="<?php echo esc_url( $del_url ); ?>" class="dcsn-delete" onclick="return confirm('<?php echo esc_js( __( 'Delete this rule?', 'dc-woo-shipping-notices' ) ); ?>');"><?php esc_html_e( 'Delete', 'dc-woo-shipping-notices' ); ?></a>
						</td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif;
	}

	/* ------------------------------------------------------------------ */
	/*  Edit / Add screen                                                  */
	/* ------------------------------------------------------------------ */

	/**
	 * Render the rule editor.
	 *
	 * Fields are rendered inside WooCommerce's #mainform — no nested <form>.
	 * A custom "Save rule" button replaces WC's hidden "Save changes".
	 */
	private function render_edit_screen(): void {
		$tab_url = admin_url( 'admin.php?page=wc-settings&tab=' . $this->id );

		// Load rule data.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$rule_id = sanitize_text_field( $_GET['rule_id'] ?? '' );
		$rule    = null;

		// Check for re-post data (validation failed → redirect back).
		$form_data = get_transient( 'dcsn_form_data' );
		$errors    = get_transient( 'dcsn_form_errors' );
		delete_transient( 'dcsn_form_data' );
		delete_transient( 'dcsn_form_errors' );

		if ( $form_data ) {
			$rule = $form_data;
		} elseif ( $rule_id ) {
			$rule = DCSN_Rules::get_rule( $rule_id );
		}

		if ( ! $rule ) {
			$rule             = dcsn_default_rule();
			$rule['priority'] = DCSN_Rules::next_priority();
		}

		$modes        = dcsn_get_mode_labels();
		$notice_types = dcsn_get_notice_type_labels();
		$is_edit      = ! empty( $rule['id'] );
		$title        = $is_edit
			? __( 'Edit Rule', 'dc-woo-shipping-notices' )
			: __( 'Add Rule', 'dc-woo-shipping-notices' );

		// WooCommerce countries list.
		$wc_countries = WC()->countries->get_countries();
		?>

		<?php if ( ! empty( $errors ) ) : ?>
			<div class="notice notice-error">
				<?php foreach ( $errors as $err ) : ?>
					<p><?php echo esc_html( $err ); ?></p>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>

		<h2>
			<a href="<?php echo esc_url( $tab_url ); ?>">&larr; <?php esc_html_e( 'Back to list', 'dc-woo-shipping-notices' ); ?></a>
			&nbsp; <?php echo esc_html( $title ); ?>
		</h2>

		<!-- Hidden fields (inside WC's #mainform) -->
		<input type="hidden" name="dcsn_screen" value="edit">
		<input type="hidden" name="rule_id" value="<?php echo esc_attr( $rule['id'] ); ?>">

		<table class="form-table dcsn-form">
			<!-- Enabled -->
			<tr>
				<th scope="row"><?php esc_html_e( 'Enabled', 'dc-woo-shipping-notices' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="enabled" value="1" <?php checked( $rule['enabled'] ); ?>>
						<?php esc_html_e( 'Active', 'dc-woo-shipping-notices' ); ?>
					</label>
				</td>
			</tr>

			<!-- Label -->
			<tr>
				<th scope="row"><label for="dcsn-label"><?php esc_html_e( 'Label', 'dc-woo-shipping-notices' ); ?></label></th>
				<td><input type="text" id="dcsn-label" name="label" value="<?php echo esc_attr( $rule['label'] ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'Internal label…', 'dc-woo-shipping-notices' ); ?>"></td>
			</tr>

			<!-- Countries -->
			<tr>
				<th scope="row"><label for="dcsn-countries"><?php esc_html_e( 'Countries', 'dc-woo-shipping-notices' ); ?></label></th>
				<td>
					<select id="dcsn-countries" name="countries[]" multiple size="8" style="min-width:320px;min-height:180px;">
						<?php foreach ( $wc_countries as $code => $name ) : ?>
							<option value="<?php echo esc_attr( $code ); ?>" <?php selected( in_array( $code, $rule['countries'] ?? [], true ) ); ?>>
								<?php echo esc_html( "$code — $name" ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'Hold Ctrl/Cmd to select multiple.', 'dc-woo-shipping-notices' ); ?></p>
				</td>
			</tr>

			<!-- US States -->
			<tr id="dcsn-states-row" style="<?php echo in_array( 'US', $rule['countries'] ?? [], true ) ? '' : 'display:none;'; ?>">
				<th scope="row"><label for="dcsn-states"><?php esc_html_e( 'US States', 'dc-woo-shipping-notices' ); ?></label></th>
				<td>
					<input type="text" id="dcsn-states" name="states" value="<?php echo esc_attr( implode( ', ', $rule['states'] ?? [] ) ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'CA, NY, TX… (empty = all US states)', 'dc-woo-shipping-notices' ); ?>">
					<p class="description"><?php esc_html_e( 'Comma-separated state codes. Leave empty to match all US states.', 'dc-woo-shipping-notices' ); ?></p>
				</td>
			</tr>

			<!-- Mode -->
			<tr>
				<th scope="row"><label for="dcsn-mode"><?php esc_html_e( 'Mode', 'dc-woo-shipping-notices' ); ?></label></th>
				<td>
					<select id="dcsn-mode" name="mode">
						<?php foreach ( $modes as $val => $label ) : ?>
							<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $rule['mode'], $val ); ?>>
								<?php echo esc_html( $label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>

			<!-- Notice type -->
			<tr id="dcsn-notice-type-row" style="<?php echo $rule['mode'] === 'BLOCK_WITH_MESSAGE' ? 'display:none;' : ''; ?>">
				<th scope="row"><label for="dcsn-notice-type"><?php esc_html_e( 'Notice type', 'dc-woo-shipping-notices' ); ?></label></th>
				<td>
					<select id="dcsn-notice-type" name="notice_type">
						<?php foreach ( $notice_types as $val => $label ) : ?>
							<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $rule['notice_type'], $val ); ?>>
								<?php echo esc_html( $label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>

			<!-- Message -->
			<?php
			$wpml_languages = dcsn_get_active_languages();
			if ( ! empty( $wpml_languages ) ) :
				// WPML active — one textarea per language.
				foreach ( $wpml_languages as $lang_code => $lang_info ) :
					$lang_label = $lang_info['native_name'] ?? strtoupper( $lang_code );
					$lang_flag  = $lang_info['country_flag_url'] ?? '';
					$msg_value  = is_array( $rule['message'] ) ? ( $rule['message'][ $lang_code ] ?? '' ) : ( $lang_code === dcsn_default_language() ? $rule['message'] : '' );
			?>
			<tr>
				<th scope="row">
					<label for="dcsn-message-<?php echo esc_attr( $lang_code ); ?>">
						<?php esc_html_e( 'Message', 'dc-woo-shipping-notices' ); ?>
						<?php if ( $lang_flag ) : ?>
							<img src="<?php echo esc_url( $lang_flag ); ?>" alt="" style="vertical-align:middle;margin-left:4px;" width="18" height="12">
						<?php endif; ?>
						<span style="font-weight:normal;color:#666;">(<?php echo esc_html( $lang_label ); ?>)</span>
					</label>
				</th>
				<td>
					<textarea id="dcsn-message-<?php echo esc_attr( $lang_code ); ?>" name="message[<?php echo esc_attr( $lang_code ); ?>]" rows="3" class="large-text"><?php echo esc_textarea( $msg_value ); ?></textarea>
					<?php if ( $lang_code === array_key_first( $wpml_languages ) ) : ?>
						<p class="description"><?php esc_html_e( 'Basic HTML allowed (links, bold, italic).', 'dc-woo-shipping-notices' ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
			<?php endforeach; ?>
			<?php else : ?>
			<tr>
				<th scope="row"><label for="dcsn-message"><?php esc_html_e( 'Message', 'dc-woo-shipping-notices' ); ?></label></th>
				<td>
					<textarea id="dcsn-message" name="message" rows="4" class="large-text"><?php echo esc_textarea( is_array( $rule['message'] ) ? dcsn_resolve_message( $rule['message'] ) : $rule['message'] ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Basic HTML allowed (links, bold, italic).', 'dc-woo-shipping-notices' ); ?></p>
				</td>
			</tr>
			<?php endif; ?>

			<!-- Priority -->
			<tr>
				<th scope="row"><label for="dcsn-priority"><?php esc_html_e( 'Priority', 'dc-woo-shipping-notices' ); ?></label></th>
				<td>
					<input type="number" id="dcsn-priority" name="priority" value="<?php echo (int) $rule['priority']; ?>" min="0" step="1" style="width:80px;">
					<p class="description"><?php esc_html_e( 'Lower runs first.', 'dc-woo-shipping-notices' ); ?></p>
				</td>
			</tr>

			<!-- Stop on match -->
			<tr>
				<th scope="row"><?php esc_html_e( 'Stop after match', 'dc-woo-shipping-notices' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="stop_on_match" value="1" <?php checked( $rule['stop_on_match'] ); ?>>
						<?php esc_html_e( 'Do not evaluate further rules if this one matches.', 'dc-woo-shipping-notices' ); ?>
					</label>
				</td>
			</tr>
		</table>

		<p class="submit">
			<button type="submit" name="save" class="button button-primary" value="1"><?php esc_html_e( 'Save rule', 'dc-woo-shipping-notices' ); ?></button>
			<a href="<?php echo esc_url( $tab_url ); ?>" class="button"><?php esc_html_e( 'Cancel', 'dc-woo-shipping-notices' ); ?></a>
		</p>

		<!-- Inline JS for toggling states row and notice type row -->
		<script>
		(function(){
			var countrySel = document.getElementById('dcsn-countries');
			var statesRow  = document.getElementById('dcsn-states-row');
			var modeSel    = document.getElementById('dcsn-mode');
			var ntRow      = document.getElementById('dcsn-notice-type-row');

			if (countrySel && statesRow) {
				countrySel.addEventListener('change', function(){
					var opts = Array.from(this.selectedOptions).map(function(o){ return o.value; });
					statesRow.style.display = opts.indexOf('US') !== -1 ? '' : 'none';
				});
			}
			if (modeSel && ntRow) {
				modeSel.addEventListener('change', function(){
					ntRow.style.display = this.value === 'BLOCK_WITH_MESSAGE' ? 'none' : '';
				});
			}
		})();
		</script>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/*  Helpers                                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * Display a success notice for rule actions (saved, deleted, duplicated).
	 */
	private function maybe_show_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$msg = sanitize_text_field( $_GET['dcsn_msg'] ?? '' );
		if ( ! $msg ) {
			return;
		}

		$messages = [
			'saved'      => __( 'Rule saved.', 'dc-woo-shipping-notices' ),
			'deleted'    => __( 'Rule deleted.', 'dc-woo-shipping-notices' ),
			'duplicated' => __( 'Rule duplicated.', 'dc-woo-shipping-notices' ),
		];

		if ( isset( $messages[ $msg ] ) ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html( $messages[ $msg ] )
			);
		}
	}

	/**
	 * Redirect to list screen with optional message.
	 */
	private function redirect_to_list( string $msg = '' ): void {
		$url = admin_url( 'admin.php?page=wc-settings&tab=' . $this->id );
		if ( $msg ) {
			$url = add_query_arg( 'dcsn_msg', $msg, $url );
		}
		wp_safe_redirect( $url );
		exit;
	}
}
