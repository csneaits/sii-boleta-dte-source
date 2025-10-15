<?php
namespace Sii\BoletaDte\Presentation\Admin;

use Sii\BoletaDte\Infrastructure\WordPress\Settings as Settings;
use Sii\BoletaDte\Infrastructure\Persistence\FoliosDb;
use Sii\BoletaDte\Infrastructure\Certification\ProgressTracker;

/**
 * Help/about page with certification guidance and quick links.
 */
class Help {
private Settings $settings;

/** @var callable */
private $has_folio_callback;

public function __construct( ?Settings $settings = null, ?callable $has_folio_callback = null ) {
$this->settings = $settings ?? new Settings();
$this->has_folio_callback = $has_folio_callback ?? array( FoliosDb::class, 'has_type' );
}

public function register(): void {
if ( function_exists( 'add_submenu_page' ) ) {
add_submenu_page(
'sii-boleta-dte',
__( 'Ayuda', 'sii-boleta-dte' ),
__( 'Ayuda', 'sii-boleta-dte' ),
'manage_options',
'sii-boleta-dte-help',
array( $this, 'render_page' )
);
}
}

public function render_page(): void {
$settings   = $this->settings->get_settings();
$env        = $this->settings->get_environment();
$enabled    = array_map( 'intval', $settings['enabled_types'] ?? array() );
$cert_path  = isset( $settings['cert_path'] ) ? (string) $settings['cert_path'] : '';
$cert_ready = '' !== $cert_path && file_exists( $cert_path );
$rut_ready  = ! empty( $settings['rut_emisor'] ) && ! empty( $settings['cert_pass'] );
$types_set  = ! empty( $enabled );
$missing    = $this->find_missing_folios( $enabled, $env );
$token_time = ProgressTracker::last_timestamp( ProgressTracker::OPTION_TOKEN );
$api_time   = ProgressTracker::last_timestamp( ProgressTracker::OPTION_API );
$send_time  = ProgressTracker::last_timestamp( ProgressTracker::OPTION_TEST_SEND );

AdminStyles::open_container( 'sii-help-page' );
echo '<h1>' . esc_html__( 'Ayuda', 'sii-boleta-dte' ) . '</h1>';
echo '<div class="sii-admin-card sii-admin-card--compact">';
echo '<h2>' . esc_html__( 'Guía de certificación con el SII', 'sii-boleta-dte' ) . '</h2>';
echo '<p class="sii-admin-subtitle">' . esc_html__( 'Sigue cada hito para completar el proceso de certificación y habilitar el envío de DTE en producción.', 'sii-boleta-dte' ) . '</p>';
echo '<ul class="sii-admin-checklist">';

$steps = array(
array(
'completed' => $cert_ready && $rut_ready,
'title'     => __( 'Configura datos del emisor y certificado digital', 'sii-boleta-dte' ),
'description' => __( 'Actualiza el RUT, razón social y carga el certificado PFX desde los ajustes.', 'sii-boleta-dte' ),
'actions'   => array(
array(
'label' => __( 'Ir a Ajustes', 'sii-boleta-dte' ),
'url'   => $this->admin_link( 'admin.php?page=sii-boleta-dte-settings' ),
),
),
'extra'     => $cert_ready ? '' : __( 'El certificado no se encuentra en la ruta configurada o falta la contraseña.', 'sii-boleta-dte' ),
),
array(
'completed' => $types_set && empty( $missing ),
'title'     => __( 'Carga los CAF para los tipos habilitados', 'sii-boleta-dte' ),
'description' => __( 'Importa los archivos CAF entregados por el SII y verifica que cada tipo de documento tenga un rango activo.', 'sii-boleta-dte' ),
'actions'   => array(
array(
'label' => __( 'Administrar CAF', 'sii-boleta-dte' ),
'url'   => $this->admin_link( 'admin.php?page=sii-boleta-dte-cafs' ),
),
),
'extra'     => $types_set ? $this->format_missing_types( $missing ) : __( 'Selecciona al menos un tipo de DTE en los ajustes para comenzar.', 'sii-boleta-dte' ),
),
array(
'completed' => $token_time > 0 && $api_time > 0,
'title'     => __( 'Ejecuta los diagnósticos de token y API', 'sii-boleta-dte' ),
'description' => __( 'Genera un token y realiza el ping de estado para confirmar que el servidor puede conectarse al SII.', 'sii-boleta-dte' ),
'actions'   => array(
array(
'label' => __( 'Abrir Diagnósticos', 'sii-boleta-dte' ),
'url'   => $this->admin_link( 'admin.php?page=sii-boleta-dte-diagnostics' ),
),
),
'extra'     => $this->format_diagnostic_status( $token_time, $api_time ),
),
array(
'completed' => $send_time > 0,
'title'     => __( 'Envía DTE de certificación y revisa los acuses', 'sii-boleta-dte' ),
'description' => __( 'Genera documentos de prueba en el ambiente de certificación y valida los resultados en el SII.', 'sii-boleta-dte' ),
'actions'   => array(
array(
'label' => __( 'Generar DTE de prueba', 'sii-boleta-dte' ),
'url'   => $this->admin_link( 'admin.php?page=sii-boleta-dte-generate' ),
),
),
'extra'     => $send_time > 0 ? $this->format_last_completed( $send_time ) : __( 'Se marcará como completado al registrar un envío exitoso en certificación.', 'sii-boleta-dte' ),
),
);

foreach ( $steps as $step ) {
$this->render_step( $step );
}

echo '</ul>';
echo '<p><a href="https://github.com/fullLibreDte" target="_blank" rel="noopener">' . esc_html__( 'Ver documentación', 'sii-boleta-dte' ) . '</a></p>';
echo '</div>';
AdminStyles::close_container();
}

/**
 * Prints a checklist step with icon, description and actions.
 *
 * @param array<string,mixed> $step
 */
private function render_step( array $step ): void {
$completed  = ! empty( $step['completed'] );
$title      = isset( $step['title'] ) ? (string) $step['title'] : '';
$desc       = isset( $step['description'] ) ? (string) $step['description'] : '';
$extra      = isset( $step['extra'] ) ? (string) $step['extra'] : '';
$icon       = $completed ? '&#10003;' : '&#10007;';
$icon_class = $completed ? '' : ' is-bad';

echo '<li>';
echo '<span class="sii-admin-status-icon' . $icon_class . '">' . $icon . '</span>';
echo '<div class="sii-help-step">';
echo '<strong>' . esc_html( $title ) . '</strong>';
if ( '' !== $desc ) {
echo '<p>' . esc_html( $desc ) . '</p>';
}
if ( '' !== $extra ) {
echo '<p class="description">' . esc_html( $extra ) . '</p>';
}
if ( ! empty( $step['actions'] ) && is_array( $step['actions'] ) ) {
echo '<p class="sii-help-step-actions">';
foreach ( $step['actions'] as $action ) {
$label = isset( $action['label'] ) ? (string) $action['label'] : '';
$url   = isset( $action['url'] ) ? (string) $action['url'] : '';
if ( '' === $label || '' === $url ) {
continue;
}
echo '<a class="button button-secondary" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a> ';
}
echo '</p>';
}
echo '</div>';
echo '</li>';
}

/**
 * Builds a human friendly label for missing folio types.
 *
 * @param array<int,int> $missing
 */
private function format_missing_types( array $missing ): string {
if ( empty( $missing ) ) {
return '';
}
$labels = array_map( 'strval', $missing );
return sprintf( __( 'Falta cargar CAF para los tipos: %s.', 'sii-boleta-dte' ), implode( ', ', $labels ) );
}

/**
 * Formats diagnostic timestamps for display.
 */
private function format_diagnostic_status( int $token_time, int $api_time ): string {
if ( $token_time <= 0 && $api_time <= 0 ) {
return __( 'Aún no se registran diagnósticos exitosos.', 'sii-boleta-dte' );
}
$parts = array();
if ( $token_time > 0 ) {
$parts[] = sprintf( __( 'Último token correcto: %s', 'sii-boleta-dte' ), $this->format_timestamp( $token_time ) );
} else {
$parts[] = __( 'Token pendiente', 'sii-boleta-dte' );
}
if ( $api_time > 0 ) {
$parts[] = sprintf( __( 'Última consulta API correcta: %s', 'sii-boleta-dte' ), $this->format_timestamp( $api_time ) );
} else {
$parts[] = __( 'Ping API pendiente', 'sii-boleta-dte' );
}
return implode( ' — ', $parts );
}

/**
 * Formats a completion timestamp for the final step.
 */
private function format_last_completed( int $timestamp ): string {
return sprintf( __( 'Último envío registrado: %s', 'sii-boleta-dte' ), $this->format_timestamp( $timestamp ) );
}

/**
 * Returns missing folio types for the current environment.
 *
 * @param array<int,int> $enabled
 * @return array<int,int>
 */
private function find_missing_folios( array $enabled, string $environment ): array {
$missing = array();
foreach ( $enabled as $type ) {
if ( ! $type ) {
continue;
}
$has_type = is_callable( $this->has_folio_callback ) ? (bool) call_user_func( $this->has_folio_callback, (int) $type, $environment ) : false;
if ( ! $has_type ) {
$missing[] = (int) $type;
}
}
return $missing;
}

/**
 * Converts a timestamp to a readable date respecting WordPress helpers when available.
 */
private function format_timestamp( int $timestamp ): string {
if ( $timestamp <= 0 ) {
return __( 'pendiente', 'sii-boleta-dte' );
}
$format = 'Y-m-d H:i';
if ( function_exists( 'get_option' ) ) {
$format = (string) get_option( 'date_format', 'Y-m-d' ) . ' H:i';
}
if ( function_exists( 'wp_date' ) ) {
return wp_date( $format, $timestamp );
}
return gmdate( $format, $timestamp );
}

/**
 * Generates an admin URL with a safe fallback for tests.
 */
private function admin_link( string $path ): string {
if ( function_exists( 'admin_url' ) ) {
return admin_url( $path );
}
return $path;
}
}

class_alias( Help::class, 'SII_Boleta_Help' );
class_alias( Help::class, 'Sii\\BoletaDte\\Admin\\Help' );
