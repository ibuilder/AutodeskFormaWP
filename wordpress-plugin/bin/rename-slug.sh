#!/usr/bin/env bash
#
# Renames the plugin slug, text domain and display name.
#
#   ./bin/rename-slug.sh publisher-for-autodesk-forma "Publisher for Autodesk Forma"
#
# WordPress.org guideline 17 forbids a slug that begins with someone else's
# trademark, so a plugin that interoperates with Autodesk Forma has to be named
# "Feature for Brand" rather than "Brand Feature".
#
# What this changes, and why only these things:
#
#   * The plugin directory and main file name — these ARE the slug.
#   * The Text Domain header and every translation call — Plugin Check requires
#     the text domain to equal the slug.
#   * The textdomain field in every block.json, for the same reason.
#   * The display name in the plugin header and readme.txt.
#
# What it deliberately leaves alone:
#
#   * Block names such as forma-publisher/project. These are written into saved
#     post content, so renaming them would silently break every existing page.
#     A block namespace does not have to match the slug.
#   * CSS class names, the REST namespace, option names, meta keys, function
#     prefixes and constants. None of these are required to match the slug, and
#     changing them would break existing installations for no benefit.
#
set -euo pipefail

OLD_SLUG='forma-publisher'
NEW_SLUG="${1:-}"
NEW_NAME="${2:-}"

if [ -z "${NEW_SLUG}" ] || [ -z "${NEW_NAME}" ]; then
	echo "Usage: $0 <new-slug> <\"New Display Name\">" >&2
	echo "Example: $0 publisher-for-autodesk-forma \"Publisher for Autodesk Forma\"" >&2
	exit 1
fi

if ! printf '%s' "${NEW_SLUG}" | grep -Eq '^[a-z0-9]+(-[a-z0-9]+)*$'; then
	echo "The slug must be lowercase letters, digits and single hyphens." >&2
	exit 1
fi

case "${NEW_SLUG}" in
	forma-*|autodesk-*)
		echo "Refusing: a slug beginning with a trademarked term is what this script exists to fix." >&2
		echo "Use the \"feature-for-brand\" form, for example publisher-for-autodesk-forma." >&2
		exit 1
		;;
esac

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN="${ROOT}/${OLD_SLUG}"

if [ ! -d "${PLUGIN}" ]; then
	echo "Plugin directory not found: ${PLUGIN}" >&2
	exit 1
fi

echo "Renaming ${OLD_SLUG} -> ${NEW_SLUG}"

# 1. Translation calls, the Text Domain header and block.json textdomain fields.
#    Matching the quoted string avoids touching block names, CSS classes and the
#    REST namespace, which all contain the same characters unquoted or in paths.
find "${PLUGIN}" -type f \( -name '*.php' -o -name '*.js' -o -name '*.json' \) -print0 |
	while IFS= read -r -d '' file; do
		sed -i.bak \
			-e "s/'${OLD_SLUG}'/'${NEW_SLUG}'/g" \
			-e "s/\"${OLD_SLUG}\"/\"${NEW_SLUG}\"/g" \
			"${file}"
		rm -f "${file}.bak"
	done

# 2. Headers that carry the slug or the display name.
sed -i.bak \
	-e "s/^ \* Plugin Name:.*/ * Plugin Name:       ${NEW_NAME}/" \
	-e "s/^ \* Text Domain:.*/ * Text Domain:       ${NEW_SLUG}/" \
	"${PLUGIN}/${OLD_SLUG}.php"
rm -f "${PLUGIN}/${OLD_SLUG}.php.bak"

# 3. readme.txt title.
sed -i.bak "1s/^=== .* ===$/=== ${NEW_NAME} ===/" "${PLUGIN}/readme.txt"
rm -f "${PLUGIN}/readme.txt.bak"

# 4. The main file and the directory are the slug.
mv "${PLUGIN}/${OLD_SLUG}.php" "${PLUGIN}/${NEW_SLUG}.php"
mv "${PLUGIN}" "${ROOT}/${NEW_SLUG}"

# 5. The translation template is named after the slug.
if [ -f "${ROOT}/${NEW_SLUG}/languages/${OLD_SLUG}.pot" ]; then
	mv "${ROOT}/${NEW_SLUG}/languages/${OLD_SLUG}.pot" "${ROOT}/${NEW_SLUG}/languages/${NEW_SLUG}.pot"
fi

# 6. Tooling that refers to the slug by path or configuration.
for file in "${ROOT}/phpcs.xml.dist" "${ROOT}/bin/build-zip.sh" "${ROOT}/tests/setup-wp.sh"; do
	[ -f "${file}" ] && sed -i.bak "s/${OLD_SLUG}/${NEW_SLUG}/g" "${file}" && rm -f "${file}.bak"
done

for file in "${ROOT}/../.github/workflows/ci.yml" "${ROOT}/../.github/workflows/release.yml"; do
	[ -f "${file}" ] && sed -i.bak "s/${OLD_SLUG}/${NEW_SLUG}/g" "${file}" && rm -f "${file}.bak"
done

cat <<EOF

Renamed.

Next:
  1. cd "${ROOT}" && vendor/bin/phpcs
  2. Regenerate the translation template:
       wp i18n make-pot ${NEW_SLUG} ${NEW_SLUG}/languages/${NEW_SLUG}.pot \\
         --slug=${NEW_SLUG} --domain=${NEW_SLUG}
  3. Run the integration suite and Plugin Check against the renamed plugin.
  4. Search the repository for any remaining references:
       grep -rn "${OLD_SLUG}" --exclude-dir=node_modules --exclude-dir=vendor .

Block names, CSS classes, the REST namespace and option names were left
unchanged on purpose. See the comment at the top of this script.
EOF
