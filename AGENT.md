# AGENT

Guía rápida para intervenir en `sii-boleta-dte`, plugin modular de WordPress/WooCommerce que emite Documentos Tributarios Electrónicos (DTE) del SII chileno.

## 1. Contexto y stack

- PHP 8.4+, WordPress 6.x, WooCommerce 8.x (opcional).
- Dependencias centrales (Composer):
  - `libredte/libredte-lib-core` – motor de normalización/timbraje/renderizado.
  - `robrichards/xmlseclibs` – firma XML RSA-SHA256.
  - `twig/twig`, `tecnickcom/tcpdf` – plantillas y PDF.
- Calidad: `phpunit/phpunit` 11.x, `squizlabs/php_codesniffer` + `wp-coding-standards/wpcs`.
- Scripts útiles: `composer install`, `composer test`, `composer phpcs`, `build.sh`, `build.ps1`.

## 2. Arquitectura en producción

- Estilo *ports & adapters* / hexagonal.
  - `src/Domain/`: contratos (`DteEngine`, `DteRepository`), entidades (`Dte`, `Rut`).
  - `src/Application/`: casos de uso (`FolioManager`, `QueueProcessor`, `LibroBoletas`, `RvdManager`).
  - `src/Infrastructure/`: adaptadores SII, LibreDTE, WordPress, persistencia, CLI/cron, seguridad.
  - `src/Presentation/`: UI admin, endpoints públicos y activos.
- Contenedor principal: `Infrastructure\Factory\Container`.
- Repositorios personalizados: `Infrastructure\Persistence\FoliosDb`, `QueueDb`, `LogDb`.

## 3. Piezas críticas y su estado actual

| Componente | Archivo | Detalles clave |
| ---------- | ------- | -------------- |
| Motor DTE | `src/Infrastructure/Engine/LibreDteEngine.php` | Normaliza `Detalle` antes de llamar a LibreDTE (garantiza `NmbItem` string), usa `isSequentialArray()` como sustituto de `array_is_list`, intenta sanitizar/validar mediante workers cuando existen, genera XML/PDF y mantiene *fallbacks* para firmas (`xmlseclibs`). |
| CAF bridge | `src/Infrastructure/Engine/Caf/LibreDteCafBridgeProvider.php` | Sincroniza con `IdentifierComponent` de LibreDTE y persiste el folio observado +1 en `Settings` para evitar saltos cuando se vuelve al proveedor histórico (`FoliosDb`). |
| API SII | `src/Infrastructure/Rest/Api.php` | Maneja envíos (DTE, libros, RVD) con rescate automático de tokens (`Infrastructure\TokenManager`). |
| Cola | `src/Application/QueueProcessor.php` | Reintentos con *backoff*, migración a almacenamiento seguro y logging. Tests clave: `tests/Application/QueueProcessorTest.php`. |
| PDFs | `src/Infrastructure/Engine/PdfGenerator.php`, plantillas en `resources/templates/` | Usa LibreDTE para renderizar; normalización reciente asegura etiquetas de retención (`ImptoReten`) en plantillas estándar. |
| Bridge LibreDTE | `src/Infrastructure/LibredteBridge.php` | Resuelve `libredte_lib()` o `Application::getInstance()`, aplica entorno y expone `PackageRegistry`. |

## 4. Invariantes recientes (2025 Q3)

- `LibreDteEngine` debe:
  - Normalizar `Detalle` (array lista, strings) antes y después de construir el `DocumentBag`.
  - Usar el helper `isSequentialArray()` en lugar de `array_is_list()` para compatibilidad PHP < 8.1.
  - Reintentar sanitización/validación únicamente si los workers existen (`method_exists`).
- `LibreDteCafBridgeProvider` debe actualizar la caché local de folios (`Settings::update_last_folio_value`) incluso cuando LibreDTE sólo entrega folio mínimo/máximo.
- PDF de retenciones: garantiza que `ImptoReten` se normalice a texto para que aparezcan las etiquetas en el render.
- Auto folio dev/cert: habilitado a través del worker de LibreDTE; si falla, se vuelve al proveedor local sin perder secuencia.

## 5. Pruebas y flujos recomendados

Ejecuta PHPUnit focalizado al tocar cada módulo:

- `vendor/bin/phpunit tests/Infrastructure/Engine/AutoFolioTest.php`
- `vendor/bin/phpunit tests/Infrastructure/Engine/PdfRetentionRenderTest.php`
- `vendor/bin/phpunit tests/Infrastructure/Engine/LibreDteCafBridgeProviderTest.php`
- `vendor/bin/phpunit tests/Application/QueueProcessorTest.php`

Para validaciones completas:

```bash
composer test
composer phpcs
```

La suite arranca WordPress mínimo desde `tests/bootstrap.php` con stubs (`WP_Error`, filtros, cron) y dobles en memoria.

## 6. Observabilidad y seguridad

- Logs en `wp-content/uploads/sii-boleta-dte/private/logs/debug.log` cuando `WP_DEBUG` está activo.
- PDFs/XML definitivos en `wp-content/sii-boleta-dte/private/` y `wp-content/uploads/sii-boleta-dte-secure/` con enlaces firmados temporales.
- Copias de debug (`last_renders/`) se eliminan vía cron (`sii_boleta_dte_prune_debug_pdfs`) — retención por defecto 7 días (configurable con `SII_BOLETA_DTE_DEBUG_RETENTION_DAYS`).
- Certificados `.pfx` y claves se almacenan cifrados; nunca exponer rutas públicas sin token/nonce.

## 7. Lineamientos para nuevas intervenciones

1. Reutiliza el contenedor y los servicios existentes; evita instanciar dependencias manualmente salvo en pruebas controladas.
2. Protege compatibilidad con versiones anteriores de LibreDTE: usa `method_exists` y conserva *fallbacks* de xmlseclibs/CAF.
3. Cada cambio funcional debe acompañarse de pruebas (unitarias o de integración). Prioriza los tests citados en la sección 5.
4. Ejecuta `composer phpcs` antes de entregar; seguimos WPCS sin *warnings*.
5. Documenta los cambios relevantes en `README.md`, `AGENT.md` y/o `docs/`. Mantén actualizados los requisitos y nuevas banderas de Ajustes.
6. No expongas rutas privadas ni logs sensibles en producción; los enlaces firmados deben seguir generándose mediante `SignedUrlService`.
7. Para nuevas integraciones de LibreDTE, encapsula la lógica en el bridge o en adaptadores dedicados y evita modificar directamente `vendor/`.

## 8. Atajos diarios

- Empaquetado rápido: `./build.sh` (Linux/macOS) o `.\build.ps1` (Windows PowerShell).
- Validación puntual de retenciones: `vendor/bin/phpunit tests/Infrastructure/Engine/PdfRetentionRenderTest.php --filter test_pdf_contains_retention_labels`.
- Verificación de auto folio: `vendor/bin/phpunit tests/Infrastructure/Engine/AutoFolioTest.php`.
- Estado de cola manual: ejecutar `do_action('sii_boleta_dte_process_queue')` desde WP-CLI o un mu-plugin.

## 9. Próximas mejoras sugeridas

- Agregar CI (GitHub Actions) con PHPUnit y phpcs para detectar regresiones.
- Tests de integración HTML→PDF que verifiquen MediaBox y número de páginas en boletas.
- Persistir hashes normalizados del XML final para auditorías y comparaciones con previews.
- Extender validación de sobres multi-DTE y exponer comparaciones en UI administrativa.

Mantén este documento sincronizado con el estado real del repositorio cada vez que se apliquen cambios significativos.

## 10. Nota rápida sobre tests y almacenamiento seguro

- Stubs de WordPress para la suite de tests:
  - Se centralizaron en `tests/_helpers/wp-fallbacks.php` y se cargan desde `tests/bootstrap.php`.
  - Contienen implementaciones mínimas (sanitizers, `esc_*`, `checked`, `wp_nonce_field`, etc.) diseñadas solo para tests.
  - No agregar estos fallbacks en código de producción; si necesitas más helpers, añádelos en `tests/_helpers/`.

- Comportamiento de `XmlStorage::store()`:
  - Semántica: intenta mover el archivo fuente al directorio protegido (primero `rename`). Si `rename` falla, hace `copy` + `unlink` como fallback.
  - Efecto en tests: después de `XmlStorage::store($path)` el fichero original puede no existir. Las pruebas que limpian archivos deben comprobar `file_exists(...)` antes de `unlink(...)`.
  - Recomendación: cuando escribas tests que creen archivos temporales y los pasen a `XmlStorage`, usa `file_exists()` antes de `unlink()` o captura la ruta retornada por `store()` y elimínala solo si existe.

Esta nota sirve para evitar advertencias en PHPUnit y para mantener el código productivo libre de helpers de test.

---

## Perfiles de agente (rápido)

Hemos añadido perfiles breves orientados a distintos roles: Developer, Maintainer, QA y Ops. Estos documentos viven en `docs/agent-profiles/` y contienen pasos rápidos, checklists y consejos específicos para cada rol.

- `docs/agent-profiles/developer.md` — enfoque en desarrollo, tests y debugging.
- `docs/agent-profiles/maintainer.md` — guía para revisar PRs, releases y criterios de aceptación.
- `docs/agent-profiles/qa.md` — reproducir errores, casos críticos y automatización.
- `docs/agent-profiles/ops.md` — despliegue, monitoreo y procedimientos de rollback.

Consulta el perfil que corresponda según tu tarea para obtener instrucciones concisas.
