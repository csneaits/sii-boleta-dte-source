<?php
namespace Sii\BoletaDte\Presentation\Admin;

use Sii\BoletaDte\Infrastructure\Settings;

/**
 * Admin page to manage CAF uploads.
 */
class CafPage {
private Settings $settings;

public function __construct( Settings $settings ) {
$this->settings = $settings;
}

/** Registers hooks if needed. */
public function register(): void {}

/**
 * Renders the CAF management page.
 */
public function render_page(): void {
if ( function_exists( 'current_user_can' ) && ! current_user_can( 'manage_options' ) ) {
return;
}

if ( isset( $_POST['caf_mode'] ) && function_exists( 'check_admin_referer' ) && check_admin_referer( 'sii_boleta_save_caf', 'sii_boleta_caf_nonce' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
$this->handle_save();
}

if ( isset( $_GET['caf_action'], $_GET['caf_key'] ) && 'delete' === $_GET['caf_action'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
if ( function_exists( 'check_admin_referer' ) && check_admin_referer( 'sii_boleta_delete_caf' ) ) {
$this->handle_delete( (string) $_GET['caf_key'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
}
}

$settings_view = $this->settings->get_settings();
$environment   = $settings_view['environment_slug'] ?? Settings::ENV_TEST;
$hidden        = (int) ( $settings_view['cafs_hidden'] ?? 0 );
$cafs          = $this->get_cafs( $settings_view );

if ( ! empty( $cafs ) ) {
usort(
$cafs,
function ( array $a, array $b ) {
$ta = (int) ( $a['tipo'] ?? 0 );
$tb = (int) ( $b['tipo'] ?? 0 );
if ( $ta !== $tb ) {
return $ta <=> $tb;
}
$da = (int) ( $a['desde'] ?? 0 );
$db = (int) ( $b['desde'] ?? 0 );
if ( $da !== $db ) {
return $da <=> $db;
}
$fa = strtotime( (string) ( $a['fecha'] ?? '' ) ) ?: 0;
$fb = strtotime( (string) ( $b['fecha'] ?? '' ) ) ?: 0;
return $fb <=> $fa;
}
);
}

$types = $this->supported_types();

echo '<div class="wrap">';
echo '<h1>' . esc_html__( 'Folios / CAFs', 'sii-boleta-dte' ) . '</h1>';
echo '<p>' . esc_html__( 'Gestiona aquí los rangos de folios autorizados por tipo de documento. Cuando requieras ampliar un rango existente, utiliza la opción de editar para añadir folios.', 'sii-boleta-dte' ) . '</p>';
echo '<p class="description">' . sprintf( esc_html__( 'Mostrando rangos para el ambiente %s.', 'sii-boleta-dte' ), esc_html( $this->describe_environment( $environment ) ) ) . '</p>';
if ( $hidden > 0 ) {
echo '<div class="notice notice-info"><p>' . sprintf( esc_html__( 'Hay %d rangos registrados para otros ambientes. Cambia el ambiente en la página de ajustes para administrarlos.', 'sii-boleta-dte' ), (int) $hidden ) . '</p></div>';
}

echo '<p><button type="button" class="button button-primary" id="sii-caf-add">' . esc_html__( 'Añadir rango', 'sii-boleta-dte' ) . '</button></p>';

echo '<table class="wp-list-table widefat fixed striped">';
echo '<thead><tr>';
echo '<th>' . esc_html__( 'Tipo', 'sii-boleta-dte' ) . '</th>';
echo '<th>' . esc_html__( 'Rango', 'sii-boleta-dte' ) . '</th>';
echo '<th>' . esc_html__( 'Consumidos', 'sii-boleta-dte' ) . '</th>';
echo '<th>' . esc_html__( 'Restantes', 'sii-boleta-dte' ) . '</th>';
echo '<th>' . esc_html__( 'Estado', 'sii-boleta-dte' ) . '</th>';
echo '<th>' . esc_html__( 'Fecha de actualización', 'sii-boleta-dte' ) . '</th>';
echo '<th>' . esc_html__( 'Acciones', 'sii-boleta-dte' ) . '</th>';
echo '</tr></thead><tbody>';
if ( empty( $cafs ) ) {
echo '<tr><td colspan="7">' . esc_html__( 'No hay rangos registrados.', 'sii-boleta-dte' ) . '</td></tr>';
} else {
foreach ( $cafs as $caf ) {
$type_label = $types[ (int) ( $caf['tipo'] ?? 0 ) ] ?? (string) ( $caf['tipo'] ?? '' );
$d          = (int) ( $caf['desde'] ?? 0 );
$h          = (int) ( $caf['hasta'] ?? 0 );
$range      = ( $d > 0 && $h >= $d ) ? $d . ' - ' . $h : '';
$estado     = esc_html( $caf['estado'] ?? 'vigente' );
$fecha      = esc_html( $caf['fecha'] ?? '' );
$last       = function_exists( 'get_option' ) ? (int) get_option( 'sii_boleta_dte_last_folio_' . ( $caf['tipo'] ?? 0 ), 0 ) : 0;
$consumidos = max( 0, min( $h, $last ) - $d + 1 );
$consumidos = $last < $d ? 0 : min( $consumidos, max( 0, $h - $d + 1 ) );
$restantes  = max( 0, ( $h - $d + 1 ) - $consumidos );
$key        = $this->build_caf_key( $caf, $environment );
$base_url   = function_exists( 'menu_page_url' ) ? (string) menu_page_url( 'sii-boleta-dte-cafs', false ) : '?page=sii-boleta-dte-cafs';
$url        = add_query_arg( array( 'caf_action' => 'delete', 'caf_key' => $key ), $base_url );
if ( function_exists( 'wp_nonce_url' ) ) {
$url = wp_nonce_url( $url, 'sii_boleta_delete_caf' );
}
$row_data = array(
'key'    => $key,
'tipo'   => (int) ( $caf['tipo'] ?? 0 ),
'desde'  => $d,
'hasta'  => $h,
'estado' => (string) ( $caf['estado'] ?? 'vigente' ),
'fecha'  => (string) ( $caf['fecha'] ?? '' ),
'label'  => $type_label,
);
$json      = function_exists( 'wp_json_encode' ) ? wp_json_encode( $row_data ) : json_encode( $row_data );
$data_attr = esc_attr( is_string( $json ) ? $json : '{}' );

echo '<tr data-caf="' . $data_attr . '">';
echo '<td>' . esc_html( $type_label ) . '</td>';
echo '<td>' . esc_html( $range ) . '</td>';
echo '<td>' . (int) $consumidos . '</td>';
echo '<td>' . (int) $restantes . '</td>';
echo '<td>' . $estado . '</td>';
echo '<td>' . $fecha . '</td>';
echo '<td><button type="button" class="button-link sii-caf-edit">' . esc_html__( 'Editar', 'sii-boleta-dte' ) . '</button> | <a href="' . esc_url( $url ) . '">' . esc_html__( 'Eliminar', 'sii-boleta-dte' ) . '</a></td>';
echo '</tr>';
}
}
echo '</tbody></table>';

$select_options  = '<option value="">' . esc_html__( 'Selecciona un tipo de documento', 'sii-boleta-dte' ) . '</option>';
foreach ( $types as $code => $label ) {
$select_options .= '<option value="' . (int) $code . '">' . esc_html( $label ) . '</option>';
}

$range_label = esc_attr__( 'Rango actual: %1$s - %2$s', 'sii-boleta-dte' );
$modal_attrs = ' data-create-title="' . esc_attr__( 'Añadir rango de folios', 'sii-boleta-dte' ) . '" data-edit-title="' . esc_attr__( 'Ampliar rango existente', 'sii-boleta-dte' ) . '" data-range-label="' . $range_label . '"';
echo '<div id="sii-caf-modal" class="sii-caf-modal"' . $modal_attrs . '>';
echo '<div class="sii-caf-modal__dialog">';
echo '<button type="button" class="sii-caf-modal__close" data-close="1" aria-label="' . esc_attr__( 'Cerrar', 'sii-boleta-dte' ) . '">&times;</button>';
echo '<h2 id="sii-caf-modal-title">' . esc_html__( 'Añadir rango de folios', 'sii-boleta-dte' ) . '</h2>';
echo '<form method="post" id="sii-caf-form">';
if ( function_exists( 'wp_nonce_field' ) ) {
wp_nonce_field( 'sii_boleta_save_caf', 'sii_boleta_caf_nonce' );
}
echo '<input type="hidden" name="caf_mode" id="sii-caf-mode" value="create" />';
echo '<input type="hidden" name="caf_key" id="sii-caf-key" value="" />';
echo '<p class="sii-caf-field"><label for="sii-caf-tipo">' . esc_html__( 'Tipo de documento', 'sii-boleta-dte' ) . '</label><br /><select name="caf_tipo" id="sii-caf-tipo" required>' . $select_options . '</select></p>';
echo '<p class="sii-caf-field" id="sii-caf-desde-field"><label for="sii-caf-desde">' . esc_html__( 'Folio inicial', 'sii-boleta-dte' ) . '</label><br /><input type="number" min="1" name="caf_desde" id="sii-caf-desde" required /></p>';
echo '<p class="description" id="sii-caf-desde-display" style="display:none;"></p>';
echo '<p class="sii-caf-field"><label for="sii-caf-incremento">' . esc_html__( 'Cantidad de folios a añadir', 'sii-boleta-dte' ) . '</label><br /><input type="number" min="1" name="caf_incremento" id="sii-caf-incremento" required /> <span class="description">' . esc_html__( 'Ingresa cuántos folios nuevos sumarás al rango.', 'sii-boleta-dte' ) . '</span></p>';
echo '<p class="submit"><button type="submit" class="button button-primary">' . esc_html__( 'Guardar', 'sii-boleta-dte' ) . '</button> <button type="button" class="button" data-close="1">' . esc_html__( 'Cancelar', 'sii-boleta-dte' ) . '</button></p>';
echo '</form>';
echo '</div></div>';
echo '<style>.sii-caf-modal{position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.45);display:none;align-items:center;justify-content:center;z-index:100000;}.sii-caf-modal.is-visible{display:flex;}.sii-caf-modal__dialog{background:#fff;padding:24px;max-width:420px;width:90%;box-shadow:0 10px 30px rgba(0,0,0,0.2);border-radius:4px;position:relative;}.sii-caf-modal__close{position:absolute;top:10px;right:10px;background:none;border:0;font-size:24px;line-height:1;cursor:pointer;}.sii-caf-modal__close:hover{color:#d63638;}.sii-caf-modal .sii-caf-field{margin-bottom:16px;}.sii-caf-modal input[type=number],.sii-caf-modal select{width:100%;}</style>';
echo '<script>(function(){var modal=document.getElementById("sii-caf-modal");if(!modal){return;}var addBtn=document.getElementById("sii-caf-add");var form=document.getElementById("sii-caf-form");var modeInput=document.getElementById("sii-caf-mode");var keyInput=document.getElementById("sii-caf-key");var tipoSelect=document.getElementById("sii-caf-tipo");var desdeField=document.getElementById("sii-caf-desde-field");var desdeInput=document.getElementById("sii-caf-desde");var desdeDisplay=document.getElementById("sii-caf-desde-display");var incrementoInput=document.getElementById("sii-caf-incremento");var title=document.getElementById("sii-caf-modal-title");function closeModal(){modal.classList.remove("is-visible");}function openModal(){modal.classList.add("is-visible");setTimeout(function(){if("update"===modeInput.value){incrementoInput.focus();}else{tipoSelect.focus();}},100);}function resetForm(){form.reset();modeInput.value="create";keyInput.value="";tipoSelect.disabled=false;tipoSelect.value="";desdeField.style.display="";if(desdeInput){desdeInput.required=true;desdeInput.value="";}if(desdeDisplay){desdeDisplay.style.display="none";desdeDisplay.textContent="";}incrementoInput.value="";}if(addBtn){addBtn.addEventListener("click",function(){resetForm();title.textContent=modal.getAttribute("data-create-title")||title.textContent;openModal();});}document.querySelectorAll(".sii-caf-edit").forEach(function(btn){btn.addEventListener("click",function(){var row=btn.closest("tr");if(!row){return;}var raw=row.getAttribute("data-caf");if(!raw){return;}var data;try{data=JSON.parse(raw);}catch(e){data=null;}if(!data){return;}resetForm();modeInput.value="update";keyInput.value=data.key||"";tipoSelect.value=String(data.tipo||"");tipoSelect.disabled=true;if(desdeField){desdeField.style.display="none";}if(desdeInput){desdeInput.required=false;}if(desdeDisplay){var template=modal.getAttribute("data-range-label")||"";var texto=template.replace("%1$s",data.desde||"").replace("%2$s",data.hasta||"");desdeDisplay.textContent=texto;desdeDisplay.style.display="block";}title.textContent=modal.getAttribute("data-edit-title")||title.textContent;openModal();});});modal.addEventListener("click",function(event){if(event.target===modal){closeModal();}});modal.querySelectorAll("[data-close]").forEach(function(btn){btn.addEventListener("click",function(event){event.preventDefault();closeModal();});});})();</script>';
echo '</div>';
}

/**
 * Processes add/update requests.
 */
private function handle_save(): void {
$mode_raw = (string) ( $_POST['caf_mode'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
$mode     = function_exists( 'sanitize_key' ) ? sanitize_key( $mode_raw ) : strtolower( trim( $mode_raw ) );
$environment   = $this->current_environment_slug();
$raw_settings  = $this->settings->get_raw_settings();
$cafs          = isset( $raw_settings['cafs'] ) && is_array( $raw_settings['cafs'] ) ? $raw_settings['cafs'] : array();
$types         = $this->supported_types();

if ( 'create' === $mode ) {
$tipo       = isset( $_POST['caf_tipo'] ) ? (int) $_POST['caf_tipo'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
$desde      = isset( $_POST['caf_desde'] ) ? (int) $_POST['caf_desde'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
$incremento = isset( $_POST['caf_incremento'] ) ? (int) $_POST['caf_incremento'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing

if ( ! isset( $types[ $tipo ] ) ) {
echo '<div class="notice notice-error"><p>' . esc_html__( 'Selecciona un tipo de documento válido.', 'sii-boleta-dte' ) . '</p></div>';
return;
}
if ( $desde <= 0 || $incremento <= 0 ) {
echo '<div class="notice notice-error"><p>' . esc_html__( 'Debes indicar un folio inicial y una cantidad mayor a cero.', 'sii-boleta-dte' ) . '</p></div>';
return;
}

foreach ( $cafs as $caf ) {
if ( $this->caf_environment_slug( $caf ) !== $environment ) {
continue;
}
if ( (int) ( $caf['tipo'] ?? 0 ) === $tipo ) {
echo '<div class="notice notice-error"><p>' . esc_html__( 'Ya existe un rango para este tipo. Usa la opción “Editar” para ampliarlo.', 'sii-boleta-dte' ) . '</p></div>';
return;
}
}

$hasta     = $desde + $incremento - 1;
$new_entry = array(
'tipo'        => $tipo,
'desde'       => $desde,
'hasta'       => $hasta,
'estado'      => 'vigente',
'fecha'       => function_exists( 'date_i18n' ) ? date_i18n( 'Y-m-d H:i:s' ) : date( 'Y-m-d H:i:s' ),
'environment' => $environment,
);

$cafs[]                      = $new_entry;
$raw_settings['cafs']         = array_values( $cafs );
unset( $raw_settings['caf_path'] );
if ( function_exists( 'update_option' ) ) {
update_option( Settings::OPTION_NAME, $raw_settings );
}
echo '<div class="notice notice-success"><p>' . esc_html__( 'Rango creado correctamente.', 'sii-boleta-dte' ) . '</p></div>';
return;
}

if ( 'update' !== $mode ) {
return;
}

$key_raw   = (string) ( $_POST['caf_key'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
$key_clean = function_exists( 'sanitize_text_field' ) ? sanitize_text_field( $key_raw ) : trim( $key_raw );
$payload   = $this->parse_caf_key( $key_clean );
if ( null === $payload ) {
echo '<div class="notice notice-error"><p>' . esc_html__( 'No fue posible identificar el rango seleccionado.', 'sii-boleta-dte' ) . '</p></div>';
return;
}

$incremento = isset( $_POST['caf_incremento'] ) ? (int) $_POST['caf_incremento'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
if ( $incremento <= 0 ) {
echo '<div class="notice notice-error"><p>' . esc_html__( 'Debes indicar cuántos folios adicionales sumar.', 'sii-boleta-dte' ) . '</p></div>';
return;
}

$updated = false;
foreach ( $cafs as $index => $caf ) {
if ( $this->caf_environment_slug( $caf ) !== $environment ) {
continue;
}
if ( (int) ( $caf['tipo'] ?? 0 ) === $payload['tipo']
&& (int) ( $caf['desde'] ?? 0 ) === $payload['desde']
&& (int) ( $caf['hasta'] ?? 0 ) === $payload['hasta'] ) {
$cafs[ $index ]['hasta'] = (int) ( $caf['hasta'] ?? 0 ) + $incremento;
$cafs[ $index ]['fecha'] = function_exists( 'date_i18n' ) ? date_i18n( 'Y-m-d H:i:s' ) : date( 'Y-m-d H:i:s' );
if ( empty( $cafs[ $index ]['estado'] ) ) {
$cafs[ $index ]['estado'] = 'vigente';
}
$updated = true;
break;
}
}

if ( ! $updated ) {
echo '<div class="notice notice-error"><p>' . esc_html__( 'El rango seleccionado ya no está disponible.', 'sii-boleta-dte' ) . '</p></div>';
return;
}

$raw_settings['cafs'] = array_values( $cafs );
unset( $raw_settings['caf_path'] );
if ( function_exists( 'update_option' ) ) {
update_option( Settings::OPTION_NAME, $raw_settings );
}
echo '<div class="notice notice-success"><p>' . esc_html__( 'Rango actualizado correctamente.', 'sii-boleta-dte' ) . '</p></div>';
}

/**
 * Handles range deletion.
 */
private function handle_delete( string $param ): void {
$payload = $this->parse_caf_key( $param );
if ( null === $payload ) {
return;
}

$environment = $this->current_environment_slug();
if ( isset( $payload['environment'] ) && $payload['environment'] !== $environment ) {
return;
}

$raw_settings = $this->settings->get_raw_settings();
$cafs         = isset( $raw_settings['cafs'] ) && is_array( $raw_settings['cafs'] ) ? $raw_settings['cafs'] : array();

$removed = false;
foreach ( $cafs as $index => $caf ) {
if ( $this->caf_environment_slug( $caf ) !== $environment ) {
continue;
}
if ( (int) ( $caf['tipo'] ?? 0 ) === $payload['tipo']
&& (int) ( $caf['desde'] ?? 0 ) === $payload['desde']
&& (int) ( $caf['hasta'] ?? 0 ) === $payload['hasta'] ) {
unset( $cafs[ $index ] );
$removed = true;
break;
}
}

if ( ! $removed ) {
return;
}

$raw_settings['cafs'] = array_values( $cafs );
unset( $raw_settings['caf_path'] );
if ( function_exists( 'update_option' ) ) {
update_option( Settings::OPTION_NAME, $raw_settings );
}

if ( function_exists( 'wp_safe_redirect' ) && function_exists( 'menu_page_url' ) ) {
$dest = (string) menu_page_url( 'sii-boleta-dte-cafs', false );
wp_safe_redirect( $dest );
exit;
}

echo '<div class="notice notice-success"><p>' . esc_html__( 'Rango eliminado.', 'sii-boleta-dte' ) . '</p></div>';
}

/**
 * @return array<int,string>
 */
private function supported_types(): array {
return array(
33 => __( 'Factura', 'sii-boleta-dte' ),
34 => __( 'Factura Exenta', 'sii-boleta-dte' ),
39 => __( 'Boleta', 'sii-boleta-dte' ),
41 => __( 'Boleta Exenta', 'sii-boleta-dte' ),
52 => __( 'Guía de Despacho', 'sii-boleta-dte' ),
56 => __( 'Nota de Débito', 'sii-boleta-dte' ),
61 => __( 'Nota de Crédito', 'sii-boleta-dte' ),
);
}

/**
 * @param array<string,mixed>|null $settings
 * @return array<int,array<string,mixed>>
 */
private function get_cafs( ?array $settings = null ): array {
$settings = $settings ?? $this->settings->get_settings();
$result   = array();
if ( isset( $settings['cafs'] ) && is_array( $settings['cafs'] ) ) {
foreach ( $settings['cafs'] as $caf ) {
if ( ! is_array( $caf ) ) {
continue;
}
$result[] = $this->normalize_caf_entry( $caf );
}
}
return $result;
}

/**
 * Normalizes the expected keys of a CAF entry for display.
 *
 * @param array<string,mixed> $caf
 * @return array<string,mixed>
 */
private function normalize_caf_entry( array $caf ): array {
if ( isset( $caf['tipo'] ) ) {
$caf['tipo'] = (int) $caf['tipo'];
}
$caf['desde']  = isset( $caf['desde'] ) ? (int) $caf['desde'] : 0;
$caf['hasta']  = isset( $caf['hasta'] ) ? (int) $caf['hasta'] : 0;
$caf['estado'] = (string) ( $caf['estado'] ?? 'vigente' );
$caf['fecha']  = (string) ( $caf['fecha'] ?? '' );
return $caf;
}

/**
 * Builds a signed key used to identify a CAF entry in links/forms.
 *
 * @param array<string,mixed> $caf
 */
private function build_caf_key( array $caf, string $environment ): string {
$payload = array(
'tipo'        => (int) ( $caf['tipo'] ?? 0 ),
'desde'       => (int) ( $caf['desde'] ?? 0 ),
'hasta'       => (int) ( $caf['hasta'] ?? 0 ),
'environment' => $environment,
);
$json = function_exists( 'wp_json_encode' ) ? wp_json_encode( $payload ) : json_encode( $payload );
$json = is_string( $json ) ? $json : '';
return rawurlencode( base64_encode( $json ) );
}

/**
 * @return array{tipo:int,desde:int,hasta:int,environment?:string}|null
 */
private function parse_caf_key( string $key ): ?array {
$decoded = base64_decode( rawurldecode( $key ), true );
if ( ! is_string( $decoded ) || '' === $decoded ) {
return null;
}
$data = json_decode( $decoded, true );
if ( ! is_array( $data ) ) {
return null;
}
if ( ! isset( $data['tipo'], $data['desde'], $data['hasta'] ) ) {
return null;
}
$result = array(
'tipo'  => (int) $data['tipo'],
'desde' => (int) $data['desde'],
'hasta' => (int) $data['hasta'],
);
if ( isset( $data['environment'] ) && is_string( $data['environment'] ) ) {
$result['environment'] = Settings::normalize_environment_slug( $data['environment'] );
}
return $result;
}

/**
 * Normalizes the environment of a CAF entry.
 *
 * @param mixed $caf
 */
private function caf_environment_slug( $caf ): string {
if ( ! is_array( $caf ) ) {
return Settings::ENV_TEST;
}
$value = $caf['environment'] ?? '';
return Settings::normalize_environment_slug( $value );
}

private function describe_environment( string $env ): string {
return Settings::ENV_PROD === $env ? __( 'Producción', 'sii-boleta-dte' ) : __( 'Certificación', 'sii-boleta-dte' );
}

private function current_environment_slug(): string {
$settings = $this->settings->get_settings();
return $settings['environment_slug'] ?? Settings::normalize_environment_slug( $settings['environment'] ?? '' );
}
}

class_alias( CafPage::class, 'SII_Boleta_Caf_Page' );
