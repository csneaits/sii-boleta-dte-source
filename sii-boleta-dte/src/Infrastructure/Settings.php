<?php
namespace Sii\BoletaDte\Infrastructure;

/**
 * Provides access to plugin settings.
 */
class Settings {
        public const OPTION_GROUP = 'sii_boleta_dte_settings_group';
        public const OPTION_NAME  = 'sii_boleta_dte_settings';
        public const ENV_TEST     = 'test';
        public const ENV_PROD     = 'production';

		/**
		 * Returns settings from WordPress options.
		 *
		 * @return array<string,mixed>
		 */
        public function get_settings(): array {
                if ( function_exists( 'get_option' ) ) {
                                $data = get_option( self::OPTION_NAME, array() );
                        if ( is_array( $data ) ) {
                                if ( isset( $data['cert_pass'] ) ) {
                                                $data['cert_pass'] = self::decrypt( (string) $data['cert_pass'] );
                                }

                                $environment = self::normalize_environment_slug( $data['environment'] ?? '' );
                                $all_cafs    = array();
                                if ( isset( $data['cafs'] ) && is_array( $data['cafs'] ) ) {
                                        $all_cafs = $data['cafs'];
                                }

                                $filtered   = array();
                                $hidden     = 0;
                                $caf_path   = array();
                                foreach ( $all_cafs as $caf ) {
                                        if ( ! is_array( $caf ) ) {
                                                continue;
                                        }
                                        $caf_env = self::normalize_environment_slug( $caf['environment'] ?? '' );
                                        if ( '' === (string) ( $caf['environment'] ?? '' ) ) {
                                                $caf_env = self::ENV_TEST;
                                        }
                                        if ( $caf_env !== $environment ) {
                                                ++$hidden;
                                                continue;
                                        }
                                        $caf['environment'] = $caf_env;
                                        $filtered[]          = $caf;
                                        if ( isset( $caf['tipo'], $caf['path'] ) ) {
                                                $caf_path[ (int) $caf['tipo'] ] = (string) $caf['path'];
                                        }
                                }

                                $data['cafs']          = $filtered;
                                $data['caf_path']      = $caf_path;
                                $data['cafs_hidden']   = $hidden;
                                $data['environment_slug'] = $environment;
                                return $data;
                        }
                }
                        return array();
        }

        /**
         * Returns raw settings as stored in WordPress without filtering.
         *
         * @return array<string,mixed>
         */
        public function get_raw_settings(): array {
                if ( function_exists( 'get_option' ) ) {
                                $data = get_option( self::OPTION_NAME, array() );
                        return is_array( $data ) ? $data : array();
                }
                return array();
        }

        /**
         * Normalizes an environment value to either test or production.
         */
        public static function normalize_environment_slug( $value ): string {
                $raw = is_string( $value ) ? $value : (string) $value;
                $raw = strtolower( trim( $raw ) );
                if ( in_array( $raw, array( '1', 'prod', 'production', 'prod.', 'live' ), true ) ) {
                        return self::ENV_PROD;
                }
                return self::ENV_TEST;
        }

		/**
		 * Encrypts a value using a key derived from WordPress salts.
		 */
	public static function encrypt( string $plaintext ): string {
		if ( function_exists( 'wp_salt' ) ) {
				$key = hash( 'sha256', wp_salt(), true );
		} else {
				$key = hash( 'sha256', 'sii-boleta-dte', true );
		}

		if ( function_exists( 'sodium_crypto_secretbox' ) ) {
				$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
				$cipher = sodium_crypto_secretbox( $plaintext, $nonce, $key );
				return base64_encode( $nonce . $cipher );
		}

		$iv     = random_bytes( 16 );
		$cipher = openssl_encrypt( $plaintext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
		$cipher = $cipher ? $cipher : '';
		return base64_encode( $iv . $cipher );
	}

		/**
		 * Decrypts a value previously encrypted with {@see encrypt()}.
		 */
	public static function decrypt( string $encoded ): string {
		if ( '' === $encoded ) {
				return '';
		}

		if ( function_exists( 'wp_salt' ) ) {
				$key = hash( 'sha256', wp_salt(), true );
		} else {
				$key = hash( 'sha256', 'sii-boleta-dte', true );
		}

			$decoded = base64_decode( $encoded, true );
		if ( false === $decoded ) {
				return '';
		}

		if ( function_exists( 'sodium_crypto_secretbox_open' ) ) {
				$nonce_size = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
			if ( strlen( $decoded ) < $nonce_size ) {
					return '';
			}
				$nonce  = substr( $decoded, 0, $nonce_size );
				$cipher = substr( $decoded, $nonce_size );
				$plain  = sodium_crypto_secretbox_open( $cipher, $nonce, $key );
				return false === $plain ? '' : $plain;
		}

			$iv    = substr( $decoded, 0, 16 );
			$enc   = substr( $decoded, 16 );
			$plain = openssl_decrypt( $enc, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
			return is_string( $plain ) ? $plain : '';
	}
}

class_alias( Settings::class, 'SII_Boleta_Settings' );
