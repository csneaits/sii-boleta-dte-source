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

    public function test_prepare_order_data_includes_global_discount_when_available(): void {
        $order = new class() {
            public function get_id(): int {
                return 77;
            }

            public function get_items(): array {
                return array(
                    new class() {
                        public function get_name(): string {
                            return 'Producto con promoción';
                        }

                        public function get_quantity(): float {
                            return 1.0;
                        }

                        public function get_total(): float {
                            return 2800.0;
                        }

                        public function get_total_tax(): float {
                            return 532.0;
                        }
                    }
                );
            }

            public function get_total(): float {
                return 3332.0;
            }

            public function get_total_tax(): float {
                return 532.0;
            }

            public function get_discount_total(): float {
                return 400.0;
            }

            public function get_discount_tax(): float {
                return 76.0;
            }

            public function get_formatted_billing_full_name(): string {
                return 'Cliente Promoción';
            }

            public function get_billing_phone(): string {
                return '';
            }

            public function get_billing_email(): string {
                return 'promo@example.com';
            }

            public function get_billing_address_1(): string {
                return 'Av. Siempre Viva 742';
            }

            public function get_billing_city(): string {
                return 'Springfield';
            }

            public function get_order_number(): string {
                return '77';
            }
        };

        $woo = new Woo( null );

        $method = new ReflectionMethod( Woo::class, 'prepare_order_data' );
        $method->setAccessible( true );

        $data = $method->invoke( $woo, $order, 33, 77 );

        $this->assertArrayHasKey( 'DscRcgGlobal', $data );
        $this->assertSame( 'D', $data['DscRcgGlobal']['TpoMov'] );
        $this->assertSame( '$', $data['DscRcgGlobal']['TpoValor'] );
        $this->assertSame( 476, $data['DscRcgGlobal']['ValorDR'] );
    }

    public function test_prepare_order_data_uses_refund_totals_without_global_discount(): void {
        $refund = new class() {
            public function get_items( array $types ): array {
                return array(
                    new class() {
                        public function get_total(): float {
                            return 25.0;
                        }

                        public function get_total_tax(): float {
                            return 5.0;
                        }

                        public function get_quantity(): float {
                            return 1.0;
                        }

                        public function get_name(): string {
                            return 'Línea original';
                        }

                        public function get_type(): string {
                            return 'line_item';
                        }
                    }
                );
            }

            public function get_total_tax(): float {
                return -5.0;
            }

            public function get_amount(): float {
                return 30.0;
            }
        };

        $order = new class() {
            public function get_id(): int {
                return 99;
            }

            public function get_items(): array {
                return array();
            }

            public function get_total(): float {
                return 100.0;
            }

            public function get_total_tax(): float {
                return 19.0;
            }

            public function get_formatted_billing_full_name(): string {
                return 'Cliente Reembolso';
            }

            public function get_billing_phone(): string {
                return '';
            }

            public function get_billing_email(): string {
                return 'refund@example.com';
            }

            public function get_billing_address_1(): string {
                return 'Dirección 123';
            }

            public function get_billing_city(): string {
                return 'Ciudad';
            }

            public function get_order_number(): string {
                return '99';
            }
        };

        $woo = new Woo( null );

        $method = new ReflectionMethod( Woo::class, 'prepare_order_data' );
        $method->setAccessible( true );

        $data = $method->invoke( $woo, $order, 61, 99, array( 'refund' => $refund ) );

        $this->assertArrayNotHasKey( 'DscRcgGlobal', $data );

        $totals = $data['Encabezado']['Totales'] ?? array();
        $this->assertSame( 25.0, $totals['MntNeto'] ?? 0.0 );
        $this->assertSame( 5.0, $totals['IVA'] ?? 0.0 );
        $this->assertSame( 30.0, $totals['MntTotal'] ?? 0.0 );

        $detail = $data['Detalles'][0];
        $this->assertSame( 30.0, $detail['MontoItem'] );
        $this->assertSame( 1.0, $detail['QtyItem'] );
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
