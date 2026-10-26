<?php
/**
 * WordPress test-library configuration.
 *
 * The suite runs on SQLite through the SQLite Database Integration drop-in
 * that tests/bin/install-wp.sh puts in wp-content/db.php, so no MySQL server
 * is needed locally or in CI. The DB_* constants below are required by the
 * test library but are never used to connect.
 *
 * Read by the test bootstrap and by tests/request.php, which boots a fresh
 * WordPress request in a child process to exercise cron, REST and WP-CLI
 * contexts.
 *
 * @package AutoSEOManager\Tests
 */

$asm_core_dir = getenv('WP_CORE_DIR');

if (!$asm_core_dir) {
    $asm_core_dir = rtrim(sys_get_temp_dir(), '/\\') . '/wordpress-tests';
}

// Canonical: WordPress will not create directories under a path that
// contains "..".
if (realpath($asm_core_dir)) {
    $asm_core_dir = realpath($asm_core_dir);
}

if (!defined('ABSPATH')) {
    define('ABSPATH', rtrim($asm_core_dir, '/\\') . '/');
}

// A child request may be pointed at a copy of the database and a scratch
// plugins directory, so it can change both without touching the parent run.
$asm_db_file = getenv('ASM_TESTS_DB_FILE');

if (!defined('DB_DIR')) {
    define('DB_DIR', $asm_db_file ? dirname($asm_db_file) . '/' : ABSPATH . 'wp-content/database/');
}

if (!defined('DB_FILE')) {
    define('DB_FILE', $asm_db_file ? basename($asm_db_file) : 'asm-tests.sqlite');
}

if (getenv('ASM_TESTS_PLUGIN_DIR') && !defined('WP_PLUGIN_DIR')) {
    define('WP_PLUGIN_DIR', getenv('ASM_TESTS_PLUGIN_DIR'));
}

define('DB_ENGINE', 'sqlite');
define('DB_NAME', 'wordpress_tests');
define('DB_USER', 'unused');
define('DB_PASSWORD', 'unused');
define('DB_HOST', 'localhost');
define('DB_CHARSET', 'utf8');
define('DB_COLLATE', '');

define('WP_DEFAULT_THEME', 'default');
define('WP_DEBUG', true);
define('WP_TESTS_DOMAIN', 'example.org');
define('WP_TESTS_EMAIL', 'admin@example.org');
define('WP_TESTS_TITLE', 'Test Blog');
define('WP_PHP_BINARY', PHP_BINARY);
define('WPLANG', '');

// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- WordPress reads this global.
$table_prefix = 'wptests_';
