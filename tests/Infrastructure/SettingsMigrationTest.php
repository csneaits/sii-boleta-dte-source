<?php
use PHPUnit\Framework\TestCase;
use Sii\BoletaDte\Infrastructure\Persistence\SettingsMigration;
use Sii\BoletaDte\Infrastructure\Persistence\LogDb;
use Sii\BoletaDte\Infrastructure\Settings;
use Sii\BoletaDte\Infrastructure\Persistence\FoliosDb;

if ( ! isset( $GLOBALS['wp_options'] ) ) { $GLOBALS['wp_options'] = array(); }
if ( ! function_exists( 'get_option' ) ) { function get_option( $name, $default = false ) { return $GLOBALS['wp_options'][ $name ] ?? $default; } }
if ( ! function_exists( 'update_option' ) ) { function update_option( $name, $value ) { $GLOBALS['wp_options'][ $name ] = $value; } }
if ( ! function_exists( 'delete_option' ) ) { function delete_option( $name ) { unset( $GLOBALS['wp_options'][ $name ] ); } }
if ( ! function_exists( 'wp_upload_dir' ) ) { function wp_upload_dir() { return array( 'basedir' => sys_get_temp_dir() ); } }
if ( ! function_exists( 'trailingslashit' ) ) { function trailingslashit( $path ) { return rtrim( $path, '/' ) . '/'; } }

class SettingsMigrationTest extends TestCase {
    protected function setUp(): void {
        $GLOBALS['wp_options'] = array(
            Settings::OPTION_NAME => array(
                'cert_pass' => '1234',
                'cafs'      => array(
                    array(
                        'tipo'  => 39,
                        'desde' => 120,
                        'hasta' => 150,
                    ),
                ),
            ),
        );
        unset( $GLOBALS['wp_options']['sii_boleta_dte_migrated'] );
        // create fake log file
        $dir = trailingslashit( wp_upload_dir()['basedir'] ) . 'sii-boleta-logs';
        if ( ! is_dir( $dir ) ) { mkdir( $dir ); }
        file_put_contents( $dir . '/sii-boleta-2020-01-01.log', "[2020-01-01] INFO: ok\n" );
        LogDb::install();
        FoliosDb::purge();
    }

    /**
     * @runInSeparateProcess
     */
    public function test_migrate_options_and_logs(): void {
        SettingsMigration::migrate();
        $logs = LogDb::get_logs();
        $this->assertNotEmpty( $logs );
        $this->assertIsArray( FoliosDb::all() );
    }
}
