<?php
declare(strict_types=1);

namespace Sii\BoletaDte\Infrastructure\WordPress;

use Sii\BoletaDte\Presentation\Admin\Ajax;
use Sii\BoletaDte\Presentation\Admin\Pages;
use Sii\BoletaDte\Infrastructure\WordPress\Settings;
use Sii\BoletaDte\Application\FolioManager;
use Sii\BoletaDte\Infrastructure\Engine\Signer;
use Sii\BoletaDte\Infrastructure\Rest\Api;
use Sii\BoletaDte\Infrastructure\Rest\Endpoints;
use Sii\BoletaDte\Infrastructure\Monitoring\Metrics;
use Sii\BoletaDte\Application\Queue;
use Sii\BoletaDte\Application\QueueProcessor;
use Sii\BoletaDte\Infrastructure\Scheduling\Cron;
use Sii\BoletaDte\Infrastructure\Persistence\QueueDb;
use Sii\BoletaDte\Infrastructure\Persistence\LogDb;
use Sii\BoletaDte\Presentation\Admin\Help;
use Sii\BoletaDte\Infrastructure\Engine\Factory\BoletaDteDocumentFactory;
use Sii\BoletaDte\Infrastructure\Engine\Factory\FacturaDteDocumentFactory;
use Sii\BoletaDte\Infrastructure\Engine\Factory\VatInclusiveDteDocumentFactory;
use Sii\BoletaDte\Infrastructure\Engine\LibreDteEngine;
use Sii\BoletaDte\Infrastructure\Engine\NullEngine;
use Sii\BoletaDte\Infrastructure\WooCommerce\Woo;
use Sii\BoletaDte\Infrastructure\WooCommerce\PdfStorageMigrator;
use Sii\BoletaDte\Infrastructure\WooCommerce\PdfStorage;
use Sii\BoletaDte\Infrastructure\Engine\PdfGenerator;
use Sii\BoletaDte\Presentation\WooCommerce\CheckoutFields;
use Sii\BoletaDte\Infrastructure\Factory\Container;

class Plugin {
	private ?Settings $settings = null;
	private ?FolioManager $folio_manager = null;
	private Signer $signer;
	private Api $api;
    
	private Endpoints $endpoints;
	private ?Woo $woo = null;
        private Metrics $metrics;
        private PdfGenerator $pdf_generator;
        private ?Queue $queue = null;
        private QueueProcessor $queue_processor;
	private Help $help;
	private \Sii\BoletaDte\Domain\DteEngine $engine;
	private bool $libredte_missing = false;
	private Ajax $ajax;
	private Pages $pages;

		   public function __construct( ?Settings $settings = null, ?FolioManager $folio_manager = null, ?Signer $signer = null, ?Api $api = null, ?Endpoints $endpoints = null, ?Metrics $metrics = null, ?Queue $queue = null, ?Help $help = null, ?Ajax $ajax = null, ?Pages $pages = null, ?QueueProcessor $queue_processor = null ) {
// Provide backwards-compatible alias for legacy namespace references
class_alias(\Sii\BoletaDte\Infrastructure\WordPress\Plugin::class, 'Sii\\BoletaDte\\Infrastructure\\Plugin');

						   PdfStorageMigrator::migrate();
						   LogDb::install();
						   QueueDb::install();
						   $this->settings       = $settings;
						   if (!isset($this->settings) || $this->settings === null) {
							   $this->settings = new Settings();
						   }
						   $this->folio_manager  = $folio_manager ?? new FolioManager( $this->settings );
			$this->signer         = $signer ?? new Signer();
                        $this->api            = $api ?? new Api();
                        if ( method_exists( $this->api, 'setSettings' ) ) {
                                $this->api->setSettings( $this->settings );
                        }
						$this->queue          = $queue ?? new Queue();
                        $this->endpoints      = $endpoints ?? new Endpoints();
			$this->metrics        = $metrics ?? new Metrics();

        try {
                                $default_engine = new LibreDteEngine( $this->settings );
                                $this->register_document_factories( $default_engine );
                } catch ( \RuntimeException $e ) {
                                $this->libredte_missing = true;
                                $default_engine         = new NullEngine();
                }
                        $this->engine = \apply_filters( 'sii_boleta_dte_engine', $default_engine );

                if ( $this->engine instanceof LibreDteEngine && $this->engine !== $default_engine ) {
                        $this->register_document_factories( $this->engine );
                }

                        $this->pdf_generator   = new PdfGenerator( $this->engine, $this->settings );
                        $this->queue_processor = $queue_processor ?? new QueueProcessor( $this->api, null, $this->get_folio_manager() );
                        \add_action( Cron::HOOK, array( $this->queue_processor, 'process' ) );
                        // Fallback: si estamos dentro de wp-cron.php (DOING_CRON),
                        // intenta procesar la cola incluso si el evento no se disparó.
                        // El bloqueo vía transiente en QueueProcessor evita ejecuciones duplicadas.
                        \add_action( 'init', function () {
                                if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
                                        try { $this->queue_processor->process(); } catch ( \Throwable $e ) { /*ignore*/ }
                                }
                        } );
                        // Asegurar que el cron esté programado cada 5 minutos incluso si el hook de activación no corrió.
                        // 1) Registrar el schedule personalizado
                        try { new Cron( $this->get_settings() ); } catch ( \Throwable $e ) {}
                        // 2) Programar si no existe, usando el intervalo configurado
                        if ( function_exists( 'wp_next_scheduled' ) && function_exists( 'wp_schedule_event' ) ) {
                                $ts = wp_next_scheduled( Cron::HOOK );
                                if ( ! $ts ) {
                                        $interval = $this->get_settings()->get_queue_interval();
                                        if ( ! in_array( $interval, array( 'every_minute', 'every_five_minutes', 'every_fifteen_minutes' ), true ) ) {
                                                $interval = 'every_five_minutes';
                                        }
                                        wp_schedule_event( time() + 60, $interval, Cron::HOOK );
                                }
                        }
                        $this->help = $help ?? new Help( $this->settings );

        if ( class_exists( 'WooCommerce' ) ) {
                        $this->woo = new Woo( $this );
                        $this->woo->register();
                        $checkout_fields = new CheckoutFields( $this->settings );
                        $checkout_fields->register();
        } else {
                        // Si WooCommerce aún no está cargado, registra los ganchos cuando termine de cargar.
                        \add_action( 'plugins_loaded', function () {
                                if ( class_exists( 'WooCommerce' ) ) {
                                        try {
                                                $this->woo = new Woo( $this );
                                                $this->woo->register();
                                                $checkout_fields = new CheckoutFields( $this->settings );
                                                $checkout_fields->register();
                                        } catch ( \Throwable $e ) {
                                                // evitar que un fallo aquí afecte el resto del plugin
                                        }
                                }
                        }, 20 );
        }

			$this->pages = $pages ?? new Pages( $this );
			$this->ajax  = $ajax ?? new Ajax( $this );

			\add_action( 'admin_menu', array( $this->pages, 'register' ) );
			\add_action( 'admin_enqueue_scripts', array( $this->pages, 'enqueue_assets' ) );

			\add_action( 'admin_bar_menu', array( $this, 'add_environment_indicator' ), 100 );
			\add_action( 'admin_notices', array( $this, 'maybe_show_admin_warnings' ) );

			$this->ajax->register();

			\add_filter( 'sii_boleta_available_smtp_profiles', array( $this, 'fluent_smtp_profiles' ) );
			\add_action( 'sii_boleta_setup_mailer', array( $this, 'fluent_smtp_setup_mailer' ), 10, 2 );
	}

       public function get_settings(): Settings {
	       if ( ! isset( $this->settings ) || null === $this->settings ) {
		   $this->settings = new Settings();
	       }
	       return $this->settings;
       }
	public function get_folio_manager(): FolioManager {
		if ( ! isset( $this->folio_manager ) ) {
			$this->folio_manager = new FolioManager( $this->get_settings() );
		}
		return $this->folio_manager; }
	public function get_signer() {
		return $this->signer; }
	public function get_api() {
		return $this->api; }

	public function get_queue() {
		if ( ! isset( $this->queue ) ) {
			$this->queue = new Queue();
		}
		return $this->queue; }
	public function get_engine() {
		return $this->engine; }        public function get_pdf_generator(): PdfGenerator {
                return $this->pdf_generator;
        }

        public function fluent_smtp_profiles( $profiles ) {
                if ( class_exists( '\\FluentMail\\App\\Models\\Settings' ) ) {
                        $class    = '\\FluentMail\\App\\Models\\Settings';
                        $settings = new $class();
                        $config   = $settings->getConnections();
                        foreach ( $config as $key => $data ) {
                                $label = $data['sender_email'] ?? ( $data['title'] ?? $key );
                                $profiles[ $key ] = is_scalar( $label ) ? (string) $label : (string) $key;
                        }
                }
                return $profiles;
        }

        public function fluent_smtp_setup_mailer( $phpmailer, $profile ) {
                \do_action( 'fluentmail_before_sending_email', $phpmailer, $profile );
        }

        private function register_document_factories( LibreDteEngine $engine ): void {
                $templates_root = dirname( __DIR__, 2 ) . '/resources/yaml/';

                $boleta_factory        = new BoletaDteDocumentFactory( $templates_root );
                $factura_factory       = new FacturaDteDocumentFactory( $templates_root );
                $vat_inclusive_factory = new VatInclusiveDteDocumentFactory(
                        $templates_root,
                        array(
                                52 => 'documentos_ok/052_guia_despacho',
                                56 => 'documentos_ok/056_nota_debito',
                                61 => 'documentos_ok/061_nota_credito',
                        )
                );

                foreach ( array( 39, 41 ) as $tipo_boleta ) {
                        $engine->register_document_factory( $tipo_boleta, $boleta_factory );
                }

                foreach ( array( 33, 34, 46 ) as $tipo_factura ) {
                        $engine->register_document_factory( $tipo_factura, $factura_factory );
                }

                foreach ( array( 52, 56, 61 ) as $tipo_vat_inclusive ) {
                        $engine->register_document_factory( $tipo_vat_inclusive, $vat_inclusive_factory );
                }
        }

        public function add_environment_indicator( $wp_admin_bar ) {
                if ( $this->libredte_missing ) {
			$wp_admin_bar->add_node(
				array(
					'id'    => 'sii-boleta-env',
					'title' => 'LibreDTE missing',
					'meta'  => array( 'class' => 'sii-boleta-env-warning' ),
				)
			);
		}
	}

	public function maybe_show_admin_warnings() {
		if ( $this->libredte_missing ) {
			echo '<div class="notice notice-error"><p>';
			\esc_html_e( 'No se pudo cargar el motor LibreDTE.', 'sii-boleta-dte' );
			echo '</p></div>';
		}
	}

	/**
	 * Persists the generated PDF in secure storage and returns the metadata required to retrieve it later.
	 *
	 * @return array{path:string,key:string,nonce:string}
	 */
	public function persist_pdf_for_order( string $path, $order, int $document_type, int $order_id ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInImplementedInterface
		$result = array(
			'path'  => $path,
			'key'   => '',
			'nonce' => '',
		);

		if ( '' === $path || ! file_exists( $path ) ) {
			return $result;
		}

		$stored = PdfStorage::store( $path );
		if ( empty( $stored['key'] ) ) {
			return $result;
		}

		return $stored;
	}

	/**
	 * Updates an order meta value (also updates the in-memory cache used during tests).
	 */
	public function update_order_meta( int $order_id, string $meta_key, $value ): void {
		if ( function_exists( 'update_post_meta' ) ) {
			update_post_meta( $order_id, $meta_key, $value );
		}

		global $meta; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
		if ( ! is_array( $meta ) ) {
			$meta = array();
		}

		if ( ! isset( $meta[ $order_id ] ) || ! is_array( $meta[ $order_id ] ) ) {
			$meta[ $order_id ] = array();
		}

		$meta[ $order_id ][ $meta_key ] = $value;
	}

	/**
	 * Removes legacy PDF meta keys left by previous plugin versions.
	 */
	public function clear_legacy_pdf_meta( int $order_id, string $meta_prefix ): void {
		foreach ( array( '_pdf', '_pdf_path', '_pdf_url' ) as $suffix ) {
			$this->delete_order_meta( $order_id, $meta_prefix . $suffix );
		}
	}

	/**
	 * Builds a signed download URL for a stored PDF.
	 */
	public function build_pdf_download_link( int $order_id, string $meta_prefix, string $key, string $nonce ): string {
		if ( $order_id <= 0 ) {
			return '';
		}

		$key   = trim( strtolower( (string) $key ) );
		$nonce = trim( strtolower( (string) $nonce ) );

		if ( '' === $key || '' === $nonce ) {
			return '';
		}

		$type = $this->sanitize_meta_prefix( $meta_prefix );
		if ( '' === $type ) {
			return '';
		}

		// Obtener el folio del pedido para usar en la URL amigable
		$folio = $this->get_order_folio( $order_id, $type );
		if ( $folio <= 0 ) {
			// Si no hay folio, usar el endpoint AJAX como fallback
			return $this->build_ajax_download_link( $order_id, $key, $nonce, $type );
		}

		// Usar el endpoint personalizado /boleta/{folio} con token de seguridad
		$signed_url_service = new \Sii\BoletaDte\Infrastructure\Rest\SignedUrlService();
		$token = $this->create_secure_token( $folio, $key, $nonce );
		
		$base_url = home_url( '/boleta/' . $folio . '/' );
		return add_query_arg( array(
			'sii_boleta_token' => $token,
			'download' => 'pdf'
		), $base_url );
	}

	/**
	 * Sends the PDF to the customer via email and optionally provides a download link.
	 */
	public function send_document_email( $order, string $pdf_path, int $document_type, bool $preview = false, string $download_url = '' ): void {
		if ( ! function_exists( 'wp_mail' ) || ! $order || ! method_exists( $order, 'get_billing_email' ) ) {
			return;
		}

		$email = (string) $order->get_billing_email();
		if ( '' === $email ) {
			return;
		}

		if ( ! file_exists( $pdf_path ) ) {
			return;
		}

		$subject_template = $preview
			? __( 'Previsualización del documento tributario electrónico para el pedido #%1$s (%2$s)', 'sii-boleta-dte' )
			: __( 'Documento tributario electrónico para el pedido #%1$s (%2$s)', 'sii-boleta-dte' );

		$subject = sprintf(
			/* translators: %1$s: order number, %2$s: document type. */
			$subject_template,
			method_exists( $order, 'get_order_number' ) ? $order->get_order_number() : $order->get_id(),
			$document_type
		);

		$message = $preview
			? __( 'Se adjunta la previsualización del documento tributario electrónico asociada a su compra. No ha sido enviada al SII.', 'sii-boleta-dte' )
			: __( 'Se adjunta el documento tributario electrónico asociado a su compra.', 'sii-boleta-dte' );

		if ( '' !== $download_url ) {
			$link = function_exists( 'esc_url' ) ? esc_url( $download_url ) : $download_url;
			$message .= '<br />' . sprintf(
				/* translators: %s: URL pointing to the generated PDF download. */
				__( 'También puede descargarlo en: %s', 'sii-boleta-dte' ),
				$link
			);
		}

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );

		wp_mail( $email, $subject, $message, $headers, array( $pdf_path ) );
	}

	private function get_ajax_endpoint_base(): string {
		if ( function_exists( 'admin_url' ) ) {
			return admin_url( 'admin-ajax.php' );
		}

		if ( function_exists( 'site_url' ) ) {
			$base = rtrim( (string) site_url(), '/\\' );
			if ( '' !== $base ) {
				return $base . '/wp-admin/admin-ajax.php';
			}
		}

		return '';
	}

	/**
	 * Obtiene el folio del pedido para usar en la URL amigable.
	 */
	private function get_order_folio( int $order_id, string $type ): int {
		$folio_meta = get_post_meta( $order_id, $type . '_folio', true );
		return (int) $folio_meta;
	}

	/**
	 * Crea un token seguro para la URL amigable.
	 */
	private function create_secure_token( int $folio, string $key, string $nonce ): string {
		// Crear un token que combine folio, key y nonce para mayor seguridad
		$data = $folio . '|' . $key . '|' . $nonce;
		return hash( 'sha256', $data . \wp_salt() );
	}

	/**
	 * Construye la URL de descarga usando el endpoint AJAX como fallback.
	 */
	private function build_ajax_download_link( int $order_id, string $key, string $nonce, string $type ): string {
		$params = array(
			'action'   => 'sii_boleta_dte_view_pdf',
			'order_id' => $order_id,
			'key'      => $key,
			'nonce'    => $nonce,
			'type'     => $type,
		);

		$base = $this->get_ajax_endpoint_base();
		if ( '' === $base ) {
			$base = 'admin-ajax.php';
		}

		$separator = str_contains( $base, '?' ) ? '&' : '?';

		return $base . $separator . http_build_query( $params );
	}

	private function sanitize_meta_prefix( string $meta_prefix ): string {
		$meta_prefix = strtolower( (string) $meta_prefix );

		return preg_replace( '/[^a-z0-9_]/', '', $meta_prefix ) ?? '';
	}

	private function delete_order_meta( int $order_id, string $meta_key ): void {
		if ( $order_id <= 0 || '' === $meta_key ) {
			return;
		}

		if ( function_exists( 'delete_post_meta' ) ) {
			delete_post_meta( $order_id, $meta_key );
		}

		global $meta; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
		if ( isset( $meta[ $order_id ][ $meta_key ] ) ) {
			unset( $meta[ $order_id ][ $meta_key ] );
		}
	}
}

class_alias( \Sii\BoletaDte\Infrastructure\WordPress\Plugin::class, 'Sii\\BoletaDte\\Core\\Plugin' );
