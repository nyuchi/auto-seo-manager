<?php
/**
 * Update the plugin from GitHub Releases.
 *
 * A plugin distributed outside wordpress.org gets no update notice, so sites
 * silently sit on whatever version was installed. This wires the release feed
 * into WordPress's own update machinery: the admin shows "update available"
 * and the one-click update works exactly as it does for a directory plugin.
 *
 * Deliberately self-contained rather than vendoring an update library. The
 * whole mechanism is one API call and two filters, and keeping it visible
 * means the auth path for a private repository is auditable.
 *
 * For a private repository define a fine-grained token with Contents: read:
 *
 *     define( 'AUTO_SEO_GITHUB_TOKEN', 'github_pat_...' );
 *
 * in wp-config.php. Without it, a private repo simply reports no updates
 * rather than failing loudly on every admin page load.
 *
 * @package AutoSEOManager
 */

if (!defined('ABSPATH')) {
    exit;
}

class AutoSEOUpdater {

    const REPO       = 'nyuchi/auto-seo-manager';
    const CACHE_KEY  = 'auto_seo_release_check';
    const CACHE_TTL  = 6 * HOUR_IN_SECONDS;

    /** @var string plugin_basename() of the main file */
    protected $basename;

    /** @var string current version */
    protected $version;

    public function __construct($file) {
        $this->basename = plugin_basename($file);
        $this->version  = AUTO_SEO_VERSION;

        add_filter('pre_set_site_transient_update_plugins', array($this, 'inject_update'));
        add_filter('plugins_api', array($this, 'plugin_details'), 10, 3);
        add_filter('http_request_args', array($this, 'authorise_download'), 10, 2);

        // Let an admin force a fresh check rather than waiting out the cache.
        add_action('admin_init', array($this, 'maybe_flush'));
    }

    protected function repo() {
        return apply_filters('auto_seo_updater_repo', self::REPO);
    }

    protected function token() {
        $token = defined('AUTO_SEO_GITHUB_TOKEN') ? AUTO_SEO_GITHUB_TOKEN : '';

        return apply_filters('auto_seo_updater_token', $token);
    }

    /**
     * The newest release, or null.
     *
     * Cached hard: this runs on the update-check cycle, and an uncached call
     * would hit the API on every admin page for every site.
     *
     * @return array|null
     */
    protected function latest_release() {
        $cached = get_site_transient(self::CACHE_KEY);

        if (is_array($cached)) {
            return $cached ? $cached : null;
        }

        $args = array(
            'timeout' => 10,
            'headers' => array(
                'Accept'     => 'application/vnd.github+json',
                'User-Agent' => 'nyuchi-wordpress-optimization/' . $this->version,
            ),
        );

        $token = $this->token();

        if ($token) {
            $args['headers']['Authorization'] = 'Bearer ' . $token;
        }

        $response = wp_remote_get(
            'https://api.github.com/repos/' . $this->repo() . '/releases/latest',
            $args
        );

        if (is_wp_error($response) || 200 !== (int) wp_remote_retrieve_response_code($response)) {
            // Cache the failure briefly too. A private repo with no token, or a
            // rate limit, would otherwise retry on every single page load.
            set_site_transient(self::CACHE_KEY, array(), 30 * MINUTE_IN_SECONDS);

            return null;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (!is_array($body) || empty($body['tag_name'])) {
            set_site_transient(self::CACHE_KEY, array(), 30 * MINUTE_IN_SECONDS);

            return null;
        }

        $release = array(
            'version'   => ltrim((string) $body['tag_name'], 'vV'),
            'url'       => isset($body['html_url']) ? $body['html_url'] : '',
            'notes'     => isset($body['body']) ? (string) $body['body'] : '',
            'published' => isset($body['published_at']) ? $body['published_at'] : '',
            'package'   => '',
        );

        // Prefer the built zip asset. The auto-generated source tarball has the
        // wrong folder name inside it, which installs the plugin to a directory
        // WordPress will not recognise as an update of this one.
        if (!empty($body['assets']) && is_array($body['assets'])) {
            foreach ($body['assets'] as $asset) {
                if (!empty($asset['browser_download_url']) && substr($asset['name'], -4) === '.zip') {
                    $release['package'] = $asset['browser_download_url'];
                    $release['api_url'] = isset($asset['url']) ? $asset['url'] : '';
                    break;
                }
            }
        }

        set_site_transient(self::CACHE_KEY, $release, self::CACHE_TTL);

        return $release;
    }

    /**
     * Add ourselves to WordPress's update list when a newer release exists.
     */
    public function inject_update($transient) {
        if (!is_object($transient)) {
            return $transient;
        }

        $release = $this->latest_release();

        if (!$release || empty($release['package'])) {
            return $transient;
        }

        if (version_compare($release['version'], $this->version, '<=')) {
            return $transient;
        }

        $item = (object) array(
            'id'            => $this->repo(),
            'slug'          => dirname($this->basename),
            'plugin'        => $this->basename,
            'new_version'   => $release['version'],
            'url'           => $release['url'],
            'package'       => $this->token() && !empty($release['api_url']) ? $release['api_url'] : $release['package'],
            'tested'        => '',
            'requires_php'  => '7.4',
        );

        if (!isset($transient->response) || !is_array($transient->response)) {
            $transient->response = array();
        }

        $transient->response[$this->basename] = $item;

        return $transient;
    }

    /**
     * Populate the "View details" modal.
     */
    public function plugin_details($result, $action, $args) {
        if ('plugin_information' !== $action) {
            return $result;
        }

        if (empty($args->slug) || $args->slug !== dirname($this->basename)) {
            return $result;
        }

        $release = $this->latest_release();

        if (!$release) {
            return $result;
        }

        return (object) array(
            'name'          => 'Nyuchi WordPress Optimization',
            'slug'          => dirname($this->basename),
            'version'       => $release['version'],
            'author'        => '<a href="https://nyuchi.com">Nyuchi Web Services</a>',
            'homepage'      => $release['url'],
            'requires_php'  => '7.4',
            'last_updated'  => $release['published'],
            'sections'      => array(
                'description' => 'SEO, metadata, and database cleaning and editing for WordPress. Automates Yoast SEO fields, reports what is costing the database, repairs attachments whose file and record disagree, and exposes the lot to the REST API and to MCP clients.',
                'changelog'   => $release['notes'] ? wpautop(wp_kses_post($release['notes'])) : 'See the release notes on GitHub.',
            ),
            'download_link' => $release['package'],
        );
    }

    /**
     * A private repository's asset needs an auth header and an octet-stream
     * Accept, otherwise GitHub returns the JSON metadata instead of the file
     * and WordPress unzips nothing.
     */
    public function authorise_download($args, $url) {
        $token = $this->token();

        if (!$token || strpos($url, 'api.github.com/repos/' . $this->repo()) === false) {
            return $args;
        }

        if (!isset($args['headers']) || !is_array($args['headers'])) {
            $args['headers'] = array();
        }

        $args['headers']['Authorization'] = 'Bearer ' . $token;
        $args['headers']['Accept']        = 'application/octet-stream';

        return $args;
    }

    /**
     * ?auto-seo-check-updates=1 on any admin page clears the cache.
     */
    public function maybe_flush() {
        if (empty($_GET['auto-seo-check-updates']) || !current_user_can('update_plugins')) {
            return;
        }

        delete_site_transient(self::CACHE_KEY);
        delete_site_transient('update_plugins');
    }
}
