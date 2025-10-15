# Perfil: Ops

Resumen: notas para operaciones, despliegue y monitoreo en producción.

Despliegue:
- Preferir despliegues inmutables (artifact zip creado por `build.sh`) y migraciones manuales de certificados.
- Mantener copias de seguridad de `wp-content/uploads/sii-boleta-dte/private/` y los certificados `.pfx` (cifrados en reposo).

Monitoreo y alertas:
- Alertas por fallas repetidas en `QueueProcessor` (más de 3 reintentos) -> notificar equipo.
- Revisar logs y métricas expuestas por `src/Infrastructure/Metrics.php`.

Operaciones seguras:
- Limitar acceso a la UI de emisión a administradores.
- Rotación periódica de tokens y verificación de expiración automática por `TokenManager`.
- Scripts de limpieza: `sii_boleta_dte_prune_debug_pdfs` (cron interno) y políticas de retención.

Rollback:
- Mantener snapshot del directorio `private/` antes de actualizar el plugin; si se detecta corrupción en los archivos, restaurar a snapshot anterior y re-procesar la cola manualmente.