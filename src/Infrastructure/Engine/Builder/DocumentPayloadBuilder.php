<?php
namespace Sii\BoletaDte\Infrastructure\Engine\Builder;

/**
 * Builder that assembles the array payload consumed by LibreDTE workers.
 */
class DocumentPayloadBuilder {
        /**
         * @var array<string,mixed>
         */
        private array $document;

        /**
         * @var array<string,mixed>
         */
        private array $rawReceptor = array();

        private bool $hasReferences = false;

        /**
         * @param array<string,mixed> $template
         */
        public function __construct( array $template ) {
                $this->document = $template;
        }

        /**
         * @param array<string,mixed> $source
         */
        public function withDocumentIdentification( int $tipo, array $source ): self {
                $folio = 0;
                if ( isset( $source['Folio'] ) ) {
                        $folio = (int) $source['Folio'];
                } elseif ( isset( $source['Encabezado']['IdDoc']['Folio'] ) ) {
                        $folio = (int) $source['Encabezado']['IdDoc']['Folio'];
                }

                $issueDate = '';
                if ( isset( $source['FchEmis'] ) ) {
                        $issueDate = (string) $source['FchEmis'];
                } elseif ( isset( $source['Encabezado']['IdDoc']['FchEmis'] ) ) {
                        $issueDate = (string) $source['Encabezado']['IdDoc']['FchEmis'];
                }

                if ( ! isset( $this->document['Encabezado'] ) || ! is_array( $this->document['Encabezado'] ) ) {
                        $this->document['Encabezado'] = array();
                }

                // Preserve any existing IdDoc template keys and merge any
                // additional IdDoc values provided by the caller in $source
                // (for example TermPagoGlosa, FmaPago, FchVenc, IndServicio,
                // PeriodoDesde/PeriodoHasta). Previously this replaced the
                // entire IdDoc node which caused those fields to be lost.
                $existing = is_array( $this->document['Encabezado']['IdDoc'] ?? null ) ? $this->document['Encabezado']['IdDoc'] : array();
                $fromSource = array();
                if ( isset( $source['Encabezado']['IdDoc'] ) && is_array( $source['Encabezado']['IdDoc'] ) ) {
                        $fromSource = $source['Encabezado']['IdDoc'];
                }

                $merged = array_merge( $existing, $fromSource );

                // Ensure core identification values (TipoDTE, Folio, FchEmis)
                // come from the explicit arguments and override any source/template
                // values for those keys.
                $merged['TipoDTE'] = $tipo;
                $merged['Folio']   = $folio;
                $merged['FchEmis'] = $issueDate;

                $this->document['Encabezado']['IdDoc'] = $merged;

                return $this;
        }

        /**
         * @param array<string,mixed> $emisor
         */
        public function withEmisor( array $emisor ): self {
                if ( ! isset( $this->document['Encabezado'] ) || ! is_array( $this->document['Encabezado'] ) ) {
                        $this->document['Encabezado'] = array();
                }

                $this->document['Encabezado']['Emisor'] = $emisor;

                return $this;
        }

        /**
         * @param array<string,mixed> $sanitized
         * @param array<string,mixed> $raw
         */
        public function withReceptor( array $sanitized, array $raw ): self {
                if ( ! isset( $this->document['Encabezado'] ) || ! is_array( $this->document['Encabezado'] ) ) {
                        $this->document['Encabezado'] = array();
                }

                $this->document['Encabezado']['Receptor'] = $sanitized;
                $this->rawReceptor                           = $raw;

                return $this;
        }

        /**
         * @param array<int,array<string,mixed>> $detalle
         */
        public function withDetalle( array $detalle ): self {
                $this->document['Detalle'] = $detalle;

                return $this;
        }

        /**
         * @param array<mixed> $globalDiscount
         */
        public function withGlobalDiscount( array $globalDiscount ): self {
                if ( empty( $globalDiscount ) ) {
                        unset( $this->document['DscRcgGlobal'] );

                        return $this;
                }

                $this->document['DscRcgGlobal'] = $globalDiscount;

                return $this;
        }

        /**
         * @param array<int,array<string,mixed>> $references
         */
        public function withReferences( array $references ): self {
                if ( empty( $references ) ) {
                        unset( $this->document['Referencia'] );
                        $this->hasReferences = false;

                        return $this;
                }

                $this->document['Referencia'] = $references;
                $this->hasReferences          = true;

                return $this;
        }

        public function withTotalsSkeleton( ?float $tasaIva ): self {
                if ( ! isset( $this->document['Encabezado'] ) || ! is_array( $this->document['Encabezado'] ) ) {
                        $this->document['Encabezado'] = array();
                }

                $totals = array( 'MntTotal' => 0 );
                if ( null !== $tasaIva ) {
                        $totals['TasaIVA'] = $tasaIva;
                }

                $this->document['Encabezado']['Totales'] = $totals;

                return $this;
        }

        public function build(): DocumentPayload {
                return new DocumentPayload( $this->document, $this->rawReceptor, $this->hasReferences );
        }
}
