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

El plugin está diseñado para generar la representación PDF del DTE utilizando las clases `FPDF` y `PDF417` si están disponibles. Debido a las limitaciones de este entorno, **no se incluyen estas bibliotecas en el código fuente**. Para que la generación de PDF funcione:

1. Instala FPDF (por ejemplo, mediante Composer: `composer require setasign/fpdf`).
2. Instala una librería de PDF417 que tenga un método `encode` similar al utilizado (p.ej., `lemonidea/pdf417-php`).
3. Asegúrate de que ambas clases (`FPDF` y `PDF417`) estén cargadas antes de usar el plugin. Si alguna no está disponible, el plugin usará como fallback una representación HTML con un código PDF417 generado por un servicio en línea.

## Notas sobre la licencia y originalidad

Todo el código dentro de este directorio, excepto la biblioteca `xmlseclibs.php`, ha sido escrito específicamente para este proyecto y sigue el patrón de diseño modular inspirado en el plugin de ejemplo. Se anima a los desarrolladores a revisar y adaptar el código a sus necesidades, respetando las licencias de terceros para cualquier biblioteca adicional que instalen (por ejemplo, FPDF y PDF417).
