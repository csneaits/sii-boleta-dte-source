# Evaluación de vulnerabilidades adicionales

Este documento resume hallazgos de seguridad detectados durante la revisión del plugin **SII Boleta DTE**. Cada punto se relaciona con categorías del [OWASP Top 10 2021](https://owasp.org/Top10/).

## 1. Exposición directa de PDFs tributarios (A01:2021 – Broken Access Control)

*Descripción.* Cuando se genera un DTE manual desde el panel de administración, el método `store_persistent_pdf()` copia el PDF resultante al directorio público de *uploads* (`wp-content/uploads/sii-boleta-dte/previews`) y devuelve una URL accesible sin controles adicionales. Los nombres de archivo se construyen con `build_pdf_filename()` usando etiquetas predecibles como el tipo de documento, folio y RUT del emisor/receptor, lo que facilita adivinar rutas válidas.【F:sii-boleta-dte/src/Presentation/Admin/GenerateDtePage.php†L1265-L1296】【F:sii-boleta-dte/src/Presentation/Admin/GenerateDtePage.php†L1430-L1466】

*Riesgo.* Cualquier visitante no autenticado puede descargar PDFs con RUT, datos de clientes y montos tributarios simplemente iterando folios o combinaciones de RUT conocidas. Esto rompe la confidencialidad de la información tributaria y puede violar la legislación local de protección de datos.

*Recomendaciones.*
- Mover los PDFs a un contenedor privado (por ejemplo `wp-content/sii-boleta-dte/private`) y servirlos únicamente mediante enlaces firmados o verificando capacidades de WordPress.
- Incorporar identificadores aleatorios no previsibles en los nombres/URLs.
- Añadir reglas `.htaccess` / `web.config` o configuraciones equivalentes para bloquear el acceso directo al directorio.

**Estado.** Los PDFs persistentes ahora se migran automáticamente al directorio privado `wp-content/sii-boleta-dte/private`, se registran en un catálogo temporal protegido y sólo se exponen mediante enlaces `admin-ajax.php` con nonce y token aleatorio verificado para usuarios con `manage_options`.【F:sii-boleta-dte/src/Presentation/Admin/GenerateDtePage.php†L1237-L1318】【F:sii-boleta-dte/src/Presentation/Admin/Ajax.php†L613-L666】

## 2. Registro de datos sensibles sin protección (A05:2021 – Security Misconfiguration)

*Descripción.* El flujo de previsualización registra tanto los datos crudos (`print_r` del `$_POST['items']`) como los ítems normalizados en `debug_log()` dentro de `wp-content/uploads/sii-boleta-logs/debug.log`, un directorio accesible públicamente en la mayoría de instalaciones.【F:sii-boleta-dte/src/Presentation/Admin/GenerateDtePage.php†L795-L905】【F:sii-boleta-dte/src/Presentation/Admin/GenerateDtePage.php†L1536-L1549】 No se genera ningún archivo de control de acceso (por ejemplo `.htaccess`) ni se reduce el nivel de detalle del log.

*Riesgo.* El archivo de log expone RUT, direcciones, correos, descripciones de productos y montos asociados a los contribuyentes que usan la previsualización. Un atacante que conozca la ruta por defecto (`/wp-content/uploads/sii-boleta-logs/debug.log`) puede descargarlo sin autenticación, obteniendo datos personales y comerciales.

*Recomendaciones.*
- Evitar registrar datos personales o financieros en texto plano; limitar el log a mensajes de depuración sin payload sensible.
- Reubicar los logs en un directorio no expuesto públicamente (por ejemplo fuera de `uploads`) y protegerlo con reglas de servidor.
- Permitir activar el log sólo bajo una bandera explícita de modo debug.

**Estado.** Las rutinas de depuración registran únicamente métricas agregadas (conteos y campos presentes) y escriben en `wp-content/sii-boleta-dte/private/logs`, creando automáticamente un `.htaccess` de denegación cuando `WP_DEBUG` está activo.【F:sii-boleta-dte/src/Presentation/Admin/GenerateDtePage.php†L780-L812】【F:sii-boleta-dte/src/Infrastructure/Engine/LibreDteEngine.php†L133-L157】【F:sii-boleta-dte/src/Presentation/Admin/GenerateDtePage.php†L1625-L1666】【F:sii-boleta-dte/src/Infrastructure/Engine/LibreDteEngine.php†L220-L268】

---
Estos hallazgos requieren medidas prioritarias para asegurar el cumplimiento normativo (SII/Ley 19.628) y reducir el riesgo de filtraciones de información tributaria.
