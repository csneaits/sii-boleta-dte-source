# SII Boleta DTE – Código Fuente

Este directorio contiene el código fuente completo del plugin **SII Boleta DTE** y los scripts de empaquetado. A diferencia del archivo ZIP que instalas en WordPress, aquí están todos los archivos PHP organizados por carpetas y un par de scripts de compilación para generar el ZIP instalable.

## Estructura

- `sii-boleta-dte/` – carpeta del plugin con todos los archivos de código (PHP) que implementan la integración con el Servicio de Impuestos Internos de Chile, generación de XML, firma digital, manejo de folios, integración con WooCommerce, tareas cron para el Resumen de Ventas Diarias (RVD) y representación en PDF/HTML.
  - `includes/` – contiene las clases que encapsulan cada responsabilidad (API, gestor de folios, generador de XML, firma, PDF, RVD, cron y WooCommerce).
  - `includes/libs/xmlseclibs.php` – biblioteca de firma XML utilizada por el plugin. Se incluye la librería `xmlseclibs` en su versión autónoma para firmar digitalmente los DTE.
- `build.sh` – script de empaquetado para sistemas Linux/macOS. Genera un ZIP instalable bajo `dist/` con el número de versión que aparece en el encabezado del plugin.
- `build.ps1` – script de empaquetado para PowerShell (Windows). Cumple la misma función que `build.sh`, pero adaptado a entornos Windows.

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

## Dependencias opcionales

El plugin puede generar la representación PDF del DTE utilizando las clases `FPDF` y `PDF417`. Se incluye una implementación básica de `FPDF` y la librería `BigFish/PDF417` dentro de `sii-boleta-dte/includes/libs/`, permitiendo crear un PDF con código de barras PDF417 sin depender de servicios externos.

Para producción se recomienda instalar dependencias más robustas mediante Composer:

1. Instalar FPDF: `composer require setasign/fpdf`.
2. (Opcional) Reemplazar la librería PDF417 incluida por otra de su preferencia.
3. Asegurarse de que ambas clases estén cargadas antes de usar el plugin.

## Configuración de tipos de documento para el checkout

Desde la página de ajustes del plugin es posible definir qué tipos de documentos se ofrecen a los clientes en el formulario de compra de WooCommerce. Actualmente se incluyen:

- Boleta Electrónica
- Factura Electrónica
- Factura Exenta
- Guía de Despacho

Active o desactive cada opción según las necesidades de su negocio.

## Envío de correos con Amazon SES

El plugin permite configurar el envío de correos a través de Amazon SES. En la página de ajustes se pueden definir el host y puerto SMTP junto con las credenciales proporcionadas por Amazon. Los mensajes enviados al cliente incluyen un agradecimiento por la compra y el logo configurado en los ajustes del plugin.

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

El plugin puede generar el XML de **Consumo de Folios** (RVD) para reportar al SII los montos diarios y los rangos de folios utilizados. La clase `SII_Boleta_RVD_Manager` crea el archivo según el esquema oficial (`includes/schemas/ConsumoFolio_v10.xsd`) e integra la firma digital con el certificado configurado.

Para validar un archivo generado se puede utilizar `xmllint`:

```bash
xmllint --noout --schema sii-boleta-dte/includes/schemas/ConsumoFolio_v10.xsd ejemplo_rvd.xml
```

El envío del RVD reutiliza el mismo token y certificado empleados para las boletas electrónicas.

## Consumo de Folios (CDF)

El plugin permite generar el archivo **Consumo de Folios** requerido por el SII. Desde el panel de control es posible ejecutar manualmente la generación y envío del CDF del día.

El sistema programa automáticamente una tarea diaria `sii_boleta_dte_run_cdf` que envía el CDF del día alrededor de las 23:55 (hora de Santiago). Si se prefiere gestionarlo externamente, puede invocar manualmente la acción `sii_boleta_dte_run_cdf` dentro de WordPress.

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


## Pruebas

El repositorio incluye un conjunto básico de pruebas unitarias con **PHPUnit** que cubren el cálculo de neto/IVA, la generación de TED y la validación de esquemas XML.

Para ejecutarlas:

```bash
cd sii-boleta-dte
phpunit
```

Asegúrate de tener instalado PHPUnit en el sistema (por ejemplo, `apt-get install phpunit` en distribuciones basadas en Debian).


## Certificación y manejo de errores

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


## Notas sobre la licencia y originalidad

Todo el código dentro de este directorio, excepto la biblioteca `xmlseclibs.php`, ha sido escrito específicamente para este proyecto y sigue el patrón de diseño modular inspirado en el plugin de ejemplo. Se anima a los desarrolladores a revisar y adaptar el código a sus necesidades, respetando las licencias de terceros para cualquier biblioteca adicional que instalen (por ejemplo, FPDF y PDF417).
