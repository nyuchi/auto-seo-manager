<?php
/**
 * The repository's GitHub star count, with a polite ask to star it.
 *
 * There is no telemetry and no usage ping in this plugin. Stars are the only
 * adoption signal, so the admin screen shows the current count and asks once,
 * in its own footer, rather than nagging across wp-admin.
 *
 * Privacy: the request is an anonymous GET of the public repository record.
 * It sends nothing about the site: no site URL, no WordPress, PHP or plugin
 * version and no identifier. The User-Agent is a fixed string (WordPress's
 * default one carries the site URL), and the link to GitHub is rel=noreferrer
 * so the admin URL does not travel either.
 *
 * The count is fetched only when the plugin's own admin screen renders, never
 * on the front end, from cron or REST, or on any other admin page. It is cached
 * for 12 hours; a failure is cached for an hour and the ask shows without a
 * number, so a slow or unreachable GitHub never blocks the page or raises a
 * notice.
 *
 * To hide it:
 *
 *     define( 'AUTO_SEO_HIDE_STAR_COUNT', true );
 *
 * in wp-config.php, or return false from the `auto_seo_show_star_count` filter.
 *
 * @package AutoSEOManager
 */

if (!defined('ABSPATH')) {
    exit;
}

class AutoSEOGitHubStars {

    const CACHE_KEY   = 'auto_seo_github_stars';
    const CACHE_TTL   = 12 * HOUR_IN_SECONDS;
    const FAILURE_TTL = HOUR_IN_SECONDS;

    /** Stored in place of a count when the last request failed. */
    const FAILED = 'failed';

    /** @var string plugin_basename() of the main file */
    protected $basename;

    public function __construct($file) {
        $this->basename = plugin_basename($file);

        add_filter('plugin_row_meta', array($this, 'row_meta'), 10, 2);
    }

    /**
     * Whether the count and the ask are shown at all.
     */
    public static function enabled() {
        $show = !(defined('AUTO_SEO_HIDE_STAR_COUNT') && AUTO_SEO_HIDE_STAR_COUNT);

        return (bool) apply_filters('auto_seo_show_star_count', $show);
    }

    /**
     * owner/repo, the same one the updater reads releases from.
     */
    public static function repo() {
        $repo = apply_filters('auto_seo_updater_repo', AutoSEOUpdater::REPO);

        // It goes into a URL path, so anything but owner/name falls back.
        return is_string($repo) && preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo) ? $repo : AutoSEOUpdater::REPO;
    }

    public static function repo_url() {
        return 'https://github.com/' . self::repo();
    }

    /**
     * The star count, or null when it is not known.
     *
     * @return int|null
     */
    public static function count() {
        $cached = get_site_transient(self::CACHE_KEY);

        if (self::FAILED === $cached) {
            return null;
        }

        if (false !== $cached && is_numeric($cached)) {
            return absint($cached);
        }

        return self::fetch();
    }

    /**
     * Ask GitHub for the count and cache the answer either way.
     *
     * @return int|null
     */
    protected static function fetch() {
        $url = 'https://api.github.com/repos/' . self::repo();

        // Nothing about the site goes in this request: see the file header.
        $args = array(
            'timeout'     => 3,
            'redirection' => 0,
            'user-agent'  => 'auto-seo-manager',
            'headers'     => array(
                'Accept' => 'application/vnd.github+json',
            ),
        );

        // The updater's token, only when one is configured and only for this
        // repository's own API record. Never anywhere else.
        $token = self::token();

        if ($token && self::is_repo_api_url($url)) {
            $args['headers']['Authorization'] = 'Bearer ' . $token;
        }

        // The updater's download filter matches any URL under this repo and
        // swaps Accept for octet-stream when a token is set, which is right for
        // a release asset and wrong for this JSON record. Put it back for this
        // one request only.
        $keep_json = function ($request_args, $request_url) use ($url) {
            if ($request_url === $url && isset($request_args['headers']) && is_array($request_args['headers'])) {
                $request_args['headers']['Accept'] = 'application/vnd.github+json';
            }

            return $request_args;
        };

        add_filter('http_request_args', $keep_json, 99, 2);
        $response = wp_remote_get($url, $args);
        remove_filter('http_request_args', $keep_json, 99);

        $body = null;

        if (!is_wp_error($response) && 200 === (int) wp_remote_retrieve_response_code($response)) {
            $body = json_decode(wp_remote_retrieve_body($response), true);
        }

        if (!is_array($body) || !isset($body['stargazers_count']) || !is_numeric($body['stargazers_count'])) {
            // Cache the failure too, so a rate limit or an outage costs one
            // request an hour rather than one per page view.
            set_site_transient(self::CACHE_KEY, self::FAILED, self::FAILURE_TTL);

            return null;
        }

        $count = absint($body['stargazers_count']);

        set_site_transient(self::CACHE_KEY, $count, self::CACHE_TTL);

        return $count;
    }

    /**
     * The token AutoSEOUpdater uses, read the same way. Duplicated rather than
     * opening up the updater, whose token() is deliberately not public.
     */
    protected static function token() {
        $token = defined('AUTO_SEO_GITHUB_TOKEN') ? AUTO_SEO_GITHUB_TOKEN : '';

        return (string) apply_filters('auto_seo_updater_token', $token);
    }

    /**
     * Whether $url is exactly this repository's API record over HTTPS.
     *
     * Parsed, not substring-matched, so the token cannot be sent to another
     * host, over plain http or to any other path.
     */
    protected static function is_repo_api_url($url) {
        $parts = wp_parse_url($url);

        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host']) || empty($parts['path'])) {
            return false;
        }

        if ('https' !== strtolower($parts['scheme']) || 'api.github.com' !== strtolower($parts['host'])) {
            return false;
        }

        if (isset($parts['port']) && 443 !== (int) $parts['port']) {
            return false;
        }

        return '/repos/' . self::repo() === $parts['path'] && empty($parts['query']);
    }

    /**
     * The count and the ask, for the plugin's own admin screen.
     */
    public static function render() {
        if (!self::enabled() || !current_user_can('manage_options')) {
            return;
        }

        $count = self::count();
        ?>
        <aside class="nyx-stars" aria-label="<?php echo esc_attr__('GitHub stars', 'nyuchi-wp-optimization'); ?>">
            <p>
                <?php if (null !== $count) : ?>
                    <strong>
                        <span aria-hidden="true">&#9733;</span>
                        <?php
                        echo esc_html(sprintf(
                            /* translators: %s: number of GitHub stars. */
                            _n('%s star on GitHub.', '%s stars on GitHub.', $count, 'nyuchi-wp-optimization'),
                            number_format_i18n($count)
                        ));
                        ?>
                    </strong>
                <?php endif; ?>
                <?php esc_html_e('If this plugin helps you, a star helps others find it.', 'nyuchi-wp-optimization'); ?>
            </p>
            <a class="nyx-btn" href="<?php echo esc_url(self::repo_url()); ?>" target="_blank" rel="noopener noreferrer">
                <?php esc_html_e('Star on GitHub', 'nyuchi-wp-optimization'); ?>
            </a>
        </aside>
        <?php
    }

    /**
     * A "Star on GitHub" link on the Plugins screen row. Never fetches.
     */
    public function row_meta($links, $file) {
        if ($this->basename !== $file || !self::enabled() || !current_user_can('manage_options')) {
            return $links;
        }

        $links[] = sprintf(
            '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
            esc_url(self::repo_url()),
            esc_html__('Star on GitHub', 'nyuchi-wp-optimization')
        );

        return $links;
    }
}
