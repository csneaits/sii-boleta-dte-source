<?php

use PHPUnit\Framework\TestCase;
use Sii\BoletaDte\Infrastructure\WooCommerce\Woo;

final class WooTest extends TestCase {
    public function test_prepare_order_data_uses_gross_line_totals(): void {
        $order = new class() {
            public function get_id(): int {
                return 42;
            }

            public function get_items(): array {
                return array(
                    new class() {
                        public function get_name(): string {
                            return 'Producto con IVA incluido';
                        }

                        public function get_quantity(): float {
                            return 2.0;
                        }

                        public function get_total(): float {
                            return 2000.0;
                        }

                        public function get_total_tax(): float {
                            return 380.0;
                        }
                    }
                );
            }

            public function get_total(): float {
                return 2380.0;
            }

            public function get_total_tax(): float {
                return 380.0;
            }

            public function get_formatted_billing_full_name(): string {
                return 'Cliente Demo';
            }

            public function get_billing_phone(): string {
                return '';
            }

            public function get_billing_email(): string {
                return 'cliente@example.com';
            }

            public function get_billing_address_1(): string {
                return 'Calle Falsa 123';
            }

            public function get_billing_city(): string {
                return 'Santiago';
            }

            public function get_order_number(): string {
                return '42';
            }
        };

        $woo = new Woo( null );

        $method = new ReflectionMethod( Woo::class, 'prepare_order_data' );
        $method->setAccessible( true );

        $data = $method->invoke( $woo, $order, 33, 42 );

        $this->assertIsArray( $data );
        $this->assertArrayHasKey( 'Detalles', $data );
        $this->assertCount( 1, $data['Detalles'] );

        $detail = $data['Detalles'][0];
        $this->assertSame( 2.0, $detail['QtyItem'] );
        $this->assertSame( 1190, (int) round( $detail['PrcItem'] ) );
        $this->assertSame( 2380, (int) round( $detail['MontoItem'] ) );

        $this->assertArrayHasKey( 'Totales', $data );
        $this->assertSame( 2380.0, $data['Totales']['MntTotal'] );
        $this->assertSame( 380.0, $data['Totales']['IVA'] );
        $this->assertSame( 2000.0, $data['Totales']['MntNeto'] );
    }

    public function test_build_refund_items_includes_taxes_in_amounts(): void {
        $refund = new class() {
            public function get_items( array $types ): array {
                return array(
                    new class() {
                        public function get_total(): float {
                            return -500.0;
                        }

                        public function get_total_tax(): float {
                            return -95.0;
                        }

                        public function get_quantity(): float {
                            return -1.0;
                        }

                        public function get_name(): string {
                            return 'Producto reembolsado';
                        }

                        public function get_type(): string {
                            return 'line_item';
                        }
                    }
                );
            }
        };

        $woo = new Woo( null );

        $method = new ReflectionMethod( Woo::class, 'build_refund_items' );
        $method->setAccessible( true );

        $items = $method->invoke( $woo, $refund );

        $this->assertCount( 1, $items );
        $item = $items[0];

        $this->assertSame( 1.0, $item['QtyItem'] );
        $this->assertSame( 595, (int) round( $item['PrcItem'] ) );
        $this->assertSame( 595, (int) round( $item['MontoItem'] ) );
    }
}
