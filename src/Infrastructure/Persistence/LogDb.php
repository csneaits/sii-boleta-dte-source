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

        /** @var array<int, array{track_id:string,status:string,response:string,environment:string,document_type:?int,folio:?int,created_at:string}> */
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
document_type smallint unsigned NULL,
folio bigint(20) unsigned NULL,
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

                if ( method_exists( $wpdb, 'query' ) ) {
                        $table_name = self::table();
                        $columns    = $wpdb->get_col( "SHOW COLUMNS FROM {$table_name} LIKE 'document_type'" );
                        if ( empty( $columns ) ) {
                                $wpdb->query( "ALTER TABLE {$table_name} ADD COLUMN document_type smallint unsigned NULL AFTER environment" );
                        }
                        $columns = $wpdb->get_col( "SHOW COLUMNS FROM {$table_name} LIKE 'folio'" );
                        if ( empty( $columns ) ) {
                                $wpdb->query( "ALTER TABLE {$table_name} ADD COLUMN folio bigint(20) unsigned NULL AFTER document_type" );
                        }
                }
        }

        /**
         * Persists a log entry.
         */
        public static function add_entry( string $track_id, string $status, string $response, string $environment = '0', array $meta = array() ): void {
                global $wpdb;
                $created = function_exists( 'current_time' ) ? current_time( 'mysql', true ) : gmdate( 'Y-m-d H:i:s' );
                $env     = Settings::normalize_environment( $environment );
                $doc_type = isset( $meta['type'] ) ? (int) $meta['type'] : ( isset( $meta['document_type'] ) ? (int) $meta['document_type'] : null );
                $folio    = isset( $meta['folio'] ) ? (int) $meta['folio'] : null;

                if ( is_object( $wpdb ) && method_exists( $wpdb, 'insert' ) ) {
                        $inserted = $wpdb->insert(
                                self::table(),
                                array(
                                        'track_id'  => $track_id,
                                        'status'    => $status,
                                        'response'  => $response,
                                        'environment' => $env,
                                        'document_type' => $doc_type,
                                        'folio'     => $folio,
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
                        'document_type' => $doc_type,
                        'folio'     => $folio,
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
                        $sql   = "SELECT track_id,status,response,environment,document_type,folio,created_at FROM {$table}";
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
                        if ( ! is_array( $rows ) ) {
                                return array();
                        }
                        return array_map( static fn( $row ) => self::enrich_row( (array) $row ), $rows );
                }

                // Fallback to in-memory store.
                $rows = array_map( static fn( $row ) => self::enrich_row( $row ), self::$entries );
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
                return array_slice( array_reverse( array_values( $rows ) ), 0, $limit );
        }

        /**
         * Returns a paginated list of logs with optional filters.
         *
         * @param array<string,mixed> $args
         * @return array{rows:array<int,array<string,mixed>>,total:int,page:int,pages:int,limit:int}
         */
        public static function get_logs_paginated( array $args = array() ): array {
                $defaults = array(
                        'limit'       => 10,
                        'page'        => 1,
                        'environment' => null,
                        'status'      => '',
                        'type'        => null,
                        'date_from'   => '',
                        'date_to'     => '',
                );
                $args = array_merge( $defaults, $args );

                $limit = max( 1, min( 200, (int) $args['limit'] ) );
                $page  = max( 1, (int) $args['page'] );
                $offset = ( $page - 1 ) * $limit;
                $environment = isset( $args['environment'] ) ? Settings::normalize_environment( (string) $args['environment'] ) : null;
                $status      = isset( $args['status'] ) ? trim( (string) $args['status'] ) : '';
                if ( 'all' === strtolower( $status ) ) {
                        $status = '';
                }
                $type  = isset( $args['type'] ) && '' !== $args['type'] ? (int) $args['type'] : null;
                $from  = isset( $args['date_from'] ) ? trim( (string) $args['date_from'] ) : '';
                $to    = isset( $args['date_to'] ) ? trim( (string) $args['date_to'] ) : '';

                global $wpdb;
                if ( ! self::$use_memory && is_object( $wpdb ) && method_exists( $wpdb, 'get_results' ) && method_exists( $wpdb, 'prepare' ) ) {
                        $table   = self::table();
                        $clauses = array();
                        $params  = array();

                        if ( $status ) {
                                $clauses[] = 'status = %s';
                                $params[]  = $status;
                        }
                        if ( null !== $environment ) {
                                $clauses[] = 'environment = %s';
                                $params[]  = $environment;
                        }
                        if ( null !== $type ) {
                                $clauses[] = 'document_type = %d';
                                $params[]  = $type;
                        }
                        if ( $from ) {
                                $clauses[] = 'created_at >= %s';
                                $params[]  = $from . ' 00:00:00';
                        }
                        if ( $to ) {
                                $clauses[] = 'created_at <= %s';
                                $params[]  = $to . ' 23:59:59';
                        }

                        $where = $clauses ? 'WHERE ' . implode( ' AND ', $clauses ) : '';

                        $count_sql = "SELECT COUNT(*) FROM {$table} {$where}";
                        $total = $clauses ? (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : (int) $wpdb->get_var( $count_sql );
                        $pages = $limit > 0 ? (int) max( 1, ceil( $total / $limit ) ) : 1;
                        if ( $page > $pages ) {
                                $page   = $pages;
                                $offset = ( $page - 1 ) * $limit;
                        }

                        $rows_params = $params;
                        $rows_sql    = "SELECT id,track_id,status,response,environment,document_type,folio,created_at FROM {$table} {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d";
                        $rows_params[] = $limit;
                        $rows_params[] = $offset;
                        $rows = $wpdb->get_results( $wpdb->prepare( $rows_sql, $rows_params ), 'ARRAY_A' );
                        if ( ! is_array( $rows ) ) {
                                $rows = array();
                        }
                        $rows = array_map( static fn( $row ) => self::enrich_row( (array) $row ), $rows );

                        return array(
                                'rows'  => $rows,
                                'total' => $total,
                                'page'  => $page,
                                'pages' => $pages,
                                'limit' => $limit,
                        );
                }

                $rows = array_map( static fn( $row ) => self::enrich_row( $row ), self::$entries );
                if ( $status ) {
                        $rows = array_filter(
                                $rows,
                                static fn( $row ) => $row['status'] === $status
                        );
                }
                if ( null !== $environment ) {
                        $rows = array_filter(
                                $rows,
                                static fn( $row ) => ( $row['environment'] ?? null ) === $environment
                        );
                }
                if ( null !== $type ) {
                        $rows = array_filter(
                                $rows,
                                static fn( $row ) => isset( $row['document_type'] ) && (int) $row['document_type'] === $type
                        );
                }
                if ( $from ) {
                        $fromTs = strtotime( $from . ' 00:00:00' );
                        $rows = array_filter(
                                $rows,
                                static fn( $row ) => strtotime( $row['created_at'] ?? 'now' ) >= $fromTs
                        );
                }
                if ( $to ) {
                        $toTs = strtotime( $to . ' 23:59:59' );
                        $rows = array_filter(
                                $rows,
                                static fn( $row ) => strtotime( $row['created_at'] ?? 'now' ) <= $toTs
                        );
                }

                $rows = array_reverse( array_values( $rows ) );
                $total = count( $rows );
                $pages = $limit > 0 ? (int) max( 1, ceil( $total / $limit ) ) : 1;
                if ( $page > $pages ) {
                        $page   = $pages;
                        $offset = ( $page - 1 ) * $limit;
                }
                $paged_rows = array_slice( $rows, $offset, $limit );

                return array(
                                'rows'  => $paged_rows,
                                'total' => $total,
                                'page'  => $page,
                                'pages' => $pages,
                                'limit' => $limit,
                );
        }

        /**
         * Returns distinct document types present in logs.
         *
         * @return array<int,int>
         */
        public static function get_distinct_types( ?string $environment = null ): array {
                $env = null === $environment ? null : Settings::normalize_environment( $environment );
                global $wpdb;
                if ( ! self::$use_memory && is_object( $wpdb ) && method_exists( $wpdb, 'get_col' ) && method_exists( $wpdb, 'prepare' ) ) {
                        $table = self::table();
                        $sql   = "SELECT DISTINCT document_type FROM {$table} WHERE document_type IS NOT NULL";
                        $params = array();
                        if ( null !== $env ) {
                                $sql    .= ' AND environment = %s';
                                $params[] = $env;
                        }
                        $sql .= ' ORDER BY document_type ASC';
                        $types = $params ? $wpdb->get_col( $wpdb->prepare( $sql, $params ) ) : $wpdb->get_col( $sql );
                        return array_map( 'intval', array_filter( (array) $types ) );
                }

                $types = array();
                foreach ( self::$entries as $row ) {
                        if ( null !== $env && ( $row['environment'] ?? null ) !== $env ) {
                                continue;
                        }
                        if ( isset( $row['document_type'] ) && null !== $row['document_type'] ) {
                                $types[] = (int) $row['document_type'];
                        }
                }
                $types = array_unique( $types );
                sort( $types, SORT_NUMERIC );
                return $types;
        }

        /**
         * Ensures rows have document metadata populated even for legacy entries.
         */
        private static function enrich_row( array $row ): array {
                if ( ! array_key_exists( 'document_type', $row ) ) {
                        $row['document_type'] = null;
                }
                if ( ! array_key_exists( 'folio', $row ) ) {
                        $row['folio'] = null;
                }

                if ( ( null === $row['document_type'] || 0 === (int) $row['document_type'] ) || null === $row['folio'] ) {
                        $meta = self::extract_meta_from_response( (string) ( $row['response'] ?? '' ) );
                        if ( null === $row['document_type'] && isset( $meta['type'] ) ) {
                                $row['document_type'] = (int) $meta['type'];
                        }
                        if ( null === $row['folio'] && isset( $meta['folio'] ) ) {
                                $row['folio'] = (int) $meta['folio'];
                        }
                }

                if ( null !== $row['document_type'] ) {
                        $row['document_type'] = (int) $row['document_type'];
                }
                if ( null !== $row['folio'] ) {
                        $row['folio'] = (int) $row['folio'];
                }

                return $row;
        }

        /**
         * Attempts to extract metadata from a response payload.
         *
         * @return array<string,mixed>
         */
        private static function extract_meta_from_response( string $response ): array {
                if ( '' === $response ) {
                        return array();
                }
                $decoded = json_decode( $response, true );
                if ( ! is_array( $decoded ) ) {
                        return array();
                }
                if ( isset( $decoded['meta'] ) && is_array( $decoded['meta'] ) ) {
                        return $decoded['meta'];
                }
                return $decoded;
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
