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

# Crear archivo ZIP
echo "Generando $ZIP_NAME..."
cd sii-boleta-dte
zip -r "../$DEST_DIR/$ZIP_NAME" . -x '*.DS_Store'
cd ..
echo "Archivo creado en $DEST_DIR/$ZIP_NAME"