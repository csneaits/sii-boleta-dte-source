# SII Boleta DTE – Plugin WordPress para emisión de DTE

Plugin modular para WordPress y WooCommerce que emite boletas, facturas y otros Documentos Tributarios Electrónicos (DTE) integrados con el Servicio de Impuestos Internos (SII) de Chile. El núcleo abstrae la firma digital, la gestión de folios, la generación de PDF y el intercambio con las APIs del SII para que la interfaz sólo coordine los flujos de negocio.

## Características principales

- **Integración completa con el SII**: genera tokens, firma los XML con `xmlseclibs`, envía DTE, libros electrónicos y Resúmenes de Ventas Diarias (RVD) utilizando `libredte-lib-core` como motor de renderizado y validación.
- **Gestión de folios y CAF**: administra rangos autorizados en base de datos, soporta múltiples CAF por tipo de documento y expone una interfaz para cargarlos o reemplazarlos.
- **Almacenamiento seguro de XML y PDF**: mueve automáticamente los archivos generados a directorios protegidos (`wp-content/uploads/sii-boleta-dte-secure/` para XML y `wp-content/sii-boleta-dte/private/` para PDF), crea reglas `.htaccess`, firma URLs temporales y permite migrar archivos heredados.
- **Cola persistente de trabajos**: conserva envíos pendientes en la tabla `sii_boleta_dte_queue`, ejecuta reintentos controlados y ofrece procesamiento manual, automático (cron) y migración hacia el almacenamiento seguro.
- **Integración con WooCommerce**: añade campos en el checkout, genera DTE al completar el pedido, adjunta el PDF en el correo y publica enlaces de descarga firmados para clientes y operadores.
- **Panel administrativo completo**: incluye páginas para ajustes, panel de control, generación manual, carga de CAF, monitoreo de la cola, diagnósticos y visor de logs.
- **Extensibilidad**: filtros y factories permiten reemplazar el motor de generación, personalizar pipelines de preparación y registrar nuevos tipos de documento.

## Arquitectura y módulos

El proyecto sigue una arquitectura **hexagonal** (ports & adapters). Las reglas de negocio residen en `Domain/` y `Application/`, mientras que `Infrastructure/` y `Presentation/` implementan adaptadores concretos para WordPress, WooCommerce, REST y almacenamiento.

### Núcleo de dominio
- Entidades y objetos de valor (`Domain/Dte`, `Domain/Rut`, etc.).
- Contratos (`Domain/DteRepository`, `Domain/Logger`, `Domain/DteEngine`).

### Capa de aplicación
- Casos de uso que coordinan folios, cola, libros y RVD (`Application/FolioManager`, `Application/Queue`, `Application/QueueProcessor`, `Application/LibroBoletas`, `Application/RvdManager`).

### Infraestructura
- Adaptadores de API SII (`Infrastructure/Rest/Api`), motor LibreDTE y su factoría (`Infrastructure/Engine/*`), persistencia (`Infrastructure/Persistence/*`), integración WooCommerce (`Infrastructure/WooCommerce/*`), cron, CLI y servicios auxiliares (almacenamiento seguro, URLs firmadas, migradores).

### Presentación
- Interfaz de administración (`Presentation/Admin/*`), activos encolados y endpoints públicos (`Presentation/WooCommerce/*`, `Infrastructure/Rest/Endpoints`).

`Infrastructure\Factory\Container` resuelve dependencias y las expone mediante el contenedor propio utilizado en páginas, comandos y hooks.

## Flujos de generación de DTE

### Emisión automática desde WooCommerce

1. El pedido pasa a estado **Completado**.
2. `Infrastructure\WooCommerce\Woo` determina el tipo de DTE, prepara la carga útil y genera el XML con `LibreDteEngine`.
3. `Infrastructure\TokenManager` obtiene un token, `Infrastructure\Rest\Api` envía el archivo al SII y registra el `trackId`.
4. Se genera el PDF, se almacena en el directorio seguro, se crea un enlace de descarga firmado y se notifica al cliente por correo.

### Envío diferido mediante la cola

1. Los casos de uso pueden encolar XML firmados con `Application\Queue::enqueue_dte()`.
2. `Application\QueueProcessor::process()` ejecuta envíos manuales o programados (evento `sii_boleta_dte_process_queue`).
3. Cada intento registra logs, actualiza la cola y mueve los archivos al almacenamiento seguro para mantener el historial.

## Seguridad de documentos

- `Infrastructure\WooCommerce\PdfStorage` y `PdfStorageMigrator` resguardan los PDF en un directorio privado dentro de `wp-content`, aplican permisos restrictivos y generan claves/nonce para descargas temporales.
- `Infrastructure\Rest\Endpoints` mueve los XML históricos a `uploads/sii-boleta-dte-secure`, endurece el directorio (índice y `.htaccess`) y sirve las boletas sólo cuando la solicitud presenta un token válido.
- `Infrastructure\Rest\SignedUrlService` genera y valida enlaces temporales utilizados en el checkout, correos y panel administrativo.
- Los endpoints AJAX (`Presentation\Admin\Ajax`) validan claves y nonces antes de transmitir PDFs u otros artefactos almacenados en la ubicación segura.

## Estructura del repositorio

- `sii-boleta-dte/`
  - `sii-boleta-dte.php` – archivo principal del plugin: registra hooks, contenedor y migraciones.
  - `src/Domain/`, `src/Application/`, `src/Infrastructure/`, `src/Presentation/` – código del núcleo descrito anteriormente.
  - `resources/` – plantillas Twig, esquemas XML y YAML utilizados por el motor LibreDTE.
  - `languages/` – traducciones (`.po/.mo`).
  - `tests/` – suite de PHPUnit que cubre dominio, infraestructura, endpoints y procesos de migración.
- `docs/` – documentación funcional complementaria.
- `build.sh`, `build.ps1`, `build.bat` – scripts para empaquetar el plugin en un ZIP instalable.

## Construcción y pruebas

```bash
chmod +x build.sh
./build.sh
```

El script lee la versión desde la cabecera del plugin, ejecuta `composer install --no-dev --prefer-dist --optimize-autoloader` y genera `dist/sii-boleta-dte-<versión>.zip` listo para instalar.

Para preparar el entorno de desarrollo:

```bash
cd sii-boleta-dte
composer install
composer test
composer phpcs
```

Los tests bootstrappean WordPress mínimo desde `tests/bootstrap.php` y utilizan dobles en memoria para evitar dependencias externas.

## Contribuciones

1. Haz fork del repositorio y crea una rama descriptiva.
2. Ejecuta `composer test` y `composer phpcs` antes de enviar el pull request.
3. Describe claramente el objetivo de tu contribución en el mensaje del PR.

## Licencia

GPL v2 o posterior. Consulta los encabezados de cada archivo para más detalles.
