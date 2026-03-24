#!/usr/bin/env bash
# ══════════════════════════════════════════════════════════════════
#  Monorepo → Packagist split script
#
#  Syncs the SPIFFE library source from the monorepo into the
#  standalone package directory, then optionally pushes to the
#  package's own Git repository for Packagist.
#
#  Usage:
#    # Sync source files (no Git push)
#    ./packages/php-spiffe/split.sh
#
#    # Sync + push to Packagist repo
#    ./packages/php-spiffe/split.sh --push
#
#    # Sync + tag + push
#    ./packages/php-spiffe/split.sh --push --tag v1.0.0
#
#  Prerequisites:
#    - Set PACKAGIST_REPO to the package's Git remote URL
#      e.g. export PACKAGIST_REPO=git@github.com:SDPM-lab/php-spiffe.git
# ══════════════════════════════════════════════════════════════════
set -euo pipefail

MONOREPO_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
PKG_DIR="${MONOREPO_ROOT}/packages/php-spiffe"

PUSH=false
TAG=""

while [[ $# -gt 0 ]]; do
    case "$1" in
        --push) PUSH=true; shift ;;
        --tag)  TAG="$2"; shift 2 ;;
        *)      echo "Unknown option: $1"; exit 1 ;;
    esac
done

log() { echo "[split] $(date +%T) $*"; }

# ──────────────────────────────────────────────────────────────
#  1. Sync source files from monorepo → package/src/
# ──────────────────────────────────────────────────────────────
log "Syncing source files..."

rm -rf "${PKG_DIR}/src/Spiffe" "${PKG_DIR}/src/GPBMetadata"
mkdir -p "${PKG_DIR}/src"

cp -R "${MONOREPO_ROOT}/src/Spiffe"      "${PKG_DIR}/src/Spiffe"
cp -R "${MONOREPO_ROOT}/src/GPBMetadata"  "${PKG_DIR}/src/GPBMetadata"

# ──────────────────────────────────────────────────────────────
#  2. Sync proto and bin
# ──────────────────────────────────────────────────────────────
cp "${MONOREPO_ROOT}/spiffe/proto/workloadapi.proto" "${PKG_DIR}/proto/" 2>/dev/null || true
cp "${MONOREPO_ROOT}/bin/spiffe-watcher.php"         "${PKG_DIR}/bin/"   2>/dev/null || true

# ──────────────────────────────────────────────────────────────
#  3. Count files
# ──────────────────────────────────────────────────────────────
FILE_COUNT=$(find "${PKG_DIR}/src" -name '*.php' | wc -l | tr -d ' ')
log "Synced ${FILE_COUNT} PHP files into packages/php-spiffe/src/"

# ──────────────────────────────────────────────────────────────
#  4. Validate composer.json
# ──────────────────────────────────────────────────────────────
log "Validating composer.json..."
cd "${PKG_DIR}"
composer validate --strict 2>&1 || {
    log "WARNING: composer.json validation had issues (may be OK for --no-check-publish)"
}

# ──────────────────────────────────────────────────────────────
#  5. Optional: push to Packagist repo
# ──────────────────────────────────────────────────────────────
if [ "$PUSH" = true ]; then
    PACKAGIST_REPO="${PACKAGIST_REPO:-}"
    if [ -z "$PACKAGIST_REPO" ]; then
        log "ERROR: Set PACKAGIST_REPO environment variable to push"
        exit 1
    fi

    log "Initializing Git in package directory..."
    cd "${PKG_DIR}"

    if [ ! -d .git ]; then
        git init
        git remote add origin "$PACKAGIST_REPO"
    fi

    git add -A
    git commit -m "Sync from monorepo $(date +%Y-%m-%d)" || {
        log "No changes to commit"
    }

    if [ -n "$TAG" ]; then
        log "Tagging ${TAG}..."
        git tag -a "$TAG" -m "Release ${TAG}"
    fi

    log "Pushing to ${PACKAGIST_REPO}..."
    git push -u origin main --tags

    log "Push complete."
fi

log "Done."
