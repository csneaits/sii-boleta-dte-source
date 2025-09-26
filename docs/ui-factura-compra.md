# UI para Factura de Compra (Tipo 46)

Este documento resume los campos que deben estar disponibles en la interfaz de "Generar DTE" para cubrir los escenarios definidos en los archivos YAML de ejemplo del tipo 46.

## Campos base

Los cinco escenarios comparten un encabezado que exige datos del emisor, receptor y un detalle mínimo con nombre, cantidad y precio de cada ítem.【F:sii-boleta-dte/resources/yaml/documentos_ok/046_factura_compra/046_001_afecta.yaml†L1-L21】

Recomendaciones para la UI:

- Prefijar los datos del emisor con los valores guardados en la página de ajustes, permitiendo editarlos sólo si el perfil del usuario tiene permisos para sobreescribirlos.
- Exigir que la sección "Detalle" permita añadir múltiples ítems con `NmbItem`, `QtyItem` y `PrcItem`, validando que al menos uno esté presente antes de enviar el formulario.
- Incorporar autocompletado para los campos del receptor reutilizando contactos previos o maestra propia.

## Caso 046_002 – Ítem exento

El ejemplo `046_002_exenta.yaml` añade el indicador `IndExe` sobre cada ítem del detalle.【F:sii-boleta-dte/resources/yaml/documentos_ok/046_factura_compra/046_002_exenta.yaml†L18-L22】

Recomendaciones para la UI:

- Añadir un selector "Ítem exento" por línea, que establezca el valor de `IndExe` según la lista oficial del SII (p. ej. `1` para operaciones exentas). Si la línea es marcada como exenta, el cálculo de IVA debe omitirla.
- Mostrar un resumen de totales que separe montos afectos y exentos para facilitar la revisión.

## Caso 046_003 – IVA retenido total

El archivo `046_003_iva_retenido_total.yaml` introduce el campo `CodImpAdic` en el detalle para señalar la retención completa del IVA.【F:sii-boleta-dte/resources/yaml/documentos_ok/046_factura_compra/046_003_iva_retenido_total.yaml†L18-L23】

Recomendaciones para la UI:

- Añadir un grupo de opciones dentro de "Retención IVA" con alternativas como "Sin retención", "Retención total" y "Retención parcial".
- Si se elige "Retención total", completar automáticamente `CodImpAdic` con el código que corresponda al 100 % de retención, mostrando la referencia normativa al usuario.
- Incluir validaciones que adviertan cuando los montos retenidos no coinciden con el 19 % estándar.

## Caso 046_004 – IVA retenido parcial con codificación del ítem

Para la retención parcial, el YAML agrega `CdgItem` (tipo y valor), `UnmdItem` y `CodImpAdic` por línea.【F:sii-boleta-dte/resources/yaml/documentos_ok/046_factura_compra/046_004_iva_retenido_parcial.yaml†L18-L26】

Recomendaciones para la UI:

- Habilitar campos opcionales de código (`TpoCodigo`, `VlrCodigo`) y unidad (`UnmdItem`) en cada fila del detalle, visibles al activar un interruptor "Mostrar campos avanzados".
- Permitir seleccionar el porcentaje de retención (p. ej. 30 %, 50 %, 65 %) y mapearlo al `CodImpAdic` correspondiente.
- Calcular y mostrar el monto retenido estimado junto al subtotal del ítem para que el operador confirme los valores antes de generar el DTE.

## Caso 046_005 – Proveedor extranjero

Cuando el proveedor es extranjero se incorpora `CdgIntRecep` y un bloque `Extranjero` con la nacionalidad en la sección de receptor, además del `CodImpAdic` en los ítems.【F:sii-boleta-dte/resources/yaml/documentos_ok/046_factura_compra/046_005_compra_proveedor_extranjero.yaml†L12-L25】

Recomendaciones para la UI:

- Añadir una casilla "Proveedor extranjero" que muestre campos adicionales: código interno (`CdgIntRecep`), país/nacionalidad y formato de identificación extranjero.
- Mostrar ayudas contextuales indicando cómo debe completarse la nacionalidad según la tabla de países del SII.
- Verificar que, al activar este modo, se ajusten las validaciones de RUT para aceptar identificadores no chilenos.

## Validaciones y experiencia de usuario

- Incluir mensajes de error específicos por campo (p. ej. "Debes indicar el código de retención" o "Selecciona la nacionalidad del proveedor") para evitar rechazos posteriores del SII.
- Ofrecer un resumen final antes del envío que enumere: tipo de retención aplicada, totales afectos/exentos y datos del receptor.
- Registrar en el log del plugin el escenario utilizado (p. ej. "Factura de compra – retención parcial") para facilitar auditorías.
