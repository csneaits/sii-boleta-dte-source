# SII Boleta DTE – Plugin WordPress para emisión de DTE

Plugin modular para WordPress y WooCommerce que emite boletas, facturas y otros Documentos Tributarios Electrónicos (DTE) integrados con el Servicio de Impuestos Internos (SII) de Chile. El núcleo abstrae la firma digital, la gestión de folios, la generación de PDF y el intercambio con las APIs del SII para que la interfaz sólo coordine los flujos de negocio.

## Características principales

- **Integración completa con el SII**: genera tokens, firma los XML con `xmlseclibs`, envía DTE, libros electrónicos y Resúmenes de Ventas Diarias (RVD) utilizando `libredte-lib-core` como motor de renderizado y validación.
  - Para `EnvioRecibos`, el plugin firma con `xmlseclibs` (RSA‑SHA256) por defecto. Opcionalmente puede delegar la firma a LibreDTE si la biblioteca expone un firmador compatible; activa la preferencia en Ajustes → Certificado y CAF.
- **Gestión de folios y CAF**: administra rangos autorizados en base de datos, soporta múltiples CAF por tipo de documento y expone una interfaz para cargarlos o reemplazarlos.
- **Almacenamiento seguro de XML y PDF**: mueve automáticamente los archivos generados a directorios protegidos (`wp-content/uploads/sii-boleta-dte-secure/` para XML y `wp-content/sii-boleta-dte/private/` para PDF), crea reglas `.htaccess`, firma URLs temporales y permite migrar archivos heredados.
- **Cola persistente de trabajos**: conserva envíos pendientes en la tabla `sii_boleta_dte_queue`, ejecuta reintentos controlados y ofrece procesamiento manual, automático (cron) y migración hacia el almacenamiento seguro.
- **Integración con WooCommerce**: añade campos en el checkout, genera DTE al completar el pedido, adjunta el PDF en el correo y publica enlaces de descarga firmados para clientes y operadores.
- **Panel administrativo completo**: incluye páginas para ajustes, panel de control, generación manual, carga de CAF, monitoreo de la cola, diagnósticos, visor de logs y una guía de certificación con checklist interactiva.
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

## Transporte WS de LibreDTE (opcional)

Si activas en Ajustes → Certificado y CAF la opción “Usar cliente WS de LibreDTE”, el cliente `Infrastructure/Rest/Api` intentará enviar y consultar el estado de DTE/Libros/EnvioRecibos a través del componente WS (SiiLazy) del núcleo de LibreDTE cuando esté disponible.

- Envíos preferidos por WS: `send_dte_to_sii`, `send_libro_to_sii`, `send_recibos_to_sii`.
- Consulta de estado: `get_dte_status`.
- Fallback: si el componente WS no existe o ocurre cualquier error de transporte, el plugin vuelve automáticamente a la ruta HTTP actual sin intervención del usuario.
- Logs: los `trackId` y estados se registran igual que en el flujo HTTP, manteniendo la trazabilidad.

Nota: esta preferencia es independiente de la opción “Firmar EnvioRecibos con LibreDTE (si disponible)”, que controla únicamente quién firma el XML de EnvioRecibos (LibreDTE o `xmlseclibs`).

## Acceso centralizado a LibreDTE y factorías oficiales

- El plugin accede al núcleo de LibreDTE a través de `Infrastructure\LibredteBridge`, que usa `libredte_lib()` cuando está disponible y hace fallback a `Application::getInstance()`. El bridge aplica el entorno desde Ajustes y entrega acceso al `PackageRegistry`.
- Preferimos las factorías oficiales del paquete `TradingParties`:
  - `EmisorFactory->create([...])` (con mapeo conservador: rut, razón social, giro, dirección, comuna, ciudad, acteco, teléfono, email) con fallback a `new Emisor($rut, $razon)`,
  - `ReceptorFactory` integrada mediante un `EmptyReceptorProvider` que no rellena con datos ficticios.

### Receptor sin “placeholders”

- Reemplazamos el proveedor por defecto de LibreDTE con `Infrastructure\Engine\EmptyReceptorProvider` para evitar inyecciones de dirección/correo dummy cuando faltan datos. El mapeo fino de receptor se realiza en el payload (pipeline), no dentro del provider.

## Validación y sanitización con LibreDTE (por defecto ACTIVADAS)

- Tras construir el documento, el motor intenta sanitizar y validar contra XSD y firma usando los workers del `DocumentComponent` cuando existen. Todo está protegido por `method_exists` y `try-catch` para no romper la emisión.
- Ajustes → Paso “Validación” expone tres interruptores:
  - `sanitize_with_libredte`, `validate_schema_libredte`, `validate_signature_libredte`.
- Por defecto, si no hay valor guardado, se consideran ACTIVADOS en todos los entornos (opt‑out desmarcando).

## CAF y asignación automática de folio (opcional)

- Cuando activas la opción `auto_folio_libredte` y el documento no trae folio, el engine intenta obtener el siguiente folio + CAF desde el `IdentifierComponent` de LibreDTE. Si falla, vuelve al proveedor de CAF basado en base de datos (`FoliosDb`).

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
4. Cuando el plan de certificación genera `EnvioRecibos`, estos se firman usando la ruta preferida: LibreDTE (si está disponible y activado) o `xmlseclibs` como fallback. Luego se validan contra `EnvioRecibos_v10.xsd` y se encolan para envío.

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

## Cambios recientes y reproducción rápida

Se incorporaron varios arreglos y herramientas de diagnosis orientadas a resolver un fallo donde las boletas (formato tipo ticket) se generaban con páginas en blanco en algunos entornos:

- Eliminación de una llamada a formateador inexistente (`Acteco`) en `resources/templates/.../boleta_ticket.html.twig`.
- Ajustes de template para evitar layouts basados en flex/min-height y uso de `@page` explícito para mejorar compatibilidad con motores HTML→PDF (mPDF en este proyecto).
- Inyección en tiempo de ejecución del tamaño de papel desde `LibreDteEngine` cuando el template es `boleta_ticket` (ajustado a 215.9mm × 225.8mm en la sesión de debugging).
- Mecanismo condicional (gated by WP_DEBUG) que copia la última salida PDF a `wp-content/uploads/sii-boleta-dte/private/last_renders/` y registra la ruta para inspección.
- Fallback PSR-4 autoloader agregado en `sii-boleta-dte.php` para evitar fallos cuando Composer no está presente.
- Pipeline Node simple para minificar assets y su integración opcional en `build.sh`.

Para reproducir localmente:

1. Habilita WP_DEBUG en `wp-config.php`:

  ```php
  define('WP_DEBUG', true);
  ```

1. Genera una boleta desde el flujo de tu instalación (pedido de prueba, vista previa o desde admin).

1. Revisa `wp-content/uploads/sii-boleta-dte/private/logs/debug.log` para ver líneas como:

- `[debug] renderer options=...`
- `[debug] forcing boleta renderer paper=...`
- `[debug] rendered_pdf_copy=/.../last_renders/render_YYYYmmdd_HHis_temp.pdf`

1. Descarga la copia del PDF y valida MediaBox/Producer; si sigue habiendo páginas en blanco, adjunta el PDF o una captura con las propiedades.

Mejoras sugeridas:

- Añadir CI que ejecute PHPUnit y phpcs para detectar regresiones al tocar `PdfGenerator` o `LibreDteEngine`.
- Tests de integración que validen HTML→PDF y que verifiquen MediaBox y número de páginas.
- Considerar empaquetar `vendor/` en los artefactos de release o documentar la necesidad de instalar dependencias con Composer en entornos productivos.
- Añadir una pequeña herramienta/diagnóstico que recupere metadatos (MediaBox, Producer) de los PDFs generados y los registre en logs para revisiones rápidas.

### Rotación de copias de debugging

El plugin ahora incluye un job WP-Cron que elimina diariamente las copias de PDF generadas para debugging ubicadas en `wp-content/uploads/sii-boleta-dte/private/last_renders/`.

- Retención por defecto: 7 días. Para cambiarlo, define `SII_BOLETA_DTE_DEBUG_RETENTION_DAYS` en tu entorno antes de activar el plugin.
- Si no quieres usar WP-Cron en tu instalación, ejecuta manualmente `do_action('sii_boleta_dte_prune_debug_pdfs')` desde WP-CLI o un mu-plugin.

Nota de seguridad: el job de rotación filtra por nombre de fichero y solo eliminará PDFs con patrones típicos de debug/preview (por ejemplo `render_*.pdf`, `debug_*.pdf`, `preview_*.pdf`, `*_temp.pdf`, `last_render_*.pdf`). Los PDFs definitivos guardados por otros subsistemas con nombres distintos no se verán afectados.

## Previsualización y validación de XML (Nueva Funcionalidad)

La página de generación manual de DTE incluye ahora un botón **“Previsualizar XML”** que abre un modal con el XML generado en **modo preview** (sin asignar folio definitivo ni timbre final de envío). Esto permite inspeccionar estructura, encabezados, líneas e importes antes de emitir.

### Cómo usarla

1. Completa los campos del formulario de generación.
2. Haz clic en “Previsualizar XML”.
3. El modal mostrará:

- El XML formateado en un bloque `<pre><code>`.
- Tamaño (bytes) y número de líneas.
- Botones: Copiar, Descargar, Validar XSD.

1. (Opcional) Pulsa “Validar XSD” para ejecutar una validación local contra el esquema (`resources/schemas/DTE_v10.xsd` u otros según el tipo).

### Detalles técnicos

- Endpoint AJAX: `sii_boleta_dte_preview_xml` (requiere nonce `sii_boleta_generate_dte_nonce` y capacidad `manage_options`).
- El backend fuerza `preview=1` y elimina cualquier `folio` suministrado para impedir folio arbitrario.
- El XML devuelto incluye claves: `xml`, `size`, `lines`, `tipo`.
- Validación XSD: endpoint `sii_boleta_dte_validate_xml` recibe `tipo` + `xml` y retorna `valid=true` o lista de errores (línea y mensaje).

### Consideraciones

- El XML de preview no garantiza definitividad de totales sujetos a ajustes finales de timbraje (principalmente folio y timbre). El contenido de ítems, referencias y encabezados sí coincide con el que se enviará si no realizas cambios posteriores.
- No se anonimiza el RUT del emisor ni receptor en el modal (petición explícita del flujo operativo). Asegura que sólo usuarios con privilegios de administración tengan acceso.
- Errores de validación XSD se muestran con la línea aproximada reportada por libxml; pueden variar si se insertan namespaces o comentarios adicionales.

### Extensiones futuras sugeridas

- Opción para resaltar sintaxis (integrar un highlighter ligero si no impacta el peso del bundle).
- Validación incremental (sólo revalidar si cambian secciones críticas, para reducir carga en formularios extensos).
- Descarga alternativa “XML+Metadatos” incluyendo hash y timestamp para auditoría.

## Auditoría de XML (Preview vs Final)

Se incorporaron hooks y un servicio ligero de auditoría para comparar el XML generado en modo **preview** con el XML **final** (ya con folio asignado y antes / durante el envío al SII) ignorando campos volátiles.

### Hooks Disponibles

- `sii_boleta_xml_preview_generated( string $xml, array $context )`
  - Disparado tras generar el XML en modo preview.
  - `$context`: `['tipo'=>int,'folio'=>int]` (folio usualmente 0 en preview manual).

- `sii_boleta_xml_final_generated( string $xml, array $context )`
  - Disparado tras generar el XML final (con folio), antes de registrar logs comparativos.
  - `$context`: `['tipo'=>int,'folio'=>int]`.

### Filtros de Personalización

- `sii_boleta_xml_diff_ignore_paths( array $xpaths, string $phase )`
  - Permite añadir / remover XPaths a ignorar en normalización. `phase` es `preview` o `final`.
  - Por defecto se ignoran (ejemplos):
    - `//Documento//IdDoc/Folio`
    - `//Documento//TED`
    - `//Documento//IdDoc/TmstFirma`
    - `//EnvioDTE/TmstFirmaEnv`
    - Cualquier firma digital `Signature` (`xmldsig`).

### Servicio Interno

`Sii\BoletaDte\Infrastructure\Engine\XmlAuditService` expone:

- `normalize( $xml, $phase )` → XML sin nodos volátiles, atributos ordenados, espacios colapsados.
- `hash( $normalized )` → hash sha256 estable.
- `diff( $normalizedPreview, $normalizedFinal, $limit=200 )` → lista de cambios semánticos básicos (atributos, texto, nodos faltantes / extra).

En modo `WP_DEBUG` se emiten logs:

```text
[xml_audit] preview hash tipo=39 hash=<sha>
[xml_audit] tipo=39 folio=123 hash_preview=<sha1> hash_final=<sha2> diffs=N
```

### Ejemplos de Uso

Registrar acción cuando se genera preview:

```php
add_action( 'sii_boleta_xml_preview_generated', function( $xml, $ctx ) {
    error_log('[AUDIT] Preview tipo=' . ($ctx['tipo'] ?? 'n/a') . ' bytes=' . strlen($xml));
}, 10, 2 );
```

Agregar un XPath adicional a ignorar (por ejemplo una marca de entorno custom):

```php
add_filter( 'sii_boleta_xml_diff_ignore_paths', function( $paths, $phase ) {
    $paths[] = '//*[@data-entorno]';
    return $paths;
}, 10, 2 );
```

### Limitaciones Actuales

- El diff es secuencial (sensible a reorden de nodos hermanos). Para nodos reordenados se reportará como removido / agregado.
- No se persiste hash en la base de datos en la versión inicial (sólo memoria del proceso). Si se requiere persistencia por pedido, se puede ampliar para pedidos WooCommerce.
- No analiza cambios en nodos de firma (se ignoran por diseño).

### Delegación opcional de firma de EnvioRecibos

- Ajuste: “Firmar EnvioRecibos con LibreDTE (si disponible)”.
- Comportamiento: cuando está activo, el motor `LibreDteEngine` intenta firmar el `EnvioRecibos`. Si la versión actual de LibreDTE aún no expone esta capacidad, el plugin continúa usando `xmlseclibs` sin intervención del usuario.

### Futuras Mejores Prácticas (Sugerencias)

- Persistir hash normalizado final asociado a la entidad (pedido / registro) para trazabilidad.
- Implementar diff insensible a orden para colecciones específicas (por ejemplo Detalles).
- Mostrar diff en UI administrativa cuando exista un folio emitido.

## Validación del Sobre EnvioDTE (Nueva Funcionalidad)

Además de la validación del XML individual del `DTE`, ahora el plugin permite validar el **sobre completo** que se enviaría al SII:

- Para Facturas / Notas / Guías (no boletas): `EnvioDTE_v10.xsd` (incluye `DTE_v10.xsd`, `SiiTypes_v10.xsd`, `xmldsignature_v10.xsd`).
- Para Boletas Electrónicas (tipos 39 y 41): `EnvioBOLETA_v11.xsd` (estructura y carátula específicas para boletas, con límites y subtotales particulares).

### Cómo utilizarla

1. Abre el modal con “Previsualizar XML”.
2. Presiona el botón **“Validar Envío”**.
3. El backend construye un sobre mínimo temporal utilizando la raíz adecuada (`EnvioBOLETA` o `EnvioDTE`) que envuelve el DTE actual (sin folio definitivo) y genera una `Caratula` sintética con valores placeholder seguros.
4. Se ejecuta `schemaValidate` contra el XSD correspondiente (`EnvioBOLETA_v11.xsd` o `EnvioDTE_v10.xsd`).
5. El resultado se muestra en el mismo panel de validación que la validación individual.

### Resultado

- Éxito: “Sobre EnvioDTE válido.”
- Error: Lista enumerada de fallos con número de línea aproximado dentro del sobre generado. Las líneas corresponden al XML del sobre temporal (que incluye cabecera `EnvioDTE` + tu documento). Si editas el formulario y regeneras la previsualización, vuelve a presionar “Validar Envío” para recalcular.

### Limitaciones actuales

- Sólo envuelve un documento (el que se está previsualizando). Si el flujo real agrupa múltiples DTE/boletas en un mismo envío, la validación previa seguirá siendo parcial.
- La `Caratula` temporal usa RUT emisor/autorizado y fecha ficticia; su objetivo es permitir que el parser de esquema recorra los imports y reglas estructurales. No valida folios correlativos ni totales agregados de múltiples documentos.
- No firma digitalmente el sobre durante la validación (la firma definitiva se aplica más adelante en el pipeline normal de envío).

### Extensiones sugeridas

- Permitir arrastrar/seleccionar múltiples XML para validar un sobre multi‑DTE / multi‑boleta antes de enviarlo.
- Mostrar diff entre el sobre temporal y el sobre real final (después del envío) reutilizando `XmlAuditService`.
- Incluir validación opcional de firma digital cuando exista un certificado cargado en el entorno de staging.

### Nota visual

Los resultados positivos del XML individual y del sobre se muestran en verde, pero el sobre utiliza un tono más oscuro (#064) para que puedas distinguir rápidamente qué tipo de validación aprobó sin leer el texto.



