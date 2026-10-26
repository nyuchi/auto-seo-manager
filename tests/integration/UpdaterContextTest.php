<?php
/**
 * The updater in the requests WordPress actually checks for updates from.
 *
 * Each test boots a fresh WordPress in a child process (tests/request.php)
 * with the context's constants defined before the plugin loads: DOING_CRON
 * for wp-cron, REST_REQUEST for the REST API (and so MCP abilities), WP_CLI
 * for WP-CLI, WP_ADMIN for wp-admin. The child gets a copy of the test
 * database and its own plugins directory holding a copy of the plugin, so the
 * automatic-update test can let WordPress really replace the plugin.
 *
 * Before #38 only the wp-admin case offered the release.
 *
 * @package AutoSEOManager\Tests
 */

class UpdaterContextTest extends WP_UnitTestCase {

    /** @var string[] Scratch directories to remove. */
    protected $scratch = array();

    public function tear_down() {
        foreach ($this->scratch as $dir) {
            asm_test_rmdir($dir);
        }

        // An interrupted update leaves these in the shared WordPress install.
        @unlink(ABSPATH . '.maintenance'); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        asm_test_rmdir(WP_CONTENT_DIR . '/upgrade');
        asm_test_rmdir(WP_CONTENT_DIR . '/upgrade-temp-backup');

        parent::tear_down();
    }

    /**
     * Run tests/request.php and return its result.
     *
     * @param string $context  front | admin | cron | rest | cli.
     * @param string $scenario check | auto-update | auth.
     * @param array  $env      Extra environment.
     * @return array
     */
    protected function request($context, $scenario, $env = array()) {
        $tmp = rtrim(sys_get_temp_dir(), '/') . '/asm-request-' . uniqid('', true);
        mkdir($tmp, 0777, true);
        $this->scratch[] = $tmp;

        // A consistent copy of the test database, committed state only.
        $db  = $tmp . '/db.sqlite';
        $pdo = new PDO('sqlite:' . DB_DIR . DB_FILE);
        $pdo->exec('VACUUM INTO ' . $pdo->quote($db));
        $pdo = null;

        asm_test_copy_plugin($tmp . '/plugins/' . ASM_TEST_SLUG);

        $env = array_merge(
            getenv(),
            array(
                'WP_CORE_DIR'          => rtrim(ABSPATH, '/'),
                'ASM_TESTS_DB_FILE'    => $db,
                'ASM_TESTS_PLUGIN_DIR' => $tmp . '/plugins',
            ),
            $env
        );

        $proc = proc_open(
            array(PHP_BINARY, ASM_TEST_ROOT . '/tests/request.php', $context, $scenario),
            array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
            $pipes,
            ASM_TEST_ROOT,
            $env
        );

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        if (!preg_match('/<<<ASM-RESULT\n(.*)\nASM-RESULT>>>/s', $stdout, $m)) {
            $this->fail("The {$context} request failed (exit {$code}).\nSTDOUT:\n{$stdout}\nSTDERR:\n{$stderr}");
        }

        $result = json_decode($m[1], true);
        $result['plugin_dir'] = $tmp . '/plugins/' . ASM_TEST_SLUG;

        return $result;
    }

    /**
     * PHP notices raised by the plugin's own files during the request.
     *
     * @param array $result Request result.
     * @return string[]
     */
    protected function plugin_errors($result) {
        $out = array();

        foreach ($result['errors'] as $e) {
            if (0 === strpos($e['file'], $result['plugin_dir'])) {
                $out[] = "{$e['message']} at {$e['file']}:{$e['line']}";
            }
        }

        return $out;
    }

    /**
     * Build the 9.9.9 release zip the mocked GitHub serves.
     *
     * @return string Path to the zip.
     */
    protected function build_release_zip() {
        $tmp = rtrim(sys_get_temp_dir(), '/') . '/asm-zip-' . uniqid('', true);
        $this->scratch[] = $tmp;

        $src = $tmp . '/' . ASM_TEST_SLUG;
        asm_test_copy_plugin($src, array(AUTO_SEO_VERSION => '9.9.9'));

        $zip_path = $tmp . '/' . ASM_TEST_SLUG . '.zip';
        $zip      = new ZipArchive();
        $zip->open($zip_path, ZipArchive::CREATE);

        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS));

        foreach ($it as $file) {
            $zip->addFile($file->getPathname(), ASM_TEST_SLUG . '/' . substr($file->getPathname(), strlen($src) + 1));
        }

        $zip->close();

        return $zip_path;
    }

    public static function update_check_contexts() {
        return array(
            'wp-cron (DOING_CRON)'  => array('cron'),
            'REST (REST_REQUEST)'   => array('rest'),
            'WP-CLI (WP_CLI)'       => array('cli'),
            'front end'             => array('front'),
            'wp-admin (WP_ADMIN)'   => array('admin'),
        );
    }

    /**
     * @dataProvider update_check_contexts
     *
     * @param string $context Request context.
     */
    public function test_wp_update_plugins_offers_the_release($context) {
        $result = $this->request($context, 'check');

        $this->assertSame('admin' === $context, $result['is_admin'], 'Precondition: is_admin() matches the context.');
        $this->assertSame('cron' === $context, $result['doing_cron'], 'Precondition: wp_doing_cron() matches the context.');

        $this->assertSame(1, $result['updater_hooked'], "The updater is not registered in a {$context} request.");
        $this->assertNotNull($result['response'], "wp_update_plugins() in a {$context} request did not offer the release.");
        $this->assertSame('9.9.9', $result['response']['new_version']);
        $this->assertSame(ASM_Test_HTTP::asset_download_url('v9.9.9'), $result['response']['package']);
        $this->assertSame(ASM_TEST_PLUGIN, $result['response']['plugin']);
        $this->assertContains(ASM_Test_HTTP::latest_url(), $result['requests']);
        $this->assertSame(array(), $this->plugin_errors($result));
    }

    public function test_wp_cron_auto_update_installs_the_release_when_enabled() {
        $result = $this->request('cron', 'auto-update', array(
            'ASM_TESTS_AUTO_UPDATE' => '1',
            'ASM_TESTS_PACKAGE'     => $this->build_release_zip(),
        ));

        $this->assertSame(AUTO_SEO_VERSION, $result['version_before']);
        $this->assertSame('9.9.9', $result['version_after'], 'The background updater did not install the GitHub release.');
        $this->assertContains(ASM_Test_HTTP::asset_download_url('v9.9.9'), $result['requests'], 'The package was never downloaded.');
        $this->assertSame(array(), $this->plugin_errors($result));
    }

    public function test_wp_cron_auto_update_leaves_the_plugin_alone_when_not_enabled() {
        $result = $this->request('cron', 'auto-update', array(
            'ASM_TESTS_PACKAGE' => $this->build_release_zip(),
        ));

        $this->assertNotNull($result['response'], 'Precondition: the update is offered.');
        $this->assertSame('9.9.9', $result['response']['new_version']);
        $this->assertSame(AUTO_SEO_VERSION, $result['version_after'], 'Auto-updates are opt-in per plugin.');
        $this->assertNotContains(ASM_Test_HTTP::asset_download_url('v9.9.9'), $result['requests']);
    }

    public function test_the_token_constant_reaches_the_repository_api_only() {
        $repo = ASM_TEST_REPO;
        $urls = array(
            'https://api.github.com/repos/' . $repo . '/releases/assets/424242',
            'https://github.com/' . $repo . '/releases/download/v9.9.9/x.zip',
            'https://api.github.com/repos/someone/else/releases/assets/1',
            'https://evil.example/api.github.com/repos/' . $repo . '/releases/assets/1',
            'http://api.github.com/repos/' . $repo . '/releases/assets/1',
            'https://example.org/',
        );

        $result = $this->request('cron', 'auth', array(
            'ASM_TESTS_TOKEN' => 'constant-token-456',
            'ASM_TESTS_URLS'  => json_encode($urls),
        ));

        $this->assertArrayHasKey('Authorization', $result['headers'][$urls[0]], 'The repository API did not get the token.');
        $this->assertSame('Bearer constant-token-456', $result['headers'][$urls[0]]['Authorization']);

        foreach (array_slice($urls, 1) as $url) {
            $this->assertArrayNotHasKey('Authorization', $result['headers'][$url], "AUTO_SEO_GITHUB_TOKEN was sent to {$url}");
        }
    }
}
