<?php
/**
 * The GitHub token must not follow a redirect to another host.
 *
 * A private-repository download goes to the API asset URL with the token,
 * and GitHub answers with a 302 to its storage host. WordPress's HTTP library
 * follows redirects itself and re-sends every request header, Authorization
 * included, to wherever the redirect points.
 *
 * This uses real HTTP through WordPress's transport against PHP's built-in
 * server on 127.0.0.1, redirecting to "localhost" (a different host name, as
 * far as the client is concerned). The first hop stands in for
 * api.github.com, since the test cannot serve that name.
 *
 * @package AutoSEOManager\Tests
 */

class TokenRedirectTest extends WP_UnitTestCase {

    /** @var resource|null */
    protected static $server;

    /** @var int */
    protected static $port;

    public static function wpSetUpBeforeClass() {
        for ($i = 0; $i < 10 && !self::$server; $i++) {
            $port = wp_rand(20000, 45000);
            $proc = proc_open(
                array(PHP_BINARY, '-S', '127.0.0.1:' . $port, ASM_TEST_ROOT . '/tests/fixtures/http-server.php'),
                array(1 => array('file', '/dev/null', 'w'), 2 => array('file', '/dev/null', 'w')),
                $pipes,
                ASM_TEST_ROOT . '/tests/fixtures'
            );

            for ($wait = 0; $wait < 50; $wait++) {
                $sock = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1); // phpcs:ignore WordPress.PHP.NoSilencedErrors

                if ($sock) {
                    fclose($sock);
                    self::$server = $proc;
                    self::$port   = $port;
                    break;
                }

                usleep(100000);
            }

            if (!self::$server) {
                proc_terminate($proc);
            }
        }
    }

    public static function wpTearDownAfterClass() {
        if (self::$server) {
            proc_terminate(self::$server);
            proc_close(self::$server);
            self::$server = null;
        }
    }

    public function set_up() {
        parent::set_up();

        if (!self::$server) {
            $this->fail('Could not start the PHP built-in web server.');
        }

        ASM_Test_HTTP::reset();
        ASM_Test_HTTP::on('http://127.0.0.1:' . self::$port . '/', false);
        ASM_Test_HTTP::on('http://localhost:' . self::$port . '/', false);

        add_filter('auto_seo_updater_token', function () {
            return 'test-token-123';
        });
    }

    /**
     * Follow a cross-host redirect carrying the token; return the headers the
     * second host received.
     *
     * @return array
     */
    protected function headers_after_redirect() {
        $target   = 'http://localhost:' . self::$port . '/echo';
        $response = wp_remote_get(
            'http://127.0.0.1:' . self::$port . '/redirect?to=' . rawurlencode($target),
            array(
                'headers' => array('Authorization' => 'Bearer test-token-123'),
                'timeout' => 5,
            )
        );

        $this->assertNotWPError($response);
        $this->assertSame(200, wp_remote_retrieve_response_code($response));

        return json_decode(wp_remote_retrieve_body($response), true);
    }

    public function test_wordpress_forwards_headers_across_hosts_on_redirect() {
        // What the updater has to defend against: without its redirect hook,
        // the second host receives the token.
        remove_all_actions('requests-requests.before_redirect');

        $headers = $this->headers_after_redirect();

        $this->assertSame('Bearer test-token-123', $headers['authorization']);
    }

    public function test_the_token_does_not_follow_a_redirect_to_another_host() {
        $headers = $this->headers_after_redirect();

        $this->assertArrayNotHasKey('authorization', $headers, 'The GitHub token was forwarded to the redirect target.');
    }

    public function test_other_authorization_headers_are_left_alone() {
        $target   = 'http://localhost:' . self::$port . '/echo';
        $response = wp_remote_get(
            'http://127.0.0.1:' . self::$port . '/redirect?to=' . rawurlencode($target),
            array('headers' => array('Authorization' => 'Basic dXNlcjpwYXNz'))
        );

        $headers = json_decode(wp_remote_retrieve_body($response), true);

        $this->assertSame('Basic dXNlcjpwYXNz', $headers['authorization'], 'Only our own token is the updater\'s business.');
    }
}
