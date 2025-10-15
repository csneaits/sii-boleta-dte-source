# Perfil: QA

Resumen: pasos rápidos para validar funcionalidad, crear casos de prueba y reproducir errores.

Pasos de validación:
- Entorno local: instalar plugin en WordPress de desarrollo, activar modo debug, y ejecutar flujos de emisión manual y por WooCommerce.
- Casos críticos a validar: emisión de boletas 39, errores de CAF, reintentos en cola, descarga de PDFs firmados.

Automatización:
- Ejecutar suite de PHPUnit (`composer test`) y revisar fallos.
- Para pruebas E2E, preparar un entorno WordPress con DB de prueba y generar pedidos automáticos.

Registro de fallos:
- Adjuntar logs relevantes (`wp-content/uploads/sii-boleta-dte/private/logs/debug.log`), pasos reproducibles y fixtures XML/PDF cuando aplique.

Consejos:
- Para errores relacionados con archivos, comprobar `XmlStorage::store()` semántica (puede mover el archivo).
- Usar `tests/fixtures/` para payloads reproducibles.