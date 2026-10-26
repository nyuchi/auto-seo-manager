<?php
/**
 * The network, as far as the tests are concerned.
 *
 * Hooked to pre_http_request, which WordPress applies after
 * http_request_args, so every request is recorded with the headers the
 * plugin's filters gave it. Requests nothing has asked to answer are refused,
 * so a test can never reach GitHub or wordpress.org by accident.
 *
 * @package AutoSEOManager\Tests
 */

class ASM_Test_HTTP {

    /** @var array<int, array{url: string, args: array}> Every request seen. */
    public static $requests = array();

    /** @var array<string, mixed> URL prefix => response array, WP_Error or callable. */
    protected static $routes = array();

    public static function reset() {
        self::$requests = array();
        self::$routes   = array();
    }

    /**
     * Answer every URL starting with $prefix.
     *
     * @param string         $prefix   URL prefix.
     * @param array|WP_Error|callable|false $response Response, error, fn($args, $url),
     *                                               or false to let the real transport
     *                                               make the request (local servers only).
     */
    public static function on($prefix, $response) {
        self::$routes[$prefix] = $response;
    }

    /**
     * @param false|array $pre  Short-circuit value so far.
     * @param array       $args Request arguments after http_request_args.
     * @param string      $url  Request URL.
     * @return array|WP_Error
     */
    public static function handle($pre, $args, $url) {
        self::$requests[] = array('url' => $url, 'args' => $args);

        // Longest prefix wins, so a test can override one asset URL inside a
        // broader route.
        $match = null;

        foreach (array_keys(self::$routes) as $prefix) {
            if (0 === strpos($url, $prefix) && (null === $match || strlen($prefix) > strlen($match))) {
                $match = $prefix;
            }
        }

        if (null !== $match) {
            $response = self::$routes[$match];

            if (false === $response) {
                return false;
            }

            if (is_callable($response)) {
                $response = call_user_func($response, $args, $url);
            }

            return self::deliver($response, $args);
        }

        // wordpress.org's own update checks, answered with "nothing new" so
        // wp_update_plugins() completes and saves its transient.
        if (false !== strpos($url, 'api.wordpress.org/plugins/update-check/')) {
            return self::json(array('plugins' => array(), 'translations' => array(), 'no_update' => array()));
        }

        if (false !== strpos($url, 'api.wordpress.org/themes/update-check/')) {
            return self::json(array('themes' => array(), 'translations' => array(), 'no_update' => array()));
        }

        return new WP_Error('asm_test_offline', 'Network access is disabled in tests: ' . $url);
    }

    /**
     * A streamed download writes the body to the requested file, as the real
     * transport does.
     */
    protected static function deliver($response, $args) {
        if (is_array($response) && !empty($args['stream']) && !empty($args['filename'])) {
            file_put_contents($args['filename'], $response['body']);
            $response['filename'] = $args['filename'];
        }

        return $response;
    }

    /**
     * @param mixed $data    Encoded as JSON.
     * @param int   $code    HTTP status.
     * @return array
     */
    public static function json($data, $code = 200) {
        return self::response(wp_json_encode($data), $code, array('content-type' => 'application/json'));
    }

    /**
     * @param string $body    Body.
     * @param int    $code    HTTP status.
     * @param array  $headers Headers.
     * @return array
     */
    public static function response($body, $code = 200, $headers = array()) {
        return array(
            'headers'  => $headers,
            'body'     => $body,
            'response' => array('code' => $code, 'message' => get_status_header_desc($code)),
            'cookies'  => array(),
            'filename' => null,
        );
    }

    /**
     * The GitHub "latest release" URL the updater calls.
     *
     * @return string
     */
    public static function latest_url() {
        return 'https://api.github.com/repos/' . ASM_TEST_REPO . '/releases/latest';
    }

    /**
     * Public download URL of the release zip asset.
     *
     * @param string $tag Tag.
     * @return string
     */
    public static function asset_download_url($tag = 'v9.9.9') {
        return 'https://github.com/' . ASM_TEST_REPO . '/releases/download/' . $tag . '/' . ASM_TEST_SLUG . '.zip';
    }

    /**
     * API URL of the release zip asset (used when a token is configured).
     *
     * @return string
     */
    public static function asset_api_url() {
        return 'https://api.github.com/repos/' . ASM_TEST_REPO . '/releases/assets/424242';
    }

    /**
     * A GitHub releases/latest payload.
     *
     * @param string     $tag    tag_name.
     * @param array|null $assets Assets; null for one zip asset plus a source-style tarball.
     * @return array
     */
    public static function release($tag = 'v9.9.9', $assets = null) {
        if (null === $assets) {
            $assets = array(
                array(
                    'name'                 => 'checksums.txt',
                    'browser_download_url' => 'https://github.com/' . ASM_TEST_REPO . '/releases/download/' . $tag . '/checksums.txt',
                    'url'                  => 'https://api.github.com/repos/' . ASM_TEST_REPO . '/releases/assets/1',
                ),
                array(
                    'name'                 => ASM_TEST_SLUG . '.zip',
                    'browser_download_url' => self::asset_download_url($tag),
                    'url'                  => self::asset_api_url(),
                ),
            );
        }

        return array(
            'tag_name'     => $tag,
            'html_url'     => 'https://github.com/' . ASM_TEST_REPO . '/releases/tag/' . $tag,
            'body'         => "## What's changed\n\n* Test release <script>alert(1)</script>",
            'published_at' => '2026-10-26T12:00:00Z',
            'assets'       => $assets,
        );
    }

    /**
     * Serve $release from the latest-release endpoint.
     *
     * @param array $release Payload from release().
     */
    public static function serve_release($release) {
        self::on(self::latest_url(), self::json($release));
    }

    /**
     * Requests made to a URL starting with $prefix.
     *
     * @param string $prefix URL prefix.
     * @return array
     */
    public static function requests_to($prefix) {
        return array_values(array_filter(self::$requests, function ($r) use ($prefix) {
            return 0 === strpos($r['url'], $prefix);
        }));
    }
}
