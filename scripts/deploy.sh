#!/usr/bin/env bash
set -euo pipefail

# scripts/deploy.sh
# Simple atomic deploy using releases directory on remote server.
# Usage:
#  DEPLOY_USER=deploy DEPLOY_HOST=example.com DEPLOY_PATH=/var/www/site ./scripts/deploy.sh
# It will:
#  - create a timestamped release directory on remote
#  - rsync files (excluding vendor, tests, node_modules) to remote release dir
#  - run optional remote commands (composer install --no-dev --optimize-autoloader)
#  - switch symlink "current" to the new release atomically
#  - clean older releases (keep last 5)

: ${DEPLOY_USER:=}
: ${DEPLOY_HOST:=}
: ${DEPLOY_PATH:=}
: ${RELEASES_DIR:=${DEPLOY_PATH}/releases}
: ${CURRENT_LINK:=${DEPLOY_PATH}/current}
: ${KEEP:=5}

if [ -z "$DEPLOY_USER" ] || [ -z "$DEPLOY_HOST" ] || [ -z "$DEPLOY_PATH" ]; then
  echo "Usage: DEPLOY_USER=... DEPLOY_HOST=... DEPLOY_PATH=... ./scripts/deploy.sh"
  exit 2
fi

TS=$(date -u +%Y%m%dT%H%M%SZ)
RELEASE_NAME="$TS"
REMOTE_RELEASE="$RELEASES_DIR/$RELEASE_NAME"

echo "Starting deploy to $DEPLOY_HOST:$DEPLOY_PATH -> release $RELEASE_NAME"

# Ensure remote directories
ssh "$DEPLOY_USER@$DEPLOY_HOST" "mkdir -p '$RELEASES_DIR' '$REMOTE_RELEASE'"

# Rsync local content to remote release dir (exclude tests, node_modules, local build files, vendor optionally)
RSYNC_EXCLUDES=(--exclude='.git' --exclude='tests' --exclude='tests/*' --exclude='coverage' --exclude='node_modules' --exclude='var' --exclude='vendor' --exclude='*.bundle' --exclude='*.zip' --exclude='*.log')
rsync -az --delete "${RSYNC_EXCLUDES[@]}" ./ "$DEPLOY_USER@$DEPLOY_HOST:$REMOTE_RELEASE/"

# Remote: install composer deps and run migrations if any
REMOTE_CMDS=(
  "cd '$REMOTE_RELEASE'"
  "if [ -f composer.json ]; then composer install --no-dev --prefer-dist --optimize-autoloader; fi"
  "# Optionally run DB migrations or cache warm commands here"
)
ssh "$DEPLOY_USER@$DEPLOY_HOST" "${REMOTE_CMDS[*]}"

# Switch current symlink atomically
ssh "$DEPLOY_USER@$DEPLOY_HOST" "ln -nfs '$REMOTE_RELEASE' '$CURRENT_LINK' && echo 'Switched current to $REMOTE_RELEASE'"

# Cleanup older releases
ssh "$DEPLOY_USER@$DEPLOY_HOST" "ls -1dt '$RELEASES_DIR'/* | tail -n +$((KEEP+1)) | xargs --no-run-if-empty rm -rf || true"

echo "Deploy finished: $REMOTE_RELEASE -> $CURRENT_LINK"

# Print quick health check URL suggestion
echo "Quick tip: run a healthcheck or smoke tests against the server (e.g., curl -f https://your-site/_health || true)"
