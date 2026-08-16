#!/usr/bin/env bash
#
# Builds an installable plugin archive.
#
#   ./bin/build-zip.sh [output-directory]
#
# The archive contains only the plugin directory. Development tooling — tests,
# Composer, PHPCS configuration — lives outside it by design, so nothing needs
# to be stripped and there is no risk of shipping it by accident.
#
set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUTPUT_DIR="${1:-${PLUGIN_DIR}/build}"
SLUG="publisher-for-autodesk-forma"

# sed rather than grep -P: the latter is locale dependent and fails outright on
# some runners.
VERSION="$(sed -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*\(.*\)$/\1/p' \
	"${PLUGIN_DIR}/${SLUG}/${SLUG}.php" | head -n1 | tr -d '[:space:]')"
STABLE="$(sed -n 's/^Stable tag:[[:space:]]*\(.*\)$/\1/p' \
	"${PLUGIN_DIR}/${SLUG}/readme.txt" | head -n1 | tr -d '[:space:]')"

if [ -z "${VERSION}" ]; then
	echo "Could not read the version from the plugin header." >&2
	exit 1
fi

if [ "${VERSION}" != "${STABLE}" ]; then
	echo "Version mismatch: plugin header is ${VERSION} but readme.txt stable tag is ${STABLE}." >&2
	exit 1
fi

mkdir -p "${OUTPUT_DIR}"
ARCHIVE="${OUTPUT_DIR}/${SLUG}-${VERSION}.zip"
rm -f "${ARCHIVE}"

cd "${PLUGIN_DIR}"
zip -r -q "${ARCHIVE}" "${SLUG}" \
	-x "${SLUG}/.*" \
	-x "*/.DS_Store" \
	-x "*/node_modules/*" \
	-x "*/vendor/*"

echo "Built ${ARCHIVE}"
unzip -l "${ARCHIVE}" | tail -n 1
