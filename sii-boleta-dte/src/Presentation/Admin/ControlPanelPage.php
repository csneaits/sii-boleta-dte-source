<?php
namespace Sii\BoletaDte\Presentation\Admin;

use Sii\BoletaDte\Infrastructure\Settings;
use Sii\BoletaDte\Application\FolioManager;
use Sii\BoletaDte\Application\QueueProcessor;
use Sii\BoletaDte\Infrastructure\Persistence\LogDb;
use Sii\BoletaDte\Infrastructure\Persistence\QueueDb;

/**
 * Dashboard-like page summarising plugin status and queue management.
 */
class ControlPanelPage {
	private Settings $settings;
	private FolioManager $folio_manager;
	private QueueProcessor $processor;

	public function __construct( Settings $settings, FolioManager $folio_manager, QueueProcessor $processor ) {
			$this->settings      = $settings;
			$this->folio_manager = $folio_manager;
			$this->processor     = $processor;
	}

	/** Displays the control panel. */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
				$this->handle_queue_action( sanitize_text_field( (string) ( $_POST['queue_action'] ?? '' ) ), (int) ( $_POST['job_id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}
		?>
				<div class="wrap">
						<h1><?php esc_html_e( 'Control Panel', 'sii-boleta-dte' ); ?></h1>
						<?php $this->render_folios(); ?>
						<?php $this->render_recent_logs(); ?>
						<?php $this->render_queue(); ?>
				</div>
				<?php
	}

	/** Renders folio availability table. */
	private function render_folios(): void {
				$cfg   = $this->settings->get_settings();
				$types = $cfg['enabled_types'] ?? array();
		?>
				<h2><?php esc_html_e( 'Folio availability', 'sii-boleta-dte' ); ?></h2>
				<table class="widefat striped">
						<thead>
								<tr>
										<th><?php esc_html_e( 'Type', 'sii-boleta-dte' ); ?></th>
										<th><?php esc_html_e( 'Available', 'sii-boleta-dte' ); ?></th>
								</tr>
						</thead>
						<tbody>
								<?php
								foreach ( $types as $type ) :
										$caf       = $this->folio_manager->get_caf_info( (int) $type );
										$last      = function_exists( 'get_option' ) ? (int) get_option( 'sii_boleta_dte_last_folio_' . $type, 0 ) : 0;
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
				<h2><?php esc_html_e( 'Recent DTEs', 'sii-boleta-dte' ); ?></h2>
				<table class="widefat striped">
						<thead>
								<tr>
										<th><?php esc_html_e( 'Track ID', 'sii-boleta-dte' ); ?></th>
										<th><?php esc_html_e( 'Status', 'sii-boleta-dte' ); ?></th>
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
				<?php
	}

	/** Lists queue items with controls. */
	private function render_queue(): void {
				$jobs = QueueDb::get_pending_jobs();
		?>
				<h2><?php esc_html_e( 'Queue', 'sii-boleta-dte' ); ?></h2>
				<?php if ( empty( $jobs ) ) : ?>
						<p><?php esc_html_e( 'No queued items.', 'sii-boleta-dte' ); ?></p>
				<?php else : ?>
						<table class="wp-list-table widefat fixed striped">
								<thead>
										<tr>
												<th><?php esc_html_e( 'ID', 'sii-boleta-dte' ); ?></th>
												<th><?php esc_html_e( 'Type', 'sii-boleta-dte' ); ?></th>
												<th><?php esc_html_e( 'Attempts', 'sii-boleta-dte' ); ?></th>
												<th><?php esc_html_e( 'Actions', 'sii-boleta-dte' ); ?></th>
										</tr>
								</thead>
								<tbody>
										<?php foreach ( $jobs as $job ) : ?>
												<tr>
														<td><?php echo (int) $job['id']; ?></td>
														<td><?php echo esc_html( $job['type'] ); ?></td>
														<td><?php echo (int) $job['attempts']; ?></td>
														<td>
																<form method="post" style="display:inline">
																		<input type="hidden" name="job_id" value="<?php echo (int) $job['id']; ?>" />
																		<?php wp_nonce_field( 'sii_boleta_queue', 'sii_boleta_queue_nonce' ); ?>
																		<button class="button" name="queue_action" value="process"><?php esc_html_e( 'Process', 'sii-boleta-dte' ); ?></button>
																		<button class="button" name="queue_action" value="requeue"><?php esc_html_e( 'Retry', 'sii-boleta-dte' ); ?></button>
																		<button class="button" name="queue_action" value="cancel"><?php esc_html_e( 'Cancel', 'sii-boleta-dte' ); ?></button>
																</form>
														</td>
												</tr>
										<?php endforeach; ?>
								</tbody>
						</table>
				<?php endif; ?>
				<?php
	}

	/** Handles queue actions. */
	public function handle_queue_action( string $action, int $id ): void {
		if ( empty( $action ) ) {
			return;
		}
		if ( empty( $_POST['sii_boleta_queue_nonce'] ) || ! \wp_verify_nonce( $_POST['sii_boleta_queue_nonce'], 'sii_boleta_queue' ) ) {
			return;
		}
		if ( 'process' === $action ) {
				$this->processor->process( $id );
		} elseif ( 'cancel' === $action ) {
				$this->processor->cancel( $id );
		} elseif ( 'requeue' === $action ) {
				$this->processor->retry( $id );
		}
	}
}

class_alias( ControlPanelPage::class, 'SII_Boleta_Control_Panel_Page' );
