<?php

declare(strict_types=1);

namespace SiiBoletaDte\Infrastructure\WooCommerce;

class WooCommerceDteRepository {
	/**
	 * Persist DTE payload as order meta data.
	 *
	 * @param array $payload  DTE data to persist.
	 * @param int	$order_id WooCommerce order identifier.
	 */
	public function save( array $payload, int $order_id ): void {
		update_post_meta(
			$order_id,
			'_sii_boleta_dte_data',
			json_encode( $payload )
		);
	}
}
