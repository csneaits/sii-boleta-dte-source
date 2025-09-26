<?php
namespace Sii\BoletaDte\Presentation\WooCommerce;

use Sii\BoletaDte\Domain\Rut;
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
                        add_action( 'woocommerce_checkout_process', array( $this, 'validate_fields' ) );
                        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
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
                                        'type'     => 'text',
                                        'label'    => $label,
                                        'required' => false,
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
                        $rut = $this->sanitize_rut_field( (string) $_POST['billing_rut'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
                        if ( '' !== $rut ) {
                                $rut = Rut::format( $rut );
                        }
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
                        $pdf_url  = get_post_meta( $order_id, '_sii_boleta_pdf_url', true );
                        $pdf_path = get_post_meta( $order_id, '_sii_boleta_pdf_path', true );
                } else {
                        $type = $track_id = $pdf = $pdf_url = $pdf_path = '';
                }
		if ( $type ) {
			echo '<p><strong>' . esc_html__( 'Document type', 'sii-boleta-dte' ) . ':</strong> ' . esc_html( (string) $type ) . '</p>';
		}
		if ( $track_id ) {
			echo '<p><strong>' . esc_html__( 'Track ID', 'sii-boleta-dte' ) . ':</strong> ' . esc_html( (string) $track_id ) . '</p>';
		}
                $pdf_link = '';
                if ( is_string( $pdf ) && '' !== $pdf ) {
                        $pdf_link = (string) $pdf;
                } elseif ( is_string( $pdf_url ) && '' !== $pdf_url ) {
                        $pdf_link = (string) $pdf_url;
                }

                if ( '' !== $pdf_link && preg_match( '#^https?://#i', $pdf_link ) ) {
                        echo '<p><a href="' . esc_url( $pdf_link ) . '" target="_blank" rel="noopener">' . esc_html__( 'View PDF', 'sii-boleta-dte' ) . '</a></p>';
                } elseif ( is_string( $pdf_path ) && '' !== $pdf_path ) {
                        echo '<p><strong>' . esc_html__( 'Ruta local del PDF', 'sii-boleta-dte' ) . ':</strong> ' . esc_html( $pdf_path ) . '</p>';
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

        /** Enqueues the JavaScript helper that validates the RUT field. */
        public function enqueue_assets(): void {
                if ( ! function_exists( 'is_checkout' ) || ! function_exists( 'wp_enqueue_script' ) ) {
                        return;
                }
                if ( ! is_checkout() ) {
                        return;
                }
                wp_enqueue_script(
                        'sii-boleta-checkout-rut',
                        SII_BOLETA_DTE_URL . 'src/Presentation/assets/js/checkout-rut.js',
                        array(),
                        SII_BOLETA_DTE_VERSION,
                        true
                );
        }

        /** Validates the posted RUT before processing the checkout. */
        public function validate_fields(): void {
                if ( ! isset( $_POST['billing_rut'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
                        return;
                }
                $rut = $this->sanitize_rut_field( (string) $_POST['billing_rut'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
                if ( '' === $rut ) {
                        $_POST['billing_rut'] = ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
                        return;
                }
                if ( Rut::isGeneric( $rut ) ) {
                        $this->add_error_notice( __( 'Generic RUT is not allowed.', 'sii-boleta-dte' ) );
                        return;
                }
                if ( ! Rut::isValid( $rut ) ) {
                        $this->add_error_notice( __( 'Invalid RUT.', 'sii-boleta-dte' ) );
                        return;
                }
                $_POST['billing_rut'] = Rut::format( $rut ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
        }

        private function add_error_notice( string $message ): void {
                if ( function_exists( 'wc_add_notice' ) ) {
                        wc_add_notice( $message, 'error' );
                }
        }

        private function sanitize_rut_field( string $value ): string {
                if ( function_exists( 'wp_unslash' ) ) {
                        $value = wp_unslash( $value );
                }
                if ( function_exists( 'sanitize_text_field' ) ) {
                        $value = sanitize_text_field( $value );
                } else {
                        $value = trim( $value );
                }
                return $value;
        }
}

class_alias( CheckoutFields::class, 'SII_Boleta_Woo_Checkout_Fields' );
