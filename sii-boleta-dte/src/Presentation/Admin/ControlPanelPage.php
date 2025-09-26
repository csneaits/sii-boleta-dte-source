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
<?php AdminStyles::open_container( 'sii-control-panel' ); ?>
<?php $this->render_notices(); ?>
<h1><?php echo esc_html__( 'Panel de control', 'sii-boleta-dte' ); ?></h1>
<p><?php echo esc_html__( 'Supervisa tus operaciones DTE, las colas y las tareas programadas desde un panel unificado.', 'sii-boleta-dte' ); ?></p>
<h2 class="nav-tab-wrapper">
								<?php
								$base = function_exists( 'menu_page_url' ) ? menu_page_url( 'sii-boleta-dte', false ) : '?page=sii-boleta-dte';
$tabs = array(
'logs'    => __( 'DTE recientes', 'sii-boleta-dte' ),
'queue'   => __( 'Cola', 'sii-boleta-dte' ),
'rvd'     => __( 'RVD', 'sii-boleta-dte' ),
'libro'   => __( 'Validación de Libros', 'sii-boleta-dte' ),
'metrics' => __( 'Métricas', 'sii-boleta-dte' ),
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
<h2><?php echo esc_html__( 'Disponibilidad de folios', 'sii-boleta-dte' ); ?></h2>
<table class="widefat striped">
<thead>
<tr>
<th><?php echo esc_html__( 'Tipo', 'sii-boleta-dte' ); ?></th>
<th><?php echo esc_html__( 'Disponibles', 'sii-boleta-dte' ); ?></th>
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
<h2><?php echo esc_html__( 'DTE recientes', 'sii-boleta-dte' ); ?></h2>
<table class="widefat striped">
<thead>
<tr>
<th><?php echo esc_html__( 'ID de seguimiento', 'sii-boleta-dte' ); ?></th>
<th><?php echo esc_html__( 'Estado', 'sii-boleta-dte' ); ?></th>
</tr>
</thead>
<tbody id="sii-control-logs-body">
<?php if ( empty( $logs ) ) : ?>
<tr class="sii-control-empty-row">
<td colspan="2"><?php echo esc_html__( 'Sin DTE recientes.', 'sii-boleta-dte' ); ?></td>
</tr>
<?php else : ?>
<?php foreach ( $logs as $row ) : ?>
<tr>
<td><?php echo esc_html( $row['track_id'] ); ?></td>
<td><?php echo esc_html( $this->translate_status_label( (string) $row['status'] ) ); ?></td>
</tr>
<?php endforeach; ?>
<?php endif; ?>
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
<h2><?php echo esc_html__( 'Cola', 'sii-boleta-dte' ); ?></h2>
<p id="sii-control-queue-empty"<?php echo empty( $jobs ) ? '' : ' style="display:none;"'; ?>><?php echo esc_html__( 'No hay elementos en la cola.', 'sii-boleta-dte' ); ?></p>
<table id="sii-control-queue-table" class="wp-list-table widefat fixed striped"<?php echo empty( $jobs ) ? ' style="display:none;"' : ''; ?>>
<thead>
<tr>
<th><?php echo esc_html__( 'ID', 'sii-boleta-dte' ); ?></th>
<th><?php echo esc_html__( 'Tipo', 'sii-boleta-dte' ); ?></th>
<th><?php echo esc_html__( 'Intentos', 'sii-boleta-dte' ); ?></th>
<th><?php echo esc_html__( 'Acciones', 'sii-boleta-dte' ); ?></th>
</tr>
</thead>
<tbody id="sii-control-queue-body">
<?php if ( empty( $jobs ) ) : ?>
<tr class="sii-control-empty-row">
<td colspan="4"><?php echo esc_html__( 'No hay elementos en la cola.', 'sii-boleta-dte' ); ?></td>
</tr>
<?php else : ?>
<?php foreach ( $jobs as $job ) : ?>
<tr>
<td><?php echo (int) $job['id']; ?></td>
<td><?php echo esc_html( $this->translate_type( $job['type'] ) ); ?></td>
<td><?php echo (int) $job['attempts']; ?></td>
<td>
<form method="post" class="sii-inline-form">
<input type="hidden" name="job_id" value="<?php echo (int) $job['id']; ?>" />
<?php $this->output_nonce_field( 'sii_boleta_queue', 'sii_boleta_queue_nonce' ); ?>
<button class="button" name="queue_action" value="process"><?php echo esc_html__( 'Procesar', 'sii-boleta-dte' ); ?></button>
<button class="button" name="queue_action" value="requeue"><?php echo esc_html__( 'Reintentar', 'sii-boleta-dte' ); ?></button>
<button class="button" name="queue_action" value="cancel"><?php echo esc_html__( 'Cancelar', 'sii-boleta-dte' ); ?></button>
</form>
</td>
</tr>
<?php endforeach; ?>
<?php endif; ?>
</tbody>
</table>
</div>
<?php
}

	private function render_rvd_tools(): void {
		?>
<div class="sii-section">
<h2><?php echo esc_html__( 'Generar y enviar RVD', 'sii-boleta-dte' ); ?></h2>
<p><?php echo esc_html__( 'Genera el resumen de ventas diario y lo envía al SII de inmediato.', 'sii-boleta-dte' ); ?></p>
<form method="post">
<input type="hidden" name="rvd_action" value="generate_send" />
<?php $this->output_nonce_field( 'sii_boleta_rvd', 'sii_boleta_rvd_nonce' ); ?>
<button type="submit" class="button button-primary"><?php echo esc_html__( 'Generar y enviar RVD', 'sii-boleta-dte' ); ?></button>
</form>
<?php $this->render_rvd_schedule(); ?>
</div>
<?php
}

private function render_libro_validation(): void {
?>
<div class="sii-section">
<h2><?php echo esc_html__( 'Validar Libro XML', 'sii-boleta-dte' ); ?></h2>
<p><?php echo esc_html__( 'Pega el XML del Libro para validarlo contra el esquema oficial.', 'sii-boleta-dte' ); ?></p>
<?php $this->render_libro_schedule(); ?>
<form method="post">
<input type="hidden" name="libro_action" value="validate" />
<?php $this->output_nonce_field( 'sii_boleta_libro', 'sii_boleta_libro_nonce' ); ?>
<textarea name="libro_xml" rows="10" class="large-text" placeholder="&lt;LibroBoleta&gt;...&lt;/LibroBoleta&gt;"></textarea>
<?php
if ( function_exists( 'submit_button' ) ) {
submit_button( __( 'Validar XML', 'sii-boleta-dte' ) );
} else {
echo '<button type="submit" class="button button-primary">' . esc_html__( 'Validar XML', 'sii-boleta-dte' ) . '</button>';
}
?>
</form>
</div>
<?php
	}

        private function render_metrics_dashboard(): void {
                $logs  = LogDb::get_logs( array( 'limit' => 500 ) );
                $years = array();
                foreach ( $logs as $row ) {
                        $timestamp = isset( $row['created_at'] ) ? strtotime( (string) $row['created_at'] ) : false;
                        if ( false === $timestamp ) {
                                continue;
                        }
                        $year = (int) gmdate( 'Y', $timestamp );
                        if ( ! in_array( $year, $years, true ) ) {
                                $years[] = $year;
                        }
                }
                rsort( $years );

                $raw_year = isset( $_GET['metrics_year'] ) ? (string) $_GET['metrics_year'] : '';
                if ( function_exists( 'sanitize_text_field' ) ) {
                        $raw_year = sanitize_text_field( $raw_year );
                } else {
                        $raw_year = preg_replace( '/[^0-9]/', '', $raw_year );
                }
                $selected_year = (int) $raw_year;
                if ( $selected_year <= 0 || ! in_array( $selected_year, $years, true ) ) {
                        $selected_year = 0;
                }

                $raw_month = isset( $_GET['metrics_month'] ) ? (string) $_GET['metrics_month'] : '';
                if ( function_exists( 'sanitize_text_field' ) ) {
                        $raw_month = sanitize_text_field( $raw_month );
                } else {
                        $raw_month = preg_replace( '/[^0-9]/', '', $raw_month );
                }
                $selected_month = (int) $raw_month;
                if ( $selected_month < 1 || $selected_month > 12 ) {
                        $selected_month = 0;
                }

                $filtered_logs = array();
                foreach ( $logs as $row ) {
                        $timestamp = isset( $row['created_at'] ) ? strtotime( (string) $row['created_at'] ) : false;
                        if ( false === $timestamp ) {
                                continue;
                        }
                        $year  = (int) gmdate( 'Y', $timestamp );
                        $month = (int) gmdate( 'n', $timestamp );
                        if ( $selected_year > 0 && $year !== $selected_year ) {
                                continue;
                        }
                        if ( $selected_month > 0 && $month !== $selected_month ) {
                                continue;
                        }
                        $row['__timestamp'] = $timestamp;
                        $filtered_logs[]    = $row;
                }

                $status_counts = array();
                foreach ( $filtered_logs as $row ) {
                        $status = isset( $row['status'] ) ? (string) $row['status'] : '';
                        if ( '' === $status ) {
                                continue;
                        }
                        if ( ! isset( $status_counts[ $status ] ) ) {
                                $status_counts[ $status ] = 0;
                        }
                        ++$status_counts[ $status ];
                }

                $total_dtes    = count( $filtered_logs );
                $accepted_dtes = $status_counts['accepted'] ?? 0;
                $sent_dtes     = $status_counts['sent'] ?? 0;
                $rejected_dtes = $status_counts['rejected'] ?? 0;

                $reference_year = $selected_year > 0 ? $selected_year : (int) gmdate( 'Y' );
                $month_options  = array();
                for ( $m = 1; $m <= 12; ++$m ) {
                        $timestamp            = gmmktime( 0, 0, 0, $m, 1, $reference_year );
                        $month_options[ $m ] = function_exists( 'date_i18n' ) ? date_i18n( 'F', $timestamp ) : gmdate( 'F', $timestamp );
                }

                $page_slug = isset( $_GET['page'] ) ? (string) $_GET['page'] : 'sii-boleta-dte';
                if ( function_exists( 'sanitize_key' ) ) {
                        $page_slug = sanitize_key( $page_slug );
                } else {
                        $page_slug = strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', $page_slug ) );
                }
                if ( '' === $page_slug ) {
                        $page_slug = 'sii-boleta-dte';
                }
                $metrics_base = function_exists( 'menu_page_url' ) ? menu_page_url( 'sii-boleta-dte', false ) : '?page=' . rawurlencode( $page_slug );
                $metrics_url  = $metrics_base . '&tab=metrics';

                $series_points  = array();
                $series_caption = '';

                if ( $selected_year > 0 && $selected_month > 0 ) {
                        $days         = $this->days_in_month( $selected_year, $selected_month );
                        $daily_counts = array_fill( 1, $days, 0 );
                        foreach ( $filtered_logs as $row ) {
                                $day = (int) gmdate( 'j', $row['__timestamp'] );
                                if ( isset( $daily_counts[ $day ] ) ) {
                                        ++$daily_counts[ $day ];
                                }
                        }
                        for ( $day = 1; $day <= $days; ++$day ) {
                                $series_points[] = array(
                                        'label' => sprintf( '%02d', $day ),
                                        'value' => $daily_counts[ $day ] ?? 0,
                                );
                        }
                        $month_label   = $month_options[ $selected_month ] ?? (string) $selected_month;
$series_caption = sprintf( __( 'Actividad diaria para %1$s %2$d', 'sii-boleta-dte' ), $month_label, $selected_year );
                } elseif ( $selected_year > 0 ) {
                        $monthly_counts = array_fill( 1, 12, 0 );
                        foreach ( $filtered_logs as $row ) {
                                $month = (int) gmdate( 'n', $row['__timestamp'] );
                                ++$monthly_counts[ $month ];
                        }
                        for ( $month = 1; $month <= 12; ++$month ) {
                                $timestamp = gmmktime( 0, 0, 0, $month, 1, $selected_year );
                                $label     = function_exists( 'date_i18n' ) ? date_i18n( 'M', $timestamp ) : gmdate( 'M', $timestamp );
                                $series_points[] = array(
                                        'label' => $label,
                                        'value' => $monthly_counts[ $month ] ?? 0,
                                );
                        }
$series_caption = sprintf( __( 'Totales mensuales para %d', 'sii-boleta-dte' ), $selected_year );
                } elseif ( $selected_month > 0 ) {
                        $year_counts = array();
                        foreach ( $filtered_logs as $row ) {
                                $year = (int) gmdate( 'Y', $row['__timestamp'] );
                                if ( ! isset( $year_counts[ $year ] ) ) {
                                        $year_counts[ $year ] = 0;
                                }
                                ++$year_counts[ $year ];
                        }
                        ksort( $year_counts );
                        foreach ( $year_counts as $year => $value ) {
                                $series_points[] = array(
                                        'label' => (string) $year,
                                        'value' => $value,
                                );
                        }
                        $month_label   = $month_options[ $selected_month ] ?? (string) $selected_month;
$series_caption = sprintf( __( 'Comparación anual para %s', 'sii-boleta-dte' ), $month_label );
                } else {
                        $month_counts = array();
                        foreach ( $filtered_logs as $row ) {
                                $year  = (int) gmdate( 'Y', $row['__timestamp'] );
                                $month = (int) gmdate( 'n', $row['__timestamp'] );
                                $key   = sprintf( '%04d-%02d', $year, $month );
                                if ( ! isset( $month_counts[ $key ] ) ) {
                                        $month_counts[ $key ] = 0;
                                }
                                ++$month_counts[ $key ];
                        }
                        ksort( $month_counts );
                        if ( count( $month_counts ) > 6 ) {
                                $month_counts = array_slice( $month_counts, -6, null, true );
                        }
                        foreach ( $month_counts as $key => $value ) {
                                list( $year, $month ) = array_map( 'intval', explode( '-', $key ) );
                                $timestamp = gmmktime( 0, 0, 0, $month, 1, $year );
                                $label     = function_exists( 'date_i18n' ) ? date_i18n( 'M Y', $timestamp ) : gmdate( 'M Y', $timestamp );
                                $series_points[] = array(
                                        'label' => $label,
                                        'value' => $value,
                                );
                        }
$series_caption = __( 'Actividad de los últimos seis meses', 'sii-boleta-dte' );
}

if ( '' === $series_caption ) {
$series_caption = __( 'Cronología de los últimos envíos', 'sii-boleta-dte' );
}

                $series_total = 0;
                foreach ( $series_points as $point ) {
                        $series_total += (int) $point['value'];
                }
                $series_max = 0;
                if ( $series_total > 0 ) {
                        $series_max = max( array_map( static fn( $point ) => (int) $point['value'], $series_points ) );
                }

$legend_items = array(
array(
'label' => __( 'Aceptados', 'sii-boleta-dte' ),
'value' => $accepted_dtes,
'color' => '#22c55e',
),
array(
'label' => __( 'Enviados (en espera)', 'sii-boleta-dte' ),
'value' => $sent_dtes,
'color' => '#38bdf8',
),
array(
'label' => __( 'Rechazados', 'sii-boleta-dte' ),
'value' => $rejected_dtes,
'color' => '#ef4444',
),
);

                $pie_total   = 0;
                $gradients   = array();
                $start_angle = 0.0;
                foreach ( $legend_items as &$item ) {
                        $pie_total += (int) $item['value'];
                }
                if ( $pie_total > 0 ) {
                        foreach ( $legend_items as &$item ) {
                                $item_value        = (int) $item['value'];
                                $item['percentage'] = (int) round( ( $item_value / $pie_total ) * 100 );
                                $angle              = ( $item_value / $pie_total ) * 360;
                                $end_angle          = $start_angle + $angle;
                                if ( $angle > 0 ) {
                                        $gradients[] = sprintf( '%s %.2fdeg %.2fdeg', $item['color'], $start_angle, $end_angle );
                                }
                                $start_angle = $end_angle;
                        }
                } else {
                        foreach ( $legend_items as &$item ) {
                                $item['percentage'] = 0;
                        }
                }
                unset( $item );

                $pie_style = '';
                if ( ! empty( $gradients ) ) {
                        $pie_style = 'background: conic-gradient(' . implode( ', ', $gradients ) . ');';
                }

                $cfg         = $this->settings->get_settings();
                $environment = $this->settings->get_environment();
                $rvd_enabled = ! empty( $cfg['rvd_auto_enabled'] );
                $rvd_time    = isset( $cfg['rvd_auto_time'] ) ? (string) $cfg['rvd_auto_time'] : '02:00';
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

                $queue_jobs   = QueueDb::get_pending_jobs( 50 );
                $queue_counts = array(
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

$dominant_status = __( 'Aceptados', 'sii-boleta-dte' );
                $dominant_value  = $accepted_dtes;
                if ( $sent_dtes > $dominant_value ) {
$dominant_status = __( 'Enviados (en espera)', 'sii-boleta-dte' );
                        $dominant_value  = $sent_dtes;
                }
                if ( $rejected_dtes > $dominant_value ) {
$dominant_status = __( 'Rechazados', 'sii-boleta-dte' );
                        $dominant_value  = $rejected_dtes;
                }
                ?>
<div class="sii-section">
<h2><?php echo esc_html__( 'Métricas operativas', 'sii-boleta-dte' ); ?></h2>
                        <form method="get" class="sii-metric-filter">
                                <input type="hidden" name="page" value="<?php echo esc_attr( $page_slug ); ?>" />
                                <input type="hidden" name="tab" value="metrics" />
<label>
<span><?php echo esc_html__( 'Año', 'sii-boleta-dte' ); ?></span>
<select name="metrics_year">
<option value=""><?php echo esc_html__( 'Todos los años', 'sii-boleta-dte' ); ?></option>
                                                <?php foreach ( $years as $year_option ) : ?>
                                                        <?php $selected_attr = ( (int) $year_option === $selected_year ) ? ' selected="selected"' : ''; ?>
                                                        <option value="<?php echo (int) $year_option; ?>"<?php echo $selected_attr; ?>><?php echo (int) $year_option; ?></option>
                                                <?php endforeach; ?>
                                        </select>
                                </label>
<label>
<span><?php echo esc_html__( 'Mes', 'sii-boleta-dte' ); ?></span>
<select name="metrics_month">
<option value=""><?php echo esc_html__( 'Todos los meses', 'sii-boleta-dte' ); ?></option>
                                                <?php foreach ( $month_options as $month_number => $month_label ) : ?>
                                                        <?php $selected_attr = ( (int) $month_number === $selected_month ) ? ' selected="selected"' : ''; ?>
                                                        <option value="<?php echo (int) $month_number; ?>"<?php echo $selected_attr; ?>><?php echo esc_html( $month_label ); ?></option>
                                                <?php endforeach; ?>
                                        </select>
                                </label>
<button type="submit" class="button button-primary"><?php echo esc_html__( 'Aplicar filtros', 'sii-boleta-dte' ); ?></button>
<?php if ( $selected_year || $selected_month ) : ?>
<a class="button" href="<?php echo esc_url( $metrics_url ); ?>"><?php echo esc_html__( 'Reiniciar', 'sii-boleta-dte' ); ?></a>
<?php endif; ?>
                        </form>
<div class="sii-metric-grid">
<div class="sii-metric-card">
<h3><?php echo esc_html__( 'Desempeño de DTE', 'sii-boleta-dte' ); ?></h3>
                                        <p class="sii-metric-value"><?php echo (int) $total_dtes; ?></p>
                                        <ul class="sii-metric-details">
<li><?php echo esc_html__( 'Aceptados', 'sii-boleta-dte' ) . ': ' . (int) $accepted_dtes; ?></li>
<li><?php echo esc_html__( 'Enviados (en espera)', 'sii-boleta-dte' ) . ': ' . (int) $sent_dtes; ?></li>
<li><?php echo esc_html__( 'Rechazados', 'sii-boleta-dte' ) . ': ' . (int) $rejected_dtes; ?></li>
</ul>
</div>
<div class="sii-metric-card">
<h3><?php echo esc_html__( 'Automatización de RVD', 'sii-boleta-dte' ); ?></h3>
<p class="sii-metric-value"><?php echo esc_html( $rvd_enabled ? __( 'Activo', 'sii-boleta-dte' ) : __( 'En pausa', 'sii-boleta-dte' ) ); ?></p>
<ul class="sii-metric-details">
<li><?php echo esc_html__( 'Último envío', 'sii-boleta-dte' ) . ': ' . esc_html( '' !== $rvd_last_run ? $rvd_last_run : __( 'Nunca', 'sii-boleta-dte' ) ); ?></li>
<li><?php echo esc_html__( 'Próxima ejecución', 'sii-boleta-dte' ) . ': ' . esc_html( $this->format_datetime( $rvd_next ) ); ?></li>
<li><?php echo esc_html__( 'Trabajos pendientes', 'sii-boleta-dte' ) . ': ' . (int) $queue_counts['rvd']; ?></li>
</ul>
</div>
<div class="sii-metric-card">
<h3><?php echo esc_html__( 'Libro de boletas', 'sii-boleta-dte' ); ?></h3>
<p class="sii-metric-value"><?php echo esc_html( $libro_enabled ? __( 'Programado', 'sii-boleta-dte' ) : __( 'Manual', 'sii-boleta-dte' ) ); ?></p>
<ul class="sii-metric-details">
<li><?php echo esc_html__( 'Último reporte', 'sii-boleta-dte' ) . ': ' . esc_html( '' !== $libro_last_run ? $libro_last_run : __( 'Nunca', 'sii-boleta-dte' ) ); ?></li>
<li><?php echo esc_html__( 'Próxima ventana de envío', 'sii-boleta-dte' ) . ': ' . esc_html( $this->format_datetime( $libro_next ) ); ?></li>
<li><?php echo esc_html__( 'Período en preparación', 'sii-boleta-dte' ) . ': ' . esc_html( $libro_period ); ?></li>
<li><?php echo esc_html__( 'Trabajos pendientes', 'sii-boleta-dte' ) . ': ' . (int) $queue_counts['libro']; ?></li>
</ul>
</div>
</div>
<div class="sii-metric-charts">
<div class="sii-chart-card">
<h3><?php echo esc_html__( 'Serie temporal', 'sii-boleta-dte' ); ?></h3>
                                        <p><?php echo esc_html( $series_caption ); ?></p>
                                        <?php if ( $series_total > 0 && $series_max > 0 ) : ?>
                                                <div class="sii-series-bars">
                                                        <?php foreach ( $series_points as $point ) : ?>
                                                                <?php
                                                                $value      = (int) $point['value'];
                                                                $height     = max( 6, (int) round( ( $value / $series_max ) * 100 ) );
                                                                $bar_style  = 'height: ' . $height . '%;';
                                                                ?>
                                                                <div class="sii-series-bar" style="<?php echo esc_attr( $bar_style ); ?>" data-value="<?php echo esc_attr( (string) $value ); ?>"></div>
                                                        <?php endforeach; ?>
                                                </div>
                                                <div class="sii-series-axis">
                                                        <?php foreach ( $series_points as $point ) : ?>
                                                                <span><?php echo esc_html( (string) $point['label'] ); ?></span>
                                                        <?php endforeach; ?>
                                                </div>
<?php else : ?>
<div class="sii-chart-empty"><?php echo esc_html__( 'No hay datos disponibles para los filtros seleccionados.', 'sii-boleta-dte' ); ?></div>
<?php endif; ?>
</div>
<div class="sii-chart-card">
<h3><?php echo esc_html__( 'Distribución de estados', 'sii-boleta-dte' ); ?></h3>
<p><?php echo esc_html__( 'Aceptados vs pendientes vs rechazados', 'sii-boleta-dte' ); ?></p>
                                        <?php if ( $pie_total > 0 ) : ?>
                                                <div class="sii-chart-pie"<?php echo '' !== $pie_style ? ' style="' . esc_attr( $pie_style ) . '"' : ''; ?>></div>
<?php else : ?>
<div class="sii-chart-empty"><?php echo esc_html__( 'No hay actividad de DTE para mostrar.', 'sii-boleta-dte' ); ?></div>
<?php endif; ?>
                                        <div class="sii-pie-legend">
                                                <?php foreach ( $legend_items as $item ) : ?>
                                                        <span style="color: <?php echo esc_attr( $item['color'] ); ?>;">
                                                                <?php echo esc_html( $item['label'] ); ?> · <?php echo (int) $item['value']; ?> (<?php echo (int) $item['percentage']; ?>%)
                                                        </span>
                                                <?php endforeach; ?>
                                        </div>
                                </div>
                        </div>
<div class="sii-highlight">
<strong><?php echo esc_html__( 'Conclusión clave', 'sii-boleta-dte' ); ?></strong>
<?php if ( $series_total > 0 ) : ?>
<span><?php echo esc_html( sprintf( __( 'La mayoría de los documentos están clasificados como %1$s (%2$d registros).', 'sii-boleta-dte' ), $dominant_status, $dominant_value ) ); ?></span>
<?php else : ?>
<span><?php echo esc_html__( 'Aún no registramos actividad para este período; utiliza los filtros para revisar otras fechas.', 'sii-boleta-dte' ); ?></span>
<?php endif; ?>
                        </div>
                <?php AdminStyles::close_container(); ?>
                <?php
        }

	/** Handles queue actions. */
	public function handle_queue_action( string $action, int $id ): void {
		if ( empty( $action ) ) {
			return;
		}

if ( ! $this->verify_nonce( 'sii_boleta_queue_nonce', 'sii_boleta_queue' ) ) {
$this->add_notice( __( 'La verificación de seguridad falló. Inténtalo nuevamente.', 'sii-boleta-dte' ), 'error' );
			return;
		}

		if ( 'process' === $action ) {
			$this->processor->process( $id );
		} elseif ( 'cancel' === $action ) {
			$this->processor->cancel( $id );
		} elseif ( 'requeue' === $action ) {
			$this->processor->retry( $id );
		}

$this->add_notice( __( 'Acción de cola ejecutada.', 'sii-boleta-dte' ) );
	}

	private function handle_rvd_action( string $action ): void {
		if ( 'generate_send' !== $action ) {
			return;
		}

if ( ! $this->verify_nonce( 'sii_boleta_rvd_nonce', 'sii_boleta_rvd' ) ) {
$this->add_notice( __( 'La verificación de seguridad falló. Inténtalo nuevamente.', 'sii-boleta-dte' ), 'error' );
			return;
		}

		$xml = $this->rvd_manager->generate_xml();
if ( '' === $xml ) {
$this->add_notice( __( 'No fue posible generar el XML del RVD. Revisa la configuración e inténtalo nuevamente.', 'sii-boleta-dte' ), 'error' );
			return;
		}

if ( ! $this->rvd_manager->validate_rvd_xml( $xml ) ) {
$this->add_notice( __( 'El XML de RVD generado no es válido.', 'sii-boleta-dte' ), 'error' );
			return;
		}

				$environment = $this->settings->get_environment();
				$token       = $this->token_manager->get_token( $environment );
				$response    = $this->api->send_libro_to_sii( $xml, $environment, $token );
		$is_error            = false;
		if ( function_exists( 'is_wp_error' ) && is_wp_error( $response ) ) {
			$is_error = true;
		} elseif ( class_exists( '\WP_Error' ) && $response instanceof \WP_Error ) {
			$is_error = true;
		}

		if ( $is_error ) {
$error_message = method_exists( $response, 'get_error_message' ) ? $response->get_error_message() : __( 'Error desconocido', 'sii-boleta-dte' );
$this->add_notice( sprintf( __( 'Error al enviar el RVD: %s', 'sii-boleta-dte' ), $error_message ), 'error' );
			return;
		}

		$track = '';
		if ( is_array( $response ) && isset( $response['trackId'] ) ) {
			$track = (string) $response['trackId'];
		}

$message = __( 'RVD enviado correctamente.', 'sii-boleta-dte' );
if ( '' !== $track ) {
$message .= ' ' . sprintf( __( 'ID de seguimiento: %s', 'sii-boleta-dte' ), $track );
		}

		$this->add_notice( $message );
	}

	private function handle_libro_action( string $action, string $xml ): void {
		if ( 'validate' !== $action ) {
				return;
		}

if ( ! $this->verify_nonce( 'sii_boleta_libro_nonce', 'sii_boleta_libro' ) ) {
$this->add_notice( __( 'La verificación de seguridad falló. Inténtalo nuevamente.', 'sii-boleta-dte' ), 'error' );
			return;
		}

			$xml = trim( $xml );
if ( '' === $xml ) {
$this->add_notice( __( 'Debes pegar el XML del Libro antes de validar.', 'sii-boleta-dte' ), 'error' );
			return;
		}

if ( $this->libro_boletas->validate_libro_xml( $xml ) ) {
$this->add_notice( __( 'El XML del Libro es válido.', 'sii-boleta-dte' ) );
} else {
$this->add_notice( __( 'El XML del Libro no pasó la validación. Revisa la estructura e inténtalo nuevamente.', 'sii-boleta-dte' ), 'error' );
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

	/** Returns a translated label for log statuses. */
	private function translate_status_label( string $status ): string {
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

	private function render_rvd_schedule(): void {
			$cfg     = $this->settings->get_settings();
			$enabled = ! empty( $cfg['rvd_auto_enabled'] );
			$time    = isset( $cfg['rvd_auto_time'] ) ? (string) $cfg['rvd_auto_time'] : '02:00';
		if ( ! preg_match( '/^(\d{2}):(\d{2})$/', $time ) ) {
				$time = '02:00';
		}
			$environment = $this->settings->get_environment();
			$last        = Settings::get_schedule_last_run( 'rvd', $environment );
			$next_ts     = $enabled ? $this->next_daily_run_timestamp( $time ) : 0;
		?>
<h3><?php echo esc_html__( 'Programación automática', 'sii-boleta-dte' ); ?></h3>
<p><?php echo esc_html__( 'Estado:', 'sii-boleta-dte' ) . ' ' . esc_html( $enabled ? __( 'Habilitado', 'sii-boleta-dte' ) : __( 'Deshabilitado', 'sii-boleta-dte' ) ); ?></p>
<?php if ( $enabled ) : ?>
<p><?php echo esc_html__( 'Hora diaria:', 'sii-boleta-dte' ) . ' ' . esc_html( $time ); ?></p>
<p><?php echo esc_html__( 'Próxima ejecución:', 'sii-boleta-dte' ) . ' ' . esc_html( $this->format_datetime( $next_ts ) ); ?></p>
<?php endif; ?>
<p><?php echo esc_html__( 'Última ejecución:', 'sii-boleta-dte' ) . ' ' . esc_html( '' !== $last ? $last : __( 'Nunca', 'sii-boleta-dte' ) ); ?></p>
				<?php
	}

	private function render_libro_schedule(): void {
			$cfg     = $this->settings->get_settings();
			$enabled = ! empty( $cfg['libro_auto_enabled'] );
			$day     = isset( $cfg['libro_auto_day'] ) ? (int) $cfg['libro_auto_day'] : 1;
		if ( $day < 1 || $day > 31 ) {
				$day = 1;
		}
			$time = isset( $cfg['libro_auto_time'] ) ? (string) $cfg['libro_auto_time'] : '03:00';
		if ( ! preg_match( '/^(\d{2}):(\d{2})$/', $time ) ) {
				$time = '03:00';
		}
			$environment = $this->settings->get_environment();
			$last        = Settings::get_schedule_last_run( 'libro', $environment );
			$next_ts     = $enabled ? $this->next_monthly_run_timestamp( $day, $time ) : 0;
			$period      = $this->previous_month_period( $this->current_timestamp() );
		?>
<h3><?php echo esc_html__( 'Programación mensual del Libro', 'sii-boleta-dte' ); ?></h3>
<p><?php echo esc_html__( 'Estado:', 'sii-boleta-dte' ) . ' ' . esc_html( $enabled ? __( 'Habilitado', 'sii-boleta-dte' ) : __( 'Deshabilitado', 'sii-boleta-dte' ) ); ?></p>
<?php if ( $enabled ) : ?>
<p><?php echo esc_html__( 'Día programado:', 'sii-boleta-dte' ) . ' ' . esc_html( (string) $day ); ?></p>
<p><?php echo esc_html__( 'Hora de envío:', 'sii-boleta-dte' ) . ' ' . esc_html( $time ); ?></p>
<p><?php echo esc_html__( 'Próxima ejecución:', 'sii-boleta-dte' ) . ' ' . esc_html( $this->format_datetime( $next_ts ) ); ?></p>
<p><?php echo esc_html__( 'Período a informar:', 'sii-boleta-dte' ) . ' ' . esc_html( $period ); ?></p>
<?php endif; ?>
<p><?php echo esc_html__( 'Última ejecución:', 'sii-boleta-dte' ) . ' ' . esc_html( '' !== $last ? $last : __( 'Nunca', 'sii-boleta-dte' ) ); ?></p>
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
			$timezone              = $this->get_timezone();
			$date                  = new \DateTimeImmutable( '@' . $reference );
			$date                  = $date->setTimezone( $timezone );
			list( $hour, $minute ) = array_map( 'intval', explode( ':', $time ) );
			$date                  = $date->setTime( $hour, $minute, 0 );
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
			$timezone              = $this->get_timezone();
			$date                  = new \DateTimeImmutable( '@' . $reference );
			$date                  = $date->setTimezone( $timezone );
			$year                  = (int) $date->format( 'Y' );
			$month                 = (int) $date->format( 'm' );
			$base                  = new \DateTimeImmutable( sprintf( '%04d-%02d-01 00:00:00', $year, $month ), $timezone );
			$days                  = (int) $base->format( 't' );
			$day                   = min( max( 1, $day ), $days );
			list( $hour, $minute ) = array_map( 'intval', explode( ':', $time ) );
			$target                = $base->setDate( $year, $month, $day )->setTime( $hour, $minute, 0 );
			return $target->getTimestamp();
	}

        private function next_monthly_run_timestamp( int $day, string $time ): int {
                        $now    = $this->current_timestamp();
                        $target = $this->timestamp_for_month_day_time( $day, $time, $now );
                if ( $now >= $target ) {
                                $timezone              = $this->get_timezone();
                                $date                  = new \DateTimeImmutable( '@' . $now );
                                $date                  = $date->setTimezone( $timezone )->modify( 'first day of next month' );
                                $year                  = (int) $date->format( 'Y' );
                                $month                 = (int) $date->format( 'm' );
                                $days                  = (int) $date->format( 't' );
                                $day                   = min( max( 1, $day ), $days );
                                list( $hour, $minute ) = array_map( 'intval', explode( ':', $time ) );
                                $target                = $date->setDate( $year, $month, $day )->setTime( $hour, $minute, 0 )->getTimestamp();
                }
                        return $target;
        }

        private function days_in_month( int $year, int $month ): int {
                if ( $month < 1 || $month > 12 ) {
                                return 30;
                }
                $timestamp = gmmktime( 0, 0, 0, $month, 1, max( 1, $year ) );
                return (int) gmdate( 't', $timestamp );
        }

        private function previous_month_period( int $timestamp ): string {
                        $timezone = $this->get_timezone();
                        $date     = new \DateTimeImmutable( '@' . $timestamp );
                        $date     = $date->setTimezone( $timezone )->modify( 'first day of last month' );
                        return $date->format( 'Y-m' );
	}

	private function format_datetime( int $timestamp ): string {
if ( $timestamp <= 0 ) {
return __( 'Sin programación', 'sii-boleta-dte' );
		}
		if ( function_exists( 'wp_date' ) ) {
				return wp_date( 'Y-m-d H:i', $timestamp );
		}
			return gmdate( 'Y-m-d H:i', $timestamp );
	}
}

class_alias( ControlPanelPage::class, 'SII_Boleta_Control_Panel_Page' );
