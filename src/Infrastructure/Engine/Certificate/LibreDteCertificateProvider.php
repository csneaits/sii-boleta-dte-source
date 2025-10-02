<?php

namespace Sii\BoletaDte\Infrastructure\Engine\Certificate;

use Derafu\Certificate\Service\CertificateFaker;
use Derafu\Certificate\Service\CertificateLoader;
use libredte\lib\Core\Package\Billing\Component\TradingParties\Entity\Emisor;

class LibreDteCertificateProvider implements CertificateProviderInterface {
    public function __construct(
        private readonly CertificateLoader $certificateLoader,
        private readonly CertificateFaker $certificateFaker
    ) {
    }

    public function resolve(array $settings, Emisor $emisor): object {
        $certFile = $settings['cert_path'] ?? '';
        $certPass = $settings['cert_pass'] ?? '';

        try {
            if ($certFile && @file_exists($certFile)) {
                return $this->certificateLoader->load($certFile, (string) $certPass);
            }

            return $this->certificateFaker->createFake(id: $emisor->getRUT());
        } catch (\Throwable $e) {
            return $this->certificateFaker->createFake(id: $emisor->getRUT());
        }
    }
}
