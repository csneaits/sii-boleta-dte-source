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

El plugin puede generar la representación PDF del DTE utilizando las clases `FPDF` y `PDF417`. Se incluyen implementaciones básicas de ambas en `sii-boleta-dte/includes/libs/` que permiten crear un PDF sencillo con un timbre electrónico mínimo. Estas versiones son útiles para entornos de prueba o demostración, pero no reemplazan a las bibliotecas completas.

Para producción se recomienda instalar dependencias más robustas mediante Composer:

1. Instalar FPDF: `composer require setasign/fpdf`.
2. Instalar una librería de PDF417 con un método `encode` compatible (por ejemplo, `lemonidea/pdf417-php`).
3. Asegurarse de que ambas clases estén cargadas antes de usar el plugin. Si alguna falta, se recurrirá a la representación HTML de respaldo con un código PDF417 obtenido en línea.

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
2. Deje en blanco el campo "Token de la API".
3. Al enviar un DTE, el plugin solicitará la semilla, la firmará y recuperará el token, guardándolo en los ajustes para reutilizarlo mientras sea válido.


## Libro de Boletas

El plugin permite generar un **Libro de Boletas** a partir de los DTE emitidos en un rango de fechas y enviarlo manualmente al SII.
Desde el menú de administración, en la nueva sección "Libro de Boletas", seleccione la fecha de inicio y fin para generar el archivo.
Posteriormente puede descargarlo o enviarlo directamente al SII reutilizando el token y certificado configurados.
El XML generado se almacena en la carpeta de subidas de WordPress.

## Resumen de Ventas Diarias

El plugin puede generar el XML de **Consumo de Folios** (RVD) para reportar al SII los montos diarios y los rangos de folios utilizados. La clase `SII_Boleta_RVD_Manager` crea el archivo según el esquema oficial (`includes/schemas/ConsumoFolio_v10.xsd`) e integra la firma digital con el certificado configurado.

Para validar un archivo generado se puede utilizar `xmllint`:

```bash
xmllint --noout --schema sii-boleta-dte/includes/schemas/ConsumoFolio_v10.xsd ejemplo_rvd.xml
```

El envío del RVD reutiliza el mismo token y certificado empleados para las boletas electrónicas.

## Consumo de Folios (CDF)

El plugin permite generar el archivo **Consumo de Folios** requerido por el SII. Desde el panel de control es posible ejecutar manualmente la generación y envío del CDF del día.

Para automatizar este proceso se recomienda programar una tarea cron diaria posterior al envío del Resumen de Ventas Diarias, por ejemplo alrededor de las 23:55. El comando debería invocar la acción `sii_boleta_dte_run_cdf` dentro de WordPress.


## Certificación y manejo de errores

Durante el proceso de certificación ante el SII se recomienda:

1. Generar y firmar los DTE en el ambiente de prueba validando cada XML contra el XSD correspondiente.
2. Enviar los archivos utilizando el token de ensayo y revisar los **trackId** devueltos por la API.
3. Consultar el estado de cada envío hasta que sea **ACEPTADO** o aparezca un motivo de rechazo.

El plugin registra los eventos relevantes mediante la función `sii_boleta_write_log` que utiliza la clase `SII_Logger`.
Los archivos de log se guardan diariamente en `wp-content/uploads/sii-boleta-logs/`, evitando exponer datos sensibles.
Ante un rechazo del SII, revise el cuerpo de la respuesta y el archivo de log para identificar la causa exacta.


## Notas sobre la licencia y originalidad

Todo el código dentro de este directorio, excepto la biblioteca `xmlseclibs.php`, ha sido escrito específicamente para este proyecto y sigue el patrón de diseño modular inspirado en el plugin de ejemplo. Se anima a los desarrolladores a revisar y adaptar el código a sus necesidades, respetando las licencias de terceros para cualquier biblioteca adicional que instalen (por ejemplo, FPDF y PDF417).
