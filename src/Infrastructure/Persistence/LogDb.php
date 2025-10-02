<?php
namespace Sii\BoletaDte\Infrastructure\Persistence;

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

        /** @var array<int, array{track_id:string,status:string,response:string,created_at:string}> */
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
created_at datetime NOT NULL,
PRIMARY KEY  (id),
KEY track_id (track_id),
KEY status (status)
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
        public static function add_entry( string $track_id, string $status, string $response ): void {
                global $wpdb;
                $created = function_exists( 'current_time' ) ? current_time( 'mysql', true ) : gmdate( 'Y-m-d H:i:s' );

                if ( is_object( $wpdb ) && method_exists( $wpdb, 'insert' ) ) {
                        $inserted = $wpdb->insert(
                                self::table(),
                                array(
                                        'track_id'  => $track_id,
                                        'status'    => $status,
                                        'response'  => $response,
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
                        'created_at'=> $created,
                );
        }

        /**
         * Returns pending track IDs with status 'sent'.
         *
         * @return array<int,string>
         */
        public static function get_pending_track_ids( int $limit = 50 ): array {
                global $wpdb;
                if ( is_object( $wpdb ) && method_exists( $wpdb, 'get_col' ) && method_exists( $wpdb, 'prepare' ) ) {
                        $table  = self::table();
                        $sql    = $wpdb->prepare( "SELECT track_id FROM {$table} WHERE status = %s ORDER BY id DESC LIMIT %d", 'sent', $limit );
                        $result = $wpdb->get_col( $sql );
                        return is_array( $result ) ? $result : array();
                }

                $ids = array();
                foreach ( array_reverse( self::$entries ) as $entry ) {
                        if ( 'sent' === $entry['status'] ) {
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

                global $wpdb;
                if ( ! self::$use_memory && is_object( $wpdb ) && method_exists( $wpdb, 'get_results' ) && method_exists( $wpdb, 'prepare' ) ) {
                        $table = self::table();
                        $sql   = "SELECT track_id,status,response,created_at FROM {$table}";
                        $params = array();
                        if ( $status ) {
                                $sql    .= ' WHERE status = %s';
                                $params[] = $status;
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
                return array_slice( array_reverse( $rows ), 0, $limit );
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
