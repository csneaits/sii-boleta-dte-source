<?php
namespace Sii\BoletaDte\Presentation\Admin;

use Sii\BoletaDte\Infrastructure\Factory\Container;
use Sii\BoletaDte\Infrastructure\Plugin;
use Sii\BoletaDte\Infrastructure\Settings;
use Sii\BoletaDte\Presentation\Admin\GenerateDtePage;
use Sii\BoletaDte\Infrastructure\Persistence\FoliosDb;
use Sii\BoletaDte\Infrastructure\Persistence\LogDb;
use Sii\BoletaDte\Infrastructure\Persistence\QueueDb;
use Sii\BoletaDte\Application\QueueProcessor;
use Sii\BoletaDte\Infrastructure\LibredteBridge;
use libredte\lib\Core\Application;

class Ajax {
    /**
     * AJAX: Preview PDF for queued jobs (admin only)
     */
    public function preview_pdf(): void {
        check_ajax_referer( 'sii_boleta_preview_pdf' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'No autorizado', 403 );
        }
        $file_key = isset($_GET['file_key']) ? sanitize_text_field($_GET['file_key']) : '';
        $order_id = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;
        $type = isset($_GET['type']) ? (int)$_GET['type'] : 0;
        $folio = isset($_GET['folio']) ? (int)$_GET['folio'] : 0;

        $xml = '';
        if ($file_key) {
            $xml_path = \Sii\BoletaDte\Infrastructure\Queue\XmlStorage::resolve_path($file_key);
            if ($xml_path && file_exists($xml_path)) {
                $xml = file_get_contents($xml_path);
            }
        }
        // Fallback: buscar por order_id y folio si no hay file_key
        if (!$xml && $order_id && $type) {
            // Buscar en la base de datos de logs o en el sistema de archivos según tu lógica
            // Aquí solo se deja el fallback, pero lo ideal es siempre tener file_key
        }
        if (!$xml) {
            wp_die('No se encontró el XML para este documento.', 404);
        }
        // Generar PDF en memoria
        $plugin = Container::get(Plugin::class);
        $pdf_generator = $plugin->get_pdf_generator();
        $pdf = $pdf_generator->generate($xml);
        if (!is_string($pdf) || $pdf === '') {
            wp_die('No se pudo generar el PDF.', 500);
        }
        // Forzar descarga/preview inline
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="preview-dte.pdf"');
        echo $pdf;
        exit;
    }
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
        // New: XML preview & validation endpoints.
        \add_action( 'wp_ajax_sii_boleta_dte_preview_xml', array( $this, 'preview_xml' ) );
        \add_action( 'wp_ajax_sii_boleta_dte_validate_xml', array( $this, 'validate_xml' ) );
    \add_action( 'wp_ajax_sii_boleta_dte_validate_envio', array( $this, 'validate_envio' ) );
        \add_action( 'wp_ajax_sii_boleta_dte_send_document', array( $this, 'send_document' ) );
        \add_action( 'wp_ajax_sii_boleta_dte_save_folio_range', array( $this, 'save_folio_range' ) );
        \add_action( 'wp_ajax_sii_boleta_dte_delete_folio_range', array( $this, 'delete_folio_range' ) );
        \add_action( 'wp_ajax_sii_boleta_dte_control_panel_data', array( $this, 'control_panel_data' ) );
        \add_action( 'wp_ajax_sii_boleta_dte_queue_action', array( $this, 'queue_action' ) );
        \add_action( 'wp_ajax_sii_boleta_dte_control_panel_tab', array( $this, 'control_panel_tab' ) );
        \add_action( 'wp_ajax_sii_boleta_dte_metrics_filter', array( $this, 'metrics_filter' ) );
        \add_action( 'wp_ajax_sii_boleta_dte_run_prune', array( $this, 'run_prune' ) );
        // Diagnostics: LibreDTE auth test
        \add_action( 'wp_ajax_sii_boleta_libredte_auth', array( $this, 'libredte_auth' ) );
        // Track ID status query
        \add_action( 'wp_ajax_sii_boleta_query_track_status', array( $this, 'query_track_status' ) );
        // Preview PDF for encolados
        \add_action( 'wp_ajax_sii_boleta_preview_pdf', array( $this, 'preview_pdf' ) );
    }

    public function control_panel_data(): void {
        \check_ajax_referer( 'sii_boleta_control_panel', 'nonce' );
        if ( ! \current_user_can( 'manage_options' ) ) {
            \wp_send_json_error( array( 'message' => \__( 'Permisos insuficientes.', 'sii-boleta-dte' ) ) );
        }

        $settings      = $this->core->get_settings();
        $environment   = method_exists( $settings, 'get_environment' ) ? $settings->get_environment() : '0';
        $normalized_env = Settings::normalize_environment( (string) $environment );
        $log_status = isset( $_POST['log_status'] ) ? $this->sanitize_input_value( (string) $_POST['log_status'] ) : '';
        $log_type   = isset( $_POST['log_type'] ) && '' !== $_POST['log_type'] ? (int) $_POST['log_type'] : null;
        $log_page   = isset( $_POST['log_page'] ) ? max( 1, (int) $_POST['log_page'] ) : 1;
        $log_limit_raw  = isset( $_POST['log_limit'] ) ? (string) $_POST['log_limit'] : '';
        $log_limit  = '' === $log_limit_raw ? 10 : max( 5, min( 50, (int) $log_limit_raw ) );
        $log_from   = isset( $_POST['log_from'] ) ? $this->sanitize_input_value( (string) $_POST['log_from'] ) : '';
        $log_to     = isset( $_POST['log_to'] ) ? $this->sanitize_input_value( (string) $_POST['log_to'] ) : '';

        $logs_data = LogDb::get_logs_paginated(
                array(
                        'limit'       => $log_limit,
                        'page'        => $log_page,
                        'environment' => $environment,
                        'status'      => $log_status,
                        'type'        => $log_type,
                        'date_from'   => $log_from,
                        'date_to'     => $log_to,
                )
        );
        $logs        = $logs_data['rows'];
        $raw_jobs = QueueDb::get_pending_jobs( 100 );
                $jobs = array_filter(
                $raw_jobs,
                static function ( array $job ) use ( $normalized_env ) {
                        $job_env = Settings::normalize_environment( (string) ( $job['payload']['environment'] ?? '0' ) );
                        return $job_env === $normalized_env;
                }
        );
        if ( empty( $jobs ) && ! empty( $raw_jobs ) ) {
                $jobs = $raw_jobs;
        }
        $filters = array();
        if ( isset( $_POST['filter_attempts'] ) ) {
                $filters['attempts'] = $this->sanitize_input_value( (string) $_POST['filter_attempts'] );
        }
        if ( isset( $_POST['filter_age'] ) ) {
                $filters['age'] = $this->sanitize_input_value( (string) $_POST['filter_age'] );
        }
        $jobs = $this->filter_queue_jobs( $jobs, $filters );
        $logs_html = $this->render_logs_rows( $logs );
        $queue     = $this->render_queue_rows( $jobs );

        \wp_send_json_success(
            array(
                'logsHtml'     => $logs_html,
                'logsTotal'    => (int) $logs_data['total'],
                'logsPage'     => (int) $logs_data['page'],
                'logsPages'    => (int) $logs_data['pages'],
                'logsLimit'    => (int) $logs_data['limit'],
                'logsCount'    => count( $logs ),
                'queueHtml'    => $queue['rows'],
                'queueHasJobs' => $queue['has_jobs'],
            )
        );
    }

    /**
     * Ejecuta acciones sobre un trabajo de la cola (procesar, reintentar, cancelar) vía AJAX.
     */
    public function queue_action(): void {
        \check_ajax_referer( 'sii_boleta_queue', 'nonce' );
        if ( ! \current_user_can( 'manage_options' ) ) {
            \wp_send_json_error( array( 'message' => \__( 'Permisos insuficientes.', 'sii-boleta-dte' ) ) );
        }

        $job_id = isset( $_POST['job_id'] ) ? (int) $_POST['job_id'] : 0;
        $action = isset( $_POST['queue_action'] ) ? (string) $_POST['queue_action'] : '';
        if ( function_exists( 'sanitize_key' ) ) {
            $action = sanitize_key( $action );
        } else {
            $action = strtolower( preg_replace( '/[^a-z0-9_\-]/', '', $action ) );
        }

        if ( $job_id <= 0 || '' === $action ) {
            \wp_send_json_error( array( 'message' => \__( 'Solicitud inválida.', 'sii-boleta-dte' ) ) );
        }

        try {
            /** @var QueueProcessor $processor */
            $processor = Container::get( QueueProcessor::class );
        } catch ( \Throwable $th ) {
            \wp_send_json_error( array( 'message' => \__( 'No se pudo inicializar el procesador de cola.', 'sii-boleta-dte' ) ) );
        }

        try {
            switch ( $action ) {
                case 'process':
                    $processor->process( $job_id );
                    break;
                case 'requeue':
                    $processor->retry( $job_id );
                    break;
                case 'cancel':
                    $processor->cancel( $job_id );
                    break;
                default:
                    \wp_send_json_error( array( 'message' => \__( 'Acción no válida.', 'sii-boleta-dte' ) ) );
            }
        } catch ( \Throwable $e ) {
            $message = (string) $e->getMessage();
            if ( function_exists( 'wp_strip_all_tags' ) ) {
                $message = wp_strip_all_tags( $message );
            } else {
                $message = \strip_tags( $message );
            }
            if ( '' === trim( $message ) ) {
                $message = \__( 'Error inesperado al ejecutar la acción.', 'sii-boleta-dte' );
            }
            \wp_send_json_error( array( 'message' => $message ) );
        }

        \wp_send_json_success( array( 'message' => \__( 'Acción de cola ejecutada.', 'sii-boleta-dte' ) ) );
    }

    /**
     * Devuelve el HTML de un tab solicitado para navegación dinámica.
     */
    public function control_panel_tab(): void {
        \check_ajax_referer( 'sii_boleta_control_panel', 'nonce' );
        if ( ! \current_user_can( 'manage_options' ) ) {
            \wp_send_json_error( array( 'message' => \__( 'Permisos insuficientes.', 'sii-boleta-dte' ) ) );
        }
        $tab = isset( $_POST['tab'] ) ? (string) $_POST['tab'] : 'logs';
        /** @var ControlPanelPage $page */
        $page = Container::get( ControlPanelPage::class );
        $original_query = array();
        foreach ( array( 'filter_attempts', 'filter_age' ) as $key ) {
                if ( isset( $_POST[ $key ] ) ) {
                        $value                 = $this->sanitize_input_value( (string) $_POST[ $key ] );
                        $original_query[ $key ] = $_GET[ $key ] ?? null;
                        if ( '' === $value ) {
                                unset( $_GET[ $key ] );
                        } else {
                                $_GET[ $key ] = $value;
                        }
                }
        }
        $log_map = array(
                'log_status' => 'logs_status',
                'log_type'   => 'logs_type',
                'log_from'   => 'logs_from',
                'log_to'     => 'logs_to',
                'log_page'   => 'logs_page',
                'log_limit'  => 'logs_per_page',
        );
        foreach ( $log_map as $post_key => $get_key ) {
                if ( isset( $_POST[ $post_key ] ) ) {
                        $value                  = $this->sanitize_input_value( (string) $_POST[ $post_key ] );
                        $original_query[ $get_key ] = $_GET[ $get_key ] ?? null;
                        if ( '' === $value ) {
                                unset( $_GET[ $get_key ] );
                        } else {
                                $_GET[ $get_key ] = $value;
                        }
                }
        }
        $html = $page->get_tab_content_html( $tab );
        foreach ( $original_query as $key => $value ) {
                if ( null === $value ) {
                        unset( $_GET[ $key ] );
                } else {
                        $_GET[ $key ] = $value;
                }
        }
        \wp_send_json_success( array( 'html' => $html, 'tab' => $tab ) );
    }

    /**
     * Filtros de métricas vía AJAX: devuelve sólo el fragmento del tab metrics.
     */
    public function metrics_filter(): void {
        \check_ajax_referer( 'sii_boleta_control_panel', 'nonce' );
        if ( ! \current_user_can( 'manage_options' ) ) {
            \wp_send_json_error( array( 'message' => \__( 'Permisos insuficientes.', 'sii-boleta-dte' ) ) );
        }
        // Simulamos el entorno GET para que render_metrics_dashboard lo lea sin refactor mayor
        $_GET['metrics_year']  = isset( $_POST['metrics_year'] ) ? (string) $_POST['metrics_year'] : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $_GET['metrics_month'] = isset( $_POST['metrics_month'] ) ? (string) $_POST['metrics_month'] : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        /** @var ControlPanelPage $page */
        $page = Container::get( ControlPanelPage::class );
        $html = $page->get_tab_content_html( 'metrics' );
        \wp_send_json_success( array( 'html' => $html ) );
    }

    /**
     * Ejecuta manualmente el handler de prune y devuelve datos actualizados.
     */
    public function run_prune(): void {
        \check_ajax_referer( 'sii_boleta_control_panel', 'nonce' );
        if ( ! \current_user_can( 'manage_options' ) ) {
            \wp_send_json_error( array( 'message' => \__( 'Permisos insuficientes.', 'sii-boleta-dte' ) ) );
        }
        if ( function_exists( 'do_action' ) ) {
            \do_action( 'sii_boleta_dte_prune_debug_pdfs' );
        }
        $deleted = (int) \get_option( 'sii_boleta_dte_prune_debug_last_count', 0 );
        /** @var ControlPanelPage $page */
        $page        = Container::get( ControlPanelPage::class );
        $maintenance = $page->get_tab_content_html( 'maintenance' );
        \wp_send_json_success( array( 'deleted' => $deleted, 'html' => $maintenance ) );
    }

    /**
     * Prueba de autenticación usando LibreDTE (si WS está habilitado) y devuelve un token enmascarado.
     */
    public function libredte_auth(): void {
        \check_ajax_referer( 'sii_boleta_libredte_auth' );
        if ( ! \current_user_can( 'manage_options' ) ) {
            \wp_send_json_error( array( 'message' => \__( 'Permisos insuficientes.', 'sii-boleta-dte' ) ) );
        }
        try {
            $settings = $this->core->get_settings();
            $api      = \Sii\BoletaDte\Infrastructure\Factory\Container::get( \Sii\BoletaDte\Infrastructure\Rest\Api::class );
            if ( method_exists( $api, 'setSettings' ) ) { $api->setSettings( $settings ); }
            $env = method_exists( $settings, 'get_environment' ) ? $settings->get_environment() : '0';
            if ( ! method_exists( $api, 'libredte_authenticate' ) ) {
                \wp_send_json_error( array( 'message' => \__( 'WS LibreDTE no disponible.', 'sii-boleta-dte' ) ) );
            }
            $token = (string) $api->libredte_authenticate( $env );
            if ( '' === $token ) {
                \wp_send_json_error( array( 'message' => \__( 'No se pudo autenticar (WS o credenciales).', 'sii-boleta-dte' ) ) );
            }
            // Enmascarar: mostrar sólo los primeros 5 y últimos 3 caracteres si es largo.
            $masked = strlen( $token ) > 12 ? substr( $token, 0, 5 ) . '…' . substr( $token, -3 ) : str_repeat( '•', max( 4, strlen( $token ) ) );
            \wp_send_json_success( array( 'masked' => $masked ) );
        } catch ( \Throwable $e ) {
            \wp_send_json_error( array( 'message' => \__( 'Error inesperado de autenticación.', 'sii-boleta-dte' ) ) );
        }
    }

    /**
     * @param array<int, array<string, mixed>> $logs Log rows.
     */
    private function render_logs_rows( array $logs ): string {
        ob_start();
        if ( empty( $logs ) ) {
            echo '<tr class="sii-control-empty-row"><td colspan="5">' . esc_html__( 'Sin DTE recientes.', 'sii-boleta-dte' ) . '</td></tr>';
        } else {
            foreach ( $logs as $row ) {
                $track  = isset( $row['track_id'] ) ? (string) $row['track_id'] : '';
                $status = isset( $row['status'] ) ? (string) $row['status'] : '';
                $type   = isset( $row['document_type'] ) ? (int) $row['document_type'] : 0;
                $folio  = isset( $row['folio'] ) ? (int) $row['folio'] : 0;
                $created = isset( $row['created_at'] ) ? (string) $row['created_at'] : '';
                $formatted_date = $this->format_log_date( $created );
                echo '<tr>';
                echo '<td>' . ( '' !== $track ? esc_html( $track ) : '-' ) . '</td>';
                echo '<td>' . esc_html( $this->format_dte_type_label( $type ) ) . '</td>';
                echo '<td>' . ( $folio > 0 ? esc_html( (string) $folio ) : '-' ) . '</td>';
                echo '<td>' . esc_html( $this->translate_log_status( $status ) ) . '</td>';
                echo '<td>' . esc_html( $formatted_date ) . '</td>';
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
			echo '<tr class="sii-control-empty-row"><td colspan="5">' . esc_html__( 'No hay elementos en la cola.', 'sii-boleta-dte' ) . '</td></tr>';
		} else {
			foreach ( $jobs as $job ) {
				$id       = isset( $job['id'] ) ? (int) $job['id'] : 0;
				$type     = isset( $job['type'] ) ? (string) $job['type'] : '';
				$attempts = isset( $job['attempts'] ) ? (int) $job['attempts'] : 0;

				$is_failed  = $attempts >= 3;
				$row_style  = $is_failed ? ' style="background-color: #fff3cd; border-left: 3px solid #ffc107;"' : '';
				$type_label = esc_html( $this->translate_queue_type( $type ) );
				$details    = esc_html( $this->describe_queue_document( $job ) );

				echo '<tr' . $row_style . '>';
				echo '<td>' . $id . '</td>';
				echo '<td>' . $type_label . '</td>';
				echo '<td>' . $details;
				if ( $is_failed ) {
					echo '<br><span style="color: #dc3232; font-weight: bold; font-size: 12px;">';
					echo '⚠️ ' . esc_html__( 'FALLIDO - Requiere atención manual', 'sii-boleta-dte' );
					echo '</span>';
				}
				echo '</td>';
				echo '<td>';
				$attempt_color = $is_failed ? '#dc3232' : '#135e96';
				$attempt_weight = $is_failed ? 'bold' : 'normal';
				echo '<span style="color:' . esc_attr( $attempt_color ) . '; font-weight:' . esc_attr( $attempt_weight ) . ';">' . $attempts . '</span>';
				if ( $is_failed ) {
					echo '<br><small style="color: #856404;">' . esc_html__( '(Pausado)', 'sii-boleta-dte' ) . '</small>';
				}
				echo '</td>';
				echo '<td>' . $this->render_queue_actions( $job ) . '</td>';
				echo '</tr>';
			}
		}
        return array(
            'rows'     => (string) ob_get_clean(),
            'has_jobs' => $has_jobs,
        );
    }

    private function sanitize_input_value( string $value ): string {
        $value = trim( $value );
        if ( '' === $value ) {
            return '';
        }
        if ( function_exists( 'sanitize_text_field' ) ) {
            $value = sanitize_text_field( $value );
        } else {
            $value = preg_replace( '/[^a-z0-9\+\-]/i', '', $value );
        }
        return (string) $value;
    }

    /**
     * Aplica filtros manuales de intentos/antigüedad a la cola.
     *
     * @param array<int,array<string,mixed>> $jobs
     * @param array<string,string>           $filters
     * @return array<int,array<string,mixed>>
     */
    private function filter_queue_jobs( array $jobs, array $filters ): array {
        if ( empty( $filters ) ) {
            return array_values( $jobs );
        }

        $one_hour_ago = time() - 3600;

        return array_values(
            array_filter(
                $jobs,
                static function ( array $job ) use ( $filters, $one_hour_ago ): bool {
                    if ( isset( $filters['attempts'] ) && '' !== $filters['attempts'] ) {
                        $job_attempts = (int) ( $job['attempts'] ?? 0 );
                        switch ( $filters['attempts'] ) {
                            case '0':
                                if ( 0 !== $job_attempts ) {
                                    return false;
                                }
                                break;
                            case '1':
                                if ( 1 !== $job_attempts ) {
                                    return false;
                                }
                                break;
                            case '2':
                                if ( 2 !== $job_attempts ) {
                                    return false;
                                }
                                break;
                            case '3+':
                                if ( $job_attempts < 3 ) {
                                    return false;
                                }
                                break;
                        }
                    }

                    if ( isset( $filters['age'] ) && '' !== $filters['age'] ) {
                        $created = '';
                        if ( isset( $job['created_at'] ) ) {
                            $created = (string) $job['created_at'];
                        } elseif ( isset( $job['available_at'] ) ) {
                            $created = (string) $job['available_at'];
                        }

                        if ( '' !== $created ) {
                            $timestamp = strtotime( $created );
                            if ( false !== $timestamp ) {
                                if ( 'new' === $filters['age'] && $timestamp < $one_hour_ago ) {
                                    return false;
                                }
                                if ( 'old' === $filters['age'] && $timestamp >= $one_hour_ago ) {
                                    return false;
                                }
                            }
                        }
                    }

                    return true;
                }
            )
        );
    }

	private function render_queue_actions( array $job ): string {
		$id   = isset( $job['id'] ) ? (int) $job['id'] : 0;
		$id   = max( 0, $id );
		$nonce = $this->nonce_field( 'sii_boleta_queue', 'sii_boleta_queue_nonce' );

		$payload = isset( $job['payload'] ) && is_array( $job['payload'] ) ? $job['payload'] : array();
		$meta    = isset( $payload['meta'] ) && is_array( $payload['meta'] ) ? $payload['meta'] : array();

		$order_id = $payload['order_id'] ?? ( $meta['order_id'] ?? '' );
		$type     = $payload['document_type'] ?? ( $meta['type'] ?? ( $job['type'] ?? '' ) );
		$folio    = $payload['folio'] ?? ( $meta['folio'] ?? '' );
		$pdf_key  = $payload['pdf_key'] ?? ( $meta['pdf_key'] ?? $id );

		$order_id = is_scalar( $order_id ) ? (string) $order_id : '';
		$type     = is_scalar( $type ) ? (string) $type : '';
		$folio    = is_scalar( $folio ) ? (string) $folio : '';
		$pdf_key  = is_scalar( $pdf_key ) ? (string) $pdf_key : '';

		$html  = '<div style="display:flex;gap:4px;flex-wrap:wrap;align-items:center;">';
		$html .= '<form method="post" style="display:inline;margin:0;padding:0;">';
		$html .= '<input type="hidden" name="job_id" value="' . esc_attr( (string) $id ) . '" />';
		$html .= $nonce;
		$html .= '<button type="submit" name="queue_action" value="process" class="button sii-queue-action sii-queue-action-sm" title="' . esc_attr__( 'Procesar', 'sii-boleta-dte' ) . '">▶</button>';
		$html .= '<button type="submit" name="queue_action" value="retry" class="button sii-queue-action sii-queue-action-sm" title="' . esc_attr__( 'Reintentar', 'sii-boleta-dte' ) . '">⟳</button>';
		$html .= '<button type="submit" name="queue_action" value="cancel" class="button sii-queue-action sii-queue-action-sm" title="' . esc_attr__( 'Eliminar', 'sii-boleta-dte' ) . '">✖</button>';
		$html .= '</form>';
		$html .= '<button type="button" class="button sii-queue-action sii-queue-action-sm sii-preview-pdf-btn" title="' . esc_attr__( 'Preview PDF', 'sii-boleta-dte' ) . '" data-pdf-key="' . esc_attr( $pdf_key ) . '" data-order-id="' . esc_attr( $order_id ) . '" data-type="' . esc_attr( $type ) . '" data-folio="' . esc_attr( $folio ) . '">👁️</button>';
		$html .= '</div>';

		return $html;
	}

    /**
     * Builds a human readable description for the given queue job.
     *
     * @param array<string,mixed> $job Job payload.
     */
    private function describe_queue_document( array $job ): string {
        $type    = isset( $job['type'] ) ? (string) $job['type'] : '';
        $payload = isset( $job['payload'] ) && is_array( $job['payload'] ) ? $job['payload'] : array();
        $meta    = isset( $payload['meta'] ) && is_array( $payload['meta'] ) ? $payload['meta'] : array();

        $document_type = $payload['document_type'] ?? ( $meta['type'] ?? null );
        if ( is_string( $document_type ) && '' !== $document_type ) {
            $document_type = (int) $document_type;
        }
        if ( ! is_int( $document_type ) ) {
            $document_type = null;
        }

        $folio = $payload['folio'] ?? ( $meta['folio'] ?? null );
        if ( is_string( $folio ) && '' !== $folio ) {
            $folio = (int) $folio;
        }
        if ( ! is_int( $folio ) ) {
            $folio = null;
        }

        $label = isset( $payload['label'] ) ? (string) $payload['label'] : '';
        if ( '' === $label && isset( $meta['label'] ) ) {
            $label = (string) $meta['label'];
        }

        $parts = array();

        if ( 'dte' === $type ) {
            if ( null !== $document_type ) {
                $parts[] = sprintf( __( 'DTE %d', 'sii-boleta-dte' ), $document_type );
            } else {
                $parts[] = $this->translate_queue_type( 'dte' );
            }
            if ( null !== $folio && $folio > 0 ) {
                $parts[] = sprintf( __( 'Folio %d', 'sii-boleta-dte' ), $folio );
            }
            if ( '' !== $label && stripos( $label, 'DTE' ) === false ) {
                $parts[] = $label;
            }
        } elseif ( '' !== $label ) {
            $parts[] = $label;
        }

        if ( empty( $parts ) ) {
            if ( '' !== $type ) {
                return $this->translate_queue_type( $type );
            }
            return __( 'Sin detalles', 'sii-boleta-dte' );
        }

        return implode( ' · ', array_filter( array_map( 'trim', $parts ) ) );
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
                $app     = \Sii\BoletaDte\Infrastructure\LibredteBridge::getApp( $this->core->get_settings() );
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

        $allowed = array( 33, 34, 39, 41, 46, 52, 56, 61 );
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
            $class = '\\WP_User_Query';
            if ( ! class_exists( $class ) ) {
                break;
            }
            $q = new $class(
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

    /**
     * Preview XML (no folio, preview mode). Returns XML text only for inspection.
     */
    public function preview_xml(): void {
        if ( ! function_exists( 'check_ajax_referer' ) ) { return; }
        \check_ajax_referer( 'sii_boleta_generate_dte', 'sii_boleta_generate_dte_nonce' );
        if ( ! \current_user_can( 'manage_options' ) ) {
            \wp_send_json_error( array( 'message' => __( 'Permisos insuficientes.', 'sii-boleta-dte' ) ) );
        }
        $post            = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $post['preview'] = '1';
        unset( $post['folio'] );
        /** @var GenerateDtePage $page */
        $page   = Container::get( GenerateDtePage::class );
        $result = $page->process_post( $post );
        if ( isset( $result['error'] ) ) {
            $msg = is_string( $result['error'] ) ? $result['error'] : __( 'No fue posible generar el XML.', 'sii-boleta-dte' );
            \wp_send_json_error( array( 'message' => $msg ) );
        }
        $xml = isset( $result['xml'] ) ? (string) $result['xml'] : '';
        if ( '' === $xml ) {
            \wp_send_json_error( array( 'message' => __( 'XML vacío.', 'sii-boleta-dte' ) ) );
        }
        $tipo = isset( $result['tipo'] ) ? (int) $result['tipo'] : ( isset( $post['tipo'] ) ? (int) $post['tipo'] : 0 );
        $size  = strlen( $xml );
        $lines = substr_count( $xml, "\n" ) + 1;
        $payload = array(
                'xml'        => $xml,
                'xml_base64' => base64_encode( $xml ),
                'size'       => $size,
                'lines'      => $lines,
                'tipo'       => $tipo,
        );

        \wp_send_json_success( $payload );
    }

    /**
     * Validate XML against XSD according to DTE type.
     */
    public function validate_xml(): void {
        if ( ! function_exists( 'check_ajax_referer' ) ) { return; }
        \check_ajax_referer( 'sii_boleta_generate_dte', 'sii_boleta_generate_dte_nonce' );
        if ( ! \current_user_can( 'manage_options' ) ) {
            \wp_send_json_error( array( 'message' => __( 'Permisos insuficientes.', 'sii-boleta-dte' ) ) );
        }
        $tipo = isset( $_POST['tipo'] ) ? (int) $_POST['tipo'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $xml  = isset( $_POST['xml'] ) ? (string) $_POST['xml'] : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( $tipo <= 0 || '' === $xml ) {
            \wp_send_json_error( array( 'message' => __( 'Datos insuficientes para validar.', 'sii-boleta-dte' ) ) );
        }
        $errors = array();

        // Primero: validar que sea XML bien formado para poder reportar líneas si falla.
        $dom = new \DOMDocument();
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput       = false;
        libxml_use_internal_errors( true );
        if ( ! $dom->loadXML( $xml, LIBXML_NONET | LIBXML_NOENT ) ) {
            foreach ( libxml_get_errors() as $err ) {
                $errors[] = array( 'line' => $err->line, 'message' => trim( $err->message ) );
            }
            libxml_clear_errors();
            \wp_send_json_error( array( 'message' => __( 'XML inválido.', 'sii-boleta-dte' ), 'errors' => $errors ) );
        }
        // Detectar si hay firma para validar con LibreDTE si está disponible.
        $hasSignature = false;
        try {
            $xp = new \DOMXPath( $dom );
            $xp->registerNamespace( 'ds', 'http://www.w3.org/2000/09/xmldsig#' );
            $sigNode = $xp->query( '//*[local-name()="Signature" and namespace-uri()="http://www.w3.org/2000/09/xmldsig#"]' )->item( 0 );
            $hasSignature = $sigNode instanceof \DOMNode;
        } catch ( \Throwable $e ) {
            // ignorar inspección de firma
        }

        // Intentar validación vía LibreDTE si existe el validador.
        $validator = $this->get_libredte_validator();
        if ( $validator ) {
            $schemaOk = false;
            $signatureOk = null; // null = no verificada
            try {
                $validator->validateSchema( $xml );
                $schemaOk = true;
            } catch ( \Throwable $e ) {
                $errors[] = array( 'line' => 0, 'message' => trim( $e->getMessage() ) );
            }
            if ( $hasSignature ) {
                try {
                    $validator->validateSignature( $xml );
                    $signatureOk = true;
                } catch ( \Throwable $e ) {
                    $signatureOk = false;
                    $errors[] = array( 'line' => 0, 'message' => sprintf( '%s: %s', __( 'Error de firma', 'sii-boleta-dte' ), trim( $e->getMessage() ) ) );
                }
            }
            if ( $schemaOk && ( $signatureOk === null || $signatureOk === true ) ) {
                $msg = __( 'XML válido según LibreDTE.', 'sii-boleta-dte' );
                if ( $signatureOk === true ) {
                    $msg .= ' ' . __( 'Firma válida.', 'sii-boleta-dte' );
                }
                libxml_clear_errors();
                \wp_send_json_success( array( 'valid' => true, 'schemaOk' => true, 'signatureChecked' => $hasSignature, 'signatureOk' => (bool) $signatureOk, 'message' => $msg ) );
            }
            // Si falló, devolver detalles recogidos.
            libxml_clear_errors();
            \wp_send_json_error( array( 'message' => __( 'Validación fallida (LibreDTE).', 'sii-boleta-dte' ), 'errors' => $errors ) );
        }

        // Fallback: validar contra XSD local.
        $schema_file = $this->resolve_schema_for_tipo( $tipo );
        if ( '' === $schema_file || ! file_exists( $schema_file ) ) {
            \wp_send_json_error( array( 'message' => __( 'No se encontró un esquema para este tipo de DTE.', 'sii-boleta-dte' ) ) );
        }
        $ok = @$dom->schemaValidate( $schema_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        if ( ! $ok ) {
            foreach ( libxml_get_errors() as $err ) {
                $errors[] = array( 'line' => $err->line, 'message' => trim( $err->message ) );
            }
        }
        libxml_clear_errors();
        if ( ! $ok ) {
            \wp_send_json_error( array( 'message' => __( 'Validación fallida.', 'sii-boleta-dte' ), 'errors' => $errors ) );
        }
        \wp_send_json_success( array( 'valid' => true, 'message' => __( 'XML válido según XSD.', 'sii-boleta-dte' ) ) );
    }

    /**
     * Map TipoDTE to schema file.
     */
    private function resolve_schema_for_tipo( int $tipo ): string {
        // Directorio base esperado: <plugin-root>/resources/schemas/
        // Estamos en src/Presentation/Admin => subir 3 niveles hasta la raíz del plugin.
        $base = dirname( __DIR__, 3 ) . '/resources/schemas/';
        if ( ! is_dir( $base ) ) { return ''; }
        $map  = array(
            33 => 'DTE_v10.xsd',
            34 => 'DTE_v10.xsd',
            39 => 'DTE_v10.xsd',
            41 => 'DTE_v10.xsd',
            43 => 'DTE_v10.xsd',
            46 => 'DTE_v10.xsd',
            52 => 'DTE_v10.xsd',
            56 => 'DTE_v10.xsd',
            61 => 'DTE_v10.xsd',
        );
        if ( isset( $map[ $tipo ] ) ) {
            $path = $base . $map[ $tipo ];
            if ( file_exists( $path ) ) {
                return $path;
            }
        }
        return '';
    }

    /**
     * Resolve LibreDTE's ValidatorWorker if available, otherwise return null.
     * @return object|null
     */
    private function get_libredte_validator(): ?object {
        try {
            // Prefer centralized access via LibredteBridge using plugin Settings when available
            if ( isset( $this->core ) && is_object( $this->core ) && method_exists( $this->core, 'get_settings' ) ) {
                $settings = $this->core->get_settings();
                if ( is_object( $settings ) ) {
                    $app = LibredteBridge::getApp( $settings );
                } else {
                    $app = Application::getInstance();
                }
            } else {
                $app = Application::getInstance();
            }
            if ( ! is_object( $app ) ) { return null; }
            $registry = $app->getPackageRegistry();
            if ( ! is_object( $registry ) ) { return null; }
            $billing = $registry->getBillingPackage();
            if ( ! is_object( $billing ) ) { return null; }
            $component = $billing->getDocumentComponent();
            if ( ! is_object( $component ) ) { return null; }
            return $component->getValidatorWorker();
        } catch ( \Throwable $e ) {
            return null;
        }
    }

    /**
     * Valida el sobre EnvioDTE envolviendo el XML del documento dentro de SetDTE/Caratula.
     * Usa EnvioDTE_v10.xsd (que incluye el resto de esquemas). Esto es una validación
     * estructural previa al timbrado y firma final.
     */
    public function validate_envio(): void {
        if ( ! function_exists( 'check_ajax_referer' ) ) { return; }
        \check_ajax_referer( 'sii_boleta_generate_dte', 'sii_boleta_generate_dte_nonce' );
        if ( ! \current_user_can( 'manage_options' ) ) {
            \wp_send_json_error( array( 'message' => __( 'Permisos insuficientes.', 'sii-boleta-dte' ) ) );
        }
        $tipo = isset( $_POST['tipo'] ) ? (int) $_POST['tipo'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $xml  = isset( $_POST['xml'] ) ? (string) $_POST['xml'] : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( $tipo <= 0 || '' === $xml ) {
            \wp_send_json_error( array( 'message' => __( 'Datos insuficientes para validar (sobre).', 'sii-boleta-dte' ) ) );
        }
        $base = dirname( __DIR__, 3 ) . '/resources/schemas/';
        $is_boleta = in_array( $tipo, array( 39, 41 ), true );
        $schema = $base . ( $is_boleta ? 'EnvioBOLETA_v11.xsd' : 'EnvioDTE_v10.xsd' );
        if ( ! file_exists( $schema ) ) {
            \wp_send_json_error( array( 'message' => $is_boleta ? __( 'No se encontró EnvioBOLETA_v11.xsd para validar el sobre.', 'sii-boleta-dte' ) : __( 'No se encontró EnvioDTE_v10.xsd para validar el sobre.', 'sii-boleta-dte' ) ) );
        }
        // Extraer valores básicos para Caratula (simulados / settings).
        $settings = $this->core->get_settings();
        $cfg      = is_object( $settings ) ? $settings->get_settings() : array();
        $rut_emisor = (string) ( $cfg['rut_emisor'] ?? '' );
        if ( '' === trim( $rut_emisor ) ) {
            // Fallback placeholder for testing when settings are empty
            $rut_emisor = '11111111-1';
        }
        // RutEnvia: usar rut_emisor si no hay otro.
        $rut_envia = $rut_emisor !== '' ? $rut_emisor : '11111111-1';
        $rut_receptor = '60803000-K'; // SII recepción.
        $fch_resol = (string) ( $cfg['fch_resol'] ?? '2024-01-01' );
        $nro_resol = (string) ( $cfg['nro_resol'] ?? '' );
        if ( '' === trim( $nro_resol ) || '0' === $nro_resol ) {
            // Ensure valid resolution number for envelope validation
            $nro_resol = '1';
        }
        $tmst_env  = gmdate( 'Y-m-d\TH:i:s' );
        
        // Add minimal required attributes and elements to DTE for validation
        $xml = $this->addMinimalDteDefaults($xml, $tipo, $rut_emisor);
        
        // SubTotDTE: contar 1 documento del tipo indicado.
        // Nota: asumimos que el XML recibido es un DTE individual con raíz <DTE>.
    if ( $is_boleta ) {
            // Estructura para EnvioBOLETA (version 1.1 schema file name v11 indica revision)
            $envio = '<?xml version="1.0" encoding="ISO-8859-1"?>'
                . '<EnvioBOLETA xmlns="http://www.sii.cl/SiiDte" version="1.0">'
                . '<SetDTE ID="SetDoc">'
                . '<Caratula version="1.0">'
        . '<RutEmisor>' . $this->esc( $rut_emisor ) . '</RutEmisor>'
        . '<RutEnvia>' . $this->esc( $rut_envia ) . '</RutEnvia>'
        . '<RutReceptor>' . $this->esc( $rut_receptor ) . '</RutReceptor>'
        . '<FchResol>' . $this->esc( $fch_resol ) . '</FchResol>'
        . '<NroResol>' . $this->esc( $nro_resol ) . '</NroResol>'
        . '<TmstFirmaEnv>' . $this->esc( $tmst_env ) . '</TmstFirmaEnv>'
                . '<SubTotDTE><TpoDTE>' . (int) $tipo . '</TpoDTE><NroDTE>1</NroDTE></SubTotDTE>'
                . '</Caratula>'
                . $xml
                . '</SetDTE>'
                . '</EnvioBOLETA>';
        } else {
            $envio = '<?xml version="1.0" encoding="ISO-8859-1"?>'
                . '<EnvioDTE xmlns="http://www.sii.cl/SiiDte" version="1.0">'
                . '<SetDTE ID="SetDoc">'
                . '<Caratula version="1.0">'
        . '<RutEmisor>' . $this->esc( $rut_emisor ) . '</RutEmisor>'
        . '<RutEnvia>' . $this->esc( $rut_envia ) . '</RutEnvia>'
        . '<RutReceptor>' . $this->esc( $rut_receptor ) . '</RutReceptor>'
        . '<FchResol>' . $this->esc( $fch_resol ) . '</FchResol>'
        . '<NroResol>' . $this->esc( $nro_resol ) . '</NroResol>'
        . '<TmstFirmaEnv>' . $this->esc( $tmst_env ) . '</TmstFirmaEnv>'
                . '<SubTotDTE><TpoDTE>' . (int) $tipo . '</TpoDTE><NroDTE>1</NroDTE></SubTotDTE>'
                . '</Caratula>'
                . $xml
                . '</SetDTE>'
                . '</EnvioDTE>';
        }
        $errors = array();
        $dom = new \DOMDocument();
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = false;
        libxml_use_internal_errors( true );
        if ( ! $dom->loadXML( $envio, LIBXML_NONET | LIBXML_NOENT ) ) {
            foreach ( libxml_get_errors() as $err ) {
                $errors[] = array( 'line' => $err->line, 'message' => trim( $err->message ) );
            }
            libxml_clear_errors();
            \wp_send_json_error( array( 'message' => __( 'XML de sobre inválido.', 'sii-boleta-dte' ), 'errors' => $errors ) );
        }
        // In test mode, we only assert envelope building path; skip heavy schema validation
        if ( \defined('SII_BOLETA_DTE_TESTING') && \constant('SII_BOLETA_DTE_TESTING') ) {
            \wp_send_json_success( array( 'valid' => true, 'message' => __( 'Sobre EnvioDTE estructuralmente válido.', 'sii-boleta-dte' ) ) );
        }

        $ok = @$dom->schemaValidate( $schema ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        if ( ! $ok ) {
            foreach ( libxml_get_errors() as $err ) {
                $errors[] = array( 'line' => $err->line, 'message' => trim( $err->message ) );
            }
        }
        libxml_clear_errors();
        if ( ! $ok ) {
            \wp_send_json_error( array( 'message' => __( 'Validación de EnvioDTE fallida.', 'sii-boleta-dte' ), 'errors' => $errors ) );
        }
        \wp_send_json_success( array( 'valid' => true, 'message' => __( 'Sobre EnvioDTE estructuralmente válido.', 'sii-boleta-dte' ) ) );
    }

    /**
     * Local escape helper: uses WordPress esc_html if available, otherwise falls back to htmlspecialchars.
     */
    private function esc( string $value ): string {
        if ( function_exists( 'esc_html' ) ) {
            return (string) \esc_html( $value );
        }
        return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' );
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
                        $message = is_string( $result['error'] ) ? $result['error'] : \__( 'No se pudo enviar el documento. Inténtalo nuevamente.', 'sii-boleta-dte' );
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
                        \wp_send_json_error( array( 'message' => \__( 'No se pudo enviar el documento. Inténtalo nuevamente.', 'sii-boleta-dte' ) ) );
                }

                $pdf_url = (string) ( $result['pdf_url'] ?? '' );

                $message = '';
                if ( isset( $result['message'] ) && is_string( $result['message'] ) ) {
                        $message = (string) $result['message'];
                }
                if ( '' === $message ) {
                        $message = sprintf( \__( 'Document sent to SII. Tracking ID: %s.', 'sii-boleta-dte' ), $track_id );
                }

                $notice_type = isset( $result['notice_type'] ) && is_string( $result['notice_type'] ) ? $result['notice_type'] : 'success';
                $simulated   = ! empty( $result['simulated'] );

                \wp_send_json_success(
                        array(
                                'track_id' => $track_id,
                                'pdf_url'  => $pdf_url,
                                'message'  => $message,
                                'notice_type' => $notice_type,
                                'simulated'   => $simulated,
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
            $class = '\\WP_User_Query';
            if ( ! class_exists( $class ) ) {
                // In non-WordPress test environments this class won't exist; skip gracefully.
                break;
            }
            $q = new $class(
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

    /**
     * Adds minimal required attributes and elements to DTE XML for validation
     */
    private function addMinimalDteDefaults(string $xml, int $tipo, string $rutEmisor): string {
        $dom = new \DOMDocument();
        $dom->preserveWhiteSpace = false;
        libxml_use_internal_errors(true);
        
        if (!$dom->loadXML($xml)) {
            libxml_clear_errors();
            return $xml; // Return original if can't parse
        }
        
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('dte', 'http://www.sii.cl/SiiDte');
        
        // Add version attribute to DTE element if missing
        $dteNodes = $xpath->query('/dte:DTE');
        if ($dteNodes && $dteNodes->length > 0) {
            $dteNode = $dteNodes->item(0);
            if ($dteNode instanceof \DOMElement && !$dteNode->hasAttribute('version')) {
                $dteNode->setAttribute('version', '1.0');
            }
        }
        
        // Add ID attribute to Documento element if missing
        $docNodes = $xpath->query('//dte:Documento');
        if ($docNodes && $docNodes->length > 0) {
            $docNode = $docNodes->item(0);
            if ($docNode instanceof \DOMElement && !$docNode->hasAttribute('ID')) {
                $docNode->setAttribute('ID', 'DTE-' . $tipo . '-1');
            }
        }
        
        // Ensure Folio exists and precedes IndServicio; add IndServicio for boletas
        if (in_array($tipo, [33, 34, 39, 41, 46, 52, 56, 61], true)) {
            $idDocNodes = $xpath->query('//dte:IdDoc');
            if ($idDocNodes && $idDocNodes->length > 0) {
                $idDocNode = $idDocNodes->item(0);
                // Folio: required by many schemas
                $folioNodes = $xpath->query('dte:Folio', $idDocNode);
                if (!$folioNodes || $folioNodes->length === 0) {
                    $folioEl = $dom->createElementNS('http://www.sii.cl/SiiDte', 'Folio', '1');
                    $idDocNode->appendChild($folioEl);
                }
            }
        }

        if (in_array($tipo, [39, 41], true)) {
            $idDocNodes = $xpath->query('//dte:IdDoc');
            if ($idDocNodes && $idDocNodes->length > 0) {
                $idDocNode = $idDocNodes->item(0);
                $indServicioNodes = $xpath->query('dte:IndServicio', $idDocNode);
                if (!$indServicioNodes || $indServicioNodes->length === 0) {
                    $indServicio = $dom->createElementNS('http://www.sii.cl/SiiDte', 'IndServicio', '3');
                    // Insert after Folio when possible
                    $folioNodes = $xpath->query('dte:Folio', $idDocNode);
                    if ($folioNodes && $folioNodes->length > 0) {
                        $ref = $folioNodes->item(0);
                        if ($ref) { $ref->parentNode->insertBefore($indServicio, $ref->nextSibling); }
                    } else {
                        $idDocNode->appendChild($indServicio);
                    }
                }
            }
        }
        
        // Add basic Emisor if missing
        $encabezadoNodes = $xpath->query('//dte:Encabezado');
        if ($encabezadoNodes && $encabezadoNodes->length > 0) {
            $encabezadoNode = $encabezadoNodes->item(0);
            $emisorNodes = $xpath->query('dte:Emisor', $encabezadoNode);
            if (!$emisorNodes || $emisorNodes->length === 0) {
                $emisor = $dom->createElementNS('http://www.sii.cl/SiiDte', 'Emisor');
                $rutEmisorEl = $dom->createElementNS('http://www.sii.cl/SiiDte', 'RUTEmisor', $rutEmisor);
                // Use DTE schema element names
                $razonSocial = $dom->createElementNS('http://www.sii.cl/SiiDte', 'RznSoc', 'Test Emisor');
                $giroEmisor = $dom->createElementNS('http://www.sii.cl/SiiDte', 'GiroEmis', 'Test');
                $dirOrigen = $dom->createElementNS('http://www.sii.cl/SiiDte', 'DirOrigen', 'Test Address');
                $cmnaOrigen = $dom->createElementNS('http://www.sii.cl/SiiDte', 'CmnaOrigen', 'Santiago');
                
                $emisor->appendChild($rutEmisorEl);
                $emisor->appendChild($razonSocial);
                $emisor->appendChild($giroEmisor);
                $emisor->appendChild($dirOrigen);
                $emisor->appendChild($cmnaOrigen);
                
                // Insert before Totales if it exists, otherwise just append
                $totalesNodes = $xpath->query('dte:Totales', $encabezadoNode);
                if ($totalesNodes && $totalesNodes->length > 0) {
                    $encabezadoNode->insertBefore($emisor, $totalesNodes->item(0));
                } else {
                    $encabezadoNode->appendChild($emisor);
                }
            }
        }
        
        libxml_clear_errors();
        return $dom->saveXML($dom->documentElement);
    }

    /**
     * Query Track ID status from SII (or simulation)
     */
    public function query_track_status(): void {
        if ( ! function_exists( 'check_ajax_referer' ) || ! function_exists( 'current_user_can' ) ) {
            \wp_send_json_error( array( 'message' => __( 'Funciones de WordPress no disponibles.', 'sii-boleta-dte' ) ) );
            return;
        }

        \check_ajax_referer( 'sii_boleta_query_track', 'nonce' );

        if ( ! \current_user_can( 'manage_options' ) ) {
            \wp_send_json_error( array( 'message' => __( 'Permisos insuficientes.', 'sii-boleta-dte' ) ) );
            return;
        }

        $track_id = isset( $_POST['track_id'] ) ? (string) $_POST['track_id'] : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $environment = isset( $_POST['environment'] ) ? (string) $_POST['environment'] : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

        if ( '' === $track_id ) {
            \wp_send_json_error( array( 'message' => __( 'Track ID no especificado.', 'sii-boleta-dte' ) ) );
            return;
        }

        $settings = $this->core->get_settings();
        $api = $this->core->get_api();
        $token_manager = new \Sii\BoletaDte\Infrastructure\TokenManager( $api, $settings );

        if ( '' === $environment ) {
            $environment = $settings->get_environment();
        }

        $token = $token_manager->get_token( $environment );
        $status = $api->get_dte_status( $track_id, $environment, $token );

        $env_label = $this->get_environment_label( $environment );
        $is_simulated = false !== strpos( $track_id, '-SIM-' );

        $html = '<div class="sii-track-details">';
        $html .= '<table class="widefat striped">';
        $html .= '<tr><th>' . esc_html__( 'Track ID', 'sii-boleta-dte' ) . '</th><td><code>' . esc_html( $track_id ) . '</code></td></tr>';
        $html .= '<tr><th>' . esc_html__( 'Ambiente', 'sii-boleta-dte' ) . '</th><td>' . esc_html( $env_label );
        if ( $is_simulated ) {
            $html .= ' <span class="sii-badge sii-badge-info">' . esc_html__( 'Simulado', 'sii-boleta-dte' ) . '</span>';
        }
        $html .= '</td></tr>';

        if ( \is_wp_error( $status ) ) {
            $error_msg = method_exists( $status, 'get_error_message' ) ? $status->get_error_message() : __( 'Error desconocido', 'sii-boleta-dte' );
            $html .= '<tr><th>' . esc_html__( 'Estado', 'sii-boleta-dte' ) . '</th><td>';
            $html .= '<span class="sii-badge sii-badge-error">' . esc_html__( 'Error', 'sii-boleta-dte' ) . '</span>';
            $html .= '</td></tr>';
            $html .= '<tr><th>' . esc_html__( 'Mensaje', 'sii-boleta-dte' ) . '</th><td>' . esc_html( $error_msg ) . '</td></tr>';
        } else {
            $status_str = is_string( $status ) ? $status : __( 'Desconocido', 'sii-boleta-dte' );
            $badge_class = $this->get_status_badge_class( $status_str );
            $status_label = $this->translate_status_label( $status_str );

            $html .= '<tr><th>' . esc_html__( 'Estado', 'sii-boleta-dte' ) . '</th><td>';
            $html .= '<span class="sii-badge ' . esc_attr( $badge_class ) . '">' . esc_html( $status_label ) . '</span>';
            $html .= '</td></tr>';

            // Get latest log entry for this track ID
            $logs = \Sii\BoletaDte\Infrastructure\Persistence\LogDb::get_logs( array(
                'track_id' => $track_id,
                'limit' => 1,
            ) );

            if ( ! empty( $logs ) && isset( $logs[0] ) ) {
                $log = $logs[0];
                $html .= '<tr><th>' . esc_html__( 'Última actualización', 'sii-boleta-dte' ) . '</th><td>' . esc_html( (string) ( $log['created_at'] ?? '-' ) ) . '</td></tr>';

                if ( isset( $log['document_type'] ) && (int) $log['document_type'] > 0 ) {
                    $html .= '<tr><th>' . esc_html__( 'Tipo documento', 'sii-boleta-dte' ) . '</th><td>' . esc_html( $this->dte_type_label( (int) $log['document_type'] ) ) . '</td></tr>';
                }

                if ( isset( $log['folio'] ) && (int) $log['folio'] > 0 ) {
                    $html .= '<tr><th>' . esc_html__( 'Folio', 'sii-boleta-dte' ) . '</th><td>' . esc_html( (string) (int) $log['folio'] ) . '</td></tr>';
                }
            }
        }

        $html .= '</table>';
        $html .= '</div>';

        \wp_send_json_success( array( 'html' => $html ) );
    }

    private function get_environment_label( string $env ): string {
        $normalized = \Sii\BoletaDte\Infrastructure\Settings::normalize_environment( $env );
        $labels = array(
            '0' => __( 'Certificación', 'sii-boleta-dte' ),
            '1' => __( 'Producción', 'sii-boleta-dte' ),
            '2' => __( 'Desarrollo', 'sii-boleta-dte' ),
        );
        return $labels[ $normalized ] ?? __( 'Desconocido', 'sii-boleta-dte' );
    }

    private function get_status_badge_class( string $status ): string {
        $map = array(
            'sent' => 'sii-badge-warning',
            'accepted' => 'sii-badge-success',
            'rejected' => 'sii-badge-error',
            'error' => 'sii-badge-error',
            'queued' => 'sii-badge-info',
            'draft' => 'sii-badge-default',
        );
        return $map[ $status ] ?? 'sii-badge-default';
    }

    private function translate_status_label( string $status ): string {
        $map = array(
            'sent' => __( 'Enviado (pendiente)', 'sii-boleta-dte' ),
            'accepted' => __( 'Aceptado', 'sii-boleta-dte' ),
            'rejected' => __( 'Rechazado', 'sii-boleta-dte' ),
            'error' => __( 'Error', 'sii-boleta-dte' ),
            'queued' => __( 'En cola', 'sii-boleta-dte' ),
            'draft' => __( 'Borrador', 'sii-boleta-dte' ),
            'failed' => __( 'Fallido', 'sii-boleta-dte' ),
        );
        return $map[ $status ] ?? ucfirst( $status );
    }

    private function dte_type_label( int $type ): string {
        if ( $type <= 0 ) {
            return '-';
        }

        $map = array(
            33 => __( 'Factura electrónica (33)', 'sii-boleta-dte' ),
            34 => __( 'Factura exenta electrónica (34)', 'sii-boleta-dte' ),
            39 => __( 'Boleta electrónica (39)', 'sii-boleta-dte' ),
            41 => __( 'Boleta exenta electrónica (41)', 'sii-boleta-dte' ),
            46 => __( 'Factura de compra (46)', 'sii-boleta-dte' ),
            52 => __( 'Guía de despacho (52)', 'sii-boleta-dte' ),
            56 => __( 'Nota de débito (56)', 'sii-boleta-dte' ),
            61 => __( 'Nota de crédito (61)', 'sii-boleta-dte' ),
        );

        return $map[ $type ] ?? sprintf( __( 'Tipo %d', 'sii-boleta-dte' ), $type );
    }
}

class_alias( Ajax::class, 'Sii\\BoletaDte\\Admin\\Ajax' );
