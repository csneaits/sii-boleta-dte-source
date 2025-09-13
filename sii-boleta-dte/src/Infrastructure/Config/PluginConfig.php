<?php

namespace Sii\BoletaDte\Infrastructure\Config;

/**
 * Gestiona la configuración del plugin utilizando Singleton.
 */
class PluginConfig {
    /**
     * Instancia única.
     *
     * @var PluginConfig|null
     */
    private static ?PluginConfig $instance = null;

    /**
     * Ajustes cargados.
     *
     * @var array<string, mixed>
     */
    private array $settings;

    /**
     * @param array<string, mixed> $settings Ajustes iniciales.
     */
    private function __construct( array $settings ) {
        $this->settings = $settings;
    }

    /**
     * Obtiene la instancia única.
     *
     * @param array<string, mixed> $settings Opcionales para inicializar.
     */
    public static function get_instance( array $settings = [] ): self {
        if ( null === self::$instance ) {
            self::$instance = new self( $settings );
        }

        return self::$instance;
    }

    /**
     * Recupera un valor de configuración.
     *
     * @param string     $key     Clave a buscar.
     * @param mixed|null $default Valor por defecto.
     *
     * @return mixed|null
     */
    public function get( string $key, $default = null ) {
        return $this->settings[ $key ] ?? $default;
    }
}
