#!/usr/bin/env bash
set -euo pipefail

# ── Zenith Wellness Release Builder ──────────────────────────────────────────
# Builds the frontend and bundles it with the API backend for deployment.

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
RELEASE_DIR="$ROOT_DIR/release"

echo "==> Building frontend..."
cd "$ROOT_DIR"
pnpm build

echo "==> Preparing release directory..."
rm -rf "$RELEASE_DIR"
mkdir -p "$RELEASE_DIR"

echo "==> Copying frontend build..."
cp -r "$ROOT_DIR/dist" "$RELEASE_DIR/public_html"

echo "==> Copying API backend..."
mkdir -p "$RELEASE_DIR/api"
cp -r "$ROOT_DIR/api/"* "$RELEASE_DIR/api/"
cp "$ROOT_DIR/composer.json" "$RELEASE_DIR/" 2>/dev/null || true

echo "==> Copying deploy config..."
cp "$ROOT_DIR/deploy/"* "$RELEASE_DIR/" 2>/dev/null || true

echo "==> Release ready at: $RELEASE_DIR"
echo "    Deploy by uploading the contents of this directory to your server."
