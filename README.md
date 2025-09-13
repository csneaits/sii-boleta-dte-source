# SII Boleta DTE – Guía Completa (Chile)

Plugin WordPress para emisión de DTE (boletas, facturas, guías, notas) con integración al SII de Chile. Soporta firma, timbraje con CAF, envío/consulta, almacenado por RUT, integración con WooCommerce, previsualización, y PDF con LibreDTE.

## Estructura

- `sii-boleta-dte/` – carpeta del plugin con todos los archivos de código (PHP) que implementan la integración con el Servicio de Impuestos Internos de Chile, generación de XML, firma digital, manejo de folios, integración con WooCommerce, tareas cron para el Resumen de Ventas Diarias (RVD) y representación en PDF/HTML.
  - `src/` – código fuente organizado según una arquitectura **hexagonal** (ports & adapters) que separa la lógica de negocio de las dependencias externas.
    - `Domain/` – entidades y reglas de negocio puras.
    - `Application/` – casos de uso que orquestan el dominio.
    - `Infrastructure/` – adaptadores concretos (WordPress, WooCommerce, APIs, persistencia).
    - `Admin/` – interfaz de administración de WordPress, considerada un adaptador de presentación.
    - `Core/` – punto de arranque del plugin y registro de servicios.
  - `resources/` – plantillas y recursos de LibreDTE. Copia aquí los `resources` de LibreDTE si tu build los busca fuera de vendor.
  - `resources/templates/billing/document/renderer/estandar.html.twig` – plantilla Twig adaptada del diseño original de LibreDTE, con soporte de logo y detalle y clases de formato A4/80mm.
- `build.sh` – script de empaquetado para sistemas Linux/macOS. Genera un ZIP instalable bajo `dist/` con el número de versión que aparece en el encabezado del plugin.
- `build.ps1` – script de empaquetado para PowerShell (Windows). Cumple la misma función que `build.sh`, pero adaptado a entornos Windows.

### Opciones de mejora

Aunque la distribución actual permite trabajar con WordPress, aún mezcla módulos heredados con las capas hexagonales. Algunas ideas para consolidar el diseño:

- Migrar gradualmente los componentes heredados a adaptadores dentro de `Infrastructure/` (por ejemplo `Infrastructure/WooCommerce`, `Infrastructure/Rest`, `Infrastructure/Cli`).
- Definir interfaces en `Domain` y registrar sus implementaciones mediante fábricas o un contenedor de dependencias.
- Extraer una capa de **presentación** separada (por ejemplo `UI/` o `Presentation/`) para desacoplar `Admin/` de WordPress y facilitar pruebas aisladas.
- Agrupar utilidades compartidas (logging, helpers) en un paquete `Shared/` para evitar dependencias circulares y reutilizar componentes.

## Cómo compilar el plugin

Para generar el ZIP instalable del plugin desde el código fuente, utiliza uno de los scripts de build.

### En Linux o macOS

```bash
chmod +x build.sh
./build.sh
```

Se creará un archivo ZIP en la carpeta `dist/` con el nombre `sii-boleta-dte-<versión>.zip`, listo para subir o instalar en WordPress.

### En Windows (PowerShell)

```powershell
Set-ExecutionPolicy -ExecutionPolicy RemoteSigned -Scope Process
./build.ps1
```

Se creará el archivo ZIP en la carpeta `dist\` con el nombre correspondiente.

## Dependencias y herramientas de desarrollo

Requisitos mínimos: PHP 8.4 con extensiones `soap`, `mbstring`, `openssl`.

Las bibliotecas externas y herramientas se gestionan con Composer. Desde la carpeta del plugin (`sii-boleta-dte/`) ejecuta:

```bash
composer install
```

Esto instalará `xmlseclibs`, LibreDTE (core) y utilidades para pruebas y estándares de código.

Notas de actualización a PHP 8.4:
- Se elevó el requisito mínimo a PHP 8.4 (composer.json y cabecera WP).
- PHPUnit actualizado a ^11 (ajusta tu entorno CI a PHP 8.4 + PHPUnit 11).
- Se inicializan variables intermedias para evitar notices en runtimes estrictos.

Motor DTE: el plugin está forzado a LibreDTE (sin fallbacks nativos). Si la lib no está disponible o no tiene recursos, verás un error explícito.

LibreDTE y recursos:
- Algunas instalaciones requieren copiar recursos (templates/datos) desde vendor a `sii-boleta-dte/resources/` porque la lib los busca ahí: por ejemplo `resources/templates/billing/document/renderer/estandar.html.twig` y `resources/data/repository/*`.

### Pruebas y calidad

- `composer test` ejecuta los tests con PHPUnit.
- `composer phpcs` verifica el código con WordPress Coding Standards.

## Configuración y diagnóstico

En Ajustes → SII Boletas configure:

- Emisor: `RUT`, `Razón Social`, `Giro`, `Dirección`, `Comuna`, `Acteco`, `CdgSIISucur` (opcional)
- Certificado: `Ruta` (`.p12/.pfx`) y `Contraseña`
- CAF: rutas por tipo de DTE (39/41/33/34/52/56/61)
- Ambiente: `test` (CERT) o `production`
- PDF: `Formato (A4/80mm)`, `Mostrar logo`, `Nota al pie`

Además, la pantalla incluye un diagnóstico con checklist (config general, OpenSSL, CAF, LibreDTE) y prueba de autenticación SII.

## Configuración de tipos de documento para el checkout

Desde la página de ajustes del plugin es posible definir qué tipos de documentos se ofrecen a los clientes en el formulario de compra de WooCommerce. Actualmente se incluyen:

- Boleta Electrónica
- Factura Electrónica
- Factura Exenta
- Guía de Despacho

Active o desactive cada opción según las necesidades de su negocio.

## SMTP / FluentSMTP (Perfiles)

- Selector de perfiles SMTP: Ajustes → Envío de Correos → Perfil SMTP.
- Auto‑detección de conexiones de FluentSMTP; se muestra “Nombre <email>” por cada perfil.
- Envío: el plugin establece From/Return‑Path del perfil elegido; FluentSMTP enruta por remitente.

Hooks:
- `sii_boleta_available_smtp_profiles` (filter): retorna lista de perfiles.
- `sii_boleta_setup_mailer` (action): recibe `$phpmailer` y `$profile` para configurar el mailer si no usas FluentSMTP.

## Generación automática del token

El plugin puede obtener de manera automática el token de autenticación del SII utilizando el certificado digital configurado en los ajustes:

1. Indique la ruta y contraseña del certificado en la página de configuración.
2. Al enviar un DTE, el plugin solicitará la semilla, la firmará y recuperará el token, guardándolo en los ajustes para reutilizarlo mientras sea válido.


## Ambientes: Integración y Producción

El plugin puede operar contra dos servicios distintos del SII, seleccionables en el campo **Ambiente** de la página de ajustes:

- **Integración (test)**: utiliza los servidores de prueba del SII (`maullin.sii.cl`). Selecciónalo durante el desarrollo, las pruebas internas y el proceso de certificación. Aquí se emplean certificados y archivos CAF de prueba y los documentos no tienen validez tributaria.
- **Producción**: usa los servicios oficiales del SII (`api.sii.cl`). Actívalo sólo cuando la empresa haya sido autorizada y disponga del certificado y CAF de producción. Los DTE enviados en este modo poseen validez legal y deben reportarse correctamente al SII.

Cambia al ambiente de producción únicamente después de completar exitosamente la certificación y verificar que el flujo de envío funciona en integración.

## Resumen de Ventas Diarias

El plugin puede generar el XML de **Consumo de Folios** (RVD) para reportar al SII los montos diarios y los rangos de folios utilizados. La clase `RvdManager` crea el archivo según el esquema oficial (`resources/schemas/ConsumoFolio_v10.xsd`) e integra la firma digital con el certificado configurado.

Para validar un archivo generado se puede utilizar `xmllint`:

```bash
xmllint --noout --schema sii-boleta-dte/src/modules/schemas/ConsumoFolio_v10.xsd ejemplo_rvd.xml
```

El envío del RVD reutiliza el mismo token y certificado empleados para las boletas electrónicas.

## Consumo de Folios (CDF)

El plugin permite generar el archivo **Consumo de Folios** requerido por el SII. Desde el panel de control es posible ejecutar manualmente la generación y envío del CDF del día.

El sistema programa automáticamente una tarea diaria `sii_boleta_dte_run_cdf` que envía el CDF del día alrededor de las 23:55 (hora de Santiago). Si se prefiere gestionarlo externamente, puede invocar manualmente la acción `sii_boleta_dte_run_cdf` dentro de WordPress.

Dentro del panel de control también se incluye la pestaña **Folios**, donde se listan los CAF configurados con su rango autorizado, el último folio utilizado y la cantidad de folios disponibles. Esto facilita identificar cuándo solicitar nuevos folios antes de que se agoten.

## Flujos de operación

1. **Emisión de boletas en WooCommerce**
   - Configura el plugin con RUT, certificado, CAF y tipos de documento permitidos.
   - En el checkout se solicita el RUT del cliente y se valida en tiempo real en el navegador.
   - Al completar un pedido se genera el XML, se firma y se encola para envío inmediato al SII mediante tareas asíncronas con reintentos; también se valida teléfono/correo según el medio de pago y se reemplaza el RUT genérico en ventas de alto valor. Además se genera un PDF que se envía por correo.
   - Cada boleta queda disponible públicamente en `/boleta/{folio}` donde puede descargarse el PDF.

2. **Resumen de Ventas Diarias (RVD)**
   - Cron `sii_boleta_dte_daily_rvd` lo envía cada 12 horas para asegurar el reporte oportuno del día anterior.
   - Manualmente se puede ejecutar `wp sii rvd --date=YYYY-MM-DD`.

3. **Consumo de Folios (CDF)**
   - Cron diario `sii_boleta_dte_run_cdf` envía el consumo del día en curso.
   - Manualmente: `wp sii cdf --date=YYYY-MM-DD`.

Todos los comandos WP‑CLI requieren que el sitio tenga acceso a los archivos generados en `wp-content/uploads` y a las credenciales del SII configuradas en los ajustes del plugin.


## Ayuda en el panel de WordPress

En el administrador navega a **SII Boletas → Ayuda Boleta SII** para abrir la página de ayuda incluida en el plugin. Allí encontrarás la configuración inicial, flujos de operación, preguntas frecuentes y un detalle paso a paso del proceso de certificación del SII, además de enlaces a este README y a los archivos XSD oficiales.


## CLI de emisión y estado

Sin salir de la terminal puedes preparar el entorno y gestionar certificados/CAF:

```bash
# Copiar recursos de LibreDTE desde vendor/
wp sii resources sync

# Importar certificado y clave
wp sii cert import --file=mi-cert.p12 --pass=secreto

# Importar CAF para boleta (39)
wp sii caf import --type=39 --file=caf_boleta.xml
```

Emisión con envío:

```bash
wp sii dte emitir \
  --type=39 \
  --rut=66666666-6 --name="Consumidor Final" --addr="Calle 123" --comuna="Santiago" \
  --desc="Servicio" --qty=1 --price=1000 --send
```

Referencias múltiples (JSON):

```bash
wp sii dte emitir --type=61 --rut=76000000-0 --name="Cliente SA" --addr="Av. Uno 100" --comuna="Providencia" \
  --desc="NC varias ref" --qty=1 --price=-1000 \
  --refs='[{"TpoDocRef":33,"FolioRef":12345,"FchRef":"2025-01-01","RazonRef":"Descuento"},{"TpoDocRef":33,"FolioRef":12346,"RazonRef":"Ajuste"}]' \
  --send
```

Campos adicionales:
- Encabezado: `--fmapago`, `--fchvenc`, `--mediopago`, `--tpotrancompra`, `--tpotranventa`
- Receptor: `--girorecep`, `--correorecep`, `--telefonorecep`
- Guía (52): `--indtraslado`, `--patente`, `--ruttrans`, `--rutchofer`, `--nombrechofer`, `--dirdest`, `--cmnadest`

UI Admin – Generar DTE (diferenciado por tipo):
- Facturas (33/34) y Guía (52): pide dirección/comuna receptor; medio de pago para 33/34.
- Notas (56/61): referencia obligatoria (folio/tipo/razón).
- Guía (52): datos de transporte (Patente, RUTTrans, RUTChofer, NombreChofer, DirDest, CmnaDest) según esquema del SII.
- Soporta múltiples ítems; botón “Previsualizar” genera representación sin consumir folio.

Estado por TrackID:

```bash
wp sii dte status --track=123456
```

## Panel de control (admin)

- Pestaña “Log de Envíos”: filtros (Track/Estado/Fecha), paginación, modal de detalle, botón “Revisar estados ahora”.
- Pestaña “Folios”: rangos de CAF, último usado, disponibles.
- Pestaña “Jobs”: estado/proxima ejecución de cron (RVD/CDF).

## Cron y verificación de estados

- Envíos asíncronos con reintentos exponenciales (hasta 3)
- Cron horario que consulta estados de TrackIDs “sent” y añade filas al log

## Endpoint público

- `/boleta/{folio}`: HTML con datos de DTE y enlace a PDF (si existe)

## PDF / HTML

- PDF con LibreDTE (renderer TCPDF). Si tu build usa Twig, el template está en `resources/templates/billing/document/renderer/estandar.html.twig` (se mantuvo el diseño original, con soporte de logo y detalle).
- Clases de formato visual: `format-a4` / `format-80mm` (ancho controlado vía CSS). La 2ª página de detalle (inyectada por el engine si hace falta) respeta 80mm/A4 real (AddPage TCPDF) según Ajustes → PDF.
- Logo: usa el configurado en Ajustes (debe estar en uploads para que TCPDF lo lea).

## Política de folios

- Admin y WooCommerce: NO se consume folio hasta que el SII devuelve TrackID exitoso.
- Flujo: peek folio → firmar → guardar XML en `uploads/dte/tmp/` → enviar → si TrackID: consumir folio y mover a `uploads/dte/<RUT>/` → generar PDF.
- Previsualización (admin): no consume folio, no guarda XML definitivo.

## Almacenamiento por RUT

- XML/PDF/HTML bajo `wp-content/uploads/dte/<RUT>/`.
- Endpoint y métricas recorren recursivamente y siguen encontrando documentos.

## Solución de problemas

- Certificado PFX/P12 (OpenSSL 3): si aparece `invalid key length` o `Unsupported encryption algorithm`, re‑exporta tu PFX a AES‑256. El plugin intentará convertir al vuelo a PEM si `exec` y `openssl` están disponibles.
- LibreDTE recursos: si ves errores tipo `resources/data/repository/tipos_documento.php`, copia recursos desde vendor a `sii-boleta-dte/resources/` manteniendo la misma estructura.
- Uploads no escribible: ajusta permisos de `wp-content/uploads`.
- PDF sin logo: asegúrate que el logo esté en la Biblioteca de Medios (uploads), no en una URL externa/CDN.

## Seguridad

- Certificados/CAF con permisos mínimos; contraseña cifrada en DB; evitar exponer rutas en UI.

## Batería de pruebas – Certificación (CERT)

Prerrequisitos:
- Emisor y `acteco` configurados, certificado de pruebas y `caf_path[<tipo>]` por cada DTE; ambiente `test`.

Casos de emisión (ejemplos):

- Boleta afecta (39):
```bash
wp sii dte emitir --type=39 --rut=66666666-6 --name="CF" --addr="Calle 123" --comuna="Santiago" --desc="Servicio afecta" --qty=1 --price=1000 --send
```

- Boleta exenta (41):
```bash
wp sii dte emitir --type=41 --rut=66666666-6 --name="CF" --addr="Calle 123" --comuna="Santiago" --desc="Servicio exento" --qty=1 --price=1000 --correorecep=cf@correo.cl --telefonorecep=987654321 --send
```

- Factura afecta (33):
```bash
wp sii dte emitir --type=33 --rut=76000000-0 --name="Cliente SA" --addr="Av. Uno 100" --comuna="Providencia" --girorecep="Servicios" --desc="Asesoría" --qty=1 --price=1190 --fmapago=1 --mediopago="Transferencia" --send
```

- Guía de despacho (52):
```bash
wp sii dte emitir --type=52 --rut=96000000-0 --name="Destino" --addr="Ruta 5 Sur KM 100" --comuna="Chillán" --desc="Despacho de productos" --qty=10 --price=1000 --indtraslado=1 --patente=ABCD12 --ruttrans=76234567-8 --rutchofer=12345678-9 --nombrechofer="Juan Pérez" --dirdest="Bodega 2" --cmnadest="Chillán" --send
```

- Nota de crédito (61):
```bash
wp sii dte emitir --type=61 --rut=76000000-0 --name="Cliente SA" --addr="Av. Uno 100" --comuna="Providencia" --girorecep="Servicios" --desc="NC por ajustes" --qty=1 --price=-1000 --refs='[{"TpoDocRef":33,"FolioRef":12345,"FchRef":"2025-01-01","RazonRef":"Descuento"}]' --send
```

- Nota de débito (56):
```bash
wp sii dte emitir --type=56 --rut=76000000-0 --name="Cliente SA" --addr="Av. Uno 100" --comuna="Providencia" --desc="ND por intereses" --qty=1 --price=100 --tpodocref=33 --folioref=12345 --razonref="Intereses" --send
```

Validación de estados:
- Panel → “Log de Envíos” → “Revisar estados ahora” y filtros de fecha/track/estado
- CLI: `wp sii dte status --track=<ID>`
- Estados esperables: `SOK`, `FOK`, `EPR` y posibles reparos/rechazos con glosa

PDF/HTML:
- Confirmar formato (A4/80mm), logo, pie de página; fallback HTML si no hay FPDF

Troubleshooting:
- Firma rechazada: revisar `.p12/.pfx`, contraseña, vigencia, hora servidor
- CAF inválido: cargar `caf_path[<tipo>]`, revisar rangos/folios
- Esquema/caratula: ver detalle en “Ver” (modal) y corregir campo

El repositorio incluye un conjunto básico de pruebas unitarias con **PHPUnit** que cubren el cálculo de neto/IVA, la generación de TED y la validación de esquemas XML.

Para ejecutarlas:

```bash
cd sii-boleta-dte
phpunit
```

Asegúrate de tener instalado PHPUnit en el sistema (por ejemplo, `apt-get install phpunit` en distribuciones basadas en Debian).


## Certificación (resumen) y manejo de errores

El SII exige que el representante legal solicite un set de pruebas y envíe cinco envíos de boletas de prueba al correo indicado por el servicio. El plugin facilita este proceso en modo de pruebas.

### Proceso de certificación ante el SII

1. **Solicitud del set de pruebas**: el representante legal ingresa a [sii.cl](https://www.sii.cl) y solicita el set en la sección de Boleta Electrónica. La respuesta suele llegar en aproximadamente 24 horas.
2. **Generar XML de prueba**: con el set recibido, configura el plugin en modo `test` con el certificado y CAF de prueba. En el administrador ve a **SII Boletas → Generar DTE**, completa los datos de cada escenario y marca la opción **Enviar al SII**. El plugin firmará el XML, lo guardará en `wp-content/uploads` y mostrará el `trackId` devuelto.
3. **Enviar evidencias**: repite el proceso para los cinco escenarios. Adjunta los XML, PDF y `trackId` generados y envíalos al correo `SII_BE_Certificacion@sii.cl` con el asunto solicitado por el SII.
4. **Declaración de cumplimiento**: una vez aceptados los envíos, ingresa nuevamente a [sii.cl](https://www.sii.cl) y completa la declaración de cumplimiento firmándola con el certificado digital.

Durante el proceso de certificación ante el SII se recomienda:

1. Generar y firmar los DTE en el ambiente de prueba validando cada XML contra el XSD correspondiente.
2. Enviar los archivos utilizando el token de ensayo y revisar los **trackId** devueltos por la API.
3. Consultar el estado de cada envío hasta que sea **ACEPTADO** o aparezca un motivo de rechazo.

El plugin registra los eventos relevantes mediante la función `sii_boleta_write_log` que utiliza la clase `SII_Logger`.
Los archivos de log se guardan diariamente en `wp-content/uploads/sii-boleta-logs/`, evitando exponer datos sensibles.
Ante un rechazo del SII, revise el cuerpo de la respuesta y el archivo de log para identificar la causa exacta.


## Licencias

Todo el código dentro de este directorio ha sido escrito específicamente para este proyecto y sigue el patrón de diseño modular inspirado en el plugin de ejemplo. Se anima a los desarrolladores a revisar y adaptar el código a sus necesidades, respetando las licencias de terceros para cualquier biblioteca adicional que instalen.
