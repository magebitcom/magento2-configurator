#!/usr/bin/env bash
#
# Conformance harness: runs every component in the shipped Samples through the
# configurator in --dry-run against a real Magento install, and fails if any
# component crashes (uncaught Throwable). Expected sample-vs-store logical
# errors (e.g. "role does not exist") do NOT fail the harness — only crashes do.
#
# Usage (from the Magento base directory, with the module installed):
#     MAGENTO_BIN="bin/magento" \
#     MODULE_DIR="vendor/magebit/module-magento2-configurator" \
#     ENV="local" \
#     bash "$MODULE_DIR/Test/Conformance/run-conformance.sh"
#
# On a dockerised setup (e.g. magebit-docker) run it via the PHP container, e.g.
#     MAGENTO_BIN="d/magento" bash vendor/magebit/.../run-conformance.sh
#
set -euo pipefail

MAGENTO_BIN="${MAGENTO_BIN:-bin/magento}"
MODULE_DIR="${MODULE_DIR:-vendor/magebit/module-magento2-configurator}"
ENV="${ENV:-local}"

ETC="app/etc"
SAMPLES="$MODULE_DIR/Samples"
BACKUP="$(mktemp -d)"
LOG="$(mktemp)"

if [ ! -f "$SAMPLES/master.yaml" ]; then
    echo "ERROR: samples not found at $SAMPLES (set MODULE_DIR)" >&2
    exit 2
fi

cleanup() {
    # Restore the store's real master.yaml + configurator sources.
    rm -rf "$ETC/configurator"
    [ -d "$BACKUP/configurator" ] && cp -R "$BACKUP/configurator" "$ETC/configurator"
    [ -f "$BACKUP/master.yaml" ] && cp "$BACKUP/master.yaml" "$ETC/master.yaml"
    rm -rf "$BACKUP"
}
trap cleanup EXIT

# Back up whatever is currently there.
[ -f "$ETC/master.yaml" ] && cp "$ETC/master.yaml" "$BACKUP/master.yaml"
[ -d "$ETC/configurator" ] && cp -R "$ETC/configurator" "$BACKUP/configurator"

# Lay down the sample sources and a master.yaml whose source paths resolve
# relative to the Magento base dir (BP . '/' . $source).
mkdir -p "$ETC/configurator"
cp -R "$SAMPLES"/Components/* "$ETC/configurator/"
sed 's#\.\./configurator/#app/etc/configurator/#g' "$SAMPLES/master.yaml" > "$ETC/master.yaml"

echo ">> Running configurator dry-run (env=$ENV) against the Samples ..."
set +e
$MAGENTO_BIN configurator:run --env="$ENV" --dry-run -i -v > "$LOG" 2>&1
set -e

echo "----- run summary -----"
grep -E "Configurator finished:" "$LOG" || true

# A crash is an uncaught Throwable recorded by the Processor's resilience guard.
CRASHES="$(grep -cE "failed on source" "$LOG" || true)"
echo "-----------------------"
if [ "$CRASHES" -gt 0 ]; then
    echo "FAIL: $CRASHES component crash(es) detected:" >&2
    grep -E "failed on source" "$LOG" >&2
    echo "(full log: $LOG)" >&2
    exit 1
fi

echo "PASS: all components executed with no crashes (logical sample errors are expected)."
