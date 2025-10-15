<?php
namespace Sii\BoletaDte\Infrastructure\WooCommerce;

/**
 * Migrates legacy public PDF metadata into the secure storage mechanism.
 */
class PdfStorageMigrator {
    private const OPTION_FLAG = 'sii_boleta_dte_secure_pdf_migrated';

    public static function migrate(): void {
        if ( self::is_already_migrated() ) {
            return;
        }

        foreach ( self::collect_order_ids() as $order_id ) {
            self::migrate_order( $order_id );
        }

        self::set_option( self::OPTION_FLAG, 1 );
    }

    private static function is_already_migrated(): bool {
        $value = self::get_option( self::OPTION_FLAG, 0 );

        return (int) $value === 1;
    }

    /**
     * @return array<int>
     */
    private static function collect_order_ids(): array {
        $ids = array();

        if ( isset( $GLOBALS['meta'] ) && is_array( $GLOBALS['meta'] ) ) {
            foreach ( $GLOBALS['meta'] as $order_id => $entries ) {
                if ( ! is_array( $entries ) ) {
                    continue;
                }
                foreach ( array_keys( $entries ) as $key ) {
                    if ( self::is_legacy_meta_key( (string) $key ) ) {
                        $ids[] = (int) $order_id;
                        break;
                    }
                }
            }
        }

        if ( function_exists( 'get_option' ) ) {
            global $wpdb;
            if ( isset( $wpdb ) && $wpdb instanceof \wpdb ) {
                $keys         = self::legacy_meta_keys();
                $placeholders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
                $query        = "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key IN ($placeholders)";
                $prepared     = $wpdb->prepare( $query, $keys );
                if ( $prepared ) {
                    $results = $wpdb->get_col( $prepared );
                    if ( is_array( $results ) ) {
                        foreach ( $results as $post_id ) {
                            $ids[] = (int) $post_id;
                        }
                    }
                }
            }
        }

        $ids = array_filter( array_map( 'intval', $ids ) );

        return array_values( array_unique( $ids ) );
    }

    private static function migrate_order( int $order_id ): void {
        foreach ( self::meta_prefixes() as $prefix ) {
            $path_meta  = $prefix . '_pdf_path';
            $url_meta   = $prefix . '_pdf_url';
            $value_meta = $prefix . '_pdf';

            $path = self::get_meta( $order_id, $path_meta );
            $key  = self::get_meta( $order_id, $prefix . '_pdf_key' );
            $nonce = self::get_meta( $order_id, $prefix . '_pdf_nonce' );

            if ( '' === $key || '' === $nonce ) {
                if ( '' !== $path && file_exists( $path ) ) {
                    $stored = PdfStorage::migrate_existing( $path );
                    if ( is_array( $stored ) && ! empty( $stored['key'] ) && ! empty( $stored['nonce'] ) ) {
                        $key   = $stored['key'];
                        $nonce = $stored['nonce'];
                        self::update_meta( $order_id, $prefix . '_pdf_key', $key );
                        self::update_meta( $order_id, $prefix . '_pdf_nonce', $nonce );
                    }
                }
            } elseif ( '' !== $path && file_exists( $path ) ) {
                $stored = PdfStorage::migrate_existing( $path );
                if ( is_array( $stored ) && ! empty( $stored['key'] ) && ! empty( $stored['nonce'] ) ) {
                    $key   = $stored['key'];
                    $nonce = $stored['nonce'];
                    self::update_meta( $order_id, $prefix . '_pdf_key', $key );
                    self::update_meta( $order_id, $prefix . '_pdf_nonce', $nonce );
                }
            }

            self::delete_meta( $order_id, $path_meta );
            self::delete_meta( $order_id, $url_meta );
            self::delete_meta( $order_id, $value_meta );
        }
    }

    private static function legacy_meta_keys(): array {
        $keys = array();
        foreach ( self::meta_prefixes() as $prefix ) {
            foreach ( array( '_pdf', '_pdf_url', '_pdf_path' ) as $suffix ) {
                $keys[] = $prefix . $suffix;
            }
        }

        return $keys;
    }

    private static function is_legacy_meta_key( string $key ): bool {
        return in_array( $key, self::legacy_meta_keys(), true );
    }

    private static function meta_prefixes(): array {
        return array( '_sii_boleta', '_sii_boleta_credit_note', '_sii_boleta_debit_note' );
    }

    private static function get_meta( int $order_id, string $meta_key ): string {
        if ( $order_id <= 0 || '' === $meta_key ) {
            return '';
        }

        if ( function_exists( 'get_post_meta' ) ) {
            $value = get_post_meta( $order_id, $meta_key, true );
            if ( is_scalar( $value ) ) {
                return (string) $value;
            }
        }

        if ( isset( $GLOBALS['meta'][ $order_id ][ $meta_key ] ) ) {
            return (string) $GLOBALS['meta'][ $order_id ][ $meta_key ];
        }

        return '';
    }

    private static function update_meta( int $order_id, string $meta_key, string $value ): void {
        if ( $order_id <= 0 || '' === $meta_key ) {
            return;
        }

        if ( function_exists( 'update_post_meta' ) ) {
            update_post_meta( $order_id, $meta_key, $value );
        }

        if ( ! isset( $GLOBALS['meta'][ $order_id ] ) || ! is_array( $GLOBALS['meta'][ $order_id ] ) ) {
            $GLOBALS['meta'][ $order_id ] = array();
        }

        $GLOBALS['meta'][ $order_id ][ $meta_key ] = $value;
    }

    private static function delete_meta( int $order_id, string $meta_key ): void {
        if ( $order_id <= 0 || '' === $meta_key ) {
            return;
        }

        if ( function_exists( 'delete_post_meta' ) ) {
            delete_post_meta( $order_id, $meta_key );
        }

        if ( isset( $GLOBALS['meta'][ $order_id ][ $meta_key ] ) ) {
            unset( $GLOBALS['meta'][ $order_id ][ $meta_key ] );
        }
    }

    private static function get_option( string $name, $default = 0 ) {
        if ( function_exists( 'get_option' ) ) {
            $value = get_option( $name, null );
            if ( null !== $value ) {
                return $value;
            }
        }

        if ( isset( $GLOBALS['wp_options'][ $name ] ) ) {
            return $GLOBALS['wp_options'][ $name ];
        }

        return $default;
    }

    private static function set_option( string $name, $value ): void {
        if ( function_exists( 'update_option' ) ) {
            update_option( $name, $value );
        }

        if ( ! isset( $GLOBALS['wp_options'] ) || ! is_array( $GLOBALS['wp_options'] ) ) {
            $GLOBALS['wp_options'] = array();
        }

        $GLOBALS['wp_options'][ $name ] = $value;
    }
}
