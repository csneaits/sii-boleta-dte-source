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
		<div class="wrap">
			<?php $this->render_notices(); ?>
			<h1><?php echo esc_html__( 'Control Panel', 'sii-boleta-dte' ); ?></h1>
			<h2 class="nav-tab-wrapper">
				<?php
				$base = function_exists( 'menu_page_url' ) ? menu_page_url( 'sii-boleta-dte', false ) : '?page=sii-boleta-dte';
				$tabs = array(
					'logs'  => __( 'Recent DTEs', 'sii-boleta-dte' ),
					'queue' => __( 'Queue', 'sii-boleta-dte' ),
					'rvd'   => __( 'RVD', 'sii-boleta-dte' ),
					'libro' => __( 'Libro validation', 'sii-boleta-dte' ),
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
		<?php
	}

	/** Lists queue items with controls. */
	private function render_queue(): void {
		$jobs = QueueDb::get_pending_jobs();
		?>
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
		<?php
	}

	private function render_rvd_tools(): void {
		?>
		<h2><?php echo esc_html__( 'Generate and send RVD', 'sii-boleta-dte' ); ?></h2>
		<p><?php echo esc_html__( 'Creates the daily sales summary and sends it to the SII immediately.', 'sii-boleta-dte' ); ?></p>
		<form method="post">
			<input type="hidden" name="rvd_action" value="generate_send" />
			<?php $this->output_nonce_field( 'sii_boleta_rvd', 'sii_boleta_rvd_nonce' ); ?>
			<button type="submit" class="button button-primary"><?php echo esc_html__( 'Generate and send RVD', 'sii-boleta-dte' ); ?></button>
		</form>
		<?php
	}

	private function render_libro_validation(): void {
		?>
		<h2><?php echo esc_html__( 'Validate Libro XML', 'sii-boleta-dte' ); ?></h2>
		<p><?php echo esc_html__( 'Paste the Libro XML to verify it against the official schema.', 'sii-boleta-dte' ); ?></p>
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
}

class_alias( ControlPanelPage::class, 'SII_Boleta_Control_Panel_Page' );
