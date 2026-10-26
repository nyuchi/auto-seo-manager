<?php
/**
 * PHPUnit bootstrap: a real WordPress (wp-phpunit) on SQLite.
 *
 * The plugin is loaded the way a site loads it: it is listed in
 * active_plugins and WordPress includes it from wp-content/plugins during
 * wp-settings.php. Loading it any other way (a bare require, or on
 * muplugins_loaded) would skip exactly the request-context logic the updater
 * tests exist to check.
 *
 * Run tests/bin/install-wp.sh once first. See TESTING.md.
 *
 * @package AutoSEOManager\Tests
 */

$asm_root = dirname(__DIR__);

require_once $asm_root . '/vendor/autoload.php';
require_once __DIR__ . '/includes/asm-test-env.php';

asm_test_env_check();

putenv('WP_PHPUNIT__TESTS_CONFIG=' . __DIR__ . '/wp-tests-config.php');

if (!defined('WP_TESTS_PHPUNIT_POLYFILLS_PATH')) {
    define('WP_TESTS_PHPUNIT_POLYFILLS_PATH', $asm_root . '/vendor/yoast/phpunit-polyfills');
}

$asm_tests_dir = getenv('WP_PHPUNIT__DIR');

require_once $asm_tests_dir . '/includes/functions.php';

// Make the working tree an installed plugin: wp-content/plugins/<slug> is a
// symlink to it, so plugin_basename() gives the same answer as on a site.
asm_test_link_plugin($asm_root);

tests_add_filter('pre_option_active_plugins', function () {
    return array(ASM_TEST_PLUGIN);
});

// No test may reach the network. Anything that asks is answered by a mock or
// refused; see ASM_Test_HTTP.
tests_add_filter('pre_http_request', array('ASM_Test_HTTP', 'handle'), 10, 3);

require_once __DIR__ . '/includes/class-asm-test-http.php';

require $asm_tests_dir . '/includes/bootstrap.php';
