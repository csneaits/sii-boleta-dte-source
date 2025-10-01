#!/bin/bash
# Empaquetador para SII Boleta DTE

set -euo pipefail

# Obtener versión desde el encabezado del plugin
VERSION=$(grep -oP 'Version:\s*\K[0-9.]+' "sii-boleta-dte/sii-boleta-dte.php" | head -n 1)
if [[ -z "$VERSION" ]]; then
  echo "No se pudo obtener la versión del plugin. Asegúrate de que el archivo sii-boleta-dte.php tenga la cabecera Version." >&2
  exit 1
fi

DEST_DIR="dist"
ZIP_NAME="sii-boleta-dte-$VERSION.zip"

# Crear carpeta de destino
rm -rf "$DEST_DIR"
mkdir -p "$DEST_DIR"

## Instalar dependencias de Composer si está disponible
if command -v composer >/dev/null 2>&1; then
  echo "Ejecutando composer install --no-dev --prefer-dist --optimize-autoloader en sii-boleta-dte/"
  (cd sii-boleta-dte && composer install --no-dev --prefer-dist --optimize-autoloader)
else
  if [ -f "sii-boleta-dte/composer.phar" ]; then
    echo "Ejecutando composer.phar install --no-dev --prefer-dist --optimize-autoloader"
    (cd sii-boleta-dte && php composer.phar install --no-dev --prefer-dist --optimize-autoloader)
  else
    echo "ADVERTENCIA: Composer no está disponible. Se empaquetará sin actualizar vendor/" >&2
  fi
fi

# Crear archivo ZIP (excluyendo tests y archivos de desarrollo)
echo "Generando $ZIP_NAME..."
cd sii-boleta-dte
## Build frontend assets with node if available
if command -v npm >/dev/null 2>&1; then
  echo "Node detected — installing npm deps and building assets..."
  (cd .. && cd sii-boleta-dte && npm ci --no-audit --no-fund >/dev/null 2>&1 || true)
  (cd .. && cd sii-boleta-dte && npm run build >/dev/null 2>&1 || true)
else
  echo "Node/npm not found — skipping frontend build"
fi
zip -r "../$DEST_DIR/$ZIP_NAME" . \
  -x '*.DS_Store' \
  -x 'tests/*' \
  -x 'phpunit.xml*' \
  -x '.phpunit.result.cache'
cd ..
echo "Archivo creado en $DEST_DIR/$ZIP_NAME"
