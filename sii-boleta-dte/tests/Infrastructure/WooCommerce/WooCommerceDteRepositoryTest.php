<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../src/Infrastructure/WooCommerce/WooCommerceDteRepository.php';

use PHPUnit\Framework\TestCase;
use SiiBoletaDte\Infrastructure\WooCommerce\WooCommerceDteRepository;

if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( $order_id, $key, $value ) {
		WooCommerceDteRepositoryTest::$last_call = func_get_args();
	}
}

final class WooCommerceDteRepositoryTest extends TestCase {
	public static array $last_call = [];

	protected function setUp(): void {
		self::$last_call = [];
	}

	public function test_save_uses_provided_order_id(): void {
		$repo = new WooCommerceDteRepository();
		$repo->save( [ 'id' => '33-2024' ], 42 );

		$this->assertSame( 42, self::$last_call[0] );
	}
}
