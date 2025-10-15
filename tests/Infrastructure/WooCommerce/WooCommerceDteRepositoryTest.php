<?php
use PHPUnit\Framework\TestCase;
use Sii\BoletaDte\Infrastructure\WooCommerce\WooCommerceDteRepository;
use Sii\BoletaDte\Domain\Dte;

if ( ! function_exists( 'update_post_meta' ) ) {
    function update_post_meta( $post_id, $meta_key, $meta_value ) {
        $GLOBALS['post_meta'][ $post_id ][ $meta_key ] = $meta_value;
        return true;
    }
}

final class WooCommerceDteRepositoryTest extends TestCase {
    protected function setUp(): void {
        $GLOBALS['post_meta'] = [];
    }

    public function test_save_stores_dte_data_in_meta(): void {
        $repo = new WooCommerceDteRepository();
        $dte  = new Dte( '10', [ 'foo' => 'bar' ] );
        $repo->save( $dte );
        $this->assertSame( [ 'foo' => 'bar' ], $GLOBALS['post_meta'][10]['_sii_boleta_dte_data'] );
    }
}
