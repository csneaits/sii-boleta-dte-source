<?php
namespace Sii\BoletaDte\Infrastructure\Rest;

/**
 * Provides signed URLs to securely share DTE documents.
 */
class SignedUrlService {
        private const TRANSIENT_PREFIX = 'sii_boleta_dte_signed_';

        /**
         * @var callable|null
         */
        private $time_provider;

        public function __construct( ?callable $time_provider = null ) {
                $this->time_provider = $time_provider;
        }

        public function generate_signed_url( int $folio, int $ttl = 3600 ): string {
                $token     = $this->create_token( $folio, $ttl );
                $permalink = home_url( '/boleta/' . $folio . '/' );
                return add_query_arg( 'sii_boleta_token', $token, $permalink );
        }

        public function validate_token( string $token, int $folio ): bool {
                $stored = get_transient( self::TRANSIENT_PREFIX . $token );
                if ( ! is_array( $stored ) ) {
                        return false;
                }

                if ( (int) $stored['folio'] !== $folio ) {
                        return false;
                }

                if ( isset( $stored['expires'] ) && $stored['expires'] < $this->now() ) {
                        delete_transient( self::TRANSIENT_PREFIX . $token );
                        return false;
                }

                delete_transient( self::TRANSIENT_PREFIX . $token );
                return true;
        }

        private function create_token( int $folio, int $ttl ): string {
                $token   = $this->random_token();
                $expires = $this->now() + max( 60, $ttl );
                set_transient(
                        self::TRANSIENT_PREFIX . $token,
                        array(
                                'folio'   => $folio,
                                'expires' => $expires,
                        ),
                        $ttl
                );

                return $token;
        }

        private function random_token(): string {
                $token = bin2hex( random_bytes( 16 ) );
                if ( '' === $token ) {
                        $seed = microtime( true );
                        if ( function_exists( 'wp_rand' ) ) {
                                $seed .= wp_rand();
                        } else {
                                $seed .= random_int( 0, PHP_INT_MAX );
                        }
                        $token = md5( (string) $seed );
                }

                return $token;
        }

        private function now(): int {
                if ( null !== $this->time_provider ) {
                        return (int) call_user_func( $this->time_provider );
                }

                return time();
        }
}
