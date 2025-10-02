#!/usr/bin/env bash
# Empaquetador para el plugin SII Boleta DTE (ejecutar desde la raíz del repositorio)
set -euo pipefail

PLUGIN_SLUG="sii-boleta-dte"
MAIN_FILE="${PLUGIN_SLUG}.php"

if [[ ! -f "$MAIN_FILE" ]]; then
  echo "No se encontró $MAIN_FILE en el directorio actual. Ejecuta este script desde la raíz del repositorio." >&2
  exit 1
fi

# Obtener versión desde la cabecera del plugin (línea Version:)
VERSION=$(grep -oE '^Version:\s*[0-9]+(\.[0-9]+)*' "$MAIN_FILE" | head -n1 | awk '{print $2}')
if [[ -z "${VERSION:-}" ]]; then
  echo "No se pudo extraer la versión desde la cabecera de $MAIN_FILE" >&2
  exit 1
fi

DEST_DIR="dist"
BUILD_DIR="$DEST_DIR/$PLUGIN_SLUG"
ZIP_NAME="${PLUGIN_SLUG}-${VERSION}.zip"

echo "==> Preparando build v$VERSION"
rm -rf "$BUILD_DIR" "$DEST_DIR/$ZIP_NAME"
mkdir -p "$BUILD_DIR"

echo "==> Instalando dependencias de PHP (producción)"
if command -v composer >/dev/null 2>&1; then
  composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
else
  if [[ -f composer.phar ]]; then
    php composer.phar install --no-dev --prefer-dist --optimize-autoloader --no-interaction
  else
    echo "ADVERTENCIA: Composer no encontrado; se usará vendor/ existente" >&2
  fi
fi

if [[ -f package.json ]]; then
  if command -v npm >/dev/null 2>&1; then
    echo "==> Construyendo assets frontend"
    npm ci --no-audit --no-fund || npm install || true
    if npm run | grep -q "build"; then
      npm run build || echo "(WARN) Falló build de frontend, continuando"
    fi
  else
    echo "(INFO) npm no disponible; se omite build de assets" >&2
  fi
fi

echo "==> Copiando archivos"
RSYNC_EXCLUDES=(
  ".git/" ".github/" ".idea/" ".vscode/" "dist/" "tests/" "node_modules/.cache/" ".DS_Store"
  "phpunit.xml" "phpunit.xml.dist" ".phpunit.result.cache" "build.sh" "package-lock.json" "composer.lock"
)

# Construir parámetros de exclusión para rsync/tar manual si rsync no está disponible
if command -v rsync >/dev/null 2>&1; then
  RSYNC_ARGS=("-a" "--delete")
  for ex in "${RSYNC_EXCLUDES[@]}"; do
    RSYNC_ARGS+=("--exclude" "$ex")
  done
  rsync "${RSYNC_ARGS[@]}" ./ "$BUILD_DIR/"
else
  echo "rsync no disponible, usando copia alternativa"
  cp -R . "$BUILD_DIR" || true
  # Borrar exclusiones básicas (no exhaustivo)
  rm -rf "$BUILD_DIR/.git" "$BUILD_DIR/tests" "$BUILD_DIR/dist" || true
fi

echo "==> Ajustes finales"
# Asegurar presencia de composer autoload optimizado (si vendor existe)
if [[ -d vendor ]]; then
  (cd "$BUILD_DIR" && composer dump-autoload --optimize --no-dev --classmap-authoritative >/dev/null 2>&1 || true)
fi

echo "==> Generando ZIP $ZIP_NAME"
cd "$DEST_DIR"
zip -rq "$ZIP_NAME" "$PLUGIN_SLUG" -x "*.DS_Store"
cd - >/dev/null

echo "Listo: $DEST_DIR/$ZIP_NAME"
