<?php
/**
 * The GitHub updater, inside a real WordPress request.
 *
 * The test request is a front-end request: is_admin() is false while
 * WordPress loads the plugin, as it is for wp-cron, REST and WP-CLI. Before
 * the fix in #38 the updater was only constructed in wp-admin, so the first
 * three tests here fail on that code. UpdaterContextTest repeats the check in
 * genuine cron, REST and WP-CLI requests.
 *
 * The GitHub API is never called: ASM_Test_HTTP answers on pre_http_request.
 *
 * @package AutoSEOManager\Tests
 */

class UpdaterTest extends WP_UnitTestCase {

    /** @var array Notices, warnings and deprecations raised during a test. */
    protected $php_errors = array();

    public function set_up() {
        parent::set_up();

        // Loaded here so the method-level tests run even where the plugin
        // did not load its updater. Whether the plugin registered it is what
        // the first tests check, through its hooks.
        if (!class_exists('AutoSEOUpdater')) {
            require_once ASM_TEST_ROOT . '/updater.php';
        }

        ASM_Test_HTTP::reset();
        delete_site_transient(AutoSEOUpdater::CACHE_KEY);
        delete_site_transient('update_plugins');

        $this->php_errors = array();
        set_error_handler(function ($no, $message, $file, $line) {
            $this->php_errors[] = "{$message} at {$file}:{$line}";

            return true;
        });
    }

    public function tear_down() {
        restore_error_handler();
        ASM_Test_HTTP::reset();

        parent::tear_down();
    }

    /**
     * The instance the plugin itself constructed while WordPress loaded.
     *
     * @return AutoSEOUpdater|null
     */
    protected function plugin_updater() {
        global $wp_filter;

        // The plugin does not keep a reference to its updater, so find the
        // instance through the hook it registers.
        if (empty($wp_filter['pre_set_site_transient_update_plugins'])) {
            return null;
        }

        foreach ($wp_filter['pre_set_site_transient_update_plugins']->callbacks as $callbacks) {
            foreach ($callbacks as $cb) {
                if (is_array($cb['function']) && $cb['function'][0] instanceof AutoSEOUpdater) {
                    return $cb['function'][0];
                }
            }
        }

        return null;
    }

    /**
     * A fresh instance for testing one method in isolation.
     *
     * @return AutoSEOUpdater
     */
    protected function updater() {
        return new AutoSEOUpdater(WP_PLUGIN_DIR . '/' . ASM_TEST_PLUGIN);
    }

    protected function assertNoPhpErrors() {
        $this->assertSame(array(), $this->php_errors, 'The updater raised PHP notices or warnings.');
    }

    /* ------------------------------------------------- registered everywhere */

    public function test_the_updater_is_registered_outside_wp_admin() {
        $this->assertFalse(is_admin(), 'Precondition: this is not a wp-admin request.');
        $this->assertTrue(class_exists('AutoSEOManager'), 'Precondition: the plugin is loaded.');
        $this->assertContains(ASM_TEST_PLUGIN, get_option('active_plugins'), 'Precondition: the plugin is active under its release slug.');

        $updater = $this->plugin_updater();

        $this->assertInstanceOf(AutoSEOUpdater::class, $updater, 'The plugin did not construct its updater on a non-admin request.');
        $this->assertSame(10, has_filter('pre_set_site_transient_update_plugins', array($updater, 'inject_update')));
        $this->assertSame(10, has_filter('plugins_api', array($updater, 'plugin_details')));
        $this->assertSame(10, has_filter('http_request_args', array($updater, 'authorise_download')));
    }

    public function test_wp_update_plugins_outside_wp_admin_offers_the_github_release() {
        ASM_Test_HTTP::serve_release(ASM_Test_HTTP::release('v9.9.9'));

        wp_update_plugins();

        $transient = get_site_transient('update_plugins');

        $this->assertIsObject($transient);
        $this->assertArrayHasKey(ASM_TEST_PLUGIN, $transient->response, 'wp_update_plugins() outside wp-admin did not see the release.');

        $item = $transient->response[ASM_TEST_PLUGIN];

        $this->assertSame('9.9.9', $item->new_version);
        $this->assertSame(ASM_Test_HTTP::asset_download_url('v9.9.9'), $item->package);
        $this->assertSame(ASM_TEST_SLUG, $item->slug);
        $this->assertSame(ASM_TEST_PLUGIN, $item->plugin);
        $this->assertCount(1, ASM_Test_HTTP::requests_to(ASM_Test_HTTP::latest_url()));
        $this->assertNoPhpErrors();
    }

    public function test_the_automatic_updater_would_install_the_release_only_when_enabled() {
        require_once ABSPATH . 'wp-admin/includes/admin.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

        ASM_Test_HTTP::serve_release(ASM_Test_HTTP::release('v9.9.9'));
        wp_update_plugins();

        $transient = get_site_transient('update_plugins');
        $this->assertArrayHasKey(ASM_TEST_PLUGIN, $transient->response, 'No update offer for the automatic updater to act on.');

        // The WordPress test library switches background updates off for
        // every test (automatic_updater_disabled); a site does not.
        remove_filter('automatic_updater_disabled', '__return_true');

        $item     = $transient->response[ASM_TEST_PLUGIN];
        $upgrader = new WP_Automatic_Updater();
        $this->assertFalse($upgrader->is_disabled(), 'Precondition: background updates are available.');

        update_site_option('auto_update_plugins', array());
        $this->assertFalse($upgrader->should_update('plugin', $item, WP_PLUGIN_DIR), 'Auto-update must stay off until the site enables it.');

        update_site_option('auto_update_plugins', array(ASM_TEST_PLUGIN));
        $this->assertTrue($upgrader->should_update('plugin', $item, WP_PLUGIN_DIR), 'With auto-updates enabled for the plugin, WordPress should take the update.');

        // Plugin_Upgrader reads the package from the same transient.
        $this->assertNotEmpty($item->package);
        $this->assertNoPhpErrors();
    }

    /* ------------------------------------------------------ failure caching */

    public static function failure_responses() {
        return array(
            '404 (private repo, no token)' => array(404, '{"message":"Not Found"}'),
            '500'                           => array(500, 'Internal Server Error'),
            '403 rate limited'              => array(403, '{"message":"API rate limit exceeded"}'),
            'timeout'                       => array('timeout', ''),
            '200 with HTML'                 => array(200, '<html>maintenance</html>'),
            '200 without a tag'             => array(200, '{"name":"draft"}'),
        );
    }

    /**
     * @dataProvider failure_responses
     *
     * @param int|string $code HTTP status, or 'timeout'.
     * @param string     $body Body.
     */
    public function test_a_failed_check_is_cached_for_thirty_minutes_without_notices($code, $body) {
        ASM_Test_HTTP::on(
            ASM_Test_HTTP::latest_url(),
            'timeout' === $code
                ? new WP_Error('http_request_failed', 'cURL error 28: Operation timed out after 10001 milliseconds')
                : ASM_Test_HTTP::response($body, $code)
        );

        $updater   = $this->updater();
        $transient = (object) array('response' => array(), 'checked' => array());

        $result = $updater->inject_update($transient);

        $this->assertSame(array(), $result->response, 'A failed check must not offer an update.');
        $this->assertSame(array(), get_site_transient(AutoSEOUpdater::CACHE_KEY), 'The failure should be cached as an empty array.');

        $expires = (int) get_option('_site_transient_timeout_' . AutoSEOUpdater::CACHE_KEY);
        $this->assertEqualsWithDelta(time() + 30 * MINUTE_IN_SECONDS, $expires, 5, 'Failures are cached for about thirty minutes.');

        // The cache is honoured: a second check does not call GitHub again.
        $updater->inject_update($transient);
        $this->assertCount(1, ASM_Test_HTTP::requests_to(ASM_Test_HTTP::latest_url()));

        $this->assertNoPhpErrors();
    }

    public function test_a_successful_check_is_cached_for_six_hours() {
        ASM_Test_HTTP::serve_release(ASM_Test_HTTP::release('v9.9.9'));

        $this->updater()->inject_update(new stdClass());

        $expires = (int) get_option('_site_transient_timeout_' . AutoSEOUpdater::CACHE_KEY);
        $this->assertEqualsWithDelta(time() + 6 * HOUR_IN_SECONDS, $expires, 5);
        $this->assertSame('9.9.9', get_site_transient(AutoSEOUpdater::CACHE_KEY)['version']);
    }

    /* ------------------------------------------------------------- versions */

    public static function release_versions() {
        return array(
            'same version, v prefix'      => array('v' . AUTO_SEO_VERSION, null),
            'same version, bare'          => array(AUTO_SEO_VERSION, null),
            'older version'               => array('v1.0.0', null),
            'older, bare'                 => array('0.9.99', null),
            'newer, v prefix'             => array('v99.2.3', '99.2.3'),
            'newer, bare'                 => array('99.2.3', '99.2.3'),
            'newer, capital V'            => array('V99.2.3', '99.2.3'),
            'newer minor than installed'  => array('v' . self::bump(AUTO_SEO_VERSION, 1), self::bump(AUTO_SEO_VERSION, 1)),
            'newer patch than installed'  => array('v' . self::bump(AUTO_SEO_VERSION, 2), self::bump(AUTO_SEO_VERSION, 2)),
        );
    }

    /**
     * AUTO_SEO_VERSION with one segment incremented.
     *
     * @param string $version Version.
     * @param int    $segment 0 major, 1 minor, 2 patch.
     * @return string
     */
    protected static function bump($version, $segment) {
        $parts = array_map('intval', explode('.', $version)) + array(0, 0, 0);
        $parts[$segment]++;

        return implode('.', $parts);
    }

    /**
     * @dataProvider release_versions
     *
     * @param string      $tag      Release tag.
     * @param string|null $expected Offered version, or null for no offer.
     */
    public function test_only_a_newer_release_is_offered($tag, $expected) {
        ASM_Test_HTTP::serve_release(ASM_Test_HTTP::release($tag));

        $result = $this->updater()->inject_update((object) array('response' => array()));

        if (null === $expected) {
            $this->assertArrayNotHasKey(ASM_TEST_PLUGIN, $result->response);
        } else {
            $this->assertSame($expected, $result->response[ASM_TEST_PLUGIN]->new_version);
        }

        $this->assertNoPhpErrors();
    }

    public function test_the_v_prefix_does_not_matter() {
        ASM_Test_HTTP::serve_release(ASM_Test_HTTP::release('v1.2.3'));
        $with = $this->updater()->inject_update(new stdClass());

        delete_site_transient(AutoSEOUpdater::CACHE_KEY);
        ASM_Test_HTTP::serve_release(ASM_Test_HTTP::release('1.2.3'));
        $without = $this->updater()->inject_update(new stdClass());

        $this->assertEquals($with, $without, "'v1.2.3' and '1.2.3' must be treated as the same release.");
    }

    public function test_a_non_object_transient_is_returned_untouched() {
        $this->assertFalse($this->updater()->inject_update(false));
        $this->assertCount(0, ASM_Test_HTTP::$requests, 'No API call for a transient WordPress is not saving.');
    }

    /* ------------------------------------------------------ asset selection */

    public function test_the_zip_asset_is_preferred_and_a_nameless_asset_raises_no_notice() {
        $assets = array(
            array(
                'name'                 => 'auto-seo-manager-src.tar.gz',
                'browser_download_url' => 'https://github.com/' . ASM_TEST_REPO . '/archive/v9.9.9.tar.gz',
            ),
            // Malformed entry: no name at all.
            array(
                'browser_download_url' => 'https://github.com/' . ASM_TEST_REPO . '/releases/download/v9.9.9/unnamed',
                'url'                  => 'https://api.github.com/repos/' . ASM_TEST_REPO . '/releases/assets/7',
            ),
            array(
                'name' => 'missing-url.zip',
            ),
            array(
                'name'                 => ASM_TEST_SLUG . '.zip',
                'browser_download_url' => ASM_Test_HTTP::asset_download_url(),
                'url'                  => ASM_Test_HTTP::asset_api_url(),
            ),
        );

        ASM_Test_HTTP::serve_release(ASM_Test_HTTP::release('v9.9.9', $assets));

        $result = $this->updater()->inject_update(new stdClass());

        $this->assertSame(ASM_Test_HTTP::asset_download_url(), $result->response[ASM_TEST_PLUGIN]->package);
        $this->assertNoPhpErrors();
    }

    public function test_a_release_without_a_zip_asset_offers_nothing() {
        $assets = array(
            array(
                'name'                 => 'source.tar.gz',
                'browser_download_url' => 'https://github.com/' . ASM_TEST_REPO . '/archive/v9.9.9.tar.gz',
            ),
        );

        ASM_Test_HTTP::serve_release(ASM_Test_HTTP::release('v9.9.9', $assets));

        $result = $this->updater()->inject_update((object) array('response' => array()));

        $this->assertSame(array(), $result->response, 'Without the built zip WordPress would install the wrong folder.');
        $this->assertNoPhpErrors();
    }

    public function test_a_release_with_no_assets_offers_nothing() {
        $release = ASM_Test_HTTP::release('v9.9.9', array());
        unset($release['assets']);
        ASM_Test_HTTP::serve_release($release);

        $result = $this->updater()->inject_update((object) array('response' => array()));

        $this->assertSame(array(), $result->response);
        $this->assertNoPhpErrors();
    }

    /* ------------------------------------------------- "View details" modal */

    public function test_plugins_api_returns_the_release_details() {
        require_once ABSPATH . 'wp-admin/includes/plugin-install.php';

        ASM_Test_HTTP::serve_release(ASM_Test_HTTP::release('v9.9.9'));

        $info = plugins_api('plugin_information', array('slug' => ASM_TEST_SLUG));

        $this->assertIsObject($info);
        $this->assertSame('Nyuchi WordPress Optimization', $info->name);
        $this->assertSame(ASM_TEST_SLUG, $info->slug);
        $this->assertSame('9.9.9', $info->version);
        $this->assertSame(ASM_Test_HTTP::asset_download_url(), $info->download_link);
        $this->assertSame('2026-10-26T12:00:00Z', $info->last_updated);
        $header = get_file_data(WP_PLUGIN_DIR . '/' . ASM_TEST_PLUGIN, array('requires_php' => 'Requires PHP'));
        $this->assertSame($header['requires_php'], $info->requires_php, 'The modal must state the plugin header\'s PHP floor.');
        $this->assertStringContainsString("What's changed", $info->sections['changelog']);
        $this->assertStringNotContainsString('<script', $info->sections['changelog'], 'Release notes must be sanitised before display.');
        $this->assertNoPhpErrors();
    }

    public function test_plugins_api_leaves_other_plugins_and_actions_alone() {
        $updater = $this->updater();

        $this->assertFalse($updater->plugin_details(false, 'plugin_information', (object) array('slug' => 'akismet')));
        $this->assertFalse($updater->plugin_details(false, 'query_plugins', (object) array('slug' => ASM_TEST_SLUG)));
        $this->assertFalse($updater->plugin_details(false, 'plugin_information', (object) array()));
        $this->assertCount(0, ASM_Test_HTTP::$requests, 'No GitHub call for a plugin that is not ours.');
    }

    public function test_plugins_api_falls_through_when_github_fails() {
        ASM_Test_HTTP::on(ASM_Test_HTTP::latest_url(), ASM_Test_HTTP::response('', 500));

        $this->assertFalse($this->updater()->plugin_details(false, 'plugin_information', (object) array('slug' => ASM_TEST_SLUG)));
        $this->assertNoPhpErrors();
    }

    /* ------------------------------------------------- download authorisation */

    /**
     * Headers the network saw for a request the updater's filters ran on.
     *
     * @param string $url URL.
     * @return array
     */
    protected function headers_sent_to($url) {
        ASM_Test_HTTP::on($url, ASM_Test_HTTP::response('ok'));
        wp_remote_get($url, array('headers' => array('Accept' => 'text/plain')));

        $seen = ASM_Test_HTTP::requests_to($url);
        $this->assertNotEmpty($seen, "No request reached {$url}");

        $last = end($seen);

        return isset($last['args']['headers']) ? $last['args']['headers'] : array();
    }

    public function test_no_authorization_header_without_a_token() {
        $this->assertFalse(defined('AUTO_SEO_GITHUB_TOKEN'), 'Precondition: no token configured.');

        ASM_Test_HTTP::serve_release(ASM_Test_HTTP::release('v9.9.9'));
        $this->updater()->inject_update(new stdClass());

        $api = ASM_Test_HTTP::requests_to(ASM_Test_HTTP::latest_url());
        $this->assertArrayNotHasKey('Authorization', $api[0]['args']['headers']);

        $headers = $this->headers_sent_to(ASM_Test_HTTP::asset_api_url());
        $this->assertArrayNotHasKey('Authorization', $headers);
        $this->assertSame('text/plain', $headers['Accept'], 'Without a token the request must be left as it was.');
    }

    public function test_the_token_is_sent_to_the_repository_api_only() {
        add_filter('auto_seo_updater_token', function () {
            return 'test-token-123';
        });

        $headers = $this->headers_sent_to(ASM_Test_HTTP::asset_api_url());
        $this->assertSame('Bearer test-token-123', $headers['Authorization']);
        $this->assertSame('application/octet-stream', $headers['Accept']);

        // The release API call carries it too, so a private repository works.
        ASM_Test_HTTP::serve_release(ASM_Test_HTTP::release('v9.9.9'));
        $result = $this->updater()->inject_update(new stdClass());
        $api    = ASM_Test_HTTP::requests_to(ASM_Test_HTTP::latest_url());
        $this->assertSame('Bearer test-token-123', $api[0]['args']['headers']['Authorization']);

        // With a token the package is the API asset URL, which needs it.
        $this->assertSame(ASM_Test_HTTP::asset_api_url(), $result->response[ASM_TEST_PLUGIN]->package);
    }

    public static function urls_that_must_never_see_the_token() {
        $repo = ASM_TEST_REPO;

        return array(
            'another repository'           => array('https://api.github.com/repos/someone/else/releases/assets/1'),
            'github.com page'              => array("https://github.com/{$repo}/releases/download/v9.9.9/x.zip"),
            'GitHub asset storage'         => array('https://objects.githubusercontent.com/github-production-release-asset/1?X-Amz-Signature=x'),
            'other host, our path in path' => array("https://evil.example/api.github.com/repos/{$repo}/releases/assets/1"),
            'other host, our path in query' => array("https://evil.example/collect?u=api.github.com/repos/{$repo}/x"),
            'lookalike host'               => array("https://api.github.com.evil.example/repos/{$repo}/releases/assets/1"),
            'userinfo trick'               => array("https://api.github.com@evil.example/repos/{$repo}/releases/assets/1"),
            'plain http'                   => array("http://api.github.com/repos/{$repo}/releases/assets/1"),
            'wordpress.org'                => array('https://api.wordpress.org/plugins/update-check/1.1/'),
        );
    }

    /**
     * @dataProvider urls_that_must_never_see_the_token
     *
     * @param string $url URL.
     */
    public function test_the_token_never_reaches_another_host($url) {
        add_filter('auto_seo_updater_token', function () {
            return 'test-token-123';
        });

        $headers = $this->headers_sent_to($url);

        $this->assertArrayNotHasKey('Authorization', $headers, "The GitHub token was attached to {$url}");
        $this->assertSame('text/plain', $headers['Accept']);
    }
}
