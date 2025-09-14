<?php
namespace Sii\BoletaDte\Presentation\Admin;

use Sii\BoletaDte\Infrastructure\Settings;
use Sii\BoletaDte\Application\FolioManager;
use Sii\BoletaDte\Application\Queue;
use Sii\BoletaDte\Infrastructure\Persistence\LogDb;
use Sii\BoletaDte\Infrastructure\Persistence\QueueDb;

/**
 * Dashboard-like page summarising plugin status and queue management.
 */
class ControlPanelPage {
	private Settings $settings;
	private FolioManager $folio_manager;
	private Queue $queue;

	public function __construct( Settings $settings, FolioManager $folio_manager, Queue $queue ) {
		$this->settings      = $settings;
		$this->folio_manager = $folio_manager;
		$this->queue         = $queue;
	}

	/** Displays the control panel. */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			$this->handle_queue_action( sanitize_text_field( (string) ( $_POST['queue_action'] ?? '' ) ), (int) ( $_POST['job_id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}
		echo '<div class="wrap">';
		echo '<h1>' . \esc_html__( 'Control Panel', 'sii-boleta-dte' ) . '</h1>';
		$this->render_folios();
		$this->render_recent_logs();
		$this->render_queue();
		echo '</div>';
	}

	/** Renders folio availability table. */
	private function render_folios(): void {
		$cfg   = $this->settings->get_settings();
		$types = $cfg['enabled_types'] ?? array();
		echo '<h2>' . \esc_html__( 'Folio availability', 'sii-boleta-dte' ) . '</h2>';
		echo '<table class="widefat"><thead><tr><th>Tipo</th><th>' . \esc_html__( 'Available', 'sii-boleta-dte' ) . '</th></tr></thead><tbody>';
		foreach ( $types as $type ) {
			$caf       = $this->folio_manager->get_caf_info( (int) $type );
			$last      = function_exists( 'get_option' ) ? (int) get_option( 'sii_boleta_dte_last_folio_' . $type, 0 ) : 0;
			$available = isset( $caf['H'] ) ? (int) $caf['H'] - $last : 0;
			echo '<tr><td>' . (int) $type . '</td><td>' . (int) $available . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/** Shows latest log entries. */
	private function render_recent_logs(): void {
		$logs = LogDb::get_logs( array( 'limit' => 5 ) );
		echo '<h2>' . \esc_html__( 'Recent DTEs', 'sii-boleta-dte' ) . '</h2>';
		echo '<table class="widefat"><thead><tr><th>Track ID</th><th>Status</th></tr></thead><tbody>';
		foreach ( $logs as $row ) {
			echo '<tr><td>' . \esc_html( $row['track_id'] ) . '</td><td>' . \esc_html( $row['status'] ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/** Lists queue items with controls. */
	private function render_queue(): void {
		$jobs = QueueDb::get_pending_jobs();
		echo '<h2>' . \esc_html__( 'Queue', 'sii-boleta-dte' ) . '</h2>';
		if ( empty( $jobs ) ) {
			echo '<p>' . \esc_html__( 'No queued items.', 'sii-boleta-dte' ) . '</p>';
			return;
		}
		echo '<table class="widefat"><thead><tr><th>ID</th><th>Type</th><th>Attempts</th><th>Actions</th></tr></thead><tbody>';
		foreach ( $jobs as $job ) {
			echo '<tr><td>' . (int) $job['id'] . '</td><td>' . \esc_html( $job['type'] ) . '</td><td>' . (int) $job['attempts'] . '</td><td>';
			echo '<form method="post" style="display:inline"><input type="hidden" name="job_id" value="' . (int) $job['id'] . '" />';
			\wp_nonce_field( 'sii_boleta_queue', 'sii_boleta_queue_nonce' );
			echo '<button class="button" name="queue_action" value="process">' . \esc_html__( 'Process', 'sii-boleta-dte' ) . '</button> ';
			echo '<button class="button" name="queue_action" value="requeue">' . \esc_html__( 'Retry', 'sii-boleta-dte' ) . '</button> ';
			echo '<button class="button" name="queue_action" value="cancel">' . \esc_html__( 'Cancel', 'sii-boleta-dte' ) . '</button>';
			echo '</form></td></tr>';
		}
		echo '</tbody></table>';
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
			$this->queue->process();
		} elseif ( 'cancel' === $action ) {
			QueueDb::delete( $id );
		} elseif ( 'requeue' === $action ) {
			QueueDb::reset_attempts( $id );
		}
	}
}

class_alias( ControlPanelPage::class, 'SII_Boleta_Control_Panel_Page' );
