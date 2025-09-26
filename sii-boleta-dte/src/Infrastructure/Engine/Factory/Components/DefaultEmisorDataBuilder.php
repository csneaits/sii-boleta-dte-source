<?php

namespace Sii\BoletaDte\Infrastructure\Engine\Factory\Components;

class DefaultEmisorDataBuilder implements EmisorDataBuilder {
        public function build( array $payload, array $settings ): array {
                $emisorData = array();
                if ( isset( $payload['Encabezado']['Emisor'] ) && is_array( $payload['Encabezado']['Emisor'] ) ) {
                        $emisorData = $payload['Encabezado']['Emisor'];
                }

                $customGiro = $emisorData['GiroEmisor']
                        ?? $emisorData['GiroEmis']
                        ?? $payload['GiroEmisor']
                        ?? $payload['GiroEmis']
                        ?? '';
                $customGiro = is_string( $customGiro ) ? trim( $customGiro ) : '';

                $emisor = array(
                        'RUTEmisor'    => $settings['rut_emisor']
                                ?? $emisorData['RUTEmisor']
                                ?? $payload['RUTEmisor']
                                ?? $payload['RutEmisor']
                                ?? '',
                        'RznSocEmisor' => $settings['razon_social']
                                ?? $emisorData['RznSocEmisor']
                                ?? $emisorData['RznSoc']
                                ?? $payload['RznSocEmisor']
                                ?? $payload['RznSoc']
                                ?? '',
                        'GiroEmisor'   => '' !== $customGiro
                                ? $customGiro
                                : ( $settings['giro']
                                        ?? ''
                                ),
                        'DirOrigen'    => $settings['direccion']
                                ?? $emisorData['DirOrigen']
                                ?? $payload['DirOrigen']
                                ?? '',
                        'CmnaOrigen'   => $settings['comuna']
                                ?? $emisorData['CmnaOrigen']
                                ?? $payload['CmnaOrigen']
                                ?? '',
                );

                if ( '' !== $emisor['RznSocEmisor'] ) {
                        $emisor['RznSoc'] = $emisor['RznSocEmisor'];
                }
                if ( '' !== $emisor['GiroEmisor'] ) {
                        $emisor['GiroEmis'] = $emisor['GiroEmisor'];
                }

                return $emisor;
        }
}
