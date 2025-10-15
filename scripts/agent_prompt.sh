#!/usr/bin/env bash
set -euo pipefail

# Imprime una plantilla de agente desde templates/agent-<role>.md
# Uso: scripts/agent_prompt.sh <role> [--copy]
# Ejemplo: ./scripts/agent_prompt.sh coordinator --copy

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$SCRIPT_DIR/.."
TEMPLATES_DIR="$REPO_ROOT/templates"

if [ $# -lt 1 ]; then
  echo "Usage: $0 <role> [--copy]"
  echo "Available roles: coordinator, analyst, coder, tester, summarizer"
  exit 2
fi

ROLE="$1"
COPY=false
if [ "${2-}" = "--copy" ]; then COPY=true; fi

PROFILE_FILE="$TEMPLATES_DIR/agent-$ROLE.md"
if [ ! -f "$PROFILE_FILE" ]; then
  echo "Profile not found: $PROFILE_FILE"
  exit 3
fi

cat "$PROFILE_FILE"

if $COPY; then
  if command -v xclip >/dev/null 2>&1; then
    xclip -selection clipboard < "$PROFILE_FILE" && echo "Copied to clipboard (xclip)."
  elif command -v wl-copy >/dev/null 2>&1; then
    wl-copy < "$PROFILE_FILE" && echo "Copied to clipboard (wl-copy)."
  elif command -v pbcopy >/dev/null 2>&1; then
    pbcopy < "$PROFILE_FILE" && echo "Copied to clipboard (pbcopy)."
  else
    echo "No clipboard utility found (xclip/wl-copy/pbcopy). Install one or omit --copy."
    exit 4
  fi
fi
