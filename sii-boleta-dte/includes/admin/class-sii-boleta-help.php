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
        $libro_xsd   = SII_BOLETA_DTE_URL . 'includes/xml/schemas/libro_boletas.xsd';
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
                <li><?php esc_html_e( 'Generación y envío del Libro de Boletas.', 'sii-boleta-dte' ); ?></li>
                <li><?php esc_html_e( 'Resumen de Ventas Diarias (RVD).', 'sii-boleta-dte' ); ?></li>
                <li><?php esc_html_e( 'Consumo de Folios (CDF).', 'sii-boleta-dte' ); ?></li>
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
                        <li><?php esc_html_e( 'Solicitar el set mediante el sitio del SII y esperar la respuesta (aprox. 24 horas).', 'sii-boleta-dte' ); ?></li>
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
                        <li><?php esc_html_e( 'Generar boletas, RVD, Libro y CDF capturando el trackId devuelto.', 'sii-boleta-dte' ); ?></li>
                        <li><?php esc_html_e( 'Comandos WP-CLI:', 'sii-boleta-dte' ); ?></li>
                        <li><code>wp sii rvd --date=YYYY-MM-DD</code></li>
                        <li><code>wp sii libro --from=YYYY-MM --to=YYYY-MM</code></li>
                        <li><code>wp sii cdf --date=YYYY-MM-DD</code></li>
                        <li><?php esc_html_e( 'Revisar el estado de cada envío hasta ser aceptado.', 'sii-boleta-dte' ); ?></li>
                    </ul>
                </li>
                <li><strong><?php esc_html_e( 'Envío de antecedentes al SII', 'sii-boleta-dte' ); ?></strong>
                    <ul>
                        <li><?php esc_html_e( 'Enviar cinco lotes de pruebas al correo SII_BE_Certificacion@sii.cl con el asunto solicitado.', 'sii-boleta-dte' ); ?></li>
                    </ul>
                </li>
                <li><strong><?php esc_html_e( 'Verificación y corrección', 'sii-boleta-dte' ); ?></strong>
                    <ul>
                        <li><?php esc_html_e( 'Revisar los trackId, corregir observaciones y asegurar que la URL de consulta funcione.', 'sii-boleta-dte' ); ?></li>
                    </ul>
                </li>
                <li><strong><?php esc_html_e( 'Declaración de cumplimiento', 'sii-boleta-dte' ); ?></strong>
                    <ul>
                        <li><?php esc_html_e( 'Una vez aprobado, declarar en línea y esperar la autorización final.', 'sii-boleta-dte' ); ?></li>
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

            <h2><?php esc_html_e( 'Preguntas frecuentes', 'sii-boleta-dte' ); ?></h2>
            <ul>
                <li><?php esc_html_e( 'Rechazo de firma: verifique que el certificado y la clave sean correctos.', 'sii-boleta-dte' ); ?></li>
                <li><?php esc_html_e( 'Token inválido: regenere el token desde los ajustes.', 'sii-boleta-dte' ); ?></li>
                <li><?php esc_html_e( 'Errores de esquema: valide el XML contra los XSD proporcionados.', 'sii-boleta-dte' ); ?></li>
            </ul>

            <p>
                <a href="<?php echo esc_url( $readme_url ); ?>" target="_blank">README</a> |
                <a href="<?php echo esc_url( $libro_xsd ); ?>" target="_blank">libro_boletas.xsd</a> |
                <a href="<?php echo esc_url( $consumo_xsd ); ?>" target="_blank">consumo_folios.xsd</a>
            </p>
        </div>
        <?php
    }
}
