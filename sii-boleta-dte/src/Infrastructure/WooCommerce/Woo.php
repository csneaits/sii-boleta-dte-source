<?php
namespace Sii\BoletaDte\Infrastructure\WooCommerce;

use Sii\BoletaDte\Infrastructure\Plugin;
use Sii\BoletaDte\Infrastructure\TokenManager;

/**
 * Minimal WooCommerce integration: adds document type field, generates DTE on
 * order completion and stores track IDs.
 */
class Woo {
	private Plugin $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/** Register hooks with WooCommerce. */
	public function register(): void {
		if ( ! function_exists( 'add_action' ) ) {
				return;
		}
				// Checkout field handling is delegated to CheckoutFields class.
				add_action( 'woocommerce_order_status_completed', array( $this, 'handle_order_completed' ) );
	}

		/**
		 * Generates and sends a DTE when an order is completed.
		 */
	public function handle_order_completed( int $order_id ): void {
		if ( ! function_exists( 'get_post_meta' ) || ! function_exists( 'update_post_meta' ) ) {
			return;
		}
		$type = get_post_meta( $order_id, '_sii_boleta_doc_type', true );
		if ( ! $type ) {
			return;
		}
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		$data  = array();
		if ( $order && method_exists( $order, 'get_total' ) ) {
			$data['Detalles'] = array();
			$data['Folio']    = $order_id;
			$data['FchEmis']  = date( 'Y-m-d' );
			$data['Detalle']  = array();
		}
		$engine = $this->plugin->get_engine();
		$xml    = $engine->generate_dte_xml( $data, (int) $type );
		if ( ! is_string( $xml ) ) {
			return;
		}
		$file = tempnam( sys_get_temp_dir(), 'dte' );
		file_put_contents( $file, $xml );
			$token_manager = new TokenManager( $this->plugin->get_api(), $this->plugin->get_settings() );
			$token         = $token_manager->get_token( 'boleta' );
			$track_id      = $this->plugin->get_api()->send_dte_to_sii( $file, 'boleta', $token );
		if ( ! is_wp_error( $track_id ) ) {
				update_post_meta( $order_id, '_sii_boleta_track_id', $track_id );
		}
			$pdf = $engine->render_pdf( $xml );
		if ( is_string( $pdf ) ) {
				update_post_meta( $order_id, '_sii_boleta_pdf', $pdf );
		}
			unlink( $file );
	}
}

class_alias( Woo::class, 'SII_Boleta_Woo' );
