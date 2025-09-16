<?php
use PHPUnit\Framework\TestCase;
use Sii\BoletaDte\Shared\SharedLogger;
use Sii\BoletaDte\Infrastructure\Persistence\LogDb;

if ( ! function_exists( 'wp_upload_dir' ) ) {
	function wp_upload_dir() {
		return [ 'basedir' => $GLOBALS['upload_dir'] ];
	}
}

if ( ! function_exists( 'wp_mkdir_p' ) ) {
	function wp_mkdir_p( string $dir ) {
		return mkdir( $dir, 0777, true );
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( string $path ) {
		return rtrim( $path, '/\\' ) . '/';
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $tag, $value ) {
		return $value;
	}
}

class LoggerTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['upload_dir'] = sys_get_temp_dir();
	}

        public function test_info_creates_log_file(): void {
                LogDb::purge();
                $logger = new SharedLogger();
                $logger->info( 'testing log' );
                $file = sys_get_temp_dir() . '/sii-boleta-logs/sii-boleta-' . date( 'Y-m-d' ) . '.log';
                $this->assertFileExists( $file );
                $logs = LogDb::get_logs();
                $this->assertSame( 'INFO', $logs[0]['status'] );
        }
}

