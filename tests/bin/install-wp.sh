#!/usr/bin/env bash
# Download WordPress core and the SQLite Database Integration drop-in, so the
# PHPUnit suite runs against a real WordPress without a MySQL server.
#
#   tests/bin/install-wp.sh            # latest WordPress into $WP_CORE_DIR
#   WP_VERSION=7.1.3 tests/bin/install-wp.sh
#
# WP_CORE_DIR defaults to ${TMPDIR:-/tmp}/wordpress-tests. Everything is
# downloaded into that one directory; nothing in it is executed by this script.
# The test library itself (wp-phpunit/wp-phpunit) comes from Composer.

set -euo pipefail

WP_VERSION="${WP_VERSION:-latest}"
SQLITE_VERSION="${SQLITE_VERSION:-3.1.1}"
WP_CORE_DIR="${WP_CORE_DIR:-${TMPDIR:-/tmp}/wordpress-tests}"
WP_CORE_DIR="${WP_CORE_DIR%/}"

if [ -f "$WP_CORE_DIR/wp-settings.php" ] && [ -f "$WP_CORE_DIR/wp-content/db.php" ]; then
  echo "WordPress already installed in $WP_CORE_DIR"
  exit 0
fi

# Canonical path: WordPress refuses to create directories under a path that
# contains "..", so a WP_CORE_DIR like "$GITHUB_WORKSPACE/../x" would leave
# wp-content/uploads missing and every test erroring.
mkdir -p "$WP_CORE_DIR"
WP_CORE_DIR="$(cd "$WP_CORE_DIR" && pwd -P)"

work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

if [ "$WP_VERSION" = "latest" ]; then
  wp_url="https://wordpress.org/latest.tar.gz"
else
  wp_url="https://wordpress.org/wordpress-${WP_VERSION}.tar.gz"
fi

echo "Downloading $wp_url"
curl -sSfL --retry 3 -o "$work/wordpress.tar.gz" "$wp_url"
mkdir -p "$WP_CORE_DIR"
tar -xzf "$work/wordpress.tar.gz" -C "$work"
cp -R "$work/wordpress/." "$WP_CORE_DIR/"

sqlite_url="https://downloads.wordpress.org/plugin/sqlite-database-integration.${SQLITE_VERSION}.zip"
echo "Downloading $sqlite_url"
curl -sSfL --retry 3 -o "$work/sqlite.zip" "$sqlite_url"
unzip -q "$work/sqlite.zip" -d "$work"

sqlite_dir="$WP_CORE_DIR/wp-content/sqlite-database-integration"
rm -rf "$sqlite_dir"
cp -R "$work/sqlite-database-integration" "$sqlite_dir"

# The drop-in is a template: point it at the implementation explicitly. It
# lives outside wp-content/plugins on purpose, so a test that swaps
# WP_PLUGIN_DIR for a scratch directory still reaches the database.
sed -e "s#{SQLITE_IMPLEMENTATION_FOLDER_PATH}#${sqlite_dir}#" \
  -e "s#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#" \
  "$sqlite_dir/db.copy" > "$WP_CORE_DIR/wp-content/db.php"

mkdir -p "$WP_CORE_DIR/wp-content/database" "$WP_CORE_DIR/wp-content/uploads"

echo "WordPress $(sed -n "s/^\$wp_version = '\(.*\)';/\1/p" "$WP_CORE_DIR/wp-includes/version.php") ready in $WP_CORE_DIR"
