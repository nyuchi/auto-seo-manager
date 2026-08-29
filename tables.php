<?php
/**
 * Dropping tables and finding options that belong to plugins which are gone.
 *
 * The database module reports orphaned tables and stops there, deliberately:
 * listing something is reversible and dropping it is not. This is the other
 * half, written so that the third-party cleaner currently installed for this
 * one job can be removed.
 *
 * Everything here is built around a single admission - attribution is a guess,
 * and a wrong guess drops a live plugin's data. The existing heuristic in
 * database.php compares a table name against installed plugin slugs, and on
 * this site it calls wp_yoast_indexable, wp_e_submissions, wp_ea11y_*, wp_wt_*
 * and even this plugin's own wp_auto_seo_log orphans, because none of those
 * names resemble the slug of the plugin that owns them. A cleaner acting on
 * that list would take Yoast's index, Elementor's form submissions and the
 * accessibility widget's settings with it.
 *
 * So the guess is made twice over. An explicit map of table stem to owning
 * plugin covers the cases where the name and the slug have nothing in common,
 * and the name heuristic is only consulted when the map has nothing to say.
 * Then the drop refuses anything the map claims, anything core, and anything
 * outside this install's prefix, and it re-derives all of that server-side
 * rather than believing a caller who says a table is orphaned.
 *
 * @package Nyuchi_WordPress_Optimization
 */

if (!defined('ABSPATH')) {
    exit;
}

class AutoSEOTables {

    const CATEGORY = 'nyuchi-optimization';
    const PREFIX   = 'nyuchi-optimization/';

    /**
     * Hard ceiling on tables dropped in a single call.
     *
     * Lower than the row limits elsewhere because the unit is bigger: fifty
     * DROP statements is already more irreversible change than anyone should
     * make without looking at the result in between.
     */
    const MAX_DROP = 50;

    /**
     * WordPress core tables, unprefixed, single site and multisite.
     *
     * Never droppable, with or without force. There is no legitimate reason to
     * reach this ability for one of these, and every reason a mistake would
     * arrive looking like a legitimate reason.
     */
    const CORE = array(
        'posts', 'postmeta', 'options', 'users', 'usermeta', 'terms', 'termmeta',
        'term_taxonomy', 'term_relationships', 'comments', 'commentmeta', 'links',
        'blogs', 'blogmeta', 'site', 'sitemeta', 'signups', 'registration_log',
        'blog_versions',
    );

    /**
     * Table stem to the plugin that owns it.
     *
     * This map exists because a name heuristic gets this wrong in exactly the
     * cases that matter. Every entry below is a table whose name resembles
     * nothing in its plugin's slug, which is precisely why a slug comparison
     * reports it as an orphan while the plugin is sitting there installed and
     * active. Dropping any of them destroys live data: wp_yoast_indexable is
     * Yoast's entire index, wp_e_submissions is every Elementor form entry
     * anyone ever submitted, wp_wt_* is WP Travel's bookings.
     *
     * Matching is longest stem first, so a longer, more specific stem always
     * wins over a shorter one that happens to be a prefix of it.
     *
     * 'bundled' marks something no plugin file declares - a library shipped
     * inside other plugins, or this plugin itself - which is therefore always
     * treated as owned rather than checked against the installed list.
     */
    const TABLE_OWNERS = array(
        'action_scheduler' => array('plugin' => 'Action Scheduler (library bundled inside other plugins)', 'slugs' => array(), 'bundled' => true),
        'actionscheduler'  => array('plugin' => 'Action Scheduler (library bundled inside other plugins)', 'slugs' => array(), 'bundled' => true),
        'easy_mcp_ai'      => array('plugin' => 'Easy MCP AI', 'slugs' => array('easy-mcp-ai')),
        'site_mail'        => array('plugin' => 'WP Mail SMTP', 'slugs' => array('wp-mail-smtp', 'wp-mail-smtp-pro')),
        'auto_seo'         => array('plugin' => 'Nyuchi WordPress Optimization (this plugin)', 'slugs' => array('auto-seo-manager'), 'bundled' => true),
        'yoast'            => array('plugin' => 'Yoast SEO', 'slugs' => array('wordpress-seo', 'wordpress-seo-premium')),
        'ea11y'            => array('plugin' => 'Accessibility (Pojo / Elementor ea11y)', 'slugs' => array('pojo-accessibility', 'ea11y')),
        'wt_'              => array('plugin' => 'WP Travel', 'slugs' => array('wp-travel')),
        'e_'               => array('plugin' => 'Elementor', 'slugs' => array('elementor', 'elementor-pro')),
    );

    /**
     * Option stem to the plugin that owns it.
     *
     * Same principle as the table map, and one entry earns it on its own.
     * wp_travel_engine_settings belongs to WP Travel Engine, which is not
     * installed here. WP Travel, a different plugin, is. Any match that treats
     * "wp_travel" as a prefix attributes the option to the wrong plugin and
     * concludes it is in use. Longest stem first is what keeps those apart, so
     * the order of this array is not cosmetic.
     */
    const OPTION_OWNERS = array(
        'wp_travel_engine'   => array('plugin' => 'WP Travel Engine', 'slugs' => array('wp-travel-engine')),
        'wp_travel'          => array('plugin' => 'WP Travel', 'slugs' => array('wp-travel')),
        'wp_hummingbird'     => array('plugin' => 'Hummingbird', 'slugs' => array('hummingbird-performance', 'wp-hummingbird')),
        'wphb'               => array('plugin' => 'Hummingbird', 'slugs' => array('hummingbird-performance', 'wp-hummingbird')),
        'wpforms'            => array('plugin' => 'WPForms', 'slugs' => array('wpforms-lite', 'wpforms')),
        'jetpack'            => array('plugin' => 'Jetpack', 'slugs' => array('jetpack')),
        'wpseo'              => array('plugin' => 'Yoast SEO', 'slugs' => array('wordpress-seo', 'wordpress-seo-premium')),
        'yoast'              => array('plugin' => 'Yoast SEO', 'slugs' => array('wordpress-seo', 'wordpress-seo-premium')),
        'elementor'          => array('plugin' => 'Elementor', 'slugs' => array('elementor', 'elementor-pro')),
        'ea11y'              => array('plugin' => 'Accessibility (Pojo / Elementor ea11y)', 'slugs' => array('pojo-accessibility', 'ea11y')),
        'pojo_accessibility' => array('plugin' => 'Accessibility (Pojo / Elementor ea11y)', 'slugs' => array('pojo-accessibility', 'ea11y')),
        'action_scheduler'   => array('plugin' => 'Action Scheduler (library bundled inside other plugins)', 'slugs' => array(), 'bundled' => true),
        'schema_ActionScheduler' => array('plugin' => 'Action Scheduler (library bundled inside other plugins)', 'slugs' => array(), 'bundled' => true),
        'auto_seo'           => array('plugin' => 'Nyuchi WordPress Optimization (this plugin)', 'slugs' => array('auto-seo-manager'), 'bundled' => true),
        'wp_mail_smtp'       => array('plugin' => 'WP Mail SMTP', 'slugs' => array('wp-mail-smtp', 'wp-mail-smtp-pro')),
        'site_mail'          => array('plugin' => 'WP Mail SMTP', 'slugs' => array('wp-mail-smtp', 'wp-mail-smtp-pro')),
        'easy_mcp_ai'        => array('plugin' => 'Easy MCP AI', 'slugs' => array('easy-mcp-ai')),
    );

    /**
     * Options WordPress itself writes.
     *
     * Not exhaustive and not meant to be - it only has to stop the obvious core
     * settings appearing on a list headed "belongs to a plugin you removed".
     * Anything core that slips through lands in the unrecognised bucket, which
     * says in as many words that it is a shortlist to read rather than a verdict.
     */
    const CORE_OPTIONS = array(
        'siteurl', 'home', 'blogname', 'blogdescription', 'users_can_register',
        'admin_email', 'start_of_week', 'use_balanceTags', 'use_smilies',
        'require_name_email', 'comments_notify', 'posts_per_rss', 'rss_use_excerpt',
        'mailserver_url', 'mailserver_login', 'mailserver_pass', 'mailserver_port',
        'default_category', 'default_comment_status', 'default_ping_status',
        'default_pingback_flag', 'posts_per_page', 'date_format', 'time_format',
        'links_updated_date_format', 'comment_moderation', 'moderation_notify',
        'permalink_structure', 'rewrite_rules', 'hack_file', 'blog_charset',
        'moderation_keys', 'active_plugins', 'category_base', 'ping_sites',
        'comment_max_links', 'gmt_offset', 'default_email_category',
        'recently_edited', 'template', 'stylesheet', 'comment_registration',
        'html_type', 'use_trackback', 'default_role', 'db_version',
        'uploads_use_yearmonth_folders', 'upload_path', 'blog_public',
        'default_link_category', 'show_on_front', 'tag_base', 'show_avatars',
        'avatar_rating', 'upload_url_path', 'thumbnail_size_w', 'thumbnail_size_h',
        'thumbnail_crop', 'medium_size_w', 'medium_size_h', 'avatar_default',
        'large_size_w', 'large_size_h', 'medium_large_size_w', 'medium_large_size_h',
        'image_default_link_type', 'image_default_size', 'image_default_align',
        'close_comments_for_old_posts', 'close_comments_days_old', 'thread_comments',
        'thread_comments_depth', 'page_comments', 'comments_per_page',
        'default_comments_page', 'comment_order', 'sticky_posts', 'uninstall_plugins',
        'timezone_string', 'page_for_posts', 'page_on_front', 'default_post_format',
        'link_manager_enabled', 'finished_splitting_shared_terms', 'site_icon',
        'wp_page_for_privacy_policy', 'show_comments_cookies_opt_in',
        'admin_email_lifespan', 'disallowed_keys', 'comment_previously_approved',
        'blacklist_keys', 'comment_whitelist', 'initial_db_version', 'fresh_site',
        'user_count', 'WPLANG', 'cron', 'category_children', 'sidebars_widgets',
        'recently_activated', 'can_compress_scripts', 'db_upgraded', 'theme_switched',
        'new_admin_email', 'wp_user_roles', 'wp_force_deactivated_plugins',
        'wp_attachment_pages_enabled', 'recovery_keys', 'https_detection_errors',
        'auto_plugin_theme_update_emails', 'db_version_checked',
    );

    /**
     * Option name prefixes WordPress owns. Deliberately narrow - a broad one
     * such as "wp_" would swallow half the plugin options on the site.
     */
    const CORE_OPTION_PREFIXES = array(
        '_transient_', '_site_transient_', '_wp_session_', '_wp_', 'theme_mods_',
        'widget_', 'nav_menu_', 'auto_update_', 'https_detection', 'db_upgraded',
        'WPLANG',
    );

    public function __construct() {
        add_action('wp_abilities_api_init', array($this, 'register_abilities'));
    }

    public function can_manage() {
        return current_user_can('manage_options');
    }

    private function register($name, $args) {
        if (!function_exists('wp_register_ability')) {
            return;
        }

        wp_register_ability($name, $args);
    }

    public function register_abilities() {
        $this->register_orphaned_tables_drop();
        $this->register_orphaned_options();
    }

    /* ---------------------------------------------------------------------
     * Attribution
     * ------------------------------------------------------------------ */

    /**
     * Slugs of every plugin present on disk, active or not.
     *
     * Presence on disk is the test rather than being active: a deactivated
     * plugin still owns its tables and expects them to be there when it is
     * switched back on. Both the directory name and the main file name are
     * collected because plugins are not consistent about which one their data
     * is named after.
     *
     * @return string[]
     */
    public function installed_slugs() {
        static $slugs = null;

        if (null !== $slugs) {
            return $slugs;
        }

        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $slugs = array();

        foreach (array_keys(get_plugins()) as $file) {
            $slugs[] = dirname($file);
            $slugs[] = basename($file, '.php');
        }

        $slugs = array_values(array_unique(array_filter($slugs, function ($s) {
            return '.' !== $s && '' !== $s;
        })));

        return $slugs;
    }

    /**
     * Compare two names ignoring the hyphen/underscore distinction, which
     * plugins use interchangeably between their slug and their table names.
     *
     * @param string $value Name to flatten.
     * @return string
     */
    protected function normalise($value) {
        return str_replace(array('-', '_'), '', strtolower((string) $value));
    }

    /**
     * Whether any of these slugs is a plugin on disk.
     *
     * @param string[] $slugs Candidate slugs.
     * @return string|false The slug that matched, or false.
     */
    protected function any_installed($slugs) {
        $installed = array_map(array($this, 'normalise'), $this->installed_slugs());

        foreach ((array) $slugs as $slug) {
            if (in_array($this->normalise($slug), $installed, true)) {
                return $slug;
            }
        }

        return false;
    }

    /**
     * Longest matching stem from one of the owner maps.
     *
     * Longest first is the whole point: "wp_travel_engine" and "wp_travel" are
     * different plugins and the shorter one is a prefix of the longer.
     *
     * @param string $name Table or option name, already unprefixed for tables.
     * @param array  $map  TABLE_OWNERS or OPTION_OWNERS.
     * @return array|null
     */
    protected function match_owner($name, $map) {
        $stems = array_keys($map);

        usort($stems, function ($a, $b) {
            return strlen($b) - strlen($a);
        });

        foreach ($stems as $stem) {
            if (0 === strpos($name, $stem)) {
                $owner = $map[$stem];

                return array(
                    'stem'    => $stem,
                    'plugin'  => $owner['plugin'],
                    'slugs'   => isset($owner['slugs']) ? $owner['slugs'] : array(),
                    'bundled' => !empty($owner['bundled']),
                );
            }
        }

        return null;
    }

    /**
     * Who owns this table.
     *
     * The map is consulted first and the name heuristic only when it says
     * nothing, so a bad guess can never override a known fact.
     *
     * @param string $bare Table name with this install's prefix removed.
     * @return array plugin, installed, matched_by.
     */
    public function attribute_table($bare) {
        $hit = $this->match_owner($bare, self::TABLE_OWNERS);

        if (null !== $hit) {
            $slug = $hit['bundled'] ? true : $this->any_installed($hit['slugs']);

            return array(
                'plugin'     => $hit['plugin'],
                'installed'  => (bool) $slug,
                'matched_by' => 'map:' . $hit['stem'],
            );
        }

        $nbare = $this->normalise($bare);

        foreach ($this->installed_slugs() as $slug) {
            $ns = $this->normalise($slug);

            // Short stems match far too much - "seo" or "wp" would attribute
            // most of the database to whichever plugin owned them.
            if (strlen($ns) > 3 && (false !== strpos($nbare, $ns) || false !== strpos($ns, $nbare))) {
                return array(
                    'plugin'     => $slug,
                    'installed'  => true,
                    'matched_by' => 'heuristic',
                );
            }
        }

        return array(
            'plugin'     => null,
            'installed'  => false,
            'matched_by' => null,
        );
    }

    /**
     * Whether a name is a core table under any prefix.
     *
     * The prefixed check is this install's own core tables. The suffix check
     * catches wp2_posts, old_options and anything else shaped like the core
     * tables of a second WordPress sharing the database - which is the case
     * where a drop is not just data loss but somebody else's data loss. That
     * test also refuses a plugin table that happens to end in a core name, and
     * that is the right trade: nothing distinguishes the two from the name
     * alone, and the cost of the two mistakes is not remotely symmetric.
     *
     * @param string $table Full table name.
     * @return string|null Core table matched, or null.
     */
    protected function core_name($table) {
        global $wpdb;

        foreach (self::CORE as $core) {
            if ($table === $core || $table === $wpdb->prefix . $core) {
                return $core;
            }

            $suffix = '_' . $core;

            if (strlen($table) > strlen($suffix) && substr($table, -strlen($suffix)) === $suffix) {
                return $core;
            }
        }

        return null;
    }

    /* ---------------------------------------------------------------------
     * Dropping
     * ------------------------------------------------------------------ */

    /**
     * Drop named tables, having re-checked every one of them.
     *
     * The caller names the tables. Nothing else about the caller's opinion is
     * used: whether a table is orphaned, whether it is core, whether it is even
     * in this database is all worked out here, because "drop what you think is
     * orphaned" is the request this ability exists to refuse.
     *
     * @param string[] $tables  Table names to drop.
     * @param bool     $dry_run Report only. Defaults to true everywhere.
     * @param bool     $force   Override the prefix and attribution refusals.
     *                          Never overrides the core refusal.
     * @return array
     */
    public function drop_tables($tables, $dry_run = true, $force = false) {
        global $wpdb;

        $tables = array_values(array_unique(array_filter((array) $tables, 'is_string')));

        if (empty($tables)) {
            return array('error' => 'Name the tables to drop. This ability never selects them for you.');
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT table_name, data_length + index_length AS bytes
                   FROM information_schema.TABLES
                  WHERE table_schema = %s',
                DB_NAME
            ),
            ARRAY_A
        );

        if (!is_array($rows)) {
            return array('error' => 'information_schema is not readable by this database user, so nothing can be verified before dropping it.');
        }

        $known = array();

        foreach ($rows as $r) {
            $known[$r['table_name']] = (int) $r['bytes'];
        }

        $prefix    = $wpdb->prefix;
        $results   = array();
        $acted     = 0;
        $freed     = 0;
        $deferred  = array();

        foreach ($tables as $table) {
            $result = array(
                'table'   => $table,
                'dropped' => false,
                'rows'    => null,
                'size_mb' => null,
            );

            // A table name cannot be a prepared-statement placeholder, so this
            // pattern is the entire defence between here and the DROP. Nothing
            // that fails it goes near a query, quoted or otherwise.
            if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
                $result['reason'] = 'Not a valid table name. Only letters, digits and underscores are accepted, because the name has to be interpolated into the statement rather than bound to it.';
                $results[]        = $result;
                continue;
            }

            $core = $this->core_name($table);

            if (null !== $core) {
                $result['reason'] = sprintf(
                    'Refused: this is the WordPress core "%s" table, or is named as another install\'s copy of it. Core tables are never droppable, with or without force.',
                    $core
                );
                $results[] = $result;
                continue;
            }

            if (0 !== strpos($table, $prefix) && !$force) {
                $result['reason'] = sprintf(
                    'Refused: outside this install\'s table prefix "%s". A different prefix usually means a second WordPress sharing this database, and dropping it would destroy an unrelated site. Pass force if you are certain it is not.',
                    $prefix
                );
                $results[] = $result;
                continue;
            }

            if (!isset($known[$table])) {
                $result['reason'] = 'Not a table in this database.';
                $results[]        = $result;
                continue;
            }

            $bare  = (0 === strpos($table, $prefix)) ? substr($table, strlen($prefix)) : $table;
            $owner = $this->attribute_table($bare);

            if ($owner['installed'] && !$force) {
                $result['reason'] = sprintf(
                    'Refused: %s is installed and claims this table (matched by %s). Dropping it would destroy a live plugin\'s data. Pass force only if you have confirmed the table really is unused.',
                    $owner['plugin'],
                    $owner['matched_by']
                );
                $result['claimed_by'] = $owner['plugin'];
                $results[]            = $result;
                continue;
            }

            if ($acted >= self::MAX_DROP) {
                $deferred[]       = $table;
                $result['reason'] = 'Deferred: this call has already reached the limit of ' . self::MAX_DROP . ' tables. Call again to continue.';
                $results[]        = $result;
                continue;
            }

            // Counted before the drop, exactly rather than from the
            // information_schema estimate, because after the statement runs
            // this number is the only record of what was in there.
            $count = $wpdb->get_var('SELECT COUNT(*) FROM `' . $table . '`');

            $result['rows']    = is_numeric($count) ? (int) $count : null;
            $result['size_mb'] = round($known[$table] / 1048576, 2);

            if ($owner['plugin']) {
                $result['claimed_by'] = $owner['plugin'];
            }

            if ($dry_run) {
                $result['would_drop'] = true;
                $result['reason']     = 'Dry run. Nothing was dropped.';
                $results[]            = $result;
                $acted++;
                continue;
            }

            $ok = $wpdb->query('DROP TABLE `' . $table . '`');

            if (false === $ok) {
                $result['reason'] = 'DROP failed: ' . $wpdb->last_error;
                $results[]        = $result;
                continue;
            }

            $result['dropped'] = true;
            $freed            += $known[$table];
            $acted++;
            $results[] = $result;
        }

        $dropped = 0;

        foreach ($results as $r) {
            if (!empty($r['dropped'])) {
                $dropped++;
            }
        }

        return array(
            'dry_run'       => (bool) $dry_run,
            'force'         => (bool) $force,
            'prefix'        => $prefix,
            'tables'        => $results,
            'dropped'       => $dropped,
            'freed_mb'      => round($freed / 1048576, 2),
            'batch_limit'   => self::MAX_DROP,
            'deferred'      => $deferred,
            'note'          => $dry_run
                ? 'Nothing was dropped. Every table above was re-checked here rather than taken on trust; call again with dry_run false to drop the ones that were not refused.'
                : 'DROP TABLE cannot be undone and there is no trash. The row counts and sizes above are the only record of what was in these tables.',
        );
    }

    /* ---------------------------------------------------------------------
     * Autoloaded options left behind
     * ------------------------------------------------------------------ */

    /**
     * Autoloaded options belonging to plugins that are no longer installed.
     *
     * The option-table equivalent of an orphaned table, and usually the more
     * expensive one: an autoloaded option is read on every request including
     * REST and AJAX, so a removed plugin's settings blob keeps being paid for
     * long after the plugin itself is gone.
     *
     * Read-only by design. Deleting these goes through db-write or db-cleanup,
     * where the previous value is returned and the change can be put back.
     *
     * @param int $limit Largest N unrecognised options to list.
     * @return array
     */
    public function orphaned_options($limit = 50) {
        global $wpdb;

        // WordPress 6.6 widened autoload from yes/no. Matching only 'yes'
        // silently under-reports on anything current.
        $on = "autoload IN ('yes','on','auto','auto-on')";

        $rows = $wpdb->get_results(
            "SELECT option_name, LENGTH(option_value) AS bytes FROM {$wpdb->options} WHERE {$on}",
            ARRAY_A
        );

        if (!is_array($rows)) {
            return array('error' => 'The options table could not be read.');
        }

        $orphaned     = array();
        $unrecognised = array();
        $attributed   = 0;
        $total        = 0;

        foreach ($rows as $r) {
            $name   = $r['option_name'];
            $bytes  = (int) $r['bytes'];
            $total += $bytes;

            $hit = $this->match_owner($name, self::OPTION_OWNERS);

            if (null !== $hit) {
                $installed = $hit['bundled'] ? true : $this->any_installed($hit['slugs']);

                if ($installed) {
                    $attributed++;
                    continue;
                }

                $orphaned[] = array(
                    'option'     => $name,
                    'kb'         => round($bytes / 1024, 1),
                    'plugin'     => $hit['plugin'],
                    'matched_by' => 'map:' . $hit['stem'],
                );
                continue;
            }

            if ($this->option_is_core($name)) {
                $attributed++;
                continue;
            }

            $nname   = $this->normalise($name);
            $matched = false;

            foreach ($this->installed_slugs() as $slug) {
                $ns = $this->normalise($slug);

                if (strlen($ns) > 3 && false !== strpos($nname, $ns)) {
                    $matched = true;
                    break;
                }
            }

            if ($matched) {
                $attributed++;
                continue;
            }

            $unrecognised[] = array(
                'option'     => $name,
                'kb'         => round($bytes / 1024, 1),
                'plugin'     => null,
                'matched_by' => null,
            );
        }

        $by_size = function ($a, $b) {
            return $b['kb'] <=> $a['kb'];
        };

        usort($orphaned, $by_size);
        usort($unrecognised, $by_size);

        $unrecognised_kb = round(array_sum(array_column($unrecognised, 'kb')), 1);
        $limit           = max(1, min(200, (int) $limit));
        $listed          = array_slice($unrecognised, 0, $limit);

        return array
        (
            'orphaned'         => $orphaned,
            'reclaimable_kb'   => round(array_sum(array_column($orphaned, 'kb')), 1),
            'unrecognised'     => $listed,
            'unrecognised_kb'  => $unrecognised_kb,
            'unrecognised_total' => count($unrecognised),
            'attributed'       => $attributed,
            'autoloaded_kb'    => round($total / 1024, 1),
            'note'             => 'Read-only. "orphaned" is named to a specific plugin that is not installed and is the list worth acting on; "unrecognised" resembles no installed plugin and no core option, which includes genuine debris but also anything named unlike its owner - read it before removing any of it. Remove either through db-write or db-cleanup, which return the previous value.',
        );
    }

    /**
     * Whether WordPress itself wrote this option.
     *
     * @param string $name Option name.
     * @return bool
     */
    protected function option_is_core($name) {
        if (in_array($name, self::CORE_OPTIONS, true)) {
            return true;
        }

        foreach (self::CORE_OPTION_PREFIXES as $prefix) {
            if (0 === strpos($name, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /* ---------------------------------------------------------------------
     * Abilities
     * ------------------------------------------------------------------ */

    private function register_orphaned_tables_drop() {
        $this->register(self::PREFIX . 'db-orphaned-tables-drop', array(
            'label'       => 'Drop tables left by removed plugins',
            'description' => 'Drop named database tables belonging to plugins that are no longer installed. Tables must be named explicitly - this never selects them itself. Defaults to a dry run reporting the rows and megabytes each drop would destroy. Core tables are refused outright; tables outside this install\'s prefix, and tables an installed plugin claims, are refused unless force is set. Attribution is re-derived here rather than taken from the caller. Capped at 50 tables per call. DROP TABLE cannot be undone.',
            'category'    => self::CATEGORY,
            'input_schema' => array(
                'type'       => 'object',
                'properties' => array(
                    'tables' => array(
                        'type'        => 'array',
                        'items'       => array('type' => 'string'),
                        'description' => 'Exact table names, e.g. ["wp_adv_db_cleaner_log"]. Required - there is no "drop everything orphaned" mode.',
                    ),
                    'dry_run' => array(
                        'type'        => 'boolean',
                        'description' => 'Defaults to true. Must be explicitly false for anything to be dropped.',
                    ),
                    'force' => array(
                        'type'        => 'boolean',
                        'description' => 'Overrides the refusal of a table outside this install\'s prefix, or one an installed plugin appears to claim. Never overrides the refusal of a core table.',
                    ),
                ),
                'required'             => array('tables'),
                'additionalProperties' => false,
            ),
            'output_schema' => array('type' => 'object'),
            'permission_callback' => array($this, 'can_manage'),
            'execute_callback'    => function ($input) {
                $tables = isset($input['tables']) ? (array) $input['tables'] : array();

                if (empty($tables)) {
                    return new WP_Error('no_tables', 'Name the tables to drop. Use db-orphaned-tables to find candidates, then pass the ones you have verified.');
                }

                // Absent means dry run. Anyone who wants a table dropped has to
                // say so, so a malformed call fails safe rather than destructively.
                $dry = !isset($input['dry_run']) || (bool) $input['dry_run'];

                return $this->drop_tables($tables, $dry, !empty($input['force']));
            },
        ));
    }

    private function register_orphaned_options() {
        $this->register(self::PREFIX . 'db-orphaned-options', array(
            'label'       => 'Autoloaded options from removed plugins',
            'description' => 'List autoloaded options that belong to plugins no longer installed, with their size in kilobytes and the plugin they came from, plus the total that could be taken off autoload. Read-only - deleting them goes through db-write or db-cleanup, which return the previous value. Attribution uses an explicit map of option name to plugin first and a name heuristic only as a fallback.',
            'category'    => self::CATEGORY,
            'input_schema' => array(
                'type'       => 'object',
                'properties' => array(
                    'limit' => array(
                        'type'        => 'integer',
                        'description' => 'How many of the largest unrecognised options to list. Default 50.',
                    ),
                ),
                'additionalProperties' => false,
            ),
            'output_schema' => array('type' => 'object'),
            'permission_callback' => array($this, 'can_manage'),
            'execute_callback'    => function ($input) {
                $limit = isset($input['limit']) ? (int) $input['limit'] : 50;

                return $this->orphaned_options($limit);
            },
        ));
    }
}
