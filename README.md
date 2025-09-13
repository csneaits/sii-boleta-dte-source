# SII Boleta DTE – Plugin WordPress para emisión de DTE

Plugin para generar boletas, facturas y otros Documentos Tributarios Electrónicos (DTE) con integración al Servicio de Impuestos Internos de Chile. Incluye firma digital, timbraje con CAF, envío y consulta de estados, integración con WooCommerce y representación en PDF/HTML.

## Arquitectura

El núcleo sigue una arquitectura **hexagonal** (ports & adapters) que mantiene la lógica de negocio aislada de las dependencias externas.

```mermaid
flowchart LR
    UI[Presentation\n(WP/WooCommerce)] --> A[Application]
    CLI[Infrastructure\nCLI] --> A
    REST[Infrastructure\nREST] --> A
    Persist[Infrastructure\nPersistence] --> A
    Engine[Infrastructure\nEngine/Config] --> A
    A --> D[Domain]
    S[Shared] --- A
    S --- D
```

### Capas

- **Domain**: entidades y reglas de negocio puras.
- **Application**: casos de uso que coordinan el dominio.
- **Infrastructure**: adaptadores concretos (CLI, REST, WooCommerce, persistencia, motor de timbraje, etc.).
- **Presentation**: interfaz de administración y formularios en WordPress.
- **Shared**: utilidades comunes reutilizables en todas las capas.

## Estructura del repositorio

- `sii-boleta-dte/`
  - `src/` – código fuente organizado según las capas anteriores.
  - `resources/` – plantillas y datos requeridos por LibreDTE.
  - `tests/` – pruebas unitarias con PHPUnit.
- `build.sh` / `build.ps1` – scripts de empaquetado que generan un ZIP instalable bajo `dist/`.

## Compilación del plugin

```bash
chmod +x build.sh
./build.sh
```

Generará `dist/sii-boleta-dte-<versión>.zip` listo para instalar en WordPress.

En Windows:

```powershell
Set-ExecutionPolicy -Scope Process RemoteSigned
./build.ps1
```

## Desarrollo

Requisitos mínimos: PHP 8.4 con extensiones `soap`, `mbstring` y `openssl`.

Instala las dependencias:

```bash
cd sii-boleta-dte
composer install
```

Ejecuta las pruebas y estándares:

```bash
composer test
composer phpcs
```

## Configuración rápida

En **Ajustes → SII Boletas** define:

- Datos del emisor (`RUT`, `Razón Social`, `Giro`, `Dirección`, `Comuna`, `Acteco` y opcional `CdgSIISucur`).
- Rutas al certificado digital y a los CAF por tipo de DTE.
- Ambiente de trabajo (`test` o `production`) y formato del PDF.

## Contribuciones

1. Haz fork del repositorio y crea una rama descriptiva.
2. Ejecuta `composer test` y `composer phpcs` antes de enviar el pull request.
3. Describe claramente el objetivo de tu contribución en el mensaje del PR.

## Licencia

El código se distribuye bajo la licencia GPL v2 o posterior. Consulta los encabezados de cada archivo para más detalles.
