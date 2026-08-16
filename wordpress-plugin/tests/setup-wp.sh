#!/usr/bin/env bash
#
# Provisions a throwaway WordPress install with the SQLite database drop-in and
# runs the Forma Publisher integration suite against it.
#
# Used by CI and reproducible locally:
#
#   ./tests/setup-wp.sh
#
set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WORK_DIR="${WORK_DIR:-${PLUGIN_DIR}/.wp-test}"
WP_DIR="${WORK_DIR}/wp"
WP_CLI="${WORK_DIR}/wp-cli.phar"

echo "== preparing ${WORK_DIR} =="
rm -rf "${WORK_DIR}"
mkdir -p "${WORK_DIR}"

echo "== downloading tooling =="
curl -sSL -o "${WP_CLI}" https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
curl -sSL -o "${WORK_DIR}/wordpress.tar.gz" https://wordpress.org/latest.tar.gz
curl -sSL -o "${WORK_DIR}/sqlite.zip" https://downloads.wordpress.org/plugin/sqlite-database-integration.zip

mkdir -p "${WP_DIR}"
tar -xzf "${WORK_DIR}/wordpress.tar.gz" -C "${WORK_DIR}"
mv "${WORK_DIR}/wordpress/"* "${WP_DIR}/"

mkdir -p "${WP_DIR}/wp-content/plugins"
unzip -q "${WORK_DIR}/sqlite.zip" -d "${WP_DIR}/wp-content/plugins"

echo "== installing the SQLite drop-in =="
sed \
	-e "s|'{SQLITE_IMPLEMENTATION_FOLDER_PATH}'|__DIR__ . '/plugins/sqlite-database-integration'|" \
	-e "s|'{SQLITE_PLUGIN}'|'sqlite-database-integration/load.php'|" \
	"${WP_DIR}/wp-content/plugins/sqlite-database-integration/db.copy" \
	> "${WP_DIR}/wp-content/db.php"

echo "== writing wp-config.php =="
cat > "${WP_DIR}/wp-config.php" <<'PHP'
<?php
define( 'DB_NAME', 'wordpress' );
define( 'DB_USER', 'root' );
define( 'DB_PASSWORD', '' );
define( 'DB_HOST', 'localhost' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );
define( 'AUTH_KEY',         'forma-ci-key-000000000000000001' );
define( 'SECURE_AUTH_KEY',  'forma-ci-key-000000000000000002' );
define( 'LOGGED_IN_KEY',    'forma-ci-key-000000000000000003' );
define( 'NONCE_KEY',        'forma-ci-key-000000000000000004' );
define( 'AUTH_SALT',        'forma-ci-key-000000000000000005' );
define( 'SECURE_AUTH_SALT', 'forma-ci-key-000000000000000006' );
define( 'LOGGED_IN_SALT',   'forma-ci-key-000000000000000007' );
define( 'NONCE_SALT',       'forma-ci-key-000000000000000008' );
$table_prefix = 'wp_';
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_DISPLAY', true );
define( 'WP_DEBUG_LOG', false );
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
require_once ABSPATH . 'wp-settings.php';
PHP

echo "== linking the plugin =="
ln -s "${PLUGIN_DIR}/publisher-for-autodesk-forma" "${WP_DIR}/wp-content/plugins/publisher-for-autodesk-forma"

echo "== installing WordPress =="
cd "${WP_DIR}"
php "${WP_CLI}" core install \
	--url=http://localhost/forma \
	--title="Forma Publisher CI" \
	--admin_user=admin \
	--admin_password=ci-password-123 \
	--admin_email=ci@example.com \
	--skip-email

php "${WP_CLI}" plugin activate publisher-for-autodesk-forma
php "${WP_CLI}" core version

echo "== running the integration suite =="
php "${WP_CLI}" eval-file "${PLUGIN_DIR}/tests/run.php"
