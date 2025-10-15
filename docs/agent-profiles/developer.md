# Perfil: Developer

Resumen: orientación rápida para desarrolladores que van a tocar el código del plugin — implementación, pruebas unitarias y debugging.

Qué necesitas saber:
- Ejecutar tests: `composer test` o `vendor/bin/phpunit`.
- Estilos: seguir WPCS (ejecutar `composer phpcs`).
- Dependencias: `composer install` y usar `vendor/bin/phpunit --filter <test>` para pruebas focalizadas.

Checklist rápido:
- Añadir pruebas unitarias para nueva lógica (happy path + 1-2 edge cases).
- Evitar helpers de test en `src/` (usar `tests/_helpers/`).
- Registrar cambios en `AGENT.md` / `README.md` si cambias contratos o semántica pública.

Trucos:
- Para depurar plantillas admin, captura la salida con `ob_start()` / `ob_get_clean()` en tests de integración.
- Usa `XmlStorage::store()` en tests sabiendo que puede mover archivos (ver sección de README sobre buenas prácticas).