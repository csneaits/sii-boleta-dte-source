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

## Cómo usar `AGENT.md`

`AGENT.md` es un documento operativo pensado para que desarrolladores, mantenedores, QA y operaciones encuentren rápidamente decisiones, atajos y acuerdos internos del proyecto.

Qué encontrarás:
- Resumen del contexto técnico (stack, arquitectura, piezas críticas).
- Notas de diseño, decisiones recientes y recomendaciones de pruebas.
- Perfiles de agente (Developer, Maintainer, QA, Ops) enlazados desde `AGENT.md`.

Cómo usarlo:
- Consulta `AGENT.md` antes de cambiar comportamiento crítico o contratos públicos.
- Para añadir una nota: crea un PR que actualice `AGENT.md` (o añade un nuevo archivo en `docs/agent-profiles/` si es un perfil específico).
- Los cambios operativos o de políticas deben registrarse en `AGENT.md` para referencia futura.

Ubicación de perfiles y helpers de tests:
- Perfiles: `docs/agent-profiles/`.
- Helpers de tests (stubs WP): `tests/_helpers/wp-fallbacks.php` (cargado desde `tests/bootstrap.php`).

Mantén `AGENT.md` como la fuente de verdad para decisiones de equipo pequeñas y operativas; documentaciones más largas o formales siguen en `README.md` o `docs/`.

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
- **Simulación de envíos en desarrollo**: desde Ajustes → Ambiente se puede forzar que los envíos al SII respondan como éxito o error cuando el ambiente activo es Desarrollo, ideal para probar la cola sin contactar servicios reales.
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

## Uso de plantillas y script de agente

Hemos añadido plantillas de prompts para orquestar sesiones multi-agente (Coordinador, Analista, Codificador, Tester, Resumidor) en la carpeta `templates/` y una guía en `docs/agent-orchestration.md`.

También hay un script auxiliar que imprime o copia al portapapeles la plantilla seleccionada:

```bash
./scripts/agent_prompt.sh <role> [--copy]
# Roles disponibles: coordinator, analyst, coder, tester, summarizer
```

Ejemplos:

```bash
# Mostrar la plantilla del tester
./scripts/agent_prompt.sh tester

# Mostrar y copiar al portapapeles (requiere xclip, wl-copy o pbcopy)
./scripts/agent_prompt.sh coordinator --copy
```

Notas:
- Las plantillas están en `templates/agent-<role>.md`.
- Si usas la opción `--copy`, el script buscará `xclip`, `wl-copy` o `pbcopy` según tu sistema.
- Haz el script ejecutable si es necesario:

```bash
chmod +x scripts/agent_prompt.sh
```

Consulta `docs/scenarios/tester-scenario.md` para un ejemplo de uso específico del rol Tester.

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

## Arquitectura de Procesamiento Asíncrono (Q4 2025)

Para mejorar la robustez y el rendimiento del plugin, se ha refactorizado el sistema de generación de DTE para operar de forma completamente asíncrona y unificada, tanto para emisiones manuales como para las generadas desde WooCommerce.

### Flujo Unificado de Encolamiento

1.  **Disparador (Trigger):** Un DTE es solicitado, ya sea manualmente desde la página "Generar DTE" o automáticamente al completarse un pedido en WooCommerce.
2.  **Encolamiento Inmediato:** En lugar de intentar un envío síncrono, el sistema ahora **siempre** encola la tarea. Se genera el XML, se reserva un folio, y la tarea se guarda en la tabla de la cola (`wp_sii_boleta_dte_queue`) para su procesamiento en segundo plano.
3.  **Registro Visual Instantáneo:** Simultáneamente, se crea una entrada en la tabla de logs (`wp_sii_boleta_dte_logs`) con estado "En cola". Esto permite que el DTE aparezca inmediatamente en el panel de control, proporcionando una respuesta visual al usuario sin demoras.

### Procesador de Tareas (Cron)

-   **Frecuencia:** La tarea programada (cron) que procesa la cola se ejecuta cada **5 minutos** para mayor agilidad.
-   **Seguridad de Concurrencia:** Se ha implementado un sistema de bloqueo (locking) mediante Transients de WordPress. Esto previene condiciones de carrera, asegurando que solo un proceso pueda procesar la cola a la vez, incluso en sitios con alto tráfico o configuraciones de cron personalizadas.
-   **Lógica de Reintentos:** Si un trabajo falla, el procesador aplica la siguiente lógica:
    -   **Reintentos Automáticos:** Se reintenta hasta 3 veces con un intervalo de 2 minutos entre cada intento.
    -   **Fallo Persistente:** Después de 3 intentos fallidos, el trabajo se marca como "fallido" pero permanece en la cola, esperando una acción manual del administrador (reintentar, cancelar, etc.) desde el panel de control.

## Buenas prácticas al usar XmlStorage en tests

1. Cuando uses `XmlStorage::store($path)` en tests, recuerda que su semántica es mover el archivo fuente al directorio protegido:
   - Intentará `rename($source, $dest)` y, si falla, hará `copy($source, $dest)` seguido de `unlink($source)`.
   - Por eso, después de llamar a `store()` el archivo original puede ya no existir.

2. Para limpiar archivos temporales en tests, siempre comprueba existencia antes de eliminar:

```php
if ( file_exists($tmpFile) ) {
    unlink($tmpFile);
}
```

3. Alternativa segura: captura y utiliza la ruta retornada por `XmlStorage::store()` y elimina esa ruta si existe. Ejemplo:

```php
$stored = XmlStorage::store($tmpFile);
if ( ! empty($stored['path']) && file_exists($stored['path']) ) {
    unlink($stored['path']);
}
```

4. Mantén los helpers de WordPress de los tests centralizados en `tests/_helpers/wp-fallbacks.php` y evita insertar helpers de testing en código productivo.

Estas prácticas evitan advertencias de PHPUnit relacionadas con `unlink()` y mantienen el código productivo limpio.