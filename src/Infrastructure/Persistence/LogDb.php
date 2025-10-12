<?php
namespace Sii\BoletaDte\Infrastructure\Persistence;

use Sii\BoletaDte\Infrastructure\Settings;

/**
 * Persistent log storage.
 *
 * The previous implementation stored entries in memory which meant that any
 * information about the communication with SII was lost between requests. This
 * replacement stores the data in a custom WordPress table when the global
 * `$wpdb` object is available.  During unit tests where a database might not be
 * present, a lightweight in-memory fallback is used to keep the behaviour
 * deterministic.
 */
class LogDb {
        /** Table name without prefix. */
        public const TABLE = 'sii_boleta_dte_logs';

        /** @var array<int, array{track_id:string,status:string,response:string,environment:string,created_at:string}> */
        private static array $entries = array();

        /** Indicates whether the in-memory store should be used. */
        private static bool $use_memory = true;

        /**
         * Returns the full table name including WordPress prefix.
         */
        private static function table(): string {
                global $wpdb;
                $prefix = is_object( $wpdb ) && property_exists( $wpdb, 'prefix' ) ? $wpdb->prefix : 'wp_';
                return $prefix . self::TABLE;
        }

        /**
         * Creates the custom table used for logging.
         */
        public static function install(): void {
                global $wpdb;
                if ( ! is_object( $wpdb ) ) {
                        // Tests without a database simply reset the in-memory store.
                        self::$entries  = array();
                        self::$use_memory = true;
                        return;
                }

                $table           = self::table();
                $charset_collate = method_exists( $wpdb, 'get_charset_collate' ) ? $wpdb->get_charset_collate() : '';
                $sql             = "CREATE TABLE {$table} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
track_id varchar(50) NOT NULL,
status varchar(20) NOT NULL,
response longtext NOT NULL,
environment varchar(20) NOT NULL DEFAULT '0',
created_at datetime NOT NULL,
PRIMARY KEY  (id),
KEY track_id (track_id),
KEY status (status),
KEY env_status (environment, status)
) {$charset_collate};";

                if ( function_exists( 'dbDelta' ) ) {
                        dbDelta( $sql );
                } elseif ( method_exists( $wpdb, 'query' ) ) {
                        // Fallback for environments without dbDelta (e.g. tests).
                        $wpdb->query( $sql );
                }
        }

        /**
         * Persists a log entry.
         */
        public static function add_entry( string $track_id, string $status, string $response, string $environment = '0' ): void {
                global $wpdb;
                $created = function_exists( 'current_time' ) ? current_time( 'mysql', true ) : gmdate( 'Y-m-d H:i:s' );
                $env     = Settings::normalize_environment( $environment );

                if ( is_object( $wpdb ) && method_exists( $wpdb, 'insert' ) ) {
                        $inserted = $wpdb->insert(
                                self::table(),
                                array(
                                        'track_id'  => $track_id,
                                        'status'    => $status,
                                        'response'  => $response,
                                        'environment' => $env,
                                        'created_at'=> $created,
                                )
                        );
                        if ( is_int( $inserted ) && $inserted > 0 ) {
                                self::$use_memory = false;
                                return;
                        }
                }

                // Fallback for tests without database.
                self::$use_memory = true;
                self::$entries[] = array(
                        'track_id'  => $track_id,
                        'status'    => $status,
                        'response'  => $response,
                        'environment' => $env,
                        'created_at'=> $created,
                );
        }

        /**
         * Returns pending track IDs with status 'sent'.
         *
         * @return array<int,string>
         */
        public static function get_pending_track_ids( int $limit = 50, ?string $environment = null ): array {
                $env = null === $environment ? null : Settings::normalize_environment( $environment );
                global $wpdb;
                if ( is_object( $wpdb ) && method_exists( $wpdb, 'get_col' ) && method_exists( $wpdb, 'prepare' ) ) {
                        $table  = self::table();
                        $where  = 'status = %s';
                        $params = array( 'sent' );
                        if ( null !== $env ) {
                                $where   .= ' AND environment = %s';
                                $params[] = $env;
                        }
                        $params[] = $limit;
                        $sql      = $wpdb->prepare(
                                "SELECT track_id FROM {$table} WHERE {$where} ORDER BY id DESC LIMIT %d",
                                $params
                        );
                        $result = $wpdb->get_col( $sql );
                        return is_array( $result ) ? $result : array();
                }

                $ids = array();
                foreach ( array_reverse( self::$entries ) as $entry ) {
                        if ( 'sent' === $entry['status'] && ( null === $env || $entry['environment'] === $env ) ) {
                                $ids[] = $entry['track_id'];
                        }
                        if ( count( $ids ) >= $limit ) {
                                break;
                        }
                }
                return $ids;
        }

        /**
         * Retrieves stored log rows.
         *
         * @param array<string,mixed> $args Query arguments: status and limit.
         * @return array<int,array{track_id:string,status:string,response:string,created_at:string}>
         */
        public static function get_logs( array $args = array() ): array {
                $status = $args['status'] ?? null;
                $limit  = isset( $args['limit'] ) ? (int) $args['limit'] : 100;
                $environment = isset( $args['environment'] ) ? Settings::normalize_environment( (string) $args['environment'] ) : null;

                global $wpdb;
                if ( ! self::$use_memory && is_object( $wpdb ) && method_exists( $wpdb, 'get_results' ) && method_exists( $wpdb, 'prepare' ) ) {
                        $table = self::table();
                        $sql   = "SELECT track_id,status,response,environment,created_at FROM {$table}";
                        $params = array();
                        $clauses = array();
                        if ( $status ) {
                                $clauses[] = 'status = %s';
                                $params[]  = $status;
                        }
                        if ( null !== $environment ) {
                                $clauses[] = 'environment = %s';
                                $params[]  = $environment;
                        }
                        if ( $clauses ) {
                                $sql .= ' WHERE ' . implode( ' AND ', $clauses );
                        }
                        $sql     .= ' ORDER BY id DESC LIMIT %d';
                        $params[] = $limit;
                        $prepared = $wpdb->prepare( $sql, $params );
                        $rows     = $wpdb->get_results( $prepared, 'ARRAY_A' );
                        return is_array( $rows ) ? $rows : array();
                }

                // Fallback to in-memory store.
                $rows = self::$entries;
                if ( $status ) {
                        $rows = array_filter(
                                $rows,
                                static fn( $row ) => $row['status'] === $status
                        );
                }
                if ( null !== $environment ) {
                        $rows = array_filter(
                                $rows,
                                static fn( $row ) => $row['environment'] === $environment
                        );
                }
                return array_slice( array_reverse( $rows ), 0, $limit );
        }

        /**
         * Returns system health metrics from the logs.
         * 
         * @return array{success_rate:float,total_last_24h:int,errors_last_24h:int,most_common_error:string,avg_queue_time:int}
         */
        public static function get_health_metrics(): array {
                global $wpdb;
                if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_results' ) ) {
                        // In-memory fallback with limited data
                        $recent = array_filter( 
                                self::$entries, 
                                fn( $entry ) => strtotime( $entry['created_at'] ?? 'now' ) > strtotime( '-24 hours' ) 
                        );
                        $total = count( $recent );
                        $errors = array_filter( $recent, fn( $entry ) => $entry['status'] === 'error' );
                        $success_rate = $total > 0 ? ( ( $total - count( $errors ) ) / $total ) * 100 : 100;
                        
                        return array(
                                'success_rate'       => round( $success_rate, 1 ),
                                'total_last_24h'     => $total,
                                'errors_last_24h'    => count( $errors ),
                                'most_common_error'  => '',
                                'avg_queue_time'     => 0,
                        );
                }

                $table = self::table();
                
                // Métricas básicas últimas 24 horas
                $stats = $wpdb->get_row( "
                        SELECT 
                                COUNT(*) as total,
                                SUM(CASE WHEN status = 'error' THEN 1 ELSE 0 END) as errors,
                                SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent,
                                SUM(CASE WHEN status = 'queued' THEN 1 ELSE 0 END) as queued
                        FROM {$table} 
                        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                " );

                // Error más común en las últimas 24 horas
                $common_error = $wpdb->get_var( "
                        SELECT response
                        FROM {$table} 
                        WHERE status = 'error' 
                        AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                        AND response != ''
                        GROUP BY response 
                        ORDER BY COUNT(*) DESC 
                        LIMIT 1
                " );

                // Tiempo promedio entre encolado y envío exitoso (aproximado)
                $avg_queue_time = $wpdb->get_var( "
                        SELECT AVG(TIMESTAMPDIFF(MINUTE, q.created_at, s.created_at)) as avg_minutes
                        FROM {$table} q
                        JOIN {$table} s ON q.track_id = s.track_id
                        WHERE q.status = 'queued' 
                        AND s.status = 'sent'
                        AND q.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                        AND s.created_at > q.created_at
                " );

                if ( ! $stats ) {
                        return array(
                                'success_rate'       => 100.0,
                                'total_last_24h'     => 0,
                                'errors_last_24h'    => 0,
                                'most_common_error'  => '',
                                'avg_queue_time'     => 0,
                        );
                }

                $stats = (array) $stats;
                $total = (int) ( $stats['total'] ?? 0 );
                $errors = (int) ( $stats['errors'] ?? 0 );
                $sent = (int) ( $stats['sent'] ?? 0 );
                
                $success_rate = $total > 0 ? ( $sent / $total ) * 100 : 100;

                return array(
                        'success_rate'       => round( $success_rate, 1 ),
                        'total_last_24h'     => $total,
                        'errors_last_24h'    => $errors,
                        'most_common_error'  => is_string( $common_error ) ? $common_error : '',
                        'avg_queue_time'     => (int) ( $avg_queue_time ?? 0 ),
                );
        }

        /**
         * Returns error breakdown by type for the last 7 days.
         * 
         * @return array<array{error_type:string,count:int,last_seen:string}>
         */
        public static function get_error_breakdown(): array {
                global $wpdb;
                if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_results' ) ) {
                        // Simplified in-memory version
                        $errors = array_filter( self::$entries, fn( $entry ) => $entry['status'] === 'error' );
                        $breakdown = array();
                        foreach ( $errors as $error ) {
                                $type = $error['response'] ?: 'Error desconocido';
                                if ( ! isset( $breakdown[ $type ] ) ) {
                                        $breakdown[ $type ] = array(
                                                'error_type' => $type,
                                                'count'      => 0,
                                                'last_seen'  => $error['created_at'],
                                        );
                                }
                                $breakdown[ $type ]['count']++;
                        }
                        return array_values( $breakdown );
                }

                $table = self::table();
                $results = $wpdb->get_results( "
                        SELECT 
                                COALESCE(NULLIF(response, ''), 'Error desconocido') as error_type,
                                COUNT(*) as count,
                                MAX(created_at) as last_seen
                        FROM {$table} 
                        WHERE status = 'error' 
                        AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                        GROUP BY response
                        ORDER BY count DESC
                        LIMIT 10
                " );

                if ( ! is_array( $results ) ) {
                        return array();
                }

                return array_map( fn( $row ) => (array) $row, $results );
        }

        /**
         * Deletes stored logs.
         */
        public static function purge(): void {
                global $wpdb;
                if ( is_object( $wpdb ) && method_exists( $wpdb, 'query' ) ) {
                        $wpdb->query( 'TRUNCATE TABLE ' . self::table() );
                        self::$use_memory = false;
                        return;
                }
                self::$entries   = array();
                self::$use_memory = true;
        }
}

class_alias( LogDb::class, 'SII_Boleta_Log_DB' );
