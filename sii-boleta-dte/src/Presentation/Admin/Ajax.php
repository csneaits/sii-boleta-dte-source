<?php
namespace Sii\BoletaDte\Presentation\Admin;

use Sii\BoletaDte\Infrastructure\Factory\Container;
use Sii\BoletaDte\Infrastructure\Plugin;
use Sii\BoletaDte\Infrastructure\Settings;
use Sii\BoletaDte\Presentation\Admin\GenerateDtePage;
use Sii\BoletaDte\Infrastructure\Persistence\FoliosDb;
use Sii\BoletaDte\Infrastructure\Persistence\LogDb;
use Sii\BoletaDte\Infrastructure\Persistence\QueueDb;
use libredte\lib\Core\Application;

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
        \add_action( 'wp_ajax_sii_boleta_dte_view_pdf', array( $this, 'view_pdf' ) );
        \add_action( 'wp_ajax_sii_boleta_dte_generate_preview', array( $this, 'generate_preview' ) );
        \add_action( 'wp_ajax_sii_boleta_dte_send_document', array( $this, 'send_document' ) );
        \add_action( 'wp_ajax_sii_boleta_dte_save_folio_range', array( $this, 'save_folio_range' ) );
        \add_action( 'wp_ajax_sii_boleta_dte_delete_folio_range', array( $this, 'delete_folio_range' ) );
        \add_action( 'wp_ajax_sii_boleta_dte_control_panel_data', array( $this, 'control_panel_data' ) );
    }

    public function control_panel_data(): void {
        \check_ajax_referer( 'sii_boleta_control_panel', 'nonce' );
        if ( ! \current_user_can( 'manage_options' ) ) {
            \wp_send_json_error( array( 'message' => \__( 'Permisos insuficientes.', 'sii-boleta-dte' ) ) );
        }

        $logs      = LogDb::get_logs( array( 'limit' => 5 ) );
        $jobs      = QueueDb::get_pending_jobs();
        $logs_html = $this->render_logs_rows( $logs );
        $queue     = $this->render_queue_rows( $jobs );

        \wp_send_json_success(
            array(
                'logsHtml'     => $logs_html,
                'queueHtml'    => $queue['rows'],
                'queueHasJobs' => $queue['has_jobs'],
            )
        );
    }

    /**
     * @param array<int, array<string, mixed>> $logs Log rows.
     */
    private function render_logs_rows( array $logs ): string {
        ob_start();
        if ( empty( $logs ) ) {
            echo '<tr class="sii-control-empty-row"><td colspan="2">' . esc_html__( 'Sin DTE recientes.', 'sii-boleta-dte' ) . '</td></tr>';
        } else {
            foreach ( $logs as $row ) {
                $track  = isset( $row['track_id'] ) ? (string) $row['track_id'] : '';
                $status = isset( $row['status'] ) ? (string) $row['status'] : '';
                echo '<tr>';
                echo '<td>' . esc_html( $track ) . '</td>';
                echo '<td>' . esc_html( $this->translate_log_status( $status ) ) . '</td>';
                echo '</tr>';
            }
        }
        return (string) ob_get_clean();
    }

    /**
     * @param array<int, array<string, mixed>> $jobs Queue items.
     * @return array{rows:string,has_jobs:bool}
     */
    private function render_queue_rows( array $jobs ): array {
        $has_jobs = ! empty( $jobs );
        ob_start();
        if ( ! $has_jobs ) {
            echo '<tr class="sii-control-empty-row"><td colspan="4">' . esc_html__( 'No hay elementos en la cola.', 'sii-boleta-dte' ) . '</td></tr>';
        } else {
            foreach ( $jobs as $job ) {
                $id        = isset( $job['id'] ) ? (int) $job['id'] : 0;
                $type      = isset( $job['type'] ) ? (string) $job['type'] : '';
                $attempts  = isset( $job['attempts'] ) ? (int) $job['attempts'] : 0;
                echo '<tr>';
                echo '<td>' . (int) $id . '</td>';
                echo '<td>' . esc_html( $this->translate_queue_type( $type ) ) . '</td>';
                echo '<td>' . (int) $attempts . '</td>';
                echo '<td>' . $this->render_queue_actions( $id ) . '</td>';
                echo '</tr>';
            }
        }
        return array(
            'rows'     => (string) ob_get_clean(),
            'has_jobs' => $has_jobs,
        );
    }

    private function render_queue_actions( int $id ): string {
        $nonce = $this->nonce_field( 'sii_boleta_queue', 'sii_boleta_queue_nonce' );
        $id    = max( 0, $id );
        $html  = '<form method="post" class="sii-inline-form">';
        $html .= '<input type="hidden" name="job_id" value="' . $id . '" />';
        $html .= $nonce;
        $html .= '<button class="button" name="queue_action" value="process">' . esc_html__( 'Procesar', 'sii-boleta-dte' ) . '</button>';
        $html .= '<button class="button" name="queue_action" value="requeue">' . esc_html__( 'Reintentar', 'sii-boleta-dte' ) . '</button>';
        $html .= '<button class="button" name="queue_action" value="cancel">' . esc_html__( 'Cancelar', 'sii-boleta-dte' ) . '</button>';
        $html .= '</form>';
        return $html;
    }

    private function nonce_field( string $action, string $name ): string {
        if ( function_exists( 'wp_nonce_field' ) ) {
            return (string) \wp_nonce_field( $action, $name, true, false );
        }
        $action_attr = htmlspecialchars( $action, ENT_QUOTES, 'UTF-8' );
        $name_attr   = htmlspecialchars( $name, ENT_QUOTES, 'UTF-8' );
        return '<input type="hidden" name="' . $name_attr . '" value="' . $action_attr . '" />';
    }

    private function translate_queue_type( string $type ): string {
        $map = array(
            'dte'   => __( 'DTE', 'sii-boleta-dte' ),
            'libro' => __( 'Libro', 'sii-boleta-dte' ),
            'rvd'   => __( 'RVD', 'sii-boleta-dte' ),
        );
        return $map[ $type ] ?? $type;
    }

    private function translate_log_status( string $status ): string {
        $normalized = strtolower( trim( $status ) );
        $map        = array(
            'accepted'   => __( 'Aceptado', 'sii-boleta-dte' ),
            'sent'       => __( 'Enviado (en espera)', 'sii-boleta-dte' ),
            'pending'    => __( 'Pendiente', 'sii-boleta-dte' ),
            'rejected'   => __( 'Rechazado', 'sii-boleta-dte' ),
            'processing' => __( 'Procesando', 'sii-boleta-dte' ),
            'queued'     => __( 'En cola', 'sii-boleta-dte' ),
            'failed'     => __( 'Fallido', 'sii-boleta-dte' ),
            'error'      => __( 'Error', 'sii-boleta-dte' ),
            'draft'      => __( 'Borrador', 'sii-boleta-dte' ),
        );
        return $map[ $normalized ] ?? $status;
    }

    public function save_folio_range(): void {
        \check_ajax_referer( 'sii_boleta_caf', 'nonce' );
        if ( ! \current_user_can( 'manage_options' ) ) {
            \wp_send_json_error( array( 'message' => \__( 'Permisos insuficientes.', 'sii-boleta-dte' ) ) );
        }

        $id       = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
        $tipo     = isset( $_POST['tipo'] ) ? (int) $_POST['tipo'] : 0;
        $start    = isset( $_POST['start'] ) ? (int) $_POST['start'] : 0;
        $quantity = isset( $_POST['quantity'] ) ? (int) $_POST['quantity'] : 0;

        $caf_file     = $_FILES['caf_file'] ?? null;
        $caf_contents = null;
        $caf_name     = '';

        if ( is_array( $caf_file ) && (int) ( $caf_file['error'] ?? UPLOAD_ERR_NO_FILE ) === UPLOAD_ERR_OK ) {
            $tmp_name = (string) ( $caf_file['tmp_name'] ?? '' );
            if ( '' === $tmp_name || ! file_exists( $tmp_name ) ) {
                \wp_send_json_error( array( 'message' => \__( 'No se pudo leer el archivo CAF subido.', 'sii-boleta-dte' ) ) );
            }
            $caf_name = isset( $caf_file['name'] ) ? (string) $caf_file['name'] : 'caf.xml';
            if ( function_exists( 'sanitize_file_name' ) ) {
                $caf_name = sanitize_file_name( $caf_name );
            }
            $ext = strtolower( pathinfo( $caf_name, PATHINFO_EXTENSION ) );
            if ( ! in_array( $ext, array( 'xml', 'caf' ), true ) ) {
                \wp_send_json_error( array( 'message' => \__( 'El CAF debe ser un archivo .xml o .caf.', 'sii-boleta-dte' ) ) );
            }
            $contents = file_get_contents( $tmp_name );
            if ( false === $contents ) {
                \wp_send_json_error( array( 'message' => \__( 'No se pudo leer el archivo CAF subido.', 'sii-boleta-dte' ) ) );
            }

            try {
                $app     = Application::getInstance();
                $loader  = $app->getPackageRegistry()->getBillingPackage()->getIdentifierComponent()->getCafLoaderWorker();
                $cafBag  = $loader->load( $contents );
                $caf     = $cafBag->getCaf();
                $tipo    = (int) $caf->getTipoDocumento();
                $start   = (int) $caf->getFolioDesde();
                $quantity = (int) $caf->getCantidadFolios();
                $caf_contents = $caf->getXml();
            } catch ( \Throwable $e ) {
                \wp_send_json_error( array( 'message' => \__( 'El archivo CAF no es válido.', 'sii-boleta-dte' ) ) );
            }
        }

        $allowed = array( 33, 34, 39, 41, 52, 56, 61 );
        if ( ! in_array( $tipo, $allowed, true ) ) {
            \wp_send_json_error( array( 'message' => \__( 'Tipo de documento inválido.', 'sii-boleta-dte' ) ) );
        }

        if ( $start <= 0 || $quantity <= 0 ) {
            \wp_send_json_error( array( 'message' => \__( 'Debes ingresar valores positivos para el folio inicial y la cantidad.', 'sii-boleta-dte' ) ) );
        }

        $hasta = $start + $quantity;
        if ( $hasta <= $start ) {
            \wp_send_json_error( array( 'message' => \__( 'El rango de folios es inválido.', 'sii-boleta-dte' ) ) );
        }

        $settings    = $this->core->get_settings();
        $environment = method_exists( $settings, 'get_environment' ) ? $settings->get_environment() : '0';

        if ( FoliosDb::overlaps( $tipo, $start, $hasta, $id, $environment ) ) {
            \wp_send_json_error( array( 'message' => \__( 'El rango se solapa con otro existente para este tipo de documento.', 'sii-boleta-dte' ) ) );
        }

        if ( $id > 0 ) {
            $existing = FoliosDb::get( $id );
            if ( ! $existing ) {
                \wp_send_json_error( array( 'message' => \__( 'El rango seleccionado no existe.', 'sii-boleta-dte' ) ) );
            }
            if ( ! FoliosDb::update( $id, $tipo, $start, $hasta, $environment ) ) {
                $message = \__( 'No se pudo actualizar el rango de folios en la base de datos.', 'sii-boleta-dte' );
                $db_error = FoliosDb::last_error();
                if ( '' !== $db_error ) {
                    $message .= ' ' . sprintf( \__( 'Error de base de datos: %s', 'sii-boleta-dte' ), $db_error );
                }
                \wp_send_json_error( array( 'message' => $message ) );
            }
        } else {
            $id = FoliosDb::insert( $tipo, $start, $hasta, $environment );
            if ( $id <= 0 ) {
                $message = \__( 'No se pudo guardar el rango de folios en la base de datos.', 'sii-boleta-dte' );
                $db_error = FoliosDb::last_error();
                if ( '' !== $db_error ) {
                    $message .= ' ' . sprintf( \__( 'Error de base de datos: %s', 'sii-boleta-dte' ), $db_error );
                }
                \wp_send_json_error( array( 'message' => $message ) );
            }
        }

        if ( null !== $caf_contents ) {
            if ( ! FoliosDb::store_caf( $id, $caf_contents, $caf_name ) ) {
                $message = \__( 'No se pudo guardar el archivo CAF. Revisa los permisos de la base de datos.', 'sii-boleta-dte' );
                $db_error = FoliosDb::last_error();
                if ( '' !== $db_error ) {
                    $message .= ' ' . sprintf( \__( 'Error de base de datos: %s', 'sii-boleta-dte' ), $db_error );
                }
                \wp_send_json_error( array( 'message' => $message ) );
            }
        }

        $this->clamp_last_folio( $tipo, $environment );
        \wp_send_json_success( array( 'id' => $id ) );
    }

    public function delete_folio_range(): void {
        \check_ajax_referer( 'sii_boleta_caf', 'nonce' );
        if ( ! \current_user_can( 'manage_options' ) ) {
            \wp_send_json_error( array( 'message' => \__( 'Permisos insuficientes.', 'sii-boleta-dte' ) ) );
        }

        $id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
        if ( $id <= 0 ) {
            \wp_send_json_error( array( 'message' => \__( 'Identificador de rango inválido.', 'sii-boleta-dte' ) ) );
        }

        $range = FoliosDb::get( $id );
        if ( ! $range ) {
            \wp_send_json_error( array( 'message' => \__( 'El rango indicado no existe.', 'sii-boleta-dte' ) ) );
        }

        if ( ! FoliosDb::delete( $id ) ) {
            \wp_send_json_error( array( 'message' => \__( 'No se pudo eliminar el rango de folios de la base de datos.', 'sii-boleta-dte' ) ) );
        }
        $settings   = $this->core->get_settings();
        $range_env  = isset( $range['environment'] ) ? (string) $range['environment'] : ( method_exists( $settings, 'get_environment' ) ? $settings->get_environment() : '0' );
        $this->clamp_last_folio( (int) $range['tipo'], $range_env );
        \wp_send_json_success();
    }

    private function clamp_last_folio( int $tipo, string $environment ): void {
        $last = Settings::get_last_folio_value( $tipo, $environment );
        if ( $last <= 0 ) {
            return;
        }
        $max = 0;
        foreach ( FoliosDb::for_type( $tipo, $environment ) as $row ) {
            $range_max = (int) $row['hasta'] - 1;
            if ( $range_max > $max ) {
                $max = $range_max;
            }
        }
        if ( $max <= 0 ) {
            Settings::update_last_folio_value( $tipo, $environment, 0 );
        } elseif ( $last > $max ) {
            Settings::update_last_folio_value( $tipo, $environment, $max );
        }
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
		$ids = array();
		// Buscar por nombre/contenido
		$args_name = array(
			'post_type'      => array( 'product', 'product_variation' ),
			's'              => $q,
			'posts_per_page' => 20,
			'post_status'    => 'publish',
			'fields'         => 'ids',
		);
		$ids  = array_merge( $ids, (array) \get_posts( $args_name ) );
		// Buscar por SKU (meta _sku)
		if ( '' !== $q ) {
			$args_sku = array(
				'post_type'      => array( 'product', 'product_variation' ),
				'posts_per_page' => 20,
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'     => '_sku',
						'value'   => $q,
						'compare' => 'LIKE',
					),
				),
			);
			$ids = array_merge( $ids, (array) \get_posts( $args_sku ) );
		}
		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
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

        public function generate_preview(): void {
                if ( ! function_exists( 'check_ajax_referer' ) ) {
                        return;
                }
                \check_ajax_referer( 'sii_boleta_generate_dte', 'sii_boleta_generate_dte_nonce' );
                if ( ! \current_user_can( 'manage_options' ) ) {
                        \wp_send_json_error( array( 'message' => \__( 'Permisos insuficientes.', 'sii-boleta-dte' ) ) );
                }
                $post = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing
                $post['preview'] = '1';
                /** @var GenerateDtePage $page */
                $page = Container::get( GenerateDtePage::class );
                $result = $page->process_post( $post );
                if ( isset( $result['error'] ) && $result['error'] ) {
                        $message = is_string( $result['error'] ) ? $result['error'] : \__( 'Could not generate preview. Please try again.', 'sii-boleta-dte' );
                        \wp_send_json_error( array( 'message' => $message ) );
                }
                $url = (string) ( $result['pdf_url'] ?? '' );
                if ( '' === $url ) {
                        \wp_send_json_error( array( 'message' => \__( 'Could not generate preview. Please try again.', 'sii-boleta-dte' ) ) );
                }
                \wp_send_json_success(
                        array(
                                'url'     => $url,
                                'message' => \__( 'Preview generated. Review the document below.', 'sii-boleta-dte' ),
                        )
                );
        }

	public function send_document(): void {
		if ( ! function_exists( 'check_ajax_referer' ) ) {
			return;
		}
		\check_ajax_referer( 'sii_boleta_generate_dte', 'sii_boleta_generate_dte_nonce' );
		if ( ! \current_user_can( 'manage_options' ) ) {
			\wp_send_json_error( array( 'message' => \__( 'Permisos insuficientes.', 'sii-boleta-dte' ) ) );
		}
		$post = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		/** @var GenerateDtePage $page */
		$page   = Container::get( GenerateDtePage::class );
                $result = $page->process_post( $post );

                if ( isset( $result['error'] ) && $result['error'] ) {
                        $message = is_string( $result['error'] ) ? $result['error'] : \__( 'Could not send the document. Please try again.', 'sii-boleta-dte' );
                        \wp_send_json_error( array( 'message' => $message ) );
                }

                if ( ! empty( $result['queued'] ) ) {
                        $queue_message = isset( $result['message'] ) && is_string( $result['message'] )
                                ? $result['message']
                                : \__( 'El SII no respondió. El documento fue puesto en cola para un reintento automático.', 'sii-boleta-dte' );
                        $pdf_url      = (string) ( $result['pdf_url'] ?? '' );
                        $notice_type  = isset( $result['notice_type'] ) && is_string( $result['notice_type'] ) ? $result['notice_type'] : 'warning';
                        \wp_send_json_success(
                                array(
                                        'queued'      => true,
                                        'pdf_url'     => $pdf_url,
                                        'message'     => $queue_message,
                                        'notice_type' => $notice_type,
                                )
                        );
                }

                $track_id = (string) ( $result['track_id'] ?? '' );
                if ( '' === $track_id ) {
                        \wp_send_json_error( array( 'message' => \__( 'Could not send the document. Please try again.', 'sii-boleta-dte' ) ) );
                }

                $pdf_url = (string) ( $result['pdf_url'] ?? '' );

                \wp_send_json_success(
                        array(
                                'track_id' => $track_id,
                                'pdf_url'  => $pdf_url,
                                'message'  => sprintf( \__( 'Document sent to SII. Tracking ID: %s.', 'sii-boleta-dte' ), $track_id ),
                                'notice_type' => isset( $result['notice_type'] ) && is_string( $result['notice_type'] ) ? $result['notice_type'] : 'success',
                        )
                );
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

    /**
     * Streams a generated PDF stored in the secure directory.
     * Accepts GET params: order_id, key, nonce, type.
     */
    public function view_pdf(): void {
        $is_preview = isset( $_GET['preview'] ) && '1' === (string) $_GET['preview']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $is_manual  = isset( $_GET['manual'] ) && '1' === (string) $_GET['manual']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        if ( $is_preview ) {
            $preview_key = isset( $_GET['key'] ) ? sanitize_file_name( (string) $_GET['key'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            if ( '' === $preview_key ) {
                $this->terminate_request( 404 );
            }

            if ( ! function_exists( 'current_user_can' ) || ! \current_user_can( 'manage_options' ) ) {
                $this->terminate_request( 403 );
            }

            $preview_file = GenerateDtePage::resolve_preview_path( $preview_key );
            if ( ! is_string( $preview_file ) || '' === $preview_file || ! file_exists( $preview_file ) ) {
                $this->terminate_request( 404 );
            }

            if ( function_exists( 'nocache_headers' ) ) {
                \nocache_headers();
            }

            header( 'Content-Type: application/pdf' );
            header( 'Content-Disposition: inline; filename="' . basename( $preview_file ) . '"' );
            header( 'Content-Length: ' . filesize( $preview_file ) );
            @readfile( $preview_file );

            if ( defined( 'SII_BOLETA_DTE_TESTING' ) && SII_BOLETA_DTE_TESTING ) {
                return;
            }

            exit;
        }

        if ( $is_manual ) {
            $key = isset( $_GET['key'] )
                ? strtolower( preg_replace( '/[^a-f0-9]/', '', (string) $_GET['key'] ) )
                : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $token = isset( $_GET['token'] )
                ? strtolower( preg_replace( '/[^a-f0-9]/', '', (string) $_GET['token'] ) )
                : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

            if ( '' === $key || '' === $token ) {
                $this->terminate_request( 403 );
            }

            if ( function_exists( 'wp_verify_nonce' ) ) {
                $nonce_value = isset( $_GET['_wpnonce'] ) ? (string) $_GET['_wpnonce'] : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                if ( '' === $nonce_value || ! \wp_verify_nonce( $nonce_value, 'sii_boleta_nonce' ) ) {
                    $this->terminate_request( 403 );
                }
            }

            if ( ! function_exists( 'current_user_can' ) || ! \current_user_can( 'manage_options' ) ) {
                $this->terminate_request( 403 );
            }

            $entry = GenerateDtePage::resolve_manual_pdf( $key );
            if ( null === $entry ) {
                $this->terminate_request( 404 );
            }

            $stored_token = isset( $entry['token'] ) ? strtolower( (string) $entry['token'] ) : '';
            if ( '' === $stored_token || ! hash_equals( $stored_token, $token ) ) {
                $this->terminate_request( 403 );
            }

            $file     = isset( $entry['path'] ) ? (string) $entry['path'] : '';
            $filename = isset( $entry['filename'] ) ? (string) $entry['filename'] : basename( $file );

            if ( '' === $file || ! file_exists( $file ) ) {
                GenerateDtePage::clear_manual_pdf( $key );
                $this->terminate_request( 404 );
            }

            if ( function_exists( 'nocache_headers' ) ) {
                \nocache_headers();
            }

            header( 'Content-Type: application/pdf' );
            header( 'Content-Disposition: inline; filename="' . basename( $filename ) . '"' );
            header( 'Content-Length: ' . filesize( $file ) );
            @readfile( $file );

            if ( defined( 'SII_BOLETA_DTE_TESTING' ) && SII_BOLETA_DTE_TESTING ) {
                return;
            }

            exit;
        }

        $order_id = isset( $_GET['order_id'] ) ? (int) $_GET['order_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $key      = isset( $_GET['key'] ) ? strtolower( preg_replace( '/[^a-f0-9]/', '', (string) $_GET['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $nonce    = isset( $_GET['nonce'] ) ? strtolower( preg_replace( '/[^a-f0-9]/', '', (string) $_GET['nonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $type     = isset( $_GET['type'] ) ? strtolower( preg_replace( '/[^a-z0-9_]/', '', (string) $_GET['type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        if ( $order_id <= 0 || '' === $key || '' === $nonce || '' === $type ) {
            $this->terminate_request( 400 );
        }

        if ( ! $this->user_can_view_pdf( $order_id ) ) {
            $this->terminate_request( 403 );
        }

        $stored_key   = strtolower( $this->get_order_meta( $order_id, $type . '_pdf_key' ) );
        $stored_nonce = strtolower( $this->get_order_meta( $order_id, $type . '_pdf_nonce' ) );

        if ( $key !== $stored_key || $nonce !== $stored_nonce ) {
            $this->terminate_request( 403 );
        }

        $file = \Sii\BoletaDte\Infrastructure\WooCommerce\PdfStorage::resolve_path( $stored_key );
        if ( '' === $file || ! file_exists( $file ) ) {
            $this->terminate_request( 404 );
        }

        if ( function_exists( 'nocache_headers' ) ) {
            \nocache_headers();
        }

        header( 'Content-Type: application/pdf' );
        header( 'Content-Disposition: inline; filename="' . basename( $file ) . '"' );
        header( 'Content-Length: ' . filesize( $file ) );
        @readfile( $file );

        if ( defined( 'SII_BOLETA_DTE_TESTING' ) && SII_BOLETA_DTE_TESTING ) {
            return;
        }

        exit;
    }

    private function terminate_request( int $status ): void {
        if ( function_exists( 'status_header' ) ) {
            \status_header( $status );
        }

        if ( defined( 'SII_BOLETA_DTE_TESTING' ) && SII_BOLETA_DTE_TESTING ) {
            throw new \RuntimeException( 'terminated:' . $status );
        }

        exit;
    }

    private function user_can_view_pdf( int $order_id ): bool {
        if ( function_exists( 'current_user_can' ) && \current_user_can( 'manage_options' ) ) {
            return true;
        }

        if ( ! function_exists( 'is_user_logged_in' ) || ! \is_user_logged_in() ) {
            return false;
        }

        if ( ! function_exists( 'get_current_user_id' ) ) {
            return false;
        }

        if ( ! function_exists( 'wc_get_order' ) ) {
            return false;
        }

        $order = \wc_get_order( $order_id );
        if ( ! $order || ! method_exists( $order, 'get_user_id' ) ) {
            return false;
        }

        $user_id = (int) $order->get_user_id();
        if ( $user_id <= 0 ) {
            return false;
        }

        return \get_current_user_id() === $user_id;
    }

    private function get_order_meta( int $order_id, string $meta_key ): string {
        if ( $order_id <= 0 || '' === $meta_key ) {
            return '';
        }

        if ( function_exists( 'get_post_meta' ) ) {
            $value = \get_post_meta( $order_id, $meta_key, true );
            if ( is_scalar( $value ) ) {
                return (string) $value;
            }
        }

        if ( isset( $GLOBALS['meta'][ $order_id ][ $meta_key ] ) ) {
            return (string) $GLOBALS['meta'][ $order_id ][ $meta_key ];
        }

        return '';
    }
}

class_alias( Ajax::class, 'Sii\\BoletaDte\\Admin\\Ajax' );
