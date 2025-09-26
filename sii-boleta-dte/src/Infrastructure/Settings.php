<?php
namespace Sii\BoletaDte\Infrastructure;

/**
 * Provides access to plugin settings.
 */
class Settings {
        public const OPTION_GROUP = 'sii_boleta_dte_settings_group';
        public const OPTION_NAME  = 'sii_boleta_dte_settings';

        /**
         * Normalises the environment value to a canonical identifier.
         */
        public static function normalize_environment( string $environment ): string {
                $env = strtolower( trim( $environment ) );
                if ( in_array( $env, array( '1', 'prod', 'production' ), true ) ) {
                        return '1';
                }
                if ( in_array( $env, array( '0', 'test', 'certificacion', 'certification' ), true ) ) {
                        return '0';
                }
                return '0';
        }

        /**
         * Returns the environment configured in the plugin settings.
         */
        public function get_environment(): string {
                $settings = $this->get_settings();
                $env      = isset( $settings['environment'] ) ? (string) $settings['environment'] : '0';
                return self::normalize_environment( $env );
        }

        /**
         * Determines if WooCommerce orders should only generate preview PDFs while testing.
         */
        public function is_woocommerce_preview_only_enabled(): bool {
                $settings = $this->get_settings();
                if ( empty( $settings['woocommerce_preview_only'] ) ) {
                        return false;
                }

                return '0' === $this->get_environment();
        }

        /**
         * Builds the option key used to persist the last used folio for a type.
         */
        public static function last_folio_option_key( int $type, string $environment ): string {
                $env = self::normalize_environment( $environment );
                return 'sii_boleta_dte_last_folio_' . $env . '_' . $type;
        }

        /**
         * Retrieves the last used folio for a type/environment, migrating legacy keys if needed.
         */
        public static function get_last_folio_value( int $type, string $environment ): int {
                $key        = self::last_folio_option_key( $type, $environment );
                $legacy_key = 'sii_boleta_dte_last_folio_' . $type;
                $sentinel   = new \stdClass();

                if ( function_exists( 'get_option' ) ) {
                        $value = get_option( $key, $sentinel );
                        if ( $value !== $sentinel ) {
                                return (int) $value;
                        }

                        $legacy = get_option( $legacy_key, $sentinel );
                        if ( $legacy !== $sentinel ) {
                                $legacy_val = (int) $legacy;
                                if ( function_exists( 'update_option' ) ) {
                                        update_option( $key, $legacy_val );
                                }
                                return $legacy_val;
                        }
                }

                if ( isset( $GLOBALS['test_options'][ $key ] ) ) {
                        return (int) $GLOBALS['test_options'][ $key ];
                }
                if ( isset( $GLOBALS['test_options'][ $legacy_key ] ) ) {
                        $legacy_val = (int) $GLOBALS['test_options'][ $legacy_key ];
                        $GLOBALS['test_options'][ $key ] = $legacy_val;
                        return $legacy_val;
                }
                if ( isset( $GLOBALS['wp_options'][ $key ] ) ) {
                        return (int) $GLOBALS['wp_options'][ $key ];
                }
                if ( isset( $GLOBALS['wp_options'][ $legacy_key ] ) ) {
                        $legacy_val = (int) $GLOBALS['wp_options'][ $legacy_key ];
                        if ( ! isset( $GLOBALS['wp_options'] ) || ! is_array( $GLOBALS['wp_options'] ) ) {
                                $GLOBALS['wp_options'] = array();
                        }
                        $GLOBALS['wp_options'][ $key ] = $legacy_val;
                        return $legacy_val;
                }

                return 0;
        }

        /**
         * Persists the last used folio for a type/environment combination.
         */
        public static function update_last_folio_value( int $type, string $environment, int $value ): void {
                $key = self::last_folio_option_key( $type, $environment );
                if ( function_exists( 'update_option' ) ) {
                        update_option( $key, $value );
                } elseif ( isset( $GLOBALS['test_options'] ) ) {
                        $GLOBALS['test_options'][ $key ] = $value;
                } else {
                        if ( ! isset( $GLOBALS['wp_options'] ) || ! is_array( $GLOBALS['wp_options'] ) ) {
                                $GLOBALS['wp_options'] = array();
                        }
                        $GLOBALS['wp_options'][ $key ] = $value;
                }
        }

        /**
         * Atomically updates the last used folio only when the stored value matches the expected one.
         */
        public static function compare_and_update_last_folio_value( int $type, string $environment, int $expected, int $value ): bool {
                $key = self::last_folio_option_key( $type, $environment );

                if ( function_exists( 'update_option' ) ) {
                        global $wpdb;

                        if ( isset( $wpdb ) && method_exists( $wpdb, 'prepare' ) && isset( $wpdb->options ) ) {
                                $table = $wpdb->options;
                                $query = $wpdb->prepare(
                                        "UPDATE {$table} SET option_value = %s WHERE option_name = %s AND option_value = %s",
                                        $value,
                                        $key,
                                        $expected
                                );
                                $updated = $wpdb->query( $query );

                                if ( false === $updated ) {
                                        return false;
                                }

                                if ( $updated > 0 ) {
                                        return true;
                                }

                                $current = get_option( $key, null );
                                if ( null === $current && function_exists( 'add_option' ) ) {
                                        return add_option( $key, $value, '', false );
                                }

                                return (int) $current === $value;
                        }

                        $current = get_option( $key, null );
                        if ( null === $current ) {
                                if ( function_exists( 'add_option' ) ) {
                                        return add_option( $key, $value, '', false );
                                }

                                return update_option( $key, $value );
                        }

                        if ( (int) $current !== $expected ) {
                                return (int) $current === $value;
                        }

                        return update_option( $key, $value );
                }

                if ( isset( $GLOBALS['test_options'] ) ) {
                        $current = $GLOBALS['test_options'][ $key ] ?? null;
                        if ( null !== $current && (int) $current !== $expected ) {
                                return (int) $current === $value;
                        }

                        $GLOBALS['test_options'][ $key ] = $value;
                        return true;
                }

                if ( ! isset( $GLOBALS['wp_options'] ) || ! is_array( $GLOBALS['wp_options'] ) ) {
                        $GLOBALS['wp_options'] = array();
                }

                $current = $GLOBALS['wp_options'][ $key ] ?? null;
                if ( null !== $current && (int) $current !== $expected ) {
                        return (int) $current === $value;
                }

                $GLOBALS['wp_options'][ $key ] = $value;
                return true;
        }

        /**
         * Builds the option key that stores the last execution for a scheduled task.
         */
        public static function schedule_option_key( string $task, string $environment ): string {
                $env  = self::normalize_environment( $environment );
                $slug = strtolower( preg_replace( '/[^a-z0-9_]+/i', '_', $task ) ?? '' );
                $slug = '' === $slug ? 'task' : trim( $slug, '_' );
                return 'sii_boleta_dte_last_' . $slug . '_run_' . $env;
        }

        /**
         * Retrieves the last execution marker for a scheduled task.
         */
        public static function get_schedule_last_run( string $task, string $environment ): string {
                $key      = self::schedule_option_key( $task, $environment );
                $sentinel = new \stdClass();

                if ( function_exists( 'get_option' ) ) {
                        $value = get_option( $key, $sentinel );
                        if ( $value !== $sentinel ) {
                                return (string) $value;
                        }
                }

                if ( isset( $GLOBALS['test_options'][ $key ] ) ) {
                        return (string) $GLOBALS['test_options'][ $key ];
                }
                if ( isset( $GLOBALS['wp_options'][ $key ] ) ) {
                        return (string) $GLOBALS['wp_options'][ $key ];
                }

                return '';
        }

        /**
         * Stores the last execution marker for a scheduled task.
         */
        public static function update_schedule_last_run( string $task, string $environment, string $value ): void {
                $key = self::schedule_option_key( $task, $environment );
                if ( function_exists( 'update_option' ) ) {
                        update_option( $key, $value );
                        return;
                }

                if ( isset( $GLOBALS['test_options'] ) && is_array( $GLOBALS['test_options'] ) ) {
                        $GLOBALS['test_options'][ $key ] = $value;
                        return;
                }

                if ( ! isset( $GLOBALS['wp_options'] ) || ! is_array( $GLOBALS['wp_options'] ) ) {
                        $GLOBALS['wp_options'] = array();
                }
                $GLOBALS['wp_options'][ $key ] = $value;
        }

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
                                unset( $data['cafs'], $data['caf_path'] );
                                return $data;
                        }
		}
			return array();
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
