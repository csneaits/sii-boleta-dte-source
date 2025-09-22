<?php
namespace Sii\BoletaDte\Infrastructure\Persistence;

/* phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared */

use Sii\BoletaDte\Infrastructure\Settings;

/**
 * Persists folio ranges configured from the admin UI.
 *
 * When a WordPress database is available the data is stored in the
 * `sii_boleta_dte_folios` table. During unit tests or environments without a
 * database connection an in-memory store is used so behaviour remains
 * deterministic.
 */
class FoliosDb {
    public const TABLE = 'sii_boleta_dte_folios';

    /**
     * @var array<int,array{ id:int,tipo:int,desde:int,hasta:int,environment:string,created_at:string,updated_at:string }>
     */
    private static array $rows = array();

    private static int $auto_inc = 1;

    private static ?bool $use_memory = null;

    private static function using_memory(): bool {
        global $wpdb;
        if ( null === self::$use_memory ) {
            self::$use_memory = ! ( is_object( $wpdb ) && method_exists( $wpdb, 'get_results' ) );
        }
        return self::$use_memory;
    }

    /** Returns the full table name with WP prefix. */
    private static function table(): string {
        global $wpdb;
        $prefix = is_object( $wpdb ) && property_exists( $wpdb, 'prefix' ) ? $wpdb->prefix : 'wp_';
        return $prefix . self::TABLE;
    }

    /** Creates the folios table or resets the in-memory store. */
    public static function install(): void {
        global $wpdb;
        if ( ! is_object( $wpdb ) ) {
            self::$rows       = array();
            self::$auto_inc   = 1;
            self::$use_memory = true;
            return;
        }

        self::$use_memory = null;

        $table           = self::table();
        $charset_collate = method_exists( $wpdb, 'get_charset_collate' ) ? $wpdb->get_charset_collate() : '';
        $sql             = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            tipo smallint unsigned NOT NULL,
            folio_inicio bigint(20) unsigned NOT NULL,
            folio_fin bigint(20) unsigned NOT NULL,
            environment varchar(20) NOT NULL DEFAULT '0',
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY tipo (tipo, folio_inicio),
            KEY env_tipo (environment, tipo, folio_inicio)
        ) {$charset_collate};";

        if ( function_exists( 'dbDelta' ) ) {
            dbDelta( $sql );
        } elseif ( method_exists( $wpdb, 'query' ) ) {
            $wpdb->query( $sql );
        }
    }

    /**
     * Inserts a new folio range.
     */
    public static function insert( int $tipo, int $desde, int $hasta, string $environment = '0' ): int {
        $env = Settings::normalize_environment( $environment );
        global $wpdb;
        $now = function_exists( 'current_time' ) ? current_time( 'mysql', true ) : gmdate( 'Y-m-d H:i:s' );
        if ( is_object( $wpdb ) && method_exists( $wpdb, 'insert' ) ) {
            $table = self::table();
            $data  = array(
                'tipo'         => $tipo,
                'folio_inicio' => $desde,
                'folio_fin'    => $hasta,
                'environment'  => $env,
                'created_at'   => $now,
                'updated_at'   => $now,
            );
            $result = $wpdb->insert( $table, $data );
            if ( false === $result && self::maybe_recreate_table() ) {
                $result = $wpdb->insert( $table, $data );
            }
            $insert_id = property_exists( $wpdb, 'insert_id' ) ? (int) $wpdb->insert_id : 0;
            if ( is_int( $result ) && $result > 0 && $insert_id > 0 ) {
                self::$use_memory = false;
                return $insert_id;
            }
        }

        self::$use_memory        = true;
        $id                      = self::$auto_inc++;
        self::$rows[ $id ] = array(
            'id'         => $id,
            'tipo'       => $tipo,
            'desde'      => $desde,
            'hasta'      => $hasta,
            'environment'=> $env,
            'created_at' => $now,
            'updated_at' => $now,
        );
        return $id;
    }

    /**
     * Attempts to recreate the folios table when the last database operation failed
     * because the table is missing (common when the plugin was updated without
     * reactivating it).
     */
    private static function maybe_recreate_table(): bool {
        global $wpdb;
        if ( ! is_object( $wpdb ) ) {
            return false;
        }

        $error = property_exists( $wpdb, 'last_error' ) ? (string) $wpdb->last_error : '';
        if ( '' === $error ) {
            return false;
        }

        $table      = strtolower( str_replace( '`', '', self::table() ) );
        $error_lc   = strtolower( $error );
        $missing_db = strpos( $error_lc, 'doesn\'t exist' ) !== false || strpos( $error_lc, 'no such table' ) !== false;

        if ( $missing_db && strpos( $error_lc, $table ) !== false ) {
            self::install();
            if ( property_exists( $wpdb, 'last_error' ) ) {
                $wpdb->last_error = '';
            }
            return true;
        }

        return false;
    }

    /**
     * Updates an existing folio range.
     */
    public static function update( int $id, int $tipo, int $desde, int $hasta, string $environment = '0' ): bool {
        $env = Settings::normalize_environment( $environment );
        global $wpdb;
        $now = function_exists( 'current_time' ) ? current_time( 'mysql', true ) : gmdate( 'Y-m-d H:i:s' );
        if ( is_object( $wpdb ) && method_exists( $wpdb, 'update' ) ) {
            $table = self::table();
            $data  = array(
                'tipo'         => $tipo,
                'folio_inicio' => $desde,
                'folio_fin'    => $hasta,
                'environment'  => $env,
                'updated_at'   => $now,
            );
            $result = $wpdb->update( $table, $data, array( 'id' => $id ) );
            if ( false === $result && self::maybe_recreate_table() ) {
                $result = $wpdb->update( $table, $data, array( 'id' => $id ) );
            }
            if ( false !== $result ) {
                self::$use_memory = false;
                return true;
            }
        }

        if ( ! isset( self::$rows[ $id ] ) ) {
            return false;
        }

        self::$use_memory              = true;
        self::$rows[ $id ]['tipo']       = $tipo;
        self::$rows[ $id ]['desde']      = $desde;
        self::$rows[ $id ]['hasta']      = $hasta;
        self::$rows[ $id ]['environment']= $env;
        self::$rows[ $id ]['updated_at'] = $now;
        return true;
    }

    /** Deletes a folio range. */
    public static function delete( int $id ): bool {
        global $wpdb;
        if ( is_object( $wpdb ) && method_exists( $wpdb, 'delete' ) ) {
            $table  = self::table();
            $result = $wpdb->delete( $table, array( 'id' => $id ) );
            if ( false === $result && self::maybe_recreate_table() ) {
                $result = $wpdb->delete( $table, array( 'id' => $id ) );
            }
            if ( false !== $result ) {
                self::$use_memory = false;
                return true;
            }
        }

        if ( ! isset( self::$rows[ $id ] ) ) {
            return false;
        }

        self::$use_memory = true;
        unset( self::$rows[ $id ] );
        return true;
    }

    /**
     * Retrieves a folio range by id.
     *
     * @return array{id:int,tipo:int,desde:int,hasta:int,created_at:string,updated_at:string}|null
     */
    public static function get( int $id ): ?array {
        global $wpdb;
        if ( ! self::using_memory() && is_object( $wpdb ) && method_exists( $wpdb, 'get_row' ) ) {
            $row = $wpdb->get_row( $wpdb->prepare( 'SELECT id,tipo,folio_inicio,folio_fin,environment,created_at,updated_at FROM ' . self::table() . ' WHERE id = %d', $id ), 'ARRAY_A' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            if ( is_array( $row ) ) {
                return array(
                    'id'         => (int) $row['id'],
                    'tipo'       => (int) $row['tipo'],
                    'desde'      => (int) $row['folio_inicio'],
                    'hasta'      => (int) $row['folio_fin'],
                    'environment'=> Settings::normalize_environment( (string) $row['environment'] ),
                    'created_at' => (string) $row['created_at'],
                    'updated_at' => (string) $row['updated_at'],
                );
            }
            return null;
        }

        return self::$rows[ $id ] ?? null;
    }

    /**
     * Returns all folio ranges.
     *
     * @return array<int,array{id:int,tipo:int,desde:int,hasta:int,created_at:string,updated_at:string}>
     */
    public static function all( string $environment = '0' ): array {
        $env = Settings::normalize_environment( $environment );
        global $wpdb;
        if ( ! self::using_memory() && is_object( $wpdb ) && method_exists( $wpdb, 'get_results' ) ) {
            $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id,tipo,folio_inicio,folio_fin,environment,created_at,updated_at FROM ' . self::table() . ' WHERE environment = %s ORDER BY tipo ASC, folio_inicio ASC', $env ), 'ARRAY_A' );
            if ( ! is_array( $rows ) ) {
                return array();
            }
            $out = array();
            foreach ( $rows as $row ) {
                $out[] = array(
                    'id'         => (int) $row['id'],
                    'tipo'       => (int) $row['tipo'],
                    'desde'      => (int) $row['folio_inicio'],
                    'hasta'      => (int) $row['folio_fin'],
                    'environment'=> Settings::normalize_environment( (string) $row['environment'] ),
                    'created_at' => (string) $row['created_at'],
                    'updated_at' => (string) $row['updated_at'],
                );
            }
            return $out;
        }

        $out = array();
        foreach ( self::$rows as $row ) {
            if ( Settings::normalize_environment( (string) $row['environment'] ) === $env ) {
                $out[] = $row;
            }
        }
        return array_values( $out );
    }

    /**
     * Returns all ranges for a specific type ordered by start folio.
     *
     * @return array<int,array{id:int,tipo:int,desde:int,hasta:int,created_at:string,updated_at:string}>
     */
    public static function for_type( int $tipo, string $environment = '0' ): array {
        $all = self::all( $environment );
        $out = array();
        foreach ( $all as $row ) {
            if ( (int) $row['tipo'] === $tipo ) {
                $out[] = $row;
            }
        }
        usort(
            $out,
            function ( $a, $b ) {
                if ( $a['desde'] === $b['desde'] ) {
                    return $a['hasta'] <=> $b['hasta'];
                }
                return $a['desde'] <=> $b['desde'];
            }
        );
        return $out;
    }

    /**
     * Checks whether a range overlaps another one for the same type.
     */
    public static function overlaps( int $tipo, int $desde, int $hasta, int $exclude_id = 0, string $environment = '0' ): bool {
        foreach ( self::for_type( $tipo, $environment ) as $row ) {
            if ( $exclude_id && (int) $row['id'] === $exclude_id ) {
                continue;
            }
            $max_inicio = max( $row['desde'], $desde );
            $min_fin    = min( $row['hasta'], $hasta );
            if ( $max_inicio <= $min_fin ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Finds the range containing a specific folio.
     *
     * @return array{id:int,tipo:int,desde:int,hasta:int,created_at:string,updated_at:string}|null
     */
    public static function find_for_folio( int $tipo, int $folio, string $environment = '0' ): ?array {
        foreach ( self::for_type( $tipo, $environment ) as $row ) {
            if ( $folio >= $row['desde'] && $folio <= $row['hasta'] ) {
                return $row;
            }
        }
        return null;
    }

    /** Indicates whether a document type has at least one range configured. */
    public static function has_type( int $tipo, string $environment = '0' ): bool {
        foreach ( self::for_type( $tipo, $environment ) as $row ) {
            if ( $row['hasta'] >= $row['desde'] ) {
                return true;
            }
        }
        return false;
    }

    /** Clears the in-memory store (used in tests). */
    public static function purge(): void {
        self::$rows       = array();
        self::$auto_inc   = 1;
        self::$use_memory = true;
    }
}

/* phpcs:enable */
