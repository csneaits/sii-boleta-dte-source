# AGENT

## Propósito del repositorio
- Plugin modular de WordPress/WooCommerce para emitir Documentos Tributarios Electrónicos (DTE) integrados con el Servicio de Impuestos Internos de Chile.
- El núcleo abstrae la firma digital, el uso de CAF, la generación de PDF y el intercambio con los servicios REST/SOAP del SII para que la interfaz de WordPress sólo coordine flujos de negocio.
- La carpeta `sii-boleta-dte/` contiene el código principal del plugin; `docs/` reúne notas funcionales complementarias.

## Stack tecnológico
- `PHP >= 8.4`, WordPress 6.x, WooCommerce 8.x y MySQL/MariaDB como base de datos vía `$wpdb`.
- Librerías clave instaladas con Composer: `libredte/libredte-lib-core` (motor de timbraje y validación), `robrichards/xmlseclibs` (firma XML), `twig/twig` (renderizado), `tecnickcom/tcpdf` (PDF). Calidad y pruebas con `phpunit` y `phpcs` + `WPCS`.
- Integración con APIs del SII mediante `Infrastructure\Rest\Api` y manejo de tokens con `Infrastructure\TokenManager`.
- Scripts de automatización: `composer test`, `composer phpcs`, `build.sh` y `build.ps1` para empaquetar el plugin.

## Arquitectura y módulos
- Arquitectura hexagonal (ports & adapters). `Domain/` y `Application/` concentran casos de uso, entidades y reglas. `Infrastructure/` y `Presentation/` implementan adaptadores para SII, base de datos, WordPress UI y WooCommerce.
- Contenedor de dependencias (`Infrastructure\Factory\Container`) registra servicios para inyección perezosa: ajustes, motor LibreDTE, API SII, gestor de folios, cola, generador PDF, páginas administrativas, etc.
- Persistencia especializada en tablas personalizadas (`QueueDb`, `FoliosDb`, `LogDb`) con fallback en memoria para pruebas.

## Interacción entre componentes
- **Checkout → Emisión automática**: `Presentation\WooCommerce\CheckoutFields` captura RUT/tipo DTE. Al completar el pedido, `Infrastructure\WooCommerce\Woo` orquesta el caso de uso: prepara datos, usa `Infrastructure\Engine\LibreDteEngine` para generar XML, obtiene token con `Infrastructure\TokenManager`, envía vía `Infrastructure\Rest\Api`, guarda `trackId`, persiste PDF en `Infrastructure\WooCommerce\PdfStorage` y activa `Infrastructure\PdfGenerator` para entregar PDF y correos.
- **Emisión manual y gestión operativa**: páginas de `Presentation\Admin` permiten cargar CAF, generar DTE ad-hoc, monitorear cola, revisar logs y ejecutar diagnósticos contra el SII.
- **Procesamiento diferido**: `Application\Queue` y `Application\QueueProcessor` persisten trabajos en `sii_boleta_dte_queue`. El cron `sii_boleta_dte_process_queue` (configurado en `Infrastructure\Cron`) consume pendientes y reintenta con backoff mientras migra XML/PDF al almacenamiento seguro.
- **Compartición segura**: `Infrastructure\Rest\SignedUrlService` crea enlaces temporales para boletas, `Infrastructure\Rest\Endpoints` sirve HTML desde `uploads/sii-boleta-dte-secure` y `Presentation\Admin\Ajax` valida claves/nonce antes de transmitir PDFs del directorio privado `wp-content/sii-boleta-dte/private`.

## Patrones de diseño y prácticas
- Ports & Adapters / Hexagonal para aislar dominio de WordPress y servicios externos.
- Inversión de dependencias mediante contenedor propio, interfaces (`Domain\DteRepository`, `Domain\Logger`) y factorías.
- Repositorios para folios/DTE, objetos de valor para datos tributarios, servicios de dominio para validaciones.
- Estrategia de resiliencia basada en cola persistente, reintentos controlados y logging centralizado (`Shared\SharedLogger`).
- Cobertura por pruebas unitarias bootstrappeadas en `tests/bootstrap.php`, con uso de dobles in-memory para evitar dependencias de WordPress/SII.

## Reglas de negocio y expectativas
- Cumplir normativas del SII: uso correcto de CAF vigentes, firmas válidas, envíos en ambientes certificados y producción.
- Asegurar trazabilidad completa: cada DTE debe almacenar XML, PDF, `trackId`, folio y bitácora de eventos dentro del pedido o tablas auxiliares.
- Mantener la integridad de folios: validar rangos, evitar saltos y bloquear reutilización hasta confirmar rechazo.
- Tolerar fallas externas: cualquier problema con SII o WooCommerce debe registrarse y permitir reintentos seguros desde el panel.
- Proteger datos sensibles (RUT, XML firmados, certificados) respetando permisos de WordPress y minimizando exposición. No deshabilitar el almacenamiento seguro ni exponer rutas públicas sin validaciones de token/nonce.

## Lineamientos para nuevas intervenciones del agente
- Reutiliza el contenedor para acceder a servicios; evita instanciar dependencias manualmente salvo en tests controlados.
- Al tocar flujos de emisión, cola, almacenamiento seguro o migraciones, agrega pruebas en `tests/` o scripts de integración y ejecuta `composer test`.
- Respeta estándares de código WordPress/PSR aplicando `composer phpcs` antes de entregar cambios.
- Documenta ajustes funcionales relevantes en `README.md` o `docs/` y actualiza este archivo si cambian supuestos de arquitectura, negocio o seguridad.
- Verifica scripts de build al introducir nuevas dependencias o assets para no romper el empaquetado del plugin.

## Cambios recientes (resumen de la sesión de debugging)

- Eliminado bloque `Acteco` del template `boleta_ticket.html.twig` para evitar una llamada a un formateador inexistente que provocaba excepciones en tiempo de ejecución.
- Normalización en tiempo de ejecución de las opciones de renderizado en `LibreDteEngine`: si sólo se provee `template` se fija una estrategia por defecto para mantener compatibilidad con PdfGenerator y con las pruebas existentes.
- Inyección de tamaño de papel (`paper`) desde `LibreDteEngine` cuando el template es `boleta_ticket` para forzar la MediaBox esperada por motores HTML→PDF (ajustada a 215.9mm × 225.8mm según ejemplo del usuario).
- Ajustes en `resources/templates/.../boleta_ticket.html.twig` para evitar layouts basados en flex/min-height (fuente conocida de páginas en blanco con mPDF), se añadió un diseño rígido y compacto con @page explícito.
- Se añadió un mecanismo seguro y condicionado por `WP_DEBUG` para copiar el PDF generado a `wp-content/uploads/sii-boleta-dte/private/last_renders/` y registrar la ruta en el log para inspección manual.
- Añadido un autoloader PSR-4 de contingencia en `sii-boleta-dte.php` para evitar fallos fatales cuando Composer no está presente en la instalación.
- Incorporado un pipeline Node simple (`sii-boleta-dte/package.json` + `scripts/build-assets.js`) para minificar CSS/JS de `Presentation/assets` y añadido su ejecución opcional en `build.sh`.

## Cómo reproducir localmente (rápido)

1. Habilita WP_DEBUG en tu instalación de WordPress (wp-config.php):

    define('WP_DEBUG', true);

2. Instala/activa el plugin (si no está activo). Genera una boleta desde el flujo que utilices (preview, test order o desde el admin).

3. Revisa `wp-content/uploads/sii-boleta-dte/private/logs/debug.log` o el log de WordPress: deberías ver entradas similares a

    - [debug] renderer options=...  (muestra las opciones pasadas al renderer)
    - [debug] forcing boleta renderer paper=...  (cuando `boleta_ticket` aplica tamaño)
    - [debug] rendered_pdf_copy=/.../last_renders/render_YYYYmmdd_HHis_temp.pdf  (copia del PDF para inspección)

4. Descarga la copia del PDF y verifica las propiedades del archivo (Producer, MediaBox). Si el PDF tiene páginas en blanco, pega el PDF aquí o sube un screenshot con las propiedades para que pueda iterar.

## Mejoras recomendadas (priorizadas)

1. Integrar una tarea de CI con PHPUnit y phpcs que ejecute la suite básica en un contenedor PHP/WordPress para detectar regresiones por cambios en `PdfGenerator` o `LibreDteEngine`.
2. Añadir tests de integración que generen una muestra de boleta con HTML -> PDF y validen que la MediaBox y el número de páginas son los esperados (usa un binario headless o una imagen mínima de PHP/mPDF en CI).
3. Reforzar locking/verificación de la presencia de `vendor/autoload.php` en instalaciones productivas o documentar el requisito en README (o distribuir plugin ya empaquetado con `vendor/`).
4. Probar con otros motores HTML→PDF (wkhtmltopdf, PrinceXML, headless Chrome) si mPDF sigue generando páginas en blanco en ambientes concretos.
5. Añadir un pequeño script de validación que abra la copia del PDF y extraiga MediaBox/Producer para el operador (por ejemplo, un comando PHP con ext-imagick o un binario `pdfinfo`) y registrar esos metadatos en el log.

## Notas de seguridad y privacidad

- Las copias de PDF guardadas para debugging quedan en un directorio bajo `uploads/`; asegurarse de no dejar estas copias en instalaciones públicas o deshabilitar este comportamiento cuando no sea necesario.
- Mantener permisos estrictos en `wp-content/uploads/sii-boleta-dte/private` y rotar/limpiar las copias de debug periódicamente.

### Limpieza automática de copias de debug (rotación)

Se añadió un job de WordPress Cron que ejecuta diariamente la limpieza de PDFs de debugging almacenados en `uploads/sii-boleta-dte/private/last_renders/`.

- Retención por defecto: 7 días. Cambia el valor definiendo la constante `SII_BOLETA_DTE_DEBUG_RETENTION_DAYS` antes de cargar el plugin (p. ej. en un mu-plugin o en wp-config.php si lo deseas).
- Activación/desactivación: el job se programa al activar el plugin y se limpia al desactivarlo.
- El job solo borra archivos `.pdf` cuya fecha de modificación supere el periodo de retención y escribe un registro de depuración por cada fichero eliminado.

Importante: el job solo eliminará archivos que coincidan con patrones de nombre típicos de copias de debugging/preview (por ejemplo: `render_*.pdf`, `debug_*.pdf`, `preview_*.pdf`, `*_temp.pdf`, `last_render_*.pdf`). Esto protege los PDFs definitivos que puedan almacenarse con otros nombres o en otras rutas.

Operacionalmente, verifica que el cron de WP se ejecute en tu instalación (wp-cron activo o cron real) para que la rotación ocurra. Si quieres ejecutar la limpieza manualmente puedes invocar `do_action('sii_boleta_dte_prune_debug_pdfs')` desde WP-CLI o un mu-plugin.
