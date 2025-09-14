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
- Ruta del certificado digital y su contraseña, más las rutas a los CAF por tipo de DTE (puedes ingresar múltiples rutas, una por línea).
- Ambiente de trabajo (`test` o `production`).
- Tipos de DTE habilitados para WooCommerce.
- Opciones del PDF: formato (`carta` o `boleta`), mostrar u ocultar el logotipo, nota de pie de página y ruta del logotipo de la empresa.
- Perfil SMTP a utilizar (por ejemplo FluentSMTP) y habilitar o deshabilitar el registro en archivos.

### Migración desde versiones anteriores

Al activar una versión nueva del plugin se migrarán automáticamente los ajustes
antiguos (`sii_boleta_dte_settings`) y los archivos de registro existentes al
nuevo esquema basado en base de datos. Esta migración se ejecuta una sola vez y
no altera los datos originales.

### Registro y visualización de logs

Los mensajes se almacenan en la tabla personalizada `sii_boleta_dte_logs` y de
forma opcional en archivos dentro de `wp-content/uploads/sii-boleta-logs/`. En
la página de ajustes puedes habilitar o deshabilitar cada método de registro y
consultar las últimas entradas desde el administrador de WordPress mediante el
visualizador de logs incluido.  La interfaz reutiliza la tabla estándar de
WordPress y permite filtrar por estado o track ID.

### Cliente API y flujo de WooCommerce

El cliente `Api` maneja la generación de tokens, el envío de DTE y consulta de
estado contra los servicios del SII.  Soporta un número configurable de reintentos
ante fallos de red u otros errores HTTP.  La integración con WooCommerce añade un
selector de tipo de documento y campo RUT en el checkout, genera el DTE al marcar
el pedido como completado, guarda el track ID devuelto por el SII y almacena el
PDF generado para que pueda descargarse desde la pantalla de edición del pedido.

### Diagnóstico y ayuda

Desde el menú del plugin puedes acceder a un panel de **diagnóstico** que verifica
requisitos básicos y permite probar la generación de tokens y la conectividad con
los servicios del SII.  También se incluye una página de **ayuda** con enlaces a la
documentación del proyecto y guías de uso.

## Contribuciones

1. Haz fork del repositorio y crea una rama descriptiva.
2. Ejecuta `composer test` y `composer phpcs` antes de enviar el pull request.
3. Describe claramente el objetivo de tu contribución en el mensaje del PR.

## Licencia

El código se distribuye bajo la licencia GPL v2 o posterior. Consulta los encabezados de cada archivo para más detalles.
