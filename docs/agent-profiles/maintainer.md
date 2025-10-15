# Perfil: Maintainer

Resumen: guía para quien revisa PRs, gestiona releases y mantiene la base de código.

Tareas frecuentes:
- Ejecutar CI localmente: `composer test && composer phpcs`.
- Revisar PRs por cobertura, phpcs warnings y cambios de contrato.
- Mantener el changelog: actualizar `AGENT.md` y `README.md` para cambios visibles.

Criterios para aceptar PRs:
- Tests existentes deben pasar sin modificar (a menos que el PR sea para arreglar tests).
- Documentación actualizada para cambios de comportamiento.
- No introducir helpers de test en `src/`.

Release flow:
1. Merge to `main`.
2. Run build & bump version; generate `dist/`.
3. Tag release and push.

Observability:
- Revisar logs en `wp-content/uploads/sii-boleta-dte/private/logs/`.
- Verificar reportes de cobertura en `coverage/` si están generados.