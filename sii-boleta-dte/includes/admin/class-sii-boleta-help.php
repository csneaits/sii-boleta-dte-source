<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Página de ayuda y certificación para el plugin.
 */
class SII_Boleta_Help {
    public function register_page() {
        add_submenu_page(
            'sii-boleta-dte',
            __( 'Ayuda Boleta SII', 'sii-boleta-dte' ),
            __( 'Ayuda Boleta SII', 'sii-boleta-dte' ),
            'manage_options',
            'sii-boleta-dte-help',
            [ $this, 'render_page' ]
        );
    }

    public function render_page() {
        $readme_url = SII_BOLETA_DTE_URL . '../README.md';
        $consumo_xsd = SII_BOLETA_DTE_URL . 'includes/xml/schemas/consumo_folios.xsd';
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Ayuda Boleta SII', 'sii-boleta-dte' ); ?></h1>

            <h2><?php esc_html_e( 'Configuración inicial', 'sii-boleta-dte' ); ?></h2>
            <ul>
                <li><?php esc_html_e( 'Carga del certificado digital y su contraseña.', 'sii-boleta-dte' ); ?></li>
                <li><?php esc_html_e( 'Registro del CAF para cada tipo de DTE.', 'sii-boleta-dte' ); ?></li>
                <li><?php esc_html_e( 'Selección de tipos de documentos a emitir.', 'sii-boleta-dte' ); ?></li>
                <li><?php esc_html_e( 'Generación automática del token de la API.', 'sii-boleta-dte' ); ?></li>
                <li><?php esc_html_e( 'Validación del RUT en el checkout de WooCommerce.', 'sii-boleta-dte' ); ?></li>
            </ul>

            <h2><?php esc_html_e( 'Flujos de operación', 'sii-boleta-dte' ); ?></h2>
            <ul>
                <li><?php esc_html_e( 'Emisión de boletas y envío al SII.', 'sii-boleta-dte' ); ?></li>
                <li><?php esc_html_e( 'Generación de notas de crédito o débito.', 'sii-boleta-dte' ); ?></li>
                <li><?php esc_html_e( 'Resumen de Ventas Diarias (RVD).', 'sii-boleta-dte' ); ?></li>
                <li><?php esc_html_e( 'Consumo de Folios (CDF).', 'sii-boleta-dte' ); ?></li>
            </ul>

            <h2><?php esc_html_e( '¿Qué son el CAF, RVD y CDF?', 'sii-boleta-dte' ); ?></h2>
            <ul>
                <li><strong><?php esc_html_e( 'CAF', 'sii-boleta-dte' ); ?></strong> <?php esc_html_e( 'Archivo XML entregado por el SII que autoriza un rango de folios para emitir DTE.', 'sii-boleta-dte' ); ?></li>
                <li><strong><?php esc_html_e( 'RVD', 'sii-boleta-dte' ); ?></strong> <?php esc_html_e( 'Resumen de Ventas Diarias que informa al SII los montos y folios utilizados cada día.', 'sii-boleta-dte' ); ?></li>
                <li><strong><?php esc_html_e( 'CDF', 'sii-boleta-dte' ); ?></strong> <?php esc_html_e( 'Consumo de Folios que reporta al SII los folios utilizados y disponibles.', 'sii-boleta-dte' ); ?></li>
            </ul>

            <h2><?php esc_html_e( 'Proceso de certificación', 'sii-boleta-dte' ); ?></h2>
            <ol>
                <li><strong><?php esc_html_e( 'Pre-requisitos', 'sii-boleta-dte' ); ?></strong>
                    <ul>
                        <li><?php esc_html_e( 'Ser contribuyente autorizado como emisor electrónico.', 'sii-boleta-dte' ); ?></li>
                        <li><?php esc_html_e( 'Contar con certificado digital vigente.', 'sii-boleta-dte' ); ?></li>
                        <li><?php esc_html_e( 'Disponer de CAF por cada tipo de DTE a certificar.', 'sii-boleta-dte' ); ?></li>
                        <li><?php esc_html_e( 'Configurar el plugin con estos datos.', 'sii-boleta-dte' ); ?></li>
                    </ul>
                </li>
                <li><strong><?php esc_html_e( 'Solicitud del Set de Pruebas', 'sii-boleta-dte' ); ?></strong>
                    <ul>
                        <li><?php esc_html_e( 'El representante legal debe solicitar el set en sii.cl y esperar la respuesta (aprox. 24 horas).', 'sii-boleta-dte' ); ?></li>
                    </ul>
                </li>
                <li><strong><?php esc_html_e( 'Preparación del ambiente de certificación', 'sii-boleta-dte' ); ?></strong>
                    <ul>
                        <li><?php esc_html_e( 'Cargar el set de pruebas entregado por el SII.', 'sii-boleta-dte' ); ?></li>
                        <li><?php esc_html_e( 'Configurar la URL de consulta pública.', 'sii-boleta-dte' ); ?></li>
                        <li><?php esc_html_e( 'Generar boletas de prueba en ambiente "test".', 'sii-boleta-dte' ); ?></li>
                    </ul>
                </li>
                <li><strong><?php esc_html_e( 'Emisión y envío de escenarios de prueba', 'sii-boleta-dte' ); ?></strong>
                    <ul>
                        <li><?php esc_html_e( 'En "SII Boletas → Generar DTE" complete los datos del escenario y marque "Enviar al SII" para firmar y enviar el XML, guardando el trackId.', 'sii-boleta-dte' ); ?></li>
                        <li><?php esc_html_e( 'Repita el proceso hasta completar los cinco escenarios. Los XML firmados quedan en wp-content/uploads.', 'sii-boleta-dte' ); ?></li>
                        <li><?php esc_html_e( 'Comandos WP-CLI adicionales:', 'sii-boleta-dte' ); ?></li>
                        <li><code>wp sii rvd --date=YYYY-MM-DD</code></li>
                        <li><code>wp sii cdf --date=YYYY-MM-DD</code></li>
                        <li><?php esc_html_e( 'Revisar el estado de cada envío hasta ser aceptado.', 'sii-boleta-dte' ); ?></li>
                    </ul>
                </li>
                <li><strong><?php esc_html_e( 'Envío de antecedentes al SII', 'sii-boleta-dte' ); ?></strong>
                    <ul>
                        <li><?php esc_html_e( 'Enviar cinco lotes de prueba al correo SII_BE_Certificacion@sii.cl con el asunto solicitado, adjuntando XML, PDF y trackId.', 'sii-boleta-dte' ); ?></li>
                    </ul>
                </li>
                <li><strong><?php esc_html_e( 'Verificación y corrección', 'sii-boleta-dte' ); ?></strong>
                    <ul>
                        <li><?php esc_html_e( 'Revisar los trackId, corregir observaciones y asegurar que la URL de consulta funcione.', 'sii-boleta-dte' ); ?></li>
                    </ul>
                </li>
                <li><strong><?php esc_html_e( 'Declaración de cumplimiento', 'sii-boleta-dte' ); ?></strong>
                    <ul>
                        <li><?php esc_html_e( 'Una vez aprobado, ingresar a sii.cl para completar y firmar la declaración de cumplimiento.', 'sii-boleta-dte' ); ?></li>
                    </ul>
                </li>
                <li><strong><?php esc_html_e( 'Paso a producción', 'sii-boleta-dte' ); ?></strong>
                    <ul>
                        <li><?php esc_html_e( 'Cambiar el ambiente a production.', 'sii-boleta-dte' ); ?></li>
                        <li><?php esc_html_e( 'Cargar los CAF de producción y reactivar las tareas cron.', 'sii-boleta-dte' ); ?></li>
                    </ul>
                </li>
                <li><strong><?php esc_html_e( 'Consejos finales', 'sii-boleta-dte' ); ?></strong>
                    <ul>
                        <li><?php esc_html_e( 'Mantener logs y respaldos.', 'sii-boleta-dte' ); ?></li>
                        <li><?php esc_html_e( 'Ejecutar pruebas automáticas regularmente.', 'sii-boleta-dte' ); ?></li>
                        <li><?php esc_html_e( 'Capacitar al equipo en el uso del sistema.', 'sii-boleta-dte' ); ?></li>
                    </ul>
                </li>
            </ol>

            <h2><?php esc_html_e( 'Paso a producción', 'sii-boleta-dte' ); ?></h2>
            <ul>
                <li><?php esc_html_e( 'Cambiar el ambiente del plugin a "production".', 'sii-boleta-dte' ); ?></li>
                <li><?php esc_html_e( 'Actualizar certificados y CAF a los de producción.', 'sii-boleta-dte' ); ?></li>
                <li><?php esc_html_e( 'Limpiar los trackId de pruebas.', 'sii-boleta-dte' ); ?></li>
                <li><?php esc_html_e( 'Realizar backups periódicos.', 'sii-boleta-dte' ); ?></li>
            </ul>

            <h2><?php esc_html_e( 'Guía rápida (CERT)', 'sii-boleta-dte' ); ?></h2>
            <ol>
                <li><strong><?php esc_html_e( 'Configurar Ajustes', 'sii-boleta-dte' ); ?></strong>
                    <ul>
                        <li><?php esc_html_e( 'Ambiente en test, RUT/Giro/Dirección/Comuna/Acteco, certificado (.p12/.pfx) y CAF por tipo.', 'sii-boleta-dte' ); ?></li>
                        <li><?php esc_html_e( 'Validar checklist y autenticación SII en la misma pantalla.', 'sii-boleta-dte' ); ?></li>
                    </ul>
                </li>
                <li><strong><?php esc_html_e( 'Emitir y enviar DTE (WP-CLI)', 'sii-boleta-dte' ); ?></strong>
                    <pre><code>wp sii dte emitir \
  --type=39 \
  --rut=66666666-6 --name="CF" --addr="Calle 123" --comuna="Santiago" \
  --desc="Servicio" --qty=1 --price=1000 --send</code></pre>
                    <p><?php esc_html_e( 'Para referencias múltiples (p. ej., NC):', 'sii-boleta-dte' ); ?></p>
                    <pre><code>wp sii dte emitir --type=61 --rut=76000000-0 --name="Cliente SA" \
  --addr="Av. Uno 100" --comuna="Providencia" --desc="NC varias ref" --qty=1 --price=-1000 \
  --refs='[{"TpoDocRef":33,"FolioRef":12345,"FchRef":"2025-01-01","RazonRef":"Descuento"}]' --send</code></pre>
                </li>
                <li><strong><?php esc_html_e( 'Verificar estado', 'sii-boleta-dte' ); ?></strong>
                    <ul>
                        <li><?php echo sprintf( esc_html__( 'Panel → %s → pestaña %s → botón %s', 'sii-boleta-dte' ), '<a href="' . esc_url( admin_url( 'admin.php?page=sii-boleta-dte-panel' ) ) . '">SII Boletas → Panel de Control</a>', esc_html__( 'Log de Envíos', 'sii-boleta-dte' ), esc_html__( 'Revisar estados ahora', 'sii-boleta-dte' ) ); ?></li>
                        <li><code>wp sii dte status --track=123456</code></li>
                    </ul>
                </li>
                <li><strong><?php esc_html_e( 'PDF/HTML', 'sii-boleta-dte' ); ?></strong>
                    <ul>
                        <li><?php esc_html_e( 'Ajustar formato (A4/80mm), logo y pie en Ajustes. Ver PDF/HTML enlazado en el panel o endpoint público.', 'sii-boleta-dte' ); ?></li>
                        <li><?php echo sprintf( esc_html__( 'URL pública: %s', 'sii-boleta-dte' ), esc_url( home_url( '/boleta/{folio}' ) ) ); ?></li>
                    </ul>
                </li>
            </ol>

            <h2><?php esc_html_e( 'Preguntas frecuentes', 'sii-boleta-dte' ); ?></h2>
            <ul>
                <li><?php esc_html_e( 'Rechazo de firma: verifique que el certificado y la clave sean correctos.', 'sii-boleta-dte' ); ?></li>
                <li><?php esc_html_e( 'Token inválido: regenere el token desde los ajustes.', 'sii-boleta-dte' ); ?></li>
                <li><?php esc_html_e( 'Errores de esquema: valide el XML contra los XSD proporcionados.', 'sii-boleta-dte' ); ?></li>
            </ul>

            <p>
                <a href="<?php echo esc_url( $readme_url ); ?>" target="_blank">README</a> |
                <a href="<?php echo esc_url( $consumo_xsd ); ?>" target="_blank">consumo_folios.xsd</a>
            </p>
        </div>
        <?php
    }
}
