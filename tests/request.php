<?php
/**
 * Boot one WordPress request in a given context, in its own PHP process.
 *
 *     php tests/request.php <context> <scenario>
 *
 * context:  front | admin | cron | rest | cli
 * scenario: check       - wp_update_plugins(), report the update_plugins transient
 *           auto-update - wp_maybe_auto_update(), the wp-cron background updater
 *           auth        - make the requests in ASM_TESTS_URLS, report their headers
 *
 * Why a separate process: whether the updater is registered is decided once,
 * while WordPress loads plugins, from constants such as DOING_CRON and
 * WP_ADMIN that cannot be undefined again. A fresh process per context is the
 * only honest way to test "WordPress checked for updates from wp-cron".
 *
 * Driven by tests/integration/UpdaterContextTest.php, which hands it a copy of
 * the test database and a scratch plugins directory through the environment
 * (see wp-tests-config.php), so an automatic update can overwrite the plugin
 * without touching the working tree. Prints one JSON object between markers.
 *
 * @package AutoSEOManager\Tests
 */

if ('cli' !== PHP_SAPI) {
    exit(1);
}

$asm_context  = isset($argv[1]) ? $argv[1] : 'front';
$asm_scenario = isset($argv[2]) ? $argv[2] : 'check';
$asm_errors   = array();

require __DIR__ . '/includes/asm-test-env.php';
require __DIR__ . '/includes/class-asm-test-http.php';

// Record every notice, warning and deprecation instead of printing it, so a
// test can assert the request raised none from the plugin.
set_error_handler(function ($no, $message, $file, $line) use (&$asm_errors) {
    $asm_errors[] = array('type' => $no, 'message' => $message, 'file' => $file, 'line' => $line);

    return true;
});

switch ($asm_context) {
    case 'cron':
        // What wp-cron.php defines before it loads WordPress.
        define('DOING_CRON', true);
        $_SERVER['REQUEST_URI'] = '/wp-cron.php';
        break;
    case 'rest':
        define('REST_REQUEST', true);
        $_SERVER['REQUEST_URI'] = '/wp-json/wp-abilities/v1/abilities';
        break;
    case 'cli':
        // What WP-CLI defines before it loads WordPress.
        define('WP_CLI', true);
        break;
    case 'admin':
        define('WP_ADMIN', true);
        $_SERVER['REQUEST_URI'] = '/wp-admin/plugins.php';
        break;
    case 'front':
        break;
    default:
        fwrite(STDERR, "Unknown context {$asm_context}\n");
        exit(1);
}

if (getenv('ASM_TESTS_TOKEN')) {
    define('AUTO_SEO_GITHUB_TOKEN', getenv('ASM_TESTS_TOKEN'));
}

$_SERVER['HTTP_HOST']       = 'example.org';
$_SERVER['SERVER_NAME']     = 'example.org';
$_SERVER['SERVER_PORT']     = '80';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
$_SERVER['REQUEST_METHOD']  = 'GET';
$_SERVER['REMOTE_ADDR']     = '127.0.0.1';

if (!isset($_SERVER['REQUEST_URI'])) {
    $_SERVER['REQUEST_URI'] = '/';
}

$PHP_SELF            = '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';

require __DIR__ . '/wp-tests-config.php';

define('FS_METHOD', 'direct');

// Hooks registered before WordPress loads, the way the test library's
// tests_add_filter() does it.
$asm_hooks = array(
    'pre_http_request'                   => array(array('ASM_Test_HTTP', 'handle'), 3),
    'pre_option_active_plugins'          => array(function () {
        return array(ASM_TEST_PLUGIN);
    }, 1),
    // The scratch directory may sit inside a checkout on a developer machine,
    // and WordPress refuses to auto-update anything under version control.
    // That is a property of where the test runs, not of the plugin.
    'automatic_updates_is_vcs_checkout'  => array('__return_false', 1),
    'auto_plugin_update_send_email'      => array('__return_false', 1),
    'auto_theme_update_send_email'       => array('__return_false', 1),
    'auto_core_update_send_email'        => array('__return_false', 1),
    'send_core_update_notification_email' => array('__return_false', 1),
    'auto_update_core'                   => array('__return_false', 1),
    'auto_update_translation'            => array('__return_false', 1),
);

foreach ($asm_hooks as $asm_hook => $asm_spec) {
    $GLOBALS['wp_filter'][$asm_hook][10][] = array('function' => $asm_spec[0], 'accepted_args' => $asm_spec[1]);
}

require ABSPATH . 'wp-settings.php';

// --------------------------------------------------------------- mocked network

$asm_release = getenv('ASM_TESTS_RELEASE') ? json_decode(getenv('ASM_TESTS_RELEASE'), true) : ASM_Test_HTTP::release();
ASM_Test_HTTP::serve_release($asm_release);

$asm_package = getenv('ASM_TESTS_PACKAGE');

if ($asm_package) {
    $asm_zip = ASM_Test_HTTP::response(file_get_contents($asm_package), 200, array('content-type' => 'application/zip'));
    ASM_Test_HTTP::on(ASM_Test_HTTP::asset_download_url($asm_release['tag_name']), $asm_zip);
    ASM_Test_HTTP::on(ASM_Test_HTTP::asset_api_url(), $asm_zip);
}

// The loopback request the automatic updater makes to look for a fatal error
// in the freshly updated plugin. A page without the scrape marker means none.
ASM_Test_HTTP::on(home_url('/'), ASM_Test_HTTP::response('<html></html>'));

// ------------------------------------------------------------------- scenario

if (getenv('ASM_TESTS_AUTO_UPDATE')) {
    update_site_option('auto_update_plugins', array(ASM_TEST_PLUGIN));
}

$asm_hooked = 0;

if (isset($GLOBALS['wp_filter']['pre_set_site_transient_update_plugins'])) {
    foreach ($GLOBALS['wp_filter']['pre_set_site_transient_update_plugins']->callbacks as $asm_callbacks) {
        foreach ($asm_callbacks as $asm_cb) {
            if (is_array($asm_cb['function']) && $asm_cb['function'][0] instanceof AutoSEOUpdater) {
                $asm_hooked++;
            }
        }
    }
}

$asm_out = array(
    'context'        => $asm_context,
    'scenario'       => $asm_scenario,
    'is_admin'       => is_admin(),
    'doing_cron'     => wp_doing_cron(),
    'updater_hooked' => $asm_hooked,
    'response'       => null,
);

if ('check' === $asm_scenario || 'auto-update' === $asm_scenario) {
    delete_site_transient('update_plugins');
    delete_site_transient('auto_seo_release_check');

    if ('check' === $asm_scenario) {
        wp_update_plugins();
    } else {
        $asm_out['version_before'] = get_plugin_data(WP_PLUGIN_DIR . '/' . ASM_TEST_PLUGIN, false, false)['Version'];
        wp_maybe_auto_update();
        clearstatcache();
        $asm_out['version_after'] = get_plugin_data(WP_PLUGIN_DIR . '/' . ASM_TEST_PLUGIN, false, false)['Version'];
    }

    $asm_transient = get_site_transient('update_plugins');

    if (is_object($asm_transient) && isset($asm_transient->response[ASM_TEST_PLUGIN])) {
        $asm_out['response'] = (array) $asm_transient->response[ASM_TEST_PLUGIN];
    }
}

if ('auth' === $asm_scenario) {
    $asm_out['headers'] = array();

    foreach (json_decode((string) getenv('ASM_TESTS_URLS'), true) as $asm_url) {
        ASM_Test_HTTP::on($asm_url, ASM_Test_HTTP::response('ok'));
        wp_remote_get($asm_url, array('headers' => array('Accept' => 'text/plain')));
        $asm_seen = ASM_Test_HTTP::requests_to($asm_url);
        $asm_out['headers'][$asm_url] = $asm_seen ? end($asm_seen)['args']['headers'] : null;
    }
}

$asm_out['requests'] = array_map(function ($r) {
    return $r['url'];
}, ASM_Test_HTTP::$requests);

$asm_out['errors'] = $asm_errors;

echo "\n<<<ASM-RESULT\n" . json_encode($asm_out) . "\nASM-RESULT>>>\n";
