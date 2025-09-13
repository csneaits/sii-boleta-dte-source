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

    /**
     * Obtiene track IDs pendientes (estado 'sent') para verificación.
     *
     * @param int $limit
     * @return array Lista de track IDs (strings)
     */
    public static function get_pending_track_ids( $limit = 50 ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $sql   = $wpdb->prepare(
            "SELECT DISTINCT track_id FROM $table WHERE status = %s ORDER BY id DESC LIMIT %d",
            'sent',
            $limit
        );
        return $wpdb->get_col( $sql );
    }

    /**
     * Obtiene registros filtrados con paginación.
     *
     * @param string $trackIdFiltro Filtro parcial por track ID.
     * @param string $statusFiltro  Filtro parcial por estado.
     * @param int    $limit         Cantidad por página.
     * @param int    $offset        Desplazamiento.
     * @return array                Registros como arreglo asociativo.
     */
    public static function get_entries_filtered( $trackIdFiltro = '', $statusFiltro = '', $dateFrom = '', $dateTo = '', $limit = 20, $offset = 0 ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        $where  = '1=1';
        $params = [];
        if ( $trackIdFiltro !== '' ) {
            $where   .= ' AND track_id LIKE %s';
            $params[] = '%' . $wpdb->esc_like( $trackIdFiltro ) . '%';
        }
        if ( $statusFiltro !== '' ) {
            $where   .= ' AND status LIKE %s';
            $params[] = '%' . $wpdb->esc_like( $statusFiltro ) . '%';
        }
        if ( $dateFrom !== '' ) {
            // Normalizar a inicio de día si viene solo fecha
            if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $dateFrom ) ) {
                $dateFrom .= ' 00:00:00';
            }
            $where   .= ' AND created_at >= %s';
            $params[] = $dateFrom;
        }
        if ( $dateTo !== '' ) {
            if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $dateTo ) ) {
                $dateTo .= ' 23:59:59';
            }
            $where   .= ' AND created_at <= %s';
            $params[] = $dateTo;
        }

        $sql = "SELECT * FROM $table WHERE $where ORDER BY id DESC LIMIT %d OFFSET %d";
        $params[] = intval( $limit );
        $params[] = intval( $offset );

        return $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
    }

    /**
     * Cuenta registros filtrados.
     *
     * @param string $trackIdFiltro
     * @param string $statusFiltro
     * @return int Total de registros filtrados.
     */
    public static function count_entries_filtered( $trackIdFiltro = '', $statusFiltro = '', $dateFrom = '', $dateTo = '' ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        $where  = '1=1';
        $params = [];
        if ( $trackIdFiltro !== '' ) {
            $where   .= ' AND track_id LIKE %s';
            $params[] = '%' . $wpdb->esc_like( $trackIdFiltro ) . '%';
        }
        if ( $statusFiltro !== '' ) {
            $where   .= ' AND status LIKE %s';
            $params[] = '%' . $wpdb->esc_like( $statusFiltro ) . '%';
        }
        if ( $dateFrom !== '' ) {
            if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $dateFrom ) ) {
                $dateFrom .= ' 00:00:00';
            }
            $where   .= ' AND created_at >= %s';
            $params[] = $dateFrom;
        }
        if ( $dateTo !== '' ) {
            if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $dateTo ) ) {
                $dateTo .= ' 23:59:59';
            }
            $where   .= ' AND created_at <= %s';
            $params[] = $dateTo;
        }

        $sql = "SELECT COUNT(*) FROM $table WHERE $where";
        return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) );
    }
}
