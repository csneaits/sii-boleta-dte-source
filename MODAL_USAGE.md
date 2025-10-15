# Sistema de Modales Reutilizable

## Descripción
Se ha implementado un sistema de modales reutilizable para mostrar notificaciones de éxito, error, advertencia e información en el Panel de Control.

## Uso desde JavaScript

El objeto `SiiModal` está disponible globalmente y se puede usar desde cualquier parte del código JavaScript:

### Mostrar modal de éxito
```javascript
SiiModal.success('El trabajo se ha procesado correctamente.');
// O con título personalizado:
SiiModal.success('El documento se envió al SII exitosamente.', 'Operación Exitosa');
```

### Mostrar modal de error
```javascript
SiiModal.error('No se pudo procesar el trabajo.');
// O con título personalizado:
SiiModal.error('La verificación de seguridad falló.', 'Error de Seguridad');
```

### Mostrar modal de advertencia
```javascript
SiiModal.warning('Este trabajo tiene 3 intentos fallidos.');
// O con título personalizado:
SiiModal.warning('Revisa la configuración antes de continuar.', '¡Atención!');
```

### Mostrar modal de información
```javascript
SiiModal.info('El proceso puede tardar algunos minutos.');
// O con título personalizado:
SiiModal.info('Se han agregado 5 documentos a la cola.', 'Información');
```

### Método genérico
```javascript
SiiModal.show(type, title, message);
// Tipos disponibles: 'success', 'error', 'warning', 'info'
```

### Cerrar modal
```javascript
SiiModal.hide();
```

## Uso desde PHP

Las notificaciones se generan automáticamente cuando usas `add_notice()` en PHP:

```php
$this->add_notice( __( 'El trabajo se ha procesado correctamente.', 'sii-boleta-dte' ), 'success' );
$this->add_notice( __( 'Error al procesar.', 'sii-boleta-dte' ), 'error' );
```

El sistema automáticamente:
1. Genera un elemento oculto con las notificaciones en formato JSON
2. El JavaScript lee estas notificaciones al cargar la página
3. Muestra la primera notificación en un modal

## Características

- ✅ **4 tipos de modales**: Éxito, Error, Advertencia, Información
- ✅ **Iconos automáticos**: Cada tipo tiene su icono distintivo
- ✅ **Animaciones suaves**: Fade in y slide in
- ✅ **Responsive**: Se adapta a dispositivos móviles
- ✅ **Cierre múltiple**: 
  - Click en botón "Aceptar"
  - Click en overlay (fondo oscuro)
  - Tecla ESC
- ✅ **Soporte dark mode**: Adapta colores automáticamente
- ✅ **Accesibilidad**: Bloquea scroll del body cuando está abierto

## Probar desde la consola del navegador

Abre la consola del navegador (F12) y ejecuta:

```javascript
// Probar éxito
SiiModal.success('¡Documento procesado exitosamente!');

// Probar error
SiiModal.error('No se pudo conectar con el SII.');

// Probar advertencia
SiiModal.warning('Este documento tiene errores de validación.');

// Probar información
SiiModal.info('Se procesarán 5 documentos en los próximos minutos.');
```

## Estilos CSS

Los estilos están en `/src/Presentation/assets/css/control-panel.css`:

- `.sii-modal`: Contenedor principal del modal
- `.sii-modal-overlay`: Fondo oscuro con blur
- `.sii-modal-dialog`: Contenedor del diálogo
- `.sii-modal-content`: Contenido del modal
- `.sii-modal-icon`: Icono circular (cambia según el tipo)
- `.sii-modal-title`: Título del modal
- `.sii-modal-message`: Mensaje principal
- `.sii-modal-actions`: Contenedor de botones

## Integración con acciones de cola

Las acciones de cola (Procesar, Reintentar, Cancelar) ahora muestran automáticamente un modal con el resultado:

- **Éxito**: Modal verde con ✓
- **Error**: Modal rojo con ✕
- **Nonce inválido**: Modal rojo explicando el error de seguridad

## Reutilización

Este sistema puede reutilizarse en otras partes del plugin:

1. Incluye el HTML del modal en tu página (ya está en ControlPanelPage.php)
2. Incluye el CSS de control-panel.css
3. Incluye el JavaScript de control-panel.js
4. Usa `window.SiiModal` desde cualquier script

## Ejemplo completo

```javascript
// Ejecutar una acción y mostrar resultado
fetch('/wp-admin/admin-ajax.php', {
    method: 'POST',
    body: formData
})
.then(response => response.json())
.then(data => {
    if (data.success) {
        SiiModal.success(data.message || 'Operación exitosa');
    } else {
        SiiModal.error(data.message || 'Error en la operación');
    }
})
.catch(error => {
    SiiModal.error('Error de conexión: ' + error.message);
});
```
