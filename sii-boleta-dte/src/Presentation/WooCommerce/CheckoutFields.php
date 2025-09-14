<?php
namespace Sii\BoletaDte\Presentation\WooCommerce;

use Sii\BoletaDte\Infrastructure\Settings;

/**
 * Adds custom fields to the WooCommerce checkout process.
 */
class CheckoutFields {
	private Settings $settings;

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Hooks into WooCommerce.
	 */
	public function register(): void {
		if ( function_exists( 'add_action' ) ) {
			add_action( 'woocommerce_after_order_notes', array( $this, 'render_fields' ) );
			add_action( 'woocommerce_checkout_update_order_meta', array( $this, 'save_fields' ) );
			add_action( 'woocommerce_admin_order_data_after_billing_address', array( $this, 'display_admin_order_meta' ) );
		}
	}

	/**
	 * Renders checkout fields for RUT and document type.
	 */
	public function render_fields( $checkout ): void { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		$settings = $this->settings->get_settings();
		$label    = $settings['rut_label'] ?? __( 'RUT', 'sii-boleta-dte' );
		if ( function_exists( 'woocommerce_form_field' ) ) {
			woocommerce_form_field(
				'billing_rut',
				array(
					'type'  => 'text',
					'label' => $label,
				),
				''
			);
		} else {
			echo '<p class="form-row"><label>' . esc_html( $label ) . '</label><input type="text" name="billing_rut" /></p>';
		}
		$types = $this->get_enabled_document_types();
		if ( $types ) {
			$options = '';
			foreach ( $types as $code => $name ) {
				$options .= '<option value="' . esc_attr( (string) $code ) . '">' . esc_html( $name ) . '</option>';
			}
			echo '<p class="form-row"><label>' . esc_html__( 'Document type', 'sii-boleta-dte' ) . '</label><select name="sii_boleta_doc_type">' . $options . '</select></p>';
		}
	}

	/** Save posted fields to order meta. */
	public function save_fields( int $order_id ): void {
		if ( isset( $_POST['billing_rut'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$rut = sanitize_text_field( (string) $_POST['billing_rut'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( function_exists( 'update_post_meta' ) ) {
				update_post_meta( $order_id, 'billing_rut', $rut );
			}
		}
		if ( isset( $_POST['sii_boleta_doc_type'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$type = sanitize_text_field( (string) $_POST['sii_boleta_doc_type'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( function_exists( 'update_post_meta' ) ) {
				update_post_meta( $order_id, '_sii_boleta_doc_type', $type );
			}
		}
	}

	/**
	 * Displays document info on admin order screen.
	 */
	public function display_admin_order_meta( $order ): void {
		if ( ! $order || ! method_exists( $order, 'get_id' ) ) {
			return;
		}
		$order_id = $order->get_id();
		if ( function_exists( 'get_post_meta' ) ) {
			$type     = get_post_meta( $order_id, '_sii_boleta_doc_type', true );
			$track_id = get_post_meta( $order_id, '_sii_boleta_track_id', true );
			$pdf      = get_post_meta( $order_id, '_sii_boleta_pdf', true );
		} else {
			$type = $track_id = $pdf = '';
		}
		if ( $type ) {
			echo '<p><strong>' . esc_html__( 'Document type', 'sii-boleta-dte' ) . ':</strong> ' . esc_html( (string) $type ) . '</p>';
		}
		if ( $track_id ) {
			echo '<p><strong>' . esc_html__( 'Track ID', 'sii-boleta-dte' ) . ':</strong> ' . esc_html( (string) $track_id ) . '</p>';
		}
		if ( $pdf ) {
			echo '<p><a href="' . esc_url( (string) $pdf ) . '" target="_blank" rel="noopener">' . esc_html__( 'View PDF', 'sii-boleta-dte' ) . '</a></p>';
		}
	}

	/**
	 * Returns enabled document types from settings.
	 *
	 * @return array<int,string>
	 */
	private function get_enabled_document_types(): array {
		$settings = $this->settings->get_settings();
		$enabled  = $settings['enabled_types'] ?? array();
		$labels   = array(
			33 => __( 'Factura', 'sii-boleta-dte' ),
			39 => __( 'Boleta', 'sii-boleta-dte' ),
		);
		$types    = array();
		foreach ( $enabled as $code ) {
			if ( isset( $labels[ $code ] ) ) {
				$types[ $code ] = $labels[ $code ];
			}
		}
		return $types;
	}
}

class_alias( CheckoutFields::class, 'SII_Boleta_Woo_Checkout_Fields' );
