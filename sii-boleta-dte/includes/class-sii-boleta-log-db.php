<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Maneja el registro consolidado de envíos al SII.
 */
class SII_Boleta_Log_DB {

    const TABLE = 'sii_boleta_logs';

    /**
     * Crea la tabla de registros si no existe.
     */
    public static function install() {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            track_id varchar(50) NOT NULL,
            status varchar(20) NOT NULL,
            response text NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY track_id (track_id)
        ) $charset_collate;";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /**
     * Inserta un registro en la tabla.
     *
     * @param string $track_id Track ID entregado por el SII.
     * @param string $status   Estado del envío.
     * @param string $response Respuesta completa del SII.
     */
    public static function add_entry( $track_id, $status, $response ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $wpdb->insert(
            $table,
            [
                'track_id'   => $track_id,
                'status'     => $status,
                'response'   => $response,
                'created_at' => current_time( 'mysql' ),
            ],
            [ '%s', '%s', '%s', '%s' ]
        );
    }

    /**
     * Obtiene los registros más recientes.
     *
     * @param int $limit Cantidad de filas a recuperar.
     * @return array
     */
    public static function get_entries( $limit = 50 ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $sql   = $wpdb->prepare( "SELECT * FROM $table ORDER BY id DESC LIMIT %d", $limit );
        return $wpdb->get_results( $sql, ARRAY_A );
    }
}
