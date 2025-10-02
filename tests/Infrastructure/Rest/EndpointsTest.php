<?php
use PHPUnit\Framework\TestCase;
use Sii\BoletaDte\Infrastructure\Rest\Endpoints;

// Stub WordPress functions needed by endpoints.
if ( ! function_exists( 'add_action' ) ) {
    function add_action( $hook, $func ) {}
}
if ( ! function_exists( 'add_filter' ) ) {
    function add_filter( $hook, $func ) { return $func; }
}
if ( ! function_exists( 'add_rewrite_rule' ) ) {
    function add_rewrite_rule() {}
}
if ( ! function_exists( 'get_query_var' ) ) {
    function get_query_var( $var ) {
        return $GLOBALS['query_var'][ $var ] ?? null;
    }
}
if ( ! function_exists( 'wp_upload_dir' ) ) {
    function wp_upload_dir() {
        $base = $GLOBALS['upload_dir'] ?? sys_get_temp_dir();
        return [ 'basedir' => $base, 'baseurl' => 'http://example.com/uploads' ];
    }
}
if ( ! function_exists( 'status_header' ) ) {
    function status_header( $code ) { $GLOBALS['status_header_code'] = $code; }
}
if ( ! function_exists( 'wp_die' ) ) {
    function wp_die( $msg = '', $title = '', $args = array() ) {
        $code = 500;
        if ( is_array( $args ) && isset( $args['response'] ) ) {
            $code = (int) $args['response'];
        }
        if ( ! isset( $GLOBALS['status_header_code'] ) ) {
            $GLOBALS['status_header_code'] = $code;
        }
        throw new Exception( $msg ?: 'wp_die', $code );
    }
}
if ( ! function_exists( 'nocache_headers' ) ) {
    function nocache_headers() {}
}
if ( ! function_exists( 'esc_html__' ) ) {
    function esc_html__( $text ) { return $text; }
}
if ( ! function_exists( 'esc_html_e' ) ) {
    function esc_html_e( $text ) { return $text; }
}
if ( ! function_exists( 'esc_html' ) ) {
    function esc_html( $text ) { return $text; }
}
if ( ! function_exists( 'esc_url' ) ) {
    function esc_url( $url ) { return $url; }
}
if ( ! function_exists( 'is_wp_error' ) ) {
    function is_wp_error( $thing ) {
        return false;
    }
}
if ( ! function_exists( 'is_user_logged_in' ) ) {
    function is_user_logged_in() {
        return ! empty( $GLOBALS['is_user_logged_in'] );
    }
}
if ( ! function_exists( 'current_user_can' ) ) {
    function current_user_can( $cap ) {
        $caps = $GLOBALS['current_user_capabilities'] ?? array();
        return ! empty( $caps[ $cap ] );
    }
}
if ( ! function_exists( 'wp_mkdir_p' ) ) {
    function wp_mkdir_p( $dir ) {
        if ( ! is_dir( $dir ) ) {
            mkdir( $dir, 0777, true );
        }
    }
}
if ( ! function_exists( 'home_url' ) ) {
    function home_url( $path = '' ) {
        return 'http://example.com' . $path;
    }
}
if ( ! function_exists( 'add_query_arg' ) ) {
    function add_query_arg( ...$args ) {
        $count = count( $args );
        if ( 0 === $count ) {
            return '';
        }

        if ( is_array( $args[0] ) ) {
            $params = $args[0];
            $url    = (string) ( $args[1] ?? '' );
        } else {
            if ( $count < 3 ) {
                $params = array( $args[0] => $args[1] ?? '' );
                $url    = '';
            } else {
                $params = array( $args[0] => $args[1] ?? '' );
                $url    = (string) $args[2];
            }
        }

        $parts = explode( '#', $url, 2 );
        $base  = $parts[0];
        $frag  = $parts[1] ?? '';
        $sep   = str_contains( $base, '?' ) ? '&' : '?';
        $query = http_build_query( $params );

        $result = $base;
        if ( '' !== $query ) {
            $result .= $sep . $query;
        }

        if ( '' !== $frag ) {
            $result .= '#' . $frag;
        }

        return $result;
    }
}
if ( ! function_exists( 'set_transient' ) ) {
    function set_transient( $key, $value, $expiration ) {
        $GLOBALS['transients'][ $key ] = array(
            'value'   => $value,
            'expires' => time() + (int) $expiration,
        );
        return true;
    }
}
if ( ! function_exists( 'get_transient' ) ) {
    function get_transient( $key ) {
        if ( empty( $GLOBALS['transients'][ $key ] ) ) {
            return false;
        }
        if ( $GLOBALS['transients'][ $key ]['expires'] < time() ) {
            unset( $GLOBALS['transients'][ $key ] );
            return false;
        }
        return $GLOBALS['transients'][ $key ]['value'];
    }
}
if ( ! function_exists( 'delete_transient' ) ) {
    function delete_transient( $key ) {
        unset( $GLOBALS['transients'][ $key ] );
        return true;
    }
}
if ( ! function_exists( 'wp_rand' ) ) {
    function wp_rand() {
        return random_int( 0, PHP_INT_MAX );
    }
}

class TestableEndpoints extends Endpoints {
    protected function terminate_request(): void {
        throw new Exception( 'terminate', 200 );
    }
}

class EndpointsTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        $GLOBALS['query_var']                = array();
        $GLOBALS['status_header_code']       = null;
        $GLOBALS['is_user_logged_in']        = false;
        $GLOBALS['current_user_capabilities'] = array();
        $GLOBALS['transients']               = array();
    }

    public function test_get_boleta_html_migrates_file_to_secure_directory() {
        $temp = sys_get_temp_dir() . '/boleta-' . uniqid();
        mkdir( $temp );
        $GLOBALS['upload_dir'] = $temp;
        $xml                   = '<EnvioDTE><Documento><Encabezado><Emisor><RznSoc>Emit</RznSoc><RUTEmisor>111</RUTEmisor></Emisor><Receptor><RznSocRecep>Client</RznSocRecep><RUTRecep>222</RUTRecep></Receptor><IdDoc><FchEmis>2024-05-01</FchEmis></IdDoc><Totales><MntTotal>1000</MntTotal></Totales></Encabezado><Detalle><NmbItem>Item</NmbItem><QtyItem>1</QtyItem><PrcItem>1000</PrcItem><MontoItem>1000</MontoItem></Detalle></Documento></EnvioDTE>';
        file_put_contents( $temp . '/DTE_1_1_1.xml', $xml );

        $ep   = new Endpoints();
        $html = $ep->get_boleta_html( 1 );

        $this->assertIsString( $html );
        $this->assertStringContainsString( 'Emit', $html );
        $this->assertStringContainsString( 'Client', $html );
        $this->assertFileDoesNotExist( $temp . '/DTE_1_1_1.xml' );

        $secure = $temp . '/sii-boleta-dte-secure';
        $this->assertDirectoryExists( $secure );

        $this->removeDirectory( $temp );
    }

    public function test_render_boleta_denies_anonymous_request_with_403() {
        $temp = sys_get_temp_dir() . '/boleta-' . uniqid();
        mkdir( $temp );
        $GLOBALS['upload_dir'] = $temp;
        $GLOBALS['query_var']['sii_boleta_folio'] = 10;

        $ep = new Endpoints();
        try {
            $ep->render_boleta();
        } catch ( Exception $e ) {
            $this->assertEquals( 403, $GLOBALS['status_header_code'] );
            $this->assertStringContainsString( 'perfil autorizado', $e->getMessage() );
            $this->removeDirectory( $temp );
            return;
        }

        $this->removeDirectory( $temp );
        $this->fail( 'Se esperaba una denegación 403 para visitantes anónimos.' );
    }

    public function test_render_boleta_404_when_missing_for_authorized_user() {
        $temp = sys_get_temp_dir() . '/boleta-' . uniqid();
        mkdir( $temp );
        $GLOBALS['upload_dir']                    = $temp;
        $GLOBALS['query_var']['sii_boleta_folio'] = 999;
        $GLOBALS['is_user_logged_in']             = true;
        $GLOBALS['current_user_capabilities']     = array( 'manage_options' => true );

        $ep = new Endpoints();
        try {
            $ep->render_boleta();
        } catch ( Exception $e ) {
            $this->assertEquals( 404, $GLOBALS['status_header_code'] );
            $this->assertStringContainsString( 'Boleta no encontrada', $e->getMessage() );
            $this->removeDirectory( $temp );
            return;
        }

        $this->removeDirectory( $temp );
        $this->fail( 'Se esperaba excepción 404.' );
    }

    public function test_render_boleta_allows_privileged_user() {
        $temp       = sys_get_temp_dir() . '/boleta-' . uniqid();
        $secure_dir = $temp . '/sii-boleta-dte-secure';
        mkdir( $secure_dir, 0777, true );
        $GLOBALS['upload_dir']                    = $temp;
        $GLOBALS['query_var']['sii_boleta_folio'] = 1;
        $GLOBALS['is_user_logged_in']             = true;
        $GLOBALS['current_user_capabilities']     = array( 'manage_options' => true );

        $xml = '<EnvioDTE><Documento><Encabezado><Emisor><RznSoc>Emit</RznSoc><RUTEmisor>111</RUTEmisor></Emisor><Receptor><RznSocRecep>Client</RznSocRecep><RUTRecep>222</RUTRecep></Receptor><IdDoc><FchEmis>2024-05-01</FchEmis></IdDoc><Totales><MntTotal>1000</MntTotal></Totales></Encabezado><Detalle><NmbItem>Item</NmbItem><QtyItem>1</QtyItem><PrcItem>1000</PrcItem><MontoItem>1000</MontoItem></Detalle></Documento></EnvioDTE>';
        file_put_contents( $secure_dir . '/DTE_1_1_' . uniqid() . '.xml', $xml );

        $ep = new TestableEndpoints();

        ob_start();
        try {
            $ep->render_boleta();
        } catch ( Exception $e ) {
            $this->assertEquals( 'terminate', $e->getMessage() );
        }
        $output = ob_get_clean();

        $this->assertEquals( 200, $GLOBALS['status_header_code'] );
        $this->assertStringContainsString( 'Emit', $output );
        $this->assertStringContainsString( 'Client', $output );

        $this->removeDirectory( $temp );
    }

    private function removeDirectory( string $directory ): void {
        if ( ! is_dir( $directory ) ) {
            return;
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $directory, RecursiveDirectoryIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ( $items as $item ) {
            if ( $item->isDir() ) {
                rmdir( $item->getPathname() );
            } else {
                unlink( $item->getPathname() );
            }
        }

        rmdir( $directory );
    }
}
