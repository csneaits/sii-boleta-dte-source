<?php
namespace SiiBoletaDte\Infrastructure\Engine;

/**
 * LibreDTE-based engine wrapper.
 *
 * Provides helpers to retrieve CAF and certificate data. When previewing
 * documents, fake credentials are generated. In production mode the
 * credentials configured in plugin settings are loaded instead.
 */
class LibreDteEngine
{
    /** @var \SII_Boleta_Settings */
    private $settings;
    /** @var object */
    private $cafFaker;
    /** @var object */
    private $certificateFaker;

    public function __construct($settings, $cafFaker, $certificateFaker)
    {
        $this->settings          = $settings;
        $this->cafFaker          = $cafFaker;
        $this->certificateFaker  = $certificateFaker;
    }

    /**
     * Obtain CAF and certificate contents.
     *
     * @param int  $dteType DTE type to sign.
     * @param bool $preview Whether the document is a preview.
     * @return array{caf:string,certificate:string,password:?string}
     */
    public function credentials(int $dteType, bool $preview = false): array
    {
        $opts     = (array) $this->settings->get_settings();
        $cafPaths = isset($opts['caf_path']) && is_array($opts['caf_path']) ? $opts['caf_path'] : [];
        $cafPath  = $cafPaths[$dteType] ?? null;
        $certPath = $opts['cert_path'] ?? null;
        $certPass = $opts['cert_pass'] ?? null;

        if (!$preview && $cafPath && is_readable($cafPath) && $certPath && is_readable($certPath)) {
            return [
                'caf'         => file_get_contents($cafPath),
                'certificate' => file_get_contents($certPath),
                'password'    => $certPass,
            ];
        }

        return [
            'caf'         => $this->cafFaker->create($dteType),
            'certificate' => $this->certificateFaker->createFake(),
            'password'    => null,
        ];
    }
}
