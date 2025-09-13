<?php
namespace Sii\BoletaDte\Presentation\Admin;

use Sii\BoletaDte\Infrastructure\Plugin;
use Sii\BoletaDte\Infrastructure\Settings;

class Ajax {
	private Plugin $core;

	public function __construct( Plugin $core ) {
		$this->core = $core;
	}

	public function register(): void {
		\add_action( 'wp_ajax_sii_boleta_dte_test_smtp', array( $this, 'test_smtp' ) );
		\add_action( 'wp_ajax_sii_boleta_dte_search_customers', array( $this, 'search_customers' ) );
		\add_action( 'wp_ajax_sii_boleta_dte_search_products', array( $this, 'search_products' ) );
		\add_action( 'wp_ajax_sii_boleta_dte_lookup_user_by_rut', array( $this, 'lookup_user_by_rut' ) );
	}

	public function test_smtp(): void {
		\check_ajax_referer( 'sii_boleta_nonce' );
		if ( ! \current_user_can( 'manage_options' ) ) {
			\wp_send_json_error( array( 'message' => \__( 'Permisos insuficientes.', 'sii-boleta-dte' ) ) );
		}
		$to = isset( $_POST['to'] ) ? \sanitize_email( \wp_unslash( $_POST['to'] ) ) : \get_option( 'admin_email' );
		if ( ! \is_email( $to ) ) {
			\wp_send_json_error( array( 'message' => \__( 'Dirección de destino inválida.', 'sii-boleta-dte' ) ) );
		}
		$profile = isset( $_POST['profile'] ) ? \sanitize_text_field( \wp_unslash( $_POST['profile'] ) ) : '';

		$settings   = new Settings();
		$conn       = method_exists( $settings, 'get_fluent_smtp_connection' ) ? $settings->get_fluent_smtp_connection( $profile ) : null;
		$from_email = is_array( $conn ) && ! empty( $conn['sender_email'] ) ? $conn['sender_email'] : \get_option( 'admin_email' );
		$from_name  = is_array( $conn ) && ! empty( $conn['sender_name'] ) ? $conn['sender_name'] : \get_bloginfo( 'name' );

		$headers = array( 'From: ' . sprintf( '%s <%s>', $from_name, $from_email ) );
		$ok      = \wp_mail( $to, 'Prueba SMTP – SII Boleta DTE', "Este es un correo de prueba enviado desde el perfil seleccionado.\nSitio: " . \home_url() . "\nPerfil: " . $profile, $headers );
		if ( ! $ok ) {
			\wp_send_json_error( array( 'message' => \__( 'No se pudo enviar el correo de prueba. Revise la configuración del proveedor SMTP.', 'sii-boleta-dte' ) ) );
		}
		\wp_send_json_success( array( 'message' => \__( 'Correo de prueba enviado. Revise su bandeja.', 'sii-boleta-dte' ) ) );
	}

	public function search_customers(): void {
		\check_ajax_referer( 'sii_boleta_nonce' );
		if ( ! \current_user_can( 'manage_options' ) ) {
			\wp_send_json_error( array( 'message' => \__( 'Permisos insuficientes.', 'sii-boleta-dte' ) ) );
		}
		$term = isset( $_POST['term'] ) ? \sanitize_text_field( \wp_unslash( $_POST['term'] ) ) : '';
		if ( strlen( preg_replace( '/[^0-9Kk]/', '', $term ) ) < 3 ) {
			\wp_send_json_success( array( 'items' => array() ) );
		}
		$norm      = $this->normalize_rut( $term );
		$compact   = strtoupper( str_replace( '-', '', $norm ) );
		$clean     = strtoupper( preg_replace( '/[^0-9Kk]/', '', $norm ) );
		$meta_keys = array( 'billing_rut', '_billing_rut', 'rut', 'user_rut', 'customer_rut', 'billing_rut_number' );

		$results = array();
		$seen    = array();

		foreach ( $meta_keys as $mk ) {
			$q = new \WP_User_Query(
				array(
					'number'      => 10,
					'count_total' => false,
					'fields'      => array( 'ID', 'display_name', 'user_email' ),
					'meta_query'  => array(
						'relation' => 'OR',
						array(
							'key'     => $mk,
							'value'   => $clean,
							'compare' => 'LIKE',
						),
						array(
							'key'     => $mk,
							'value'   => $norm,
							'compare' => 'LIKE',
						),
						array(
							'key'     => $mk,
							'value'   => $compact,
							'compare' => 'LIKE',
						),
					),
				)
			);
			foreach ( (array) $q->get_results() as $u ) {
				$rut_meta = '';
				foreach ( $meta_keys as $mk2 ) {
					$v = \get_user_meta( $u->ID, $mk2, true );
					if ( $v ) {
						$rut_meta = $v;
						break; }
				}
				$rut_show = $rut_meta ? $this->normalize_rut( $rut_meta ) : '';
				$key      = md5( 'u-' . $u->ID );
				if ( isset( $seen[ $key ] ) ) {
					continue; }
				$seen[ $key ] = 1;
				$results[]    = array(
					'source'  => 'user',
					'rut'     => $rut_show,
					'name'    => $u->display_name,
					'email'   => $u->user_email,
					'address' => \get_user_meta( $u->ID, 'billing_address_1', true ),
					'comuna'  => \get_user_meta( $u->ID, 'billing_city', true ),
				);
				if ( count( $results ) >= 10 ) {
					break 2; }
			}
		}

		if ( count( $results ) < 10 && class_exists( '\\WC_Order_Query' ) ) {
			foreach ( $meta_keys as $okey ) {
				foreach ( array( $clean, $norm, $compact ) as $rv ) {
					$oq = new \WC_Order_Query(
						array(
							'limit'      => 10,
							'orderby'    => 'date',
							'order'      => 'DESC',
							'return'     => 'ids',
							'meta_query' => array(
								array(
									'key'     => $okey,
									'value'   => $rv,
									'compare' => 'LIKE',
								),
							),
						)
					);
					foreach ( (array) $oq->get_orders() as $oid ) {
						$o = \wc_get_order( $oid );
						if ( ! $o ) {
							continue; }
						$rut_meta = '';
						foreach ( $meta_keys as $mk3 ) {
							$mv = $o->get_meta( $mk3 );
							if ($mv) {
								$rut_meta = $mv;
								break; }}
						$rut_show = $rut_meta ? $this->normalize_rut( $rut_meta ) : '';
						$key      = md5( 'o-' . $oid );
						if ( isset( $seen[ $key ] ) ) {
							continue; }
						$seen[ $key ] = 1;
						$results[]    = array(
							'source'  => 'order',
							'rut'     => $rut_show,
							'name'    => trim( $o->get_billing_first_name() . ' ' . $o->get_billing_last_name() ),
							'email'   => $o->get_billing_email(),
							'address' => $o->get_billing_address_1(),
							'comuna'  => $o->get_billing_city(),
						);
						if ( count( $results ) >= 10 ) {
							break 3; }
					}
				}
			}
		}

		\wp_send_json_success( array( 'items' => $results ) );
	}

	public function search_products(): void {
		\check_ajax_referer( 'sii_boleta_nonce' );
		if ( ! \current_user_can( 'manage_options' ) ) {
			\wp_send_json_error( array( 'message' => \__( 'Permisos insuficientes.', 'sii-boleta-dte' ) ) );
		}
		$q = isset( $_POST['q'] ) ? \sanitize_text_field( \wp_unslash( $_POST['q'] ) ) : '';
		if ( ! class_exists( 'WC_Product' ) ) {
			\wp_send_json_error( array( 'message' => \__( 'WooCommerce no está activo.', 'sii-boleta-dte' ) ) );
		}
		$args = array(
			'post_type'      => array( 'product', 'product_variation' ),
			's'              => $q,
			'posts_per_page' => 20,
			'post_status'    => 'publish',
			'fields'         => 'ids',
		);
		$ids  = \get_posts( $args );
		$out  = array();
		foreach ( $ids as $pid ) {
			$product = \wc_get_product( $pid );
			if ( ! $product ) {
				continue; }
			$out[] = array(
				'id'    => $product->get_id(),
				'name'  => html_entity_decode( \wp_strip_all_tags( $product->get_formatted_name() ), ENT_QUOTES, 'UTF-8' ),
				'price' => (float) $product->get_price(),
				'sku'   => (string) $product->get_sku(),
			);
		}
		\wp_send_json_success( array( 'items' => $out ) );
	}

	public function lookup_user_by_rut(): void {
		\check_ajax_referer( 'sii_boleta_nonce' );
		if ( ! \current_user_can( 'manage_options' ) ) {
			\wp_send_json_error( array( 'message' => \__( 'Permisos insuficientes.', 'sii-boleta-dte' ) ) );
		}
		$rut = isset( $_POST['rut'] ) ? $this->normalize_rut( \wp_unslash( $_POST['rut'] ) ) : '';
		if ( ! $rut ) {
			\wp_send_json_success( array( 'found' => false ) );
		}
		$meta_keys = array( 'billing_rut', '_billing_rut', 'rut', 'user_rut', 'customer_rut', 'billing_rut_number' );
		foreach ( $meta_keys as $mk ) {
			$q     = new \WP_User_Query(
				array(
					'number'      => 1,
					'count_total' => false,
					'fields'      => array( 'ID', 'display_name', 'user_email' ),
					'meta_query'  => array(
						array(
							'key'     => $mk,
							'value'   => $rut,
							'compare' => '=',
						),
					),
				)
			);
			$users = $q->get_results();
			if ( $users ) {
				$u = $users[0];
				\wp_send_json_success(
					array(
						'found' => true,
						'name'  => $u->display_name,
						'email' => $u->user_email,
					)
				);
			}
		}
		\wp_send_json_success( array( 'found' => false ) );
	}

	private function normalize_rut( string $rut ): string {
		$c = strtoupper( preg_replace( '/[^0-9Kk]/', '', (string) $rut ) );
		if ( strlen( $c ) < 2 ) {
			return '';
		}
		return substr( $c, 0, -1 ) . '-' . substr( $c, -1 );
	}
}

class_alias( Ajax::class, 'Sii\\BoletaDte\\Admin\\Ajax' );
