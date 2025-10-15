<?php
namespace Sii\BoletaDte\Infrastructure\Persistence;

use Sii\BoletaDte\Infrastructure\WordPress\Settings;

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
                $created            = function_exists( 'current_time' ) ? current_time( 'mysql', true ) : gmdate( 'Y-m-d H:i:s' );
                $env                = Settings::normalize_environment( $environment );
                // Normalizar y completar metadatos de documento (tipo/folio)
                $doc_type           = isset( $meta['type'] ) ? (int) $meta['type'] : ( isset( $meta['document_type'] ) ? (int) $meta['document_type'] : null );
                $folio              = isset( $meta['folio'] ) ? (int) $meta['folio'] : null;
                // Intentar extraer meta desde el response si viene en JSON
                if ( ( null === $doc_type || null === $folio ) && '' !== trim( (string) $response ) ) {
                        $decoded = json_decode( (string) $response, true );
                        if ( is_array( $decoded ) ) {
                                $meta_dec = isset( $decoded['meta'] ) && is_array( $decoded['meta'] ) ? $decoded['meta'] : $decoded;
                                if ( null === $doc_type && isset( $meta_dec['type'] ) ) {
                                        $doc_type = (int) $meta_dec['type'];
                                }
                                if ( null === $folio && isset( $meta_dec['folio'] ) ) {
                                        $folio = (int) $meta_dec['folio'];
                                }
                        }
                }
                $normalized_status  = strtolower( trim( (string) $status ) );

                // Historically info-level entries were not persisted to avoid
                // noisy logs, but tests and some integrations expect info
                // entries to be stored for auditability. Persist them here.

                $normalized_track = trim( (string) $track_id );

                // Si conocemos (tipo, folio), reutiliza el Track ID ya registrado para ese par
                if ( (int) $doc_type > 0 && (int) $folio > 0 ) {
                        $tracked_tid = '';
                        if ( is_object( $wpdb ) && method_exists( $wpdb, 'prepare' ) && method_exists( $wpdb, 'get_var' ) ) {
                                $tracked_tid = (string) $wpdb->get_var(
                                        $wpdb->prepare(
                                                'SELECT track_id FROM ' . self::table() . ' WHERE document_type = %d AND folio = %d AND track_id <> "" ORDER BY id ASC LIMIT 1',
                                                (int) $doc_type,
                                                (int) $folio
                                        )
                                );
                        } else {
                                for ( $i = count( self::$entries ) - 1; $i >= 0; --$i ) {
                                        $row = self::$entries[ $i ] ?? array();
                                        if ( (int) ( $row['document_type'] ?? 0 ) === (int) $doc_type && (int) ( $row['folio'] ?? 0 ) === (int) $folio ) {
                                                $tracked_tid = (string) ( $row['track_id'] ?? '' );
                                                if ( '' !== trim( $tracked_tid ) ) { break; }
                                        }
                                }
                        }
                        if ( '' !== trim( $tracked_tid ) ) {
                                $normalized_track = $tracked_tid;
                        }
                }

                // Avoid polluting the logs with entries that cannot be tracked or reconciled.
                $requires_track = in_array( $normalized_status, array( 'sent', 'accepted' ), true );
                if ( $requires_track && '' === $normalized_track && ( null === $folio || $folio <= 0 ) ) {
                        return;
                }

                // Si hay track_id, intenta completar datos desde registros previos con el mismo track
                if ( '' !== trim( (string) $track_id ) && ( null === $doc_type || null === $folio ) ) {
                        if ( is_object( $wpdb ) && method_exists( $wpdb, 'prepare' ) && method_exists( $wpdb, 'get_row' ) ) {
                                $prev = $wpdb->get_row( $wpdb->prepare( 'SELECT document_type, folio FROM ' . self::table() . ' WHERE track_id = %s ORDER BY id DESC LIMIT 1', trim( (string) $track_id ) ), ARRAY_A );
                                if ( is_array( $prev ) ) {
                                        if ( null === $doc_type && isset( $prev['document_type'] ) && '' !== (string) $prev['document_type'] ) {
                                                $doc_type = (int) $prev['document_type'];
                                        }
                                        if ( null === $folio && isset( $prev['folio'] ) && '' !== (string) $prev['folio'] ) {
                                                $folio = (int) $prev['folio'];
                                        }
                                }
                        } else {
                                // Fallback en memoria
                                for ( $i = count( self::$entries ) - 1; $i >= 0; --$i ) {
                                        $row = self::$entries[ $i ];
                                        if ( ( $row['track_id'] ?? '' ) === trim( (string) $track_id ) ) {
                                                if ( null === $doc_type && isset( $row['document_type'] ) && null !== $row['document_type'] ) {
                                                        $doc_type = (int) $row['document_type'];
                                                }
                                                if ( null === $folio && isset( $row['folio'] ) && null !== $row['folio'] ) {
                                                        $folio = (int) $row['folio'];
                                                }
                                                break;
                                        }
                                }
                        }
                }

                // Caso especial: simulación de envío (DTE-SIM-xxxx) sin meta
                if ( 'sent' === $normalized_status && ( false !== strpos( strtoupper( (string) $track_id ), '-SIM-' ) ) && ( null === $doc_type || null === $folio ) ) {
                        // Buscar el último encolado reciente con meta en el mismo entorno
                        if ( is_object( $wpdb ) && method_exists( $wpdb, 'get_row' ) ) {
                                $sql = 'SELECT document_type, folio FROM ' . self::table() . ' WHERE status = "queued" AND document_type IS NOT NULL AND folio IS NOT NULL AND environment = %s AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 15 MINUTE) ORDER BY id DESC LIMIT 1';
                                if ( method_exists( $wpdb, 'prepare' ) ) { $sql = $wpdb->prepare( $sql, $env ); }
                                $row = $wpdb->get_row( $sql, ARRAY_A );
                                if ( is_array( $row ) ) {
                                        if ( null === $doc_type && isset( $row['document_type'] ) ) { $doc_type = (int) $row['document_type']; }
                                        if ( null === $folio && isset( $row['folio'] ) ) { $folio = (int) $row['folio']; }
                                }
                        } else {
                                for ( $i = count( self::$entries ) - 1; $i >= 0; --$i ) {
                                        $row = self::$entries[ $i ] ?? array();
                                        if ( (string) ( $row['environment'] ?? '0' ) !== (string) $env ) { continue; }
                                        if ( ( $row['status'] ?? '' ) !== 'queued' ) { continue; }
                                        if ( isset( $row['document_type'], $row['folio'] ) && null !== $row['document_type'] && null !== $row['folio'] ) {
                                                $doc_type = $doc_type ?? (int) $row['document_type'];
                                                $folio    = $folio    ?? (int) $row['folio'];
                                                break;
                                        }
                                }
                        }
                }

                if ( is_object( $wpdb ) && method_exists( $wpdb, 'insert' ) ) {
                        $inserted = $wpdb->insert(
                                self::table(),
                                array(
                                        'track_id'  => $normalized_track,
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
                                // En entorno de desarrollo, si acabamos de insertar un 'sent' con (tipo,folio)
                                // y existen filas 'sent' recientes sin meta (doc/folio) con track SIM distinto,
                                // unificarlas para mantener un único track por (tipo,folio).
                                if ( 'sent' === $normalized_status && (int) $doc_type > 0 && (int) $folio > 0 && '2' === (string) $env ) {
                                        if ( method_exists( $wpdb, 'prepare' ) && method_exists( $wpdb, 'query' ) ) {
                                                // Unifica cualquier 'sent' SIM para el mismo par (tipo,folio)
                                                $wpdb->query( $wpdb->prepare(
                                                        'UPDATE ' . self::table() . ' SET track_id = %s, document_type = %d, folio = %d WHERE status = "sent" AND environment = %s AND document_type = %d AND folio = %d AND track_id <> %s AND track_id LIKE %s AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 20 MINUTE)',
                                                        $normalized_track,
                                                        (int) $doc_type,
                                                        (int) $folio,
                                                        (string) $env,
                                                        (int) $doc_type,
                                                        (int) $folio,
                                                        $normalized_track,
                                                        'DTE-SIM-%'
                                                ) );
                                        }
                                }
                                return;
                        }
                }

                // Fallback for tests without database.
                self::$use_memory = true;
                self::$entries[] = array(
                        'track_id'  => $normalized_track,
                        'status'    => $status,
                        'response'  => $response,
                        'environment' => $env,
                        'document_type' => $doc_type,
                        'folio'     => $folio,
                        'created_at'=> $created,
                );
                // Fallback de unificación en memoria (solo para tests):
                if ( 'sent' === $normalized_status && (int) $doc_type > 0 && (int) $folio > 0 && '2' === (string) $env ) {
                        foreach ( self::$entries as &$e ) {
                                if ( 'sent' === ( $e['status'] ?? '' ) && isset( $e['track_id'] ) && is_string( $e['track_id'] ) && false !== strpos( $e['track_id'], 'DTE-SIM-' ) && ( (int) ( $e['document_type'] ?? 0 ) === (int) $doc_type ) && ( (int) ( $e['folio'] ?? 0 ) === (int) $folio ) && $e['track_id'] !== $normalized_track ) {
                                        $e['track_id'] = $normalized_track;
                                }
                        }
                        unset( $e );
                }
        }

        /**
         * Returns every alias associated with the requested environment.
         *
         * Earlier plugin versions persisted human readable labels (e.g. "dev")
         * instead of the canonical identifiers used today ("0", "1", "2").
         * Keeping track of the synonyms ensures that the control panel still
         * lists legacy log entries.
         *
         * @return array<int,string>
         */
        private static function environment_aliases( ?string $environment ): array {
                if ( null === $environment ) {
                        return array();
                }

                $normalized = Settings::normalize_environment( (string) $environment );

                switch ( $normalized ) {
                        case '1':
                                return array( '1', 'prod', 'production', 'produccion', 'producción' );
                        case '2':
                                return array( '2', 'dev', 'development', 'desarrollo' );
                        default:
                                return array( '0', 'test', 'certificacion', 'certification', 'certificación' );
                }
        }

        /**
         * Adds an environment filter (including aliases) to SQL clauses.
         *
         * @param array<int,string> $clauses Reference to WHERE clauses.
         * @param array<int,mixed>  $params  Reference to the prepared statement parameters.
         * @param string|null       $environment Target environment.
         */
        private static function add_environment_clause( array &$clauses, array &$params, ?string $environment ): void {
                if ( null === $environment ) {
                        return;
                }

                $aliases = self::environment_aliases( $environment );
                if ( empty( $aliases ) ) {
                        return;
                }

                $placeholders = implode( ',', array_fill( 0, count( $aliases ), '%s' ) );
                $clauses[]    = 'environment IN (' . $placeholders . ')';
                foreach ( $aliases as $alias ) {
                        $params[] = $alias;
                }
        }

        /**
         * Normalises legacy rows stored in the in-memory fallback.
         *
         * @param array<int,array<string,mixed>> $rows
         * @return array<int,array<string,mixed>>
         */
        private static function normalise_legacy_rows( array $rows ): array {
                return array_map(
                        static function ( array $row ): array {
                                if ( isset( $row['environment'] ) ) {
                                        $row['environment'] = Settings::normalize_environment( (string) $row['environment'] );
                                }
                                return $row;
                        },
                        $rows
                );
        }

        /**
         * Filters an array of rows to a specific environment, using aliases.
         *
         * @param array<int,array<string,mixed>> $rows
         * @param string|null                    $environment
         * @return array<int,array<string,mixed>>
         */
        private static function filter_rows_by_environment( array $rows, ?string $environment ): array {
                if ( null === $environment ) {
                        return $rows;
                }

                $target = Settings::normalize_environment( (string) $environment );

                return array_values(
                        array_filter(
                                $rows,
                                static function ( array $row ) use ( $target ): bool {
                                        $row_env = isset( $row['environment'] ) ? (string) $row['environment'] : '0';
                                        return Settings::normalize_environment( $row_env ) === $target;
                                }
                        )
                );
        }

        /**
         * Returns pending track IDs with status 'sent'.
         *
         * @return array<int,string>
         */
        public static function get_pending_track_ids( int $limit = 50, ?string $environment = null ): array {
                global $wpdb;
                if ( is_object( $wpdb ) && method_exists( $wpdb, 'get_col' ) && method_exists( $wpdb, 'prepare' ) ) {
                        $table   = self::table();
                        $clauses = array( 'status = %s' );
                        $params  = array( 'sent' );
                        self::add_environment_clause( $clauses, $params, $environment );
                        $where = implode( ' AND ', $clauses );
                        $params[] = $limit;
                        $sql      = $wpdb->prepare(
                                "SELECT track_id FROM {$table} WHERE {$where} ORDER BY id DESC LIMIT %d",
                                $params
                        );
                        $result = $wpdb->get_col( $sql );
                        return is_array( $result ) ? $result : array();
                }

                $ids     = array();
                $target  = null === $environment ? null : Settings::normalize_environment( (string) $environment );
                $entries = array_reverse( self::normalise_legacy_rows( self::$entries ) );
                foreach ( $entries as $entry ) {
                        if ( 'sent' !== ( $entry['status'] ?? '' ) ) {
                                continue;
                        }
                        if ( null !== $target ) {
                                $entry_env = Settings::normalize_environment( (string) ( $entry['environment'] ?? '0' ) );
                                if ( $entry_env !== $target ) {
                                        continue;
                                }
                        }
                        $ids[] = $entry['track_id'];
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
                $status      = $args['status'] ?? null;
                $limit       = isset( $args['limit'] ) ? (int) $args['limit'] : 100;
                $environment = $args['environment'] ?? null;

                global $wpdb;
                // Prefer reading from the WP database when available. Previously
                // this depended on self::$use_memory being set to false by a
                // prior successful insert in the same process which caused
                // legitimate DB rows to be ignored in cold requests. Query the
                // DB whenever $wpdb offers the needed methods and fall back to
                // the in-memory store only when DB access is not possible.
                if ( is_object( $wpdb ) && method_exists( $wpdb, 'get_results' ) && method_exists( $wpdb, 'prepare' ) ) {
                        $table = self::table();
                        $sql   = "SELECT track_id,status,response,environment,document_type,folio,created_at FROM {$table}";
                        $params = array();
                        $clauses = array();
                        if ( $status ) {
                                $clauses[] = 'status = %s';
                                $params[]  = $status;
                        }
                        self::add_environment_clause( $clauses, $params, $environment );
                        // Exclude invalid rows: 'sent' without track id
                        $clauses[] = "NOT (UPPER(status) = 'SENT' AND (track_id IS NULL OR track_id = ''))";
                        // Hide informational rows from display queries
                        $clauses[] = "UPPER(status) <> 'INFO'";
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
                // When filtering by status we must apply the filter against the
                // raw stored values (e.g. 'sent', 'accepted') before calling
                // enrich_row() which maps statuses to human readable labels.
                $raw_rows = self::normalise_legacy_rows( self::$entries );
                // Filter invalid 'sent' rows without track id in memory too
                $raw_rows = array_filter(
                        $raw_rows,
                        static function ( $row ) {
                                $st = isset( $row['status'] ) ? strtolower( (string) $row['status'] ) : '';
                                $tid = isset( $row['track_id'] ) ? (string) $row['track_id'] : '';
                                return ! ( 'sent' === $st && '' === trim( $tid ) );
                        }
                );

                // NOTE: keep informational rows (e.g. 'info') available to callers
                // because some integrations and tests expect 'info' entries to be
                // visible in the audit log. Do not strip them here.

                if ( $status ) {
                        $raw_rows = array_filter(
                                $raw_rows,
                                static fn( $row ) => ( isset( $row['status'] ) ? $row['status'] : '' ) === $status
                        );
                }

                $raw_rows = self::filter_rows_by_environment( $raw_rows, $environment );

                $rows = array_map( static fn( $row ) => self::enrich_row( $row ), array_values( $raw_rows ) );
                return array_slice( array_reverse( array_values( $rows ) ), 0, $limit );
        }

        /**
         * Returns all log rows for a specific track id ordered by created_at DESC.
         *
         * @param string $track_id
         * @return array<int,array{track_id:string,status:string,response:string,document_type:?int,folio:?int,created_at:string}>
         */
        public static function get_logs_for_track( string $track_id ): array {
                $track_id = trim( (string) $track_id );
                if ( '' === $track_id ) { return array(); }

                global $wpdb;
                if ( is_object( $wpdb ) && method_exists( $wpdb, 'get_results' ) && method_exists( $wpdb, 'prepare' ) ) {
                        $table = self::table();
                        $sql = "SELECT id,track_id,status,response,environment,document_type,folio,created_at FROM {$table} WHERE track_id = %s ORDER BY created_at DESC";
                        $rows = $wpdb->get_results( $wpdb->prepare( $sql, array( $track_id ) ), 'ARRAY_A' );
                        if ( ! is_array( $rows ) ) { return array(); }
                        return array_map( static fn( $row ) => self::enrich_row( (array) $row ), $rows );
                }

                // Fallback to in-memory store
                $rows = array_filter( self::normalise_legacy_rows( self::$entries ), static function ( $r ) use ( $track_id ) {
                        return isset( $r['track_id'] ) && trim( (string) $r['track_id'] ) === $track_id;
                } );
                // Order by created_at desc
                usort( $rows, static function ( $a, $b ) {
                        return strcmp( $b['created_at'] ?? '', $a['created_at'] ?? '' );
                } );
                return array_map( static fn( $r ) => self::enrich_row( $r ), array_values( $rows ) );
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
                $environment = $args['environment'] ?? null;
                $status      = isset( $args['status'] ) ? trim( (string) $args['status'] ) : '';
                if ( 'all' === strtolower( $status ) ) {
                        $status = '';
                }
                $type  = (isset($args['type']) && $args['type'] !== '' && $args['type'] !== 0 && $args['type'] !== '0') ? (int) $args['type'] : null;
                $from  = isset( $args['date_from'] ) ? trim( (string) $args['date_from'] ) : '';
                $to    = isset( $args['date_to'] ) ? trim( (string) $args['date_to'] ) : '';

                global $wpdb;
                // Prefer DB when available (see note in get_logs()).
                if ( is_object( $wpdb ) && method_exists( $wpdb, 'get_results' ) && method_exists( $wpdb, 'prepare' ) ) {
                        $table   = self::table();
                        $clauses = array();
                        $params  = array();

                        if ( $status ) {
                                $clauses[] = 'status = %s';
                                $params[]  = $status;
                        }
                        self::add_environment_clause( $clauses, $params, $environment );
                        if ( null !== $type ) {
                                $clauses[] = 'document_type = %d';
                                $params[]  = $type;
                        }
                        // Exclude invalid 'sent' rows without track id
                        $clauses[] = "NOT (UPPER(status) = 'SENT' AND (track_id IS NULL OR track_id = ''))";
                        // Hide informational rows from display queries
                        $clauses[] = "UPPER(status) <> 'INFO'";

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

                $raw_rows = self::normalise_legacy_rows( self::$entries );
                // Remove informational rows (legacy support)
                $raw_rows = array_filter(
                        $raw_rows,
                        static fn( $row ) => strtolower( (string) ( $row['status'] ?? '' ) ) !== 'info'
                );
                // Filter out invalid sent rows without a track id before enriching
                $raw_rows = array_filter(
                        $raw_rows,
                        static function ( $row ) {
                                $st  = isset( $row['status'] ) ? strtolower( (string) $row['status'] ) : '';
                                $tid = isset( $row['track_id'] ) ? trim( (string) $row['track_id'] ) : '';
                                return ! ( 'sent' === $st && '' === $tid );
                        }
                );
                $rows = array_map( static fn( $row ) => self::enrich_row( $row ), $raw_rows );
                // Guard against legacy enriched rows lacking track id.
                $rows = array_filter(
                        $rows,
                        static function ( $row ) {
                                $st  = isset( $row['status'] ) ? strtolower( (string) $row['status'] ) : '';
                                $tid = isset( $row['track_id'] ) ? trim( (string) $row['track_id'] ) : '';
                                return ! ( in_array( $st, array( 'sent', 'enviado (pendiente)' ), true ) && '' === $tid );
                        }
                );
                if ( $status ) {
                        $rows = array_filter(
                                $rows,
                                static fn( $row ) => $row['status'] === $status
                        );
                }
                $rows = self::filter_rows_by_environment( $rows, $environment );
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
                global $wpdb;
                // Prefer DB reads when possible; fall back to memory store only
                // when DB access is not available.
                if ( is_object( $wpdb ) && method_exists( $wpdb, 'get_col' ) && method_exists( $wpdb, 'prepare' ) ) {
                        $table = self::table();
                        $sql   = "SELECT DISTINCT document_type FROM {$table} WHERE document_type IS NOT NULL";
                        $params = array();
                        $clauses = array();
                        self::add_environment_clause( $clauses, $params, $environment );
                        if ( $clauses ) {
                                $sql .= ' AND ' . implode( ' AND ', $clauses );
                        }
                        $sql .= ' ORDER BY document_type ASC';
                        $types = $params ? $wpdb->get_col( $wpdb->prepare( $sql, $params ) ) : $wpdb->get_col( $sql );
                        return array_map( 'intval', array_filter( (array) $types ) );
                }

                $types = array();
                foreach ( self::normalise_legacy_rows( self::$entries ) as $row ) {
                        if ( null !== $environment ) {
                                $target = Settings::normalize_environment( (string) $environment );
                                $row_env = Settings::normalize_environment( (string) ( $row['environment'] ?? '0' ) );
                                if ( $row_env !== $target ) {
                                        continue;
                                }
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
                        // Map database status to panel status (español)
                        $map = [
                                'INFO'      => 'Enviado (pendiente)', // o 'En cola' si prefieres
                                'SENT'      => 'Enviado (pendiente)',
                                'QUEUED'    => 'En cola',
                                'ACCEPTED'  => 'Aceptado',
                                'REJECTED'  => 'Rechazado',
                                'ERROR'     => 'Error',
                                'FAILED'    => 'Fallido',
                                'DRAFT'     => 'Borrador',
                                'CANCELLED' => 'Cancelado',
                        ];
                        if (isset($row['status'])) {
                                $status = strtoupper(trim((string)$row['status']));
                                if (isset($map[$status])) {
                                        $row['status'] = $map[$status];
                                } else {
                                        $row['status'] = ucfirst(strtolower($status));
                                }
                        }
                if ( isset( $row['environment'] ) ) {
                        $row['environment'] = Settings::normalize_environment( (string) $row['environment'] );
                }
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
                                static function ( $entry ) {
                                        $ts = strtotime( $entry['created_at'] ?? 'now' );
                                        return $ts > strtotime( '-24 hours' );
                                }
                        );
                        // Contar por documento (track_id) para no inflar con múltiples filas
                        $distinct = array();
                        $sent_ids = array();
                        $error_ids = array();
                        foreach ( $recent as $e ) {
                                $tid = trim( (string) ( $e['track_id'] ?? '' ) );
                                if ( '' === $tid ) { continue; }
                                $distinct[ $tid ] = true;
                                $status = (string) ( $e['status'] ?? '' );
                                if ( 'sent' === $status ) { $sent_ids[ $tid ] = true; }
                                if ( 'error' === $status ) { $error_ids[ $tid ] = true; }
                        }
                        $total       = count( $distinct );
                        $errors_cnt  = count( $error_ids );
                        $sent_cnt    = count( $sent_ids );
                        $success_rate = $total > 0 ? ( $sent_cnt / $total ) * 100 : 100;
                        
                        return array(
                                'success_rate'       => round( $success_rate, 1 ),
                                'total_last_24h'     => $total,
                                'errors_last_24h'    => $errors_cnt,
                                'most_common_error'  => '',
                                'avg_queue_time'     => 0,
                        );
                }

                $table = self::table();
                
                // Métricas básicas últimas 24 horas (por documento, no por filas).
                // Se cuentan track_id distintos para evitar inflar el total
                // con múltiples estados (queued/sent/error) del mismo documento.
                $stats = $wpdb->get_row( "
                        SELECT 
                                COUNT(DISTINCT CASE WHEN track_id IS NOT NULL AND track_id <> '' THEN track_id END) as total,
                                COUNT(DISTINCT CASE WHEN status = 'error' AND track_id IS NOT NULL AND track_id <> '' THEN track_id END) as errors,
                                COUNT(DISTINCT CASE WHEN status = 'sent'  AND track_id IS NOT NULL AND track_id <> '' THEN track_id END) as sent,
                                COUNT(DISTINCT CASE WHEN status = 'queued' AND track_id IS NOT NULL AND track_id <> '' THEN track_id END) as queued
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
