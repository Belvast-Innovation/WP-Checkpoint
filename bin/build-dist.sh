#!/usr/bin/env bash
# Build the distributable plugin tree in build/wp-checkpoint, applying .distignore.
# Used by `npm run check:plugin` and the Plugin Check CI job so both inspect
# exactly what would be shipped.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DEST="${ROOT}/build/wp-checkpoint"

rm -rf "${DEST}"
mkdir -p "${DEST}"
rsync -a --delete --exclude='/build' --exclude-from="${ROOT}/.distignore" "${ROOT}/" "${DEST}/"

echo "Built ${DEST}"
