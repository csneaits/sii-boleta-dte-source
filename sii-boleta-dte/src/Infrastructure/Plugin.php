<?php
declare(strict_types=1);

namespace Sii\BoletaDte\Infrastructure;

use Sii\BoletaDte\Presentation\Admin\Ajax;
use Sii\BoletaDte\Presentation\Admin\Pages;
use Sii\BoletaDte\Infrastructure\Settings;
use Sii\BoletaDte\Application\FolioManager;
use Sii\BoletaDte\Infrastructure\Signer;
use Sii\BoletaDte\Infrastructure\Rest\Api;
use Sii\BoletaDte\Application\RvdManager;
use Sii\BoletaDte\Infrastructure\Rest\Endpoints;
use Sii\BoletaDte\Infrastructure\Metrics;
use Sii\BoletaDte\Application\ConsumoFolios;
use Sii\BoletaDte\Application\Queue;
use Sii\BoletaDte\Infrastructure\Cron;
use Sii\BoletaDte\Presentation\Admin\Help;
use Sii\BoletaDte\Infrastructure\Engine\LibreDteEngine;
use Sii\BoletaDte\Infrastructure\Engine\NullEngine;
use Sii\BoletaDte\Infrastructure\WooCommerce\Woo;
use Sii\BoletaDte\Presentation\WooCommerce\CheckoutFields;
use Sii\BoletaDte\Infrastructure\Factory\Container;

class Plugin {
	private Settings $settings;
	private FolioManager $folio_manager;
	private Signer $signer;
	private Api $api;
	private RvdManager $rvd_manager;
	private Endpoints $endpoints;
	private ?Woo $woo = null;
	private Metrics $metrics;
	private ConsumoFolios $consumo_folios;
	private Queue $queue;
	private Help $help;
	private \Sii\BoletaDte\Domain\DteEngine $engine;
	private bool $libredte_missing = false;
	private Ajax $ajax;
	private Pages $pages;

	public function __construct( Settings $settings = null, FolioManager $folio_manager = null, Signer $signer = null, Api $api = null, RvdManager $rvd_manager = null, Endpoints $endpoints = null, Metrics $metrics = null, ConsumoFolios $consumo_folios = null, Queue $queue = null, Help $help = null, Ajax $ajax = null, Pages $pages = null ) {
			Container::init();
			$this->settings       = $settings ?? new Settings();
			$this->folio_manager  = $folio_manager ?? new FolioManager( $this->settings );
			$this->signer         = $signer ?? new Signer();
			$this->api            = $api ?? new Api();
			$this->rvd_manager    = $rvd_manager ?? new RvdManager( $this->settings );
			$this->endpoints      = $endpoints ?? new Endpoints();
			$this->metrics        = $metrics ?? new Metrics();
			$this->consumo_folios = $consumo_folios ?? new ConsumoFolios( $this->settings, $this->folio_manager, $this->api );

		try {
				$default_engine = new LibreDteEngine( $this->settings );
		} catch ( \RuntimeException $e ) {
				$this->libredte_missing = true;
				$default_engine         = new NullEngine();
		}
			$this->engine = \apply_filters( 'sii_boleta_dte_engine', $default_engine );

			$this->queue = $queue ?? new Queue( $this->engine, $this->settings, $this->api );
			\add_action( Cron::HOOK, array( $this->queue, 'process' ) );
			$this->help = $help ?? new Help();

		if ( class_exists( 'WooCommerce' ) ) {
						$this->woo = new Woo( $this );
						$this->woo->register();
						$checkout_fields = new CheckoutFields( $this->settings );
						$checkout_fields->register();
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

	public function get_settings() {
		return $this->settings; }
	public function get_folio_manager() {
		return $this->folio_manager; }
	public function get_signer() {
		return $this->signer; }
	public function get_api() {
		return $this->api; }
	public function get_rvd_manager() {
		return $this->rvd_manager; }
	public function get_consumo_folios() {
		return $this->consumo_folios; }
	public function get_queue() {
		return $this->queue; }
	public function get_engine() {
		return $this->engine; }

	public function fluent_smtp_profiles( $profiles ) {
		if ( class_exists( '\\FluentMail\\App\\Models\\Settings' ) ) {
			$settings = new \FluentMail\App\Models\Settings();
			$config   = $settings->getConnections();
			foreach ( $config as $key => $data ) {
				$profiles[ $key ] = $data['title'] ?? $key;
			}
		}
		return $profiles;
	}

	public function fluent_smtp_setup_mailer( $phpmailer, $profile ) {
		\do_action( 'fluentmail_before_sending_email', $phpmailer, $profile );
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
}

class_alias( \Sii\BoletaDte\Infrastructure\Plugin::class, 'Sii\\BoletaDte\\Core\\Plugin' );
