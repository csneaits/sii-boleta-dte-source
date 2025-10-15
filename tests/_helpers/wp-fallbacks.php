<?php
// Minimal WordPress fallbacks used in unit tests. Keep these small and deterministic.

if ( ! function_exists( 'esc_html_x' ) ) {
    function esc_html_x( $text, $context, $domain = 'default' ) {
        unset( $context, $domain );
        return $text;
    }
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
    function sanitize_text_field( $str ) {
        // Minimal fallback for tests: normalize whitespace and trim.
        return is_string( $str ) ? trim( preg_replace( '/[\r\n\t]+/', ' ', $str ) ) : '';
    }
}

if ( ! function_exists( 'sanitize_textarea_field' ) ) {
    function sanitize_textarea_field( $str ) {
        // Minimal fallback for tests.
        return is_string( $str ) ? trim( $str ) : '';
    }
}

if ( ! function_exists( 'sanitize_email' ) ) {
    function sanitize_email( $str ) {
        // Minimal fallback for tests.
        $str = is_string( $str ) ? trim( $str ) : '';
        return filter_var( $str, FILTER_VALIDATE_EMAIL ) ? $str : '';
    }
}

if ( ! function_exists( 'sanitize_key' ) ) {
    function sanitize_key( $key ) {
        // Minimal fallback for tests.
        $key = is_string( $key ) ? strtolower( $key ) : '';
        return preg_replace( '/[^a-z0-9_\-]/', '', $key );
    }
}

if ( ! function_exists( 'checked' ) ) {
    function checked( $checked, $current = true, $echo = true ) {
        // Minimal fallback for tests: return/echo the checked attribute.
        $result = (string) $checked === (string) $current ? ' checked="checked"' : '';
        if ( $echo ) { echo $result; }
        return $result;
    }
}

if ( ! function_exists( 'remove_accents' ) ) {
    function remove_accents( $string ) {
        // Minimal fallback for tests: return input unchanged.
        return $string;
    }
}

if ( ! function_exists( 'wc_prices_include_tax' ) ) {
    function wc_prices_include_tax() {
        // Minimal fallback for tests.
        return false;
    }
}

if ( ! function_exists( 'esc_html_e' ) ) {
    function esc_html_e( $str ) {
        // Minimal fallback for tests: echo the string.
        if ( is_string( $str ) ) { echo $str; } else { echo ''; }
    }
}

if ( ! function_exists( 'esc_textarea' ) ) {
    function esc_textarea( $str ) {
        // Minimal fallback for tests.
        return is_string( $str ) ? htmlspecialchars( $str, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) : '';
    }
}

if ( ! function_exists( 'wp_nonce_field' ) ) {
    function wp_nonce_field( ...$args ) { // Minimal no-op fallback for tests.
        return;
    }
}
