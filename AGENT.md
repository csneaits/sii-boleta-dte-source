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
- **Checkout → Emisión automática**: `Presentation\WooCommerce\CheckoutFields` captura RUT/tipo DTE. Al completar el pedido, `Infrastructure\WooCommerce\Woo` orquesta el caso de uso: prepara datos, usa `Infrastructure\Engine\LibreDteEngine` para generar XML, obtiene token con `Infrastructure\TokenManager`, envía vía `Infrastructure\Rest\Api`, guarda `trackId` y activa `Infrastructure\PdfGenerator` para entregar PDF y correos.
- **Emisión manual y gestión operativa**: páginas de `Presentation\Admin` permiten cargar CAF, generar DTE ad-hoc, monitorear cola, revisar logs y ejecutar diagnósticos contra el SII.
- **Procesamiento diferido**: `Application\Queue` y `Application\QueueProcessor` persisten trabajos en `sii_boleta_dte_queue`. El cron `sii_boleta_dte_process_queue` (configurado en `Infrastructure\Cron`) consume pendientes y reintenta con backoff.
- **Reportes y comercio diario**: `Application\LibroBoletas` y `Application\RvdManager` generan libros electrónicos y Resúmenes de Ventas Diarias reutilizando la misma infraestructura de token, API y cola.

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
- Proteger datos sensibles (RUT, XML firmados, certificados) respetando permisos de WordPress y minimizando exposición.

## Lineamientos para nuevas intervenciones del agente
- Reutiliza el contenedor para acceder a servicios; evita instanciar dependencias manualmente salvo en tests controlados.
- Al tocar flujos de emisión o cola, agrega pruebas en `tests/` o scripts de integración y ejecuta `composer test`.
- Respeta estándares de código WordPress/PSR aplicando `composer phpcs` antes de entregar cambios.
- Documenta ajustes funcionales relevantes en `README.md` o `docs/` y actualiza este archivo si cambian supuestos de arquitectura o negocio.
- Verifica scripts de build al introducir nuevas dependencias o assets para no romper el empaquetado del plugin.
