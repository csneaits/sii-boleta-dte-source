# SII Boleta DTE

Plugin modular para WordPress y WooCommerce que emite boletas, facturas, notas y otros Documentos Tributarios Electrónicos (DTE) conectados al Servicio de Impuestos Internos (SII) de Chile. El núcleo abstrae folios, CAF, firma, generación de PDF y comunicación con el SII para que los flujos de negocio se configuren desde WordPress.

## Visión general

- **Motor LibreDTE integrado**: se emplea `libredte/libredte-lib-core` como motor de normalización, timbraje y renderizado. El plugin envuelve la aplicación oficial mediante `Infrastructure\LibredteBridge` y aplica *fallbacks* cuando la versión disponible de LibreDTE carece de algún componente.
- **Gestión de folios y CAF**: administra múltiples rangos por tipo de documento, detecta agotamiento de folios, permite cargar nuevas autorizaciones y sincroniza la numeración cuando LibreDTE aporta el folio siguiente.
- **Cola persistente**: envíos al SII, libros, RVD y recibos se guardan en `sii_boleta_dte_queue` y se procesan de forma segura por cron o manualmente, con reintentos y bitácora centralizada.
- **Almacenamiento seguro**: XML y PDF se mueven a directorios privados dentro de `wp-content`; los enlaces firmados vencen automáticamente para proteger la distribución.
- **Integración WooCommerce**: captura datos tributarios en el checkout, emite DTE al completar el pedido y adjunta el PDF en los correos.
- **Panel administrativo completo**: ajustes, carga de CAF, emisores, cola, diagnósticos, visor de logs y herramientas de certificación.
- **Extensibilidad**: filtros, factories y servicios permiten intercambiar el motor, ajustar pipelines de preparación y registrar nuevos tipos de documento.

## Requisitos

| Componente             | Versión mínima recomendada |
| ---------------------- | -------------------------- |
| PHP                    | 8.4                        |
| WordPress              | 6.5                        |
| WooCommerce (opcional) | 8.6                        |
| Extensiones PHP        | `ext-json`, `ext-mbstring`, `ext-dom`, `ext-libxml`, `ext-openssl` |

Se requiere acceso a Composer para instalar dependencias y un certificado digital válido para timbrar y firmar DTE.

## Arquitectura

El proyecto sigue un estilo hexagonal:

- `src/Domain/` concentra entidades, objetos de valor y contratos.
- `src/Application/` implementa casos de uso (folios, cola, libros, RVD).
- `src/Infrastructure/` contiene adaptadores de SII, LibreDTE, persistencia, WooCommerce, CLI, cron y servicios auxiliares.
- `src/Presentation/` expone la interfaz administrativa y *endpoints* públicos.

El contenedor `Infrastructure\Factory\Container` registra servicios y los resuelve mediante *lazy loading*.

### Componentes clave

- `Infrastructure\Engine\LibreDteEngine` genera XML y PDF. Normaliza líneas de detalle antes de invocar LibreDTE, utilizando el helper `isSequentialArray()` para mantener compatibilidad con PHP < 8.1 y evitando errores como `mb_substr(false)`.
- `Infrastructure\Engine\Caf\LibreDteCafBridgeProvider` sincroniza folios con el worker oficial de LibreDTE y persiste localmente el "observed + 1" para mantener la secuencia en ambientes de certificación y desarrollo.
- `Infrastructure\Rest\Api` administra llamadas al SII con recuperación de token mediante `Infrastructure\TokenManager`.
- `Infrastructure\WooCommerce\PdfStorage` y `Infrastructure\Rest\SignedUrlService` protegen y distribuyen documentos firmados.
- `Application\QueueProcessor` procesa trabajos con *backoff*, logging y migración automática al almacenamiento seguro.

## Integración con LibreDTE

- El bridge resuelve `Application` preferentemente con `libredte_lib()` y aplica el entorno (certificación/producción) desde `Settings`.
- Se aprovechan factorías oficiales de `TradingParties` (`EmisorFactory`, `ReceptorFactory`) con *fallback* a entidades mínimas cuando faltan métodos en versiones antiguas.
- Tras construir el `DocumentBag`, el motor intenta sanitizar y validar contra XSD y firmas si los *workers* existen. Las preferencias `sanitize_with_libredte`, `validate_schema_libredte` y `validate_signature_libredte` aparecen en Ajustes y se consideran activadas si no están presentes.
- Firma de `EnvioRecibos`: `xmlseclibs` (RSA-SHA256) es el camino por defecto; puede delegarse a LibreDTE cuando la versión instalada expone el firmador. El plugin vuelve automáticamente a `xmlseclibs` si el firmador no existe.

## Flujos principales

### Emisión automática (WooCommerce)

1. El pedido pasa a **Completado**.
2. `Infrastructure\WooCommerce\Woo` selecciona el tipo de DTE y prepara el payload.
3. `LibreDteEngine` genera el XML, solicita folio/CAF cuando corresponde y crea el PDF.
4. `Infrastructure\TokenManager` obtiene token y `Infrastructure\Rest\Api` envía el documento al SII.
5. `PdfStorage` mueve los archivos al directorio seguro y `SignedUrlService` genera enlaces de descarga con expiración.

### Emisión manual desde el panel

- El formulario ofrece previsualización del XML, validación contra XSD (documento y sobre `EnvioDTE/EnvioBOLETA`) y auditoría de diferencias entre el XML de preview y el XML final mediante `XmlAuditService`. Los *hooks* `sii_boleta_xml_preview_generated` y `sii_boleta_xml_final_generated` facilitan inspecciones personalizadas.

### Cola de envíos

- `Application\\Queue` persiste trabajos con su contexto.
- `QueueProcessor` reintenta con backoff incremental y registra resultados en el log.
- El cron `sii_boleta_dte_process_queue` consume la cola; puede invocarse manualmente y migra XML/PDF legados al almacenamiento seguro.

## Mejoras recientes (2025 Q3)

- **CAF determinista**: el bridge actualiza el último folio observado +1 incluso cuando LibreDTE sólo expone rangos, evitando saltos al volver al proveedor histórico (`FoliosDb`).
- **Normalización de items para auto folio**: el motor sanea `Detalle` antes de invocar LibreDTE, asegurando que `NmbItem` sea siempre cadena y evitando el `TypeError` `mb_substr(false)`. También se añadió `isSequentialArray()` para entornos sin `array_is_list`.
- **Etiquetas de retención en PDF**: se normalizan los totales retenidos (`ImptoReten`) para que las plantillas estándar muestren los textos aunque LibreDTE entregue valores *false*.
- **Validaciones opcionales reforzadas**: sanitización, XSD y firmas se ejecutan cuando LibreDTE lo permite, con protecciones `method_exists` y *fallbacks* no disruptivos.

## Instalación y build

```bash
composer install
```

Para empaquetar el plugin:

```bash
chmod +x build.sh
./build.sh
```

Se genera `dist/sii-boleta-dte-<versión>.zip` con dependencias optimizadas. En Windows puedes usar `powershell -ExecutionPolicy Bypass -File build.ps1`.

## Pruebas

```bash
composer test
```

Comandos recomendados durante el desarrollo:

- `vendor/bin/phpunit tests/Infrastructure/Engine/AutoFolioTest.php`
- `vendor/bin/phpunit tests/Infrastructure/Engine/PdfRetentionRenderTest.php`
- `vendor/bin/phpunit tests/Infrastructure/Engine/LibreDteCafBridgeProviderTest.php`
- `composer phpcs`

Los tests bootstrappean WordPress desde `tests/bootstrap.php` y utilizan dobles en memoria para base de datos y cola.

## Solución de problemas

| Síntoma | Diagnóstico | Acciones sugeridas |
| ------- | ----------- | ------------------ |
| `WP_Error('sii_boleta_missing_caf')` | No existe un CAF que cubra el folio solicitado | Carga un CAF nuevo en Ajustes → Certificado y CAF o habilita auto folio en ambientes de prueba |
| `mb_substr(): Argument #1 must be of type string` | Datos `Detalle` sin normalizar (no lista o valores `false`) | Verifica que el payload entregue `Detalle` como lista; el motor ya sanea valores, pero conviene enviar cadenas no vacías |
| PDF sin etiquetas de retención | `ImptoReten` llega vacío/`false` | Asegura que los impuestos retenidos estén en el payload y que los totales sean consistentes |
| TrackId pendiente por largo tiempo | Token expirado o SII no disponible | Reintenta desde la cola; revisa `wp-content/uploads/sii-boleta-dte/private/logs/debug.log` |

## Seguridad

- Los certificados `.pfx` se almacenan cifrados y las contraseñas sólo son accesibles desde los casos de uso autorizados.
- Los XML/PDF se guardan en directorios privados (`wp-content/uploads/sii-boleta-dte-secure`, `wp-content/sii-boleta-dte/private`) con reglas `.htaccess` y enlaces firmados de corta duración.
- Habilita HTTPS y limita las capacidades de WordPress a usuarios administradores para todo el módulo tributario.

## Contribuir

1. Haz *fork* y crea una rama descriptiva.
2. Ejecuta `composer test` y `composer phpcs` antes de abrir el pull request.
3. Documenta cambios funcionales en `README.md`, `AGENT.md` o `docs/` según corresponda.

## Licencia

GPL v2 o posterior. Consulta los encabezados de cada archivo para más detalles.



