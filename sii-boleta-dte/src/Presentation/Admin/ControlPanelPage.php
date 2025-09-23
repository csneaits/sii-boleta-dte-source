<?php
namespace Sii\BoletaDte\Presentation\Admin;

use Sii\BoletaDte\Infrastructure\Settings;
use Sii\BoletaDte\Application\FolioManager;
use Sii\BoletaDte\Application\LibroBoletas;
use Sii\BoletaDte\Application\QueueProcessor;
use Sii\BoletaDte\Application\RvdManager;
use Sii\BoletaDte\Infrastructure\Persistence\LogDb;
use Sii\BoletaDte\Infrastructure\Persistence\QueueDb;
use Sii\BoletaDte\Infrastructure\Rest\Api;
use Sii\BoletaDte\Infrastructure\TokenManager;

/**
 * Dashboard-like page summarising plugin status and queue management.
 */
class ControlPanelPage {
	private Settings $settings;
	private FolioManager $folio_manager;
	private QueueProcessor $processor;
        private RvdManager $rvd_manager;
        private LibroBoletas $libro_boletas;
        private Api $api;
        private TokenManager $token_manager;
	/** @var array<int, array{type:string,message:string}> */
	private array $notices = array();

        public function __construct( Settings $settings, FolioManager $folio_manager, QueueProcessor $processor, RvdManager $rvd_manager, LibroBoletas $libro_boletas, Api $api, TokenManager $token_manager ) {
                $this->settings      = $settings;
                $this->folio_manager = $folio_manager;
                $this->processor     = $processor;
                $this->rvd_manager   = $rvd_manager;
                $this->libro_boletas = $libro_boletas;
                $this->api           = $api;
                $this->token_manager = $token_manager;
	}

	/** Displays the control panel. */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( isset( $_POST['queue_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
				$this->handle_queue_action( sanitize_text_field( (string) ( $_POST['queue_action'] ?? '' ) ), (int) ( $_POST['job_id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			} elseif ( isset( $_POST['rvd_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
				$this->handle_rvd_action( sanitize_text_field( (string) ( $_POST['rvd_action'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			} elseif ( isset( $_POST['libro_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
				$xml_input = (string) ( $_POST['libro_xml'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
				if ( function_exists( 'wp_unslash' ) ) {
					$xml_input = wp_unslash( $xml_input );
				} else {
					$xml_input = stripslashes( $xml_input );
				}
				$this->handle_libro_action( sanitize_text_field( (string) ( $_POST['libro_action'] ?? '' ) ), $xml_input ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			}
		}

		$tab = 'logs';
		if ( isset( $_GET['tab'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$tab = function_exists( 'sanitize_key' ) ? sanitize_key( (string) $_GET['tab'] ) : strtolower( (string) $_GET['tab'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
                ?>
                <div class="wrap sii-control-panel">
                        <style>
                                .sii-control-panel h1 {
                                        margin-bottom: 1.5rem;
                                }
                                .sii-control-panel .nav-tab-wrapper {
                                        background: #fff;
                                        border-radius: 8px;
                                        padding: 0.4rem;
                                        display: inline-flex;
                                        gap: 0.25rem;
                                        border: 1px solid #d5d8dc;
                                }
                                .sii-control-panel .nav-tab {
                                        border: none;
                                        border-radius: 6px;
                                        background: transparent;
                                        color: #4a5568;
                                        font-weight: 600;
                                        transition: all 0.2s ease;
                                        padding: 0.5rem 1rem;
                                }
                                .sii-control-panel .nav-tab:hover {
                                        background: rgba(58, 123, 213, 0.08);
                                        color: #2b6cb0;
                                }
                                .sii-control-panel .nav-tab.nav-tab-active {
                                        background: linear-gradient(135deg, #3a7bd5 0%, #00d2ff 100%);
                                        color: #fff;
                                        box-shadow: 0 6px 18px rgba(58, 123, 213, 0.25);
                                }
                                .sii-control-panel .sii-section {
                                        background: #fff;
                                        border-radius: 12px;
                                        padding: 1.5rem;
                                        margin-top: 1.5rem;
                                        box-shadow: 0 8px 24px rgba(15, 23, 42, 0.08);
                                }
                                .sii-control-panel .sii-metric-grid {
                                        display: grid;
                                        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
                                        gap: 1rem;
                                        margin-top: 1rem;
                                }
                                .sii-control-panel .sii-metric-card {
                                        border-radius: 10px;
                                        padding: 1rem 1.25rem;
                                        background: linear-gradient(135deg, #edf2ff 0%, #f8f9ff 100%);
                                        border: 1px solid #dbe7ff;
                                        box-shadow: inset 0 1px 0 rgba(255,255,255,0.6);
                                }
                                .sii-control-panel .sii-metric-card h3 {
                                        margin-top: 0;
                                        margin-bottom: 0.6rem;
                                        font-size: 1rem;
                                        color: #1a365d;
                                }
                                .sii-control-panel .sii-metric-value {
                                        font-size: 2rem;
                                        font-weight: 700;
                                        color: #2b6cb0;
                                        margin: 0.25rem 0 0.75rem;
                                }
                                .sii-control-panel .sii-metric-details {
                                        margin: 0;
                                        padding: 0;
                                        list-style: none;
                                        color: #334155;
                                }
                                .sii-control-panel .sii-metric-details li {
                                        margin: 0.15rem 0;
                                }
                                .sii-control-panel .sii-highlight {
                                        border-left: 4px solid #2b6cb0;
                                        padding-left: 1rem;
                                        margin-top: 1rem;
                                        color: #1e293b;
                                }
                                @media (prefers-color-scheme: dark) {
                                        .sii-control-panel .nav-tab-wrapper {
                                                background: rgba(15,23,42,0.65);
                                                border-color: rgba(148,163,184,0.45);
                                        }
                                        .sii-control-panel .nav-tab {
                                                color: #e2e8f0;
                                        }
                                        .sii-control-panel .nav-tab:hover {
                                                color: #63b3ed;
                                        }
                                        .sii-control-panel .nav-tab.nav-tab-active {
                                                box-shadow: 0 8px 20px rgba(15, 118, 255, 0.35);
                                        }
                                        .sii-control-panel .sii-section {
                                                background: rgba(15,23,42,0.65);
                                                box-shadow: 0 16px 30px rgba(2, 6, 23, 0.45);
                                        }
                                        .sii-control-panel .sii-metric-card {
                                                background: linear-gradient(135deg, rgba(30,64,175,0.55) 0%, rgba(14,116,144,0.45) 100%);
                                                border-color: rgba(59,130,246,0.45);
                                                color: #f8fafc;
                                        }
                                        .sii-control-panel .sii-metric-card h3,
                                        .sii-control-panel .sii-metric-details,
                                        .sii-control-panel .sii-highlight {
                                                color: #e2e8f0;
                                        }
                                        .sii-control-panel .sii-metric-value {
                                                color: #63b3ed;
                                        }
                                }
                        </style>
                        <?php $this->render_notices(); ?>
                        <h1><?php echo esc_html__( 'Control Panel', 'sii-boleta-dte' ); ?></h1>
                        <h2 class="nav-tab-wrapper">
                                <?php
                                $base = function_exists( 'menu_page_url' ) ? menu_page_url( 'sii-boleta-dte', false ) : '?page=sii-boleta-dte';
                                $tabs = array(
                                        'logs'    => __( 'Recent DTEs', 'sii-boleta-dte' ),
                                        'queue'   => __( 'Queue', 'sii-boleta-dte' ),
                                        'rvd'     => __( 'RVD', 'sii-boleta-dte' ),
                                        'libro'   => __( 'Libro validation', 'sii-boleta-dte' ),
                                        'metrics' => __( 'Metrics', 'sii-boleta-dte' ),
                                );
                                if ( ! isset( $tabs[ $tab ] ) ) {
                                        $tab = 'logs';
                                }
                                foreach ( $tabs as $key => $label ) {
					$active = $tab === $key ? ' nav-tab-active' : '';
					echo '<a href="' . esc_url( $base . '&tab=' . $key ) . '" class="nav-tab' . $active . '">' . esc_html( $label ) . '</a>';
				}
				?>
			</h2>
			<?php
                        if ( 'queue' === $tab ) {
                                $this->render_queue();
                        } elseif ( 'rvd' === $tab ) {
                                $this->render_rvd_tools();
                        } elseif ( 'libro' === $tab ) {
                                $this->render_libro_validation();
                        } elseif ( 'metrics' === $tab ) {
                                $this->render_metrics_dashboard();
                        } else {
                                $this->render_recent_logs();
                        }
			?>
		</div>
		<?php
	}

	/** Renders folio availability table. */
	private function render_folios(): void {
		$cfg   = $this->settings->get_settings();
		$types = $cfg['enabled_types'] ?? array();
		?>
		<h2><?php echo esc_html__( 'Folio availability', 'sii-boleta-dte' ); ?></h2>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php echo esc_html__( 'Type', 'sii-boleta-dte' ); ?></th>
					<th><?php echo esc_html__( 'Available', 'sii-boleta-dte' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php
				$environment = $this->settings->get_environment();
				foreach ( $types as $type ) :
					$caf       = $this->folio_manager->get_caf_info( (int) $type );
					$last      = Settings::get_last_folio_value( (int) $type, $environment );
					$available = isset( $caf['H'] ) ? (int) $caf['H'] - $last : 0;
					?>
					<tr>
						<td><?php echo (int) $type; ?></td>
						<td><?php echo (int) $available; ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/** Shows latest log entries. */
        private function render_recent_logs(): void {
                $logs = LogDb::get_logs( array( 'limit' => 5 ) );
                ?>
                <div class="sii-section">
                        <h2><?php echo esc_html__( 'Recent DTEs', 'sii-boleta-dte' ); ?></h2>
                        <table class="widefat striped">
                        <thead>
                                <tr>
                                        <th><?php echo esc_html__( 'Track ID', 'sii-boleta-dte' ); ?></th>
                                        <th><?php echo esc_html__( 'Status', 'sii-boleta-dte' ); ?></th>
                                </tr>
			</thead>
			<tbody>
				<?php foreach ( $logs as $row ) : ?>
				<tr>
					<td><?php echo esc_html( $row['track_id'] ); ?></td>
					<td><?php echo esc_html( $row['status'] ); ?></td>
				</tr>
                                <?php endforeach; ?>
                        </tbody>
                        </table>
                </div>
                <?php
        }

        /** Lists queue items with controls. */
        private function render_queue(): void {
                $jobs = QueueDb::get_pending_jobs();
                ?>
                <div class="sii-section">
                        <h2><?php echo esc_html__( 'Queue', 'sii-boleta-dte' ); ?></h2>
                        <?php if ( empty( $jobs ) ) : ?>
                        <p><?php echo esc_html__( 'No queued items.', 'sii-boleta-dte' ); ?></p>
                        <?php else : ?>
                        <table class="wp-list-table widefat fixed striped">
                <thead>
                <tr>
                        <th><?php echo esc_html__( 'ID', 'sii-boleta-dte' ); ?></th>
                        <th><?php echo esc_html__( 'Type', 'sii-boleta-dte' ); ?></th>
			<th><?php echo esc_html__( 'Attempts', 'sii-boleta-dte' ); ?></th>
			<th><?php echo esc_html__( 'Actions', 'sii-boleta-dte' ); ?></th>
		</tr>
		</thead>
		<tbody>
			<?php foreach ( $jobs as $job ) : ?>
		<tr>
			<td><?php echo (int) $job['id']; ?></td>
			<td><?php echo esc_html( $this->translate_type( $job['type'] ) ); ?></td>
			<td><?php echo (int) $job['attempts']; ?></td>
			<td>
			<form method="post" style="display:inline">
				<input type="hidden" name="job_id" value="<?php echo (int) $job['id']; ?>" />
				<?php $this->output_nonce_field( 'sii_boleta_queue', 'sii_boleta_queue_nonce' ); ?>
				<button class="button" name="queue_action" value="process"><?php echo esc_html__( 'Process', 'sii-boleta-dte' ); ?></button>
				<button class="button" name="queue_action" value="requeue"><?php echo esc_html__( 'Retry', 'sii-boleta-dte' ); ?></button>
				<button class="button" name="queue_action" value="cancel"><?php echo esc_html__( 'Cancel', 'sii-boleta-dte' ); ?></button>
				</form>
			</td>
                </tr>
                <?php endforeach; ?>
                </tbody>
                        </table>
                        <?php endif; ?>
                </div>
                <?php
        }

        private function render_rvd_tools(): void {
                ?>
                <div class="sii-section">
                        <h2><?php echo esc_html__( 'Generate and send RVD', 'sii-boleta-dte' ); ?></h2>
                        <p><?php echo esc_html__( 'Creates the daily sales summary and sends it to the SII immediately.', 'sii-boleta-dte' ); ?></p>
                        <form method="post">
                        <input type="hidden" name="rvd_action" value="generate_send" />
                        <?php $this->output_nonce_field( 'sii_boleta_rvd', 'sii_boleta_rvd_nonce' ); ?>
                        <button type="submit" class="button button-primary"><?php echo esc_html__( 'Generate and send RVD', 'sii-boleta-dte' ); ?></button>
                        </form>
                        <?php $this->render_rvd_schedule(); ?>
                </div>
                <?php
        }

        private function render_libro_validation(): void {
                ?>
                <div class="sii-section">
                        <h2><?php echo esc_html__( 'Validate Libro XML', 'sii-boleta-dte' ); ?></h2>
                        <p><?php echo esc_html__( 'Paste the Libro XML to verify it against the official schema.', 'sii-boleta-dte' ); ?></p>
                        <?php $this->render_libro_schedule(); ?>
                        <form method="post">
                        <input type="hidden" name="libro_action" value="validate" />
                        <?php $this->output_nonce_field( 'sii_boleta_libro', 'sii_boleta_libro_nonce' ); ?>
                        <textarea name="libro_xml" rows="10" class="large-text" placeholder="&lt;LibroBoleta&gt;...&lt;/LibroBoleta&gt;"></textarea>
                        <?php
                        if ( function_exists( 'submit_button' ) ) {
                                submit_button( __( 'Validate XML', 'sii-boleta-dte' ) );
                        } else {
                                echo '<button type="submit" class="button button-primary">' . esc_html__( 'Validate XML', 'sii-boleta-dte' ) . '</button>';
                        }
                        ?>
                        </form>
                </div>
                <?php
        }

        private function render_metrics_dashboard(): void {
                $logs          = LogDb::get_logs( array( 'limit' => 50 ) );
                $status_counts = array();
                foreach ( $logs as $row ) {
                        $status = isset( $row['status'] ) ? (string) $row['status'] : '';
                        if ( '' === $status ) {
                                continue;
                        }
                        if ( ! isset( $status_counts[ $status ] ) ) {
                                $status_counts[ $status ] = 0;
                        }
                        ++$status_counts[ $status ];
                }

                $total_dtes    = count( $logs );
                $accepted_dtes = $status_counts['accepted'] ?? 0;
                $sent_dtes     = $status_counts['sent'] ?? 0;
                $rejected_dtes = $status_counts['rejected'] ?? 0;

                $cfg          = $this->settings->get_settings();
                $environment  = $this->settings->get_environment();
                $rvd_enabled  = ! empty( $cfg['rvd_auto_enabled'] );
                $rvd_time     = isset( $cfg['rvd_auto_time'] ) ? (string) $cfg['rvd_auto_time'] : '02:00';
                if ( ! preg_match( '/^(\d{2}):(\d{2})$/', $rvd_time ) ) {
                        $rvd_time = '02:00';
                }
                $rvd_last_run = Settings::get_schedule_last_run( 'rvd', $environment );
                $rvd_next     = $rvd_enabled ? $this->next_daily_run_timestamp( $rvd_time ) : 0;

                $libro_enabled = ! empty( $cfg['libro_auto_enabled'] );
                $libro_day     = isset( $cfg['libro_auto_day'] ) ? (int) $cfg['libro_auto_day'] : 1;
                if ( $libro_day < 1 || $libro_day > 31 ) {
                        $libro_day = 1;
                }
                $libro_time = isset( $cfg['libro_auto_time'] ) ? (string) $cfg['libro_auto_time'] : '03:00';
                if ( ! preg_match( '/^(\d{2}):(\d{2})$/', $libro_time ) ) {
                        $libro_time = '03:00';
                }
                $libro_last_run = Settings::get_schedule_last_run( 'libro', $environment );
                $libro_next     = $libro_enabled ? $this->next_monthly_run_timestamp( $libro_day, $libro_time ) : 0;
                $libro_period   = $this->previous_month_period( $this->current_timestamp() );

                $queue_jobs    = QueueDb::get_pending_jobs( 50 );
                $queue_counts  = array(
                        'dte'   => 0,
                        'rvd'   => 0,
                        'libro' => 0,
                );
                foreach ( $queue_jobs as $job ) {
                        $type = isset( $job['type'] ) ? (string) $job['type'] : '';
                        if ( isset( $queue_counts[ $type ] ) ) {
                                ++$queue_counts[ $type ];
                        }
                }
                ?>
                <div class="sii-section">
                        <h2><?php echo esc_html__( 'Operational metrics', 'sii-boleta-dte' ); ?></h2>
                        <div class="sii-metric-grid">
                                <div class="sii-metric-card">
                                        <h3><?php echo esc_html__( 'DTE performance', 'sii-boleta-dte' ); ?></h3>
                                        <p class="sii-metric-value"><?php echo (int) $total_dtes; ?></p>
                                        <ul class="sii-metric-details">
                                                <li><?php echo esc_html__( 'Accepted', 'sii-boleta-dte' ) . ': ' . (int) $accepted_dtes; ?></li>
                                                <li><?php echo esc_html__( 'Sent (awaiting result)', 'sii-boleta-dte' ) . ': ' . (int) $sent_dtes; ?></li>
                                                <li><?php echo esc_html__( 'Rejected', 'sii-boleta-dte' ) . ': ' . (int) $rejected_dtes; ?></li>
                                        </ul>
                                </div>
                                <div class="sii-metric-card">
                                        <h3><?php echo esc_html__( 'RVD automation', 'sii-boleta-dte' ); ?></h3>
                                        <p class="sii-metric-value"><?php echo esc_html( $rvd_enabled ? __( 'Active', 'sii-boleta-dte' ) : __( 'Paused', 'sii-boleta-dte' ) ); ?></p>
                                        <ul class="sii-metric-details">
                                                <li><?php echo esc_html__( 'Last submission', 'sii-boleta-dte' ) . ': ' . esc_html( '' !== $rvd_last_run ? $rvd_last_run : __( 'Never', 'sii-boleta-dte' ) ); ?></li>
                                                <li><?php echo esc_html__( 'Next run', 'sii-boleta-dte' ) . ': ' . esc_html( $this->format_datetime( $rvd_next ) ); ?></li>
                                                <li><?php echo esc_html__( 'Pending jobs', 'sii-boleta-dte' ) . ': ' . (int) $queue_counts['rvd']; ?></li>
                                        </ul>
                                </div>
                                <div class="sii-metric-card">
                                        <h3><?php echo esc_html__( 'Libro de boletas', 'sii-boleta-dte' ); ?></h3>
                                        <p class="sii-metric-value"><?php echo esc_html( $libro_enabled ? __( 'Scheduled', 'sii-boleta-dte' ) : __( 'Manual', 'sii-boleta-dte' ) ); ?></p>
                                        <ul class="sii-metric-details">
                                                <li><?php echo esc_html__( 'Last report', 'sii-boleta-dte' ) . ': ' . esc_html( '' !== $libro_last_run ? $libro_last_run : __( 'Never', 'sii-boleta-dte' ) ); ?></li>
                                                <li><?php echo esc_html__( 'Next reporting window', 'sii-boleta-dte' ) . ': ' . esc_html( $this->format_datetime( $libro_next ) ); ?></li>
                                                <li><?php echo esc_html__( 'Period under preparation', 'sii-boleta-dte' ) . ': ' . esc_html( $libro_period ); ?></li>
                                                <li><?php echo esc_html__( 'Pending jobs', 'sii-boleta-dte' ) . ': ' . (int) $queue_counts['libro']; ?></li>
                                        </ul>
                                </div>
                        </div>
                        <div class="sii-highlight">
                                <strong><?php echo esc_html__( 'Focus on RVD:', 'sii-boleta-dte' ); ?></strong>
                                <span><?php echo esc_html__( 'Keep the daily summary flowing — it is our next objective to perfect the RVD pipeline.', 'sii-boleta-dte' ); ?></span>
                        </div>
                </div>
                <?php
        }

	/** Handles queue actions. */
	public function handle_queue_action( string $action, int $id ): void {
		if ( empty( $action ) ) {
			return;
		}

		if ( ! $this->verify_nonce( 'sii_boleta_queue_nonce', 'sii_boleta_queue' ) ) {
			$this->add_notice( __( 'Security verification failed. Please try again.', 'sii-boleta-dte' ), 'error' );
			return;
		}

		if ( 'process' === $action ) {
			$this->processor->process( $id );
		} elseif ( 'cancel' === $action ) {
			$this->processor->cancel( $id );
		} elseif ( 'requeue' === $action ) {
			$this->processor->retry( $id );
		}

		$this->add_notice( __( 'Queue action executed.', 'sii-boleta-dte' ) );
	}

	private function handle_rvd_action( string $action ): void {
		if ( 'generate_send' !== $action ) {
			return;
		}

		if ( ! $this->verify_nonce( 'sii_boleta_rvd_nonce', 'sii_boleta_rvd' ) ) {
			$this->add_notice( __( 'Security verification failed. Please try again.', 'sii-boleta-dte' ), 'error' );
			return;
		}

		$xml = $this->rvd_manager->generate_xml();
		if ( '' === $xml ) {
			$this->add_notice( __( 'Unable to generate the RVD XML. Check your configuration and try again.', 'sii-boleta-dte' ), 'error' );
			return;
		}

		if ( ! $this->rvd_manager->validate_rvd_xml( $xml ) ) {
			$this->add_notice( __( 'The generated RVD XML is not valid.', 'sii-boleta-dte' ), 'error' );
			return;
		}

                $environment = $this->settings->get_environment();
                $token       = $this->token_manager->get_token( $environment );
                $response    = $this->api->send_libro_to_sii( $xml, $environment, $token );
		$is_error    = false;
		if ( function_exists( 'is_wp_error' ) && is_wp_error( $response ) ) {
			$is_error = true;
		} elseif ( class_exists( '\WP_Error' ) && $response instanceof \WP_Error ) {
			$is_error = true;
		}

		if ( $is_error ) {
			$error_message = method_exists( $response, 'get_error_message' ) ? $response->get_error_message() : __( 'Unknown error', 'sii-boleta-dte' );
			$this->add_notice( sprintf( __( 'RVD sending failed: %s', 'sii-boleta-dte' ), $error_message ), 'error' );
			return;
		}

		$track = '';
		if ( is_array( $response ) && isset( $response['trackId'] ) ) {
			$track = (string) $response['trackId'];
		}

		$message = __( 'RVD sent successfully.', 'sii-boleta-dte' );
		if ( '' !== $track ) {
			$message .= ' ' . sprintf( __( 'Track ID: %s', 'sii-boleta-dte' ), $track );
		}

		$this->add_notice( $message );
	}

        private function handle_libro_action( string $action, string $xml ): void {
                if ( 'validate' !== $action ) {
                        return;
                }

		if ( ! $this->verify_nonce( 'sii_boleta_libro_nonce', 'sii_boleta_libro' ) ) {
			$this->add_notice( __( 'Security verification failed. Please try again.', 'sii-boleta-dte' ), 'error' );
			return;
		}

		$xml = trim( $xml );
		if ( '' === $xml ) {
			$this->add_notice( __( 'Please paste the Libro XML before validating.', 'sii-boleta-dte' ), 'error' );
			return;
		}

		if ( $this->libro_boletas->validate_libro_xml( $xml ) ) {
			$this->add_notice( __( 'Libro XML is valid.', 'sii-boleta-dte' ) );
		} else {
			$this->add_notice( __( 'Libro XML did not pass validation. Review the structure and try again.', 'sii-boleta-dte' ), 'error' );
                }
        }

        private function output_nonce_field( string $action, string $name ): void {
                if ( function_exists( 'wp_nonce_field' ) ) {
			\wp_nonce_field( $action, $name );
			return;
		}

		$action_attr = htmlspecialchars( $action, ENT_QUOTES, 'UTF-8' );
		$name_attr   = htmlspecialchars( $name, ENT_QUOTES, 'UTF-8' );
		echo '<input type="hidden" name="' . $name_attr . '" value="' . $action_attr . '" />';
	}

	private function verify_nonce( string $field, string $action ): bool {
		if ( empty( $_POST[ $field ] ) ) {
			return false;
		}

		if ( function_exists( 'wp_verify_nonce' ) ) {
			return (bool) \wp_verify_nonce( (string) $_POST[ $field ], $action );
		}

                return true;
        }

        private function add_notice( string $message, string $type = 'success' ): void {
                $this->notices[] = array(
			'type'    => 'error' === $type ? 'error' : 'success',
			'message' => $message,
		);
	}

	private function render_notices(): void {
		if ( empty( $this->notices ) ) {
			return;
		}

		foreach ( $this->notices as $notice ) {
			$type    = 'error' === $notice['type'] ? 'notice-error' : 'notice-success';
			$message = $notice['message'];
			echo '<div class="notice ' . esc_attr( $type ) . '"><p>' . esc_html( $message ) . '</p></div>';
		}

		$this->notices = array();
	}

	/** Returns a translated label for a queue job type. */
        private function translate_type( string $type ): string {
                $map = array(
                        'dte'   => __( 'DTE', 'sii-boleta-dte' ),
                        'libro' => __( 'Libro', 'sii-boleta-dte' ),
                        'rvd'   => __( 'RVD', 'sii-boleta-dte' ),
                );
                return $map[ $type ] ?? $type;
        }

        private function render_rvd_schedule(): void {
                $cfg       = $this->settings->get_settings();
                $enabled   = ! empty( $cfg['rvd_auto_enabled'] );
                $time      = isset( $cfg['rvd_auto_time'] ) ? (string) $cfg['rvd_auto_time'] : '02:00';
                if ( ! preg_match( '/^(\d{2}):(\d{2})$/', $time ) ) {
                        $time = '02:00';
                }
                $environment = $this->settings->get_environment();
                $last        = Settings::get_schedule_last_run( 'rvd', $environment );
                $next_ts     = $enabled ? $this->next_daily_run_timestamp( $time ) : 0;
                ?>
                <h3><?php echo esc_html__( 'Automatic schedule', 'sii-boleta-dte' ); ?></h3>
                <p><?php echo esc_html__( 'Status:', 'sii-boleta-dte' ) . ' ' . esc_html( $enabled ? __( 'Enabled', 'sii-boleta-dte' ) : __( 'Disabled', 'sii-boleta-dte' ) ); ?></p>
                <?php if ( $enabled ) : ?>
                        <p><?php echo esc_html__( 'Daily time:', 'sii-boleta-dte' ) . ' ' . esc_html( $time ); ?></p>
                        <p><?php echo esc_html__( 'Next run:', 'sii-boleta-dte' ) . ' ' . esc_html( $this->format_datetime( $next_ts ) ); ?></p>
                <?php endif; ?>
                <p><?php echo esc_html__( 'Last run:', 'sii-boleta-dte' ) . ' ' . esc_html( '' !== $last ? $last : __( 'Never', 'sii-boleta-dte' ) ); ?></p>
                <?php
        }

        private function render_libro_schedule(): void {
                $cfg       = $this->settings->get_settings();
                $enabled   = ! empty( $cfg['libro_auto_enabled'] );
                $day       = isset( $cfg['libro_auto_day'] ) ? (int) $cfg['libro_auto_day'] : 1;
                if ( $day < 1 || $day > 31 ) {
                        $day = 1;
                }
                $time      = isset( $cfg['libro_auto_time'] ) ? (string) $cfg['libro_auto_time'] : '03:00';
                if ( ! preg_match( '/^(\d{2}):(\d{2})$/', $time ) ) {
                        $time = '03:00';
                }
                $environment = $this->settings->get_environment();
                $last        = Settings::get_schedule_last_run( 'libro', $environment );
                $next_ts     = $enabled ? $this->next_monthly_run_timestamp( $day, $time ) : 0;
                $period      = $this->previous_month_period( $this->current_timestamp() );
                ?>
                <h3><?php echo esc_html__( 'Monthly Libro schedule', 'sii-boleta-dte' ); ?></h3>
                <p><?php echo esc_html__( 'Status:', 'sii-boleta-dte' ) . ' ' . esc_html( $enabled ? __( 'Enabled', 'sii-boleta-dte' ) : __( 'Disabled', 'sii-boleta-dte' ) ); ?></p>
                <?php if ( $enabled ) : ?>
                        <p><?php echo esc_html__( 'Scheduled day:', 'sii-boleta-dte' ) . ' ' . esc_html( (string) $day ); ?></p>
                        <p><?php echo esc_html__( 'Send time:', 'sii-boleta-dte' ) . ' ' . esc_html( $time ); ?></p>
                        <p><?php echo esc_html__( 'Next run:', 'sii-boleta-dte' ) . ' ' . esc_html( $this->format_datetime( $next_ts ) ); ?></p>
                        <p><?php echo esc_html__( 'Period to be reported:', 'sii-boleta-dte' ) . ' ' . esc_html( $period ); ?></p>
                <?php endif; ?>
                <p><?php echo esc_html__( 'Last run:', 'sii-boleta-dte' ) . ' ' . esc_html( '' !== $last ? $last : __( 'Never', 'sii-boleta-dte' ) ); ?></p>
                <?php
        }

        private function current_timestamp(): int {
                if ( function_exists( 'current_time' ) ) {
                        return (int) current_time( 'timestamp' );
                }
                return time();
        }

        private function get_timezone(): \DateTimeZone {
                try {
                        if ( function_exists( 'wp_timezone' ) ) {
                                return wp_timezone();
                        }
                } catch ( \Throwable $e ) {
                        // Ignore and fallback.
                }
                return new \DateTimeZone( 'UTC' );
        }

        private function timestamp_for_time( string $time, int $reference ): int {
                $timezone = $this->get_timezone();
                $date     = new \DateTimeImmutable( '@' . $reference );
                $date     = $date->setTimezone( $timezone );
                list( $hour, $minute ) = array_map( 'intval', explode( ':', $time ) );
                $date = $date->setTime( $hour, $minute, 0 );
                return $date->getTimestamp();
        }

        private function next_daily_run_timestamp( string $time ): int {
                $now    = $this->current_timestamp();
                $target = $this->timestamp_for_time( $time, $now );
                if ( $now >= $target ) {
                        $target = $this->timestamp_for_time( $time, $now + 86400 );
                }
                return $target;
        }

        private function timestamp_for_month_day_time( int $day, string $time, int $reference ): int {
                $timezone = $this->get_timezone();
                $date     = new \DateTimeImmutable( '@' . $reference );
                $date     = $date->setTimezone( $timezone );
                $year     = (int) $date->format( 'Y' );
                $month    = (int) $date->format( 'm' );
                $base     = new \DateTimeImmutable( sprintf( '%04d-%02d-01 00:00:00', $year, $month ), $timezone );
                $days     = (int) $base->format( 't' );
                $day      = min( max( 1, $day ), $days );
                list( $hour, $minute ) = array_map( 'intval', explode( ':', $time ) );
                $target = $base->setDate( $year, $month, $day )->setTime( $hour, $minute, 0 );
                return $target->getTimestamp();
        }

        private function next_monthly_run_timestamp( int $day, string $time ): int {
                $now    = $this->current_timestamp();
                $target = $this->timestamp_for_month_day_time( $day, $time, $now );
                if ( $now >= $target ) {
                        $timezone = $this->get_timezone();
                        $date     = new \DateTimeImmutable( '@' . $now );
                        $date     = $date->setTimezone( $timezone )->modify( 'first day of next month' );
                        $year     = (int) $date->format( 'Y' );
                        $month    = (int) $date->format( 'm' );
                        $days     = (int) $date->format( 't' );
                        $day      = min( max( 1, $day ), $days );
                        list( $hour, $minute ) = array_map( 'intval', explode( ':', $time ) );
                        $target = $date->setDate( $year, $month, $day )->setTime( $hour, $minute, 0 )->getTimestamp();
                }
                return $target;
        }

        private function previous_month_period( int $timestamp ): string {
                $timezone = $this->get_timezone();
                $date     = new \DateTimeImmutable( '@' . $timestamp );
                $date     = $date->setTimezone( $timezone )->modify( 'first day of last month' );
                return $date->format( 'Y-m' );
        }

        private function format_datetime( int $timestamp ): string {
                if ( $timestamp <= 0 ) {
                        return __( 'Not scheduled', 'sii-boleta-dte' );
                }
                if ( function_exists( 'wp_date' ) ) {
                        return wp_date( 'Y-m-d H:i', $timestamp );
                }
                return gmdate( 'Y-m-d H:i', $timestamp );
        }
}

class_alias( ControlPanelPage::class, 'SII_Boleta_Control_Panel_Page' );
