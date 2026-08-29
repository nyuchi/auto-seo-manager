<?php
/**
 * Site health as numbers.
 *
 * A site rarely announces that it is in trouble. Nothing errors, nothing is
 * logged, and the only symptom is that everything got slower over eighteen
 * months - the autoload set crept past a megabyte, the cron queue quietly
 * stopped draining, revisions outgrew the posts they belong to. Every one of
 * those is visible in the database, and none of them is visible in the admin.
 *
 * This is the read-only half of that. One call returns a structured snapshot
 * grouped by area, with every number carrying its unit in the key name, so a
 * dashboard can render it without knowing what any of it means. A second call
 * keeps a small trimmed history, because a database of 900 MB is only alarming
 * once you know it was 400 MB in March.
 *
 * The measurements deliberately mirror the queries in database.php rather than
 * inventing their own. Two features that report the same thing differently is
 * worse than not reporting it twice, and the numbers here are meant to agree
 * with db-overview and db-autoload exactly.
 *
 * @package Nyuchi_WordPress_Optimization
 */

if (!defined('ABSPATH')) {
    exit;
}

class AutoSEOMetrics {

    const CATEGORY = 'nyuchi-optimization';
    const PREFIX   = 'nyuchi-optimization/';

    /**
     * Where the trimmed history lives.
     *
     * Registered with autoload 'no', always, and never anything else. An
     * autoloaded option that grows by an entry a day would be read on every
     * request the site ever serves - including AJAX and REST - and would get
     * steadily more expensive forever. That is precisely the bug db-autoload
     * exists to find, and shipping it inside the plugin that finds it would be
     * a poor joke.
     */
    const OPTION = 'auto_seo_metrics_history';

    /**
     * Hard cap on stored entries, oldest discarded first.
     *
     * Ninety daily entries is a quarter's worth of trend, which is enough to
     * see growth, and small enough that the option stays a few kilobytes.
     */
    const MAX_ENTRIES = 90;

    /** Daily recorder. */
    const CRON_HOOK = 'auto_seo_record_metrics';

    /** Autoload weight, in bytes, past which a site starts paying noticeably. */
    const AUTOLOAD_BUDGET = 838860;

    /** Reclaimable table overhead, in bytes, worth acting on. */
    const OVERHEAD_BUDGET = 20971520;

    /** Revisions held against a single post type before it is worth a sweep. */
    const REVISION_BUDGET = 100;

    public function __construct() {
        add_action('wp_abilities_api_init', array($this, 'register_abilities'));

        // The recorder runs unattended, so it is hooked unconditionally - a
        // scheduled event whose hook has no listener is exactly the debris
        // db-orphaned-cron reports.
        add_action(self::CRON_HOOK, array($this, 'record'));

        add_action('init', array($this, 'maybe_schedule'));
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
        $this->register_snapshot();
        $this->register_history();
    }

    /**
     * Schedule the daily recorder once.
     *
     * The wp_next_scheduled() guard is the whole point: this runs on `init`, so
     * it fires on every request the site serves. Without the check a site would
     * accumulate one duplicate event per page view, which is a far more
     * expensive problem than the one being measured.
     */
    public function maybe_schedule() {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time(), 'daily', self::CRON_HOOK);
        }
    }

    /* ---------------------------------------------------------------------
     * Snapshot
     * ------------------------------------------------------------------ */

    /**
     * Everything, grouped by area.
     *
     * @return array
     */
    public function snapshot() {
        $runtime  = $this->runtime();
        $database = $this->database();
        $content  = $this->content();
        $cron     = $this->cron();

        return array(
            'generated_at' => gmdate('c'),
            'runtime'      => $runtime,
            'database'     => $database,
            'content'      => $content,
            'cron'         => $cron,
            'health'       => $this->health($runtime, $database, $content, $cron),
        );
    }

    /**
     * PHP and cache configuration.
     *
     * The raw ini strings are kept alongside the parsed byte counts. A human
     * reading '256M' understands it instantly, and a dashboard drawing a bar
     * needs the number - handing over only one of the two means somebody
     * re-parses it at the other end and gets the edge cases wrong.
     *
     * @return array
     */
    public function runtime() {
        $memory_limit  = ini_get('memory_limit');
        $upload_max    = ini_get('upload_max_filesize');
        $post_max      = ini_get('post_max_size');

        $out = array(
            'php_version'               => PHP_VERSION,
            'php_version_id'            => PHP_VERSION_ID,
            'memory_limit'              => $memory_limit,
            'memory_limit_mb'           => $this->to_mb($this->ini_bytes($memory_limit)),
            'wp_memory_limit'           => defined('WP_MEMORY_LIMIT') ? WP_MEMORY_LIMIT : null,
            'wp_memory_limit_mb'        => defined('WP_MEMORY_LIMIT') ? $this->to_mb($this->ini_bytes(WP_MEMORY_LIMIT)) : null,
            'wp_max_memory_limit'       => defined('WP_MAX_MEMORY_LIMIT') ? WP_MAX_MEMORY_LIMIT : null,
            'wp_max_memory_limit_mb'    => defined('WP_MAX_MEMORY_LIMIT') ? $this->to_mb($this->ini_bytes(WP_MAX_MEMORY_LIMIT)) : null,
            'max_execution_time_seconds' => (int) ini_get('max_execution_time'),
            'upload_max_filesize'       => $upload_max,
            'upload_max_filesize_mb'    => $this->to_mb($this->ini_bytes($upload_max)),
            'post_max_size'             => $post_max,
            'post_max_size_mb'          => $this->to_mb($this->ini_bytes($post_max)),
            'memory_peak_bytes'         => memory_get_peak_usage(true),
            'memory_peak_mb'            => $this->to_mb(memory_get_peak_usage(true)),
            'opcache_enabled'           => false,
            'opcache_hit_rate_percent'  => null,
            'opcache_memory_used_mb'    => null,
            'opcache_memory_free_mb'    => null,
            'object_cache_external'     => function_exists('wp_using_ext_object_cache') ? (bool) wp_using_ext_object_cache() : false,
        );

        // opcache.restrict_api can make this unreadable from a web request even
        // when OPcache is on, so a null hit rate means "could not tell", not
        // "zero". Reported as null rather than 0 for exactly that reason.
        if (function_exists('opcache_get_status')) {
            $status = @opcache_get_status(false);

            if (is_array($status)) {
                $out['opcache_enabled'] = !empty($status['opcache_enabled']);

                if (isset($status['opcache_statistics']['opcache_hit_rate'])) {
                    $out['opcache_hit_rate_percent'] = round((float) $status['opcache_statistics']['opcache_hit_rate'], 2);
                }

                if (isset($status['memory_usage']['used_memory'])) {
                    $out['opcache_memory_used_mb'] = $this->to_mb($status['memory_usage']['used_memory']);
                }

                if (isset($status['memory_usage']['free_memory'])) {
                    $out['opcache_memory_free_mb'] = $this->to_mb($status['memory_usage']['free_memory']);
                }
            }
        }

        return $out;
    }

    /**
     * Table weight and autoload weight.
     *
     * The two queries are the ones db-overview and db-autoload run, character
     * for character in the parts that matter, so a dashboard built on this and
     * an operator running the database abilities directly see the same figures.
     *
     * @return array
     */
    public function database() {
        global $wpdb;

        $out = array(
            'total_size_mb'      => null,
            'total_overhead_mb'  => null,
            'table_count'        => null,
            'largest_tables'     => array(),
            'autoloaded_kb'      => 0.0,
            'autoloaded_options' => 0,
        );

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT table_name, table_rows, data_length, index_length, data_free, engine
                   FROM information_schema.TABLES
                  WHERE table_schema = %s
                  ORDER BY (data_length + index_length) DESC',
                DB_NAME
            ),
            ARRAY_A
        );

        if (!is_array($rows)) {
            $out['note'] = 'information_schema is not readable by this database user, so table sizes are unavailable.';
        } else {
            $total = 0;
            $free  = 0;
            $large = array();

            foreach ($rows as $r) {
                $size = (int) $r['data_length'] + (int) $r['index_length'];
                $total += $size;
                $free  += (int) $r['data_free'];

                // Already ordered largest first by the query, so the first five
                // rows are the five largest tables.
                if (count($large) < 5) {
                    $large[] = array(
                        'table'       => $r['table_name'],
                        'engine'      => $r['engine'],
                        // An estimate for InnoDB, as in db-overview. Said plainly
                        // so nobody charts it as an exact count.
                        'rows_approx' => (int) $r['table_rows'],
                        'size_mb'     => $this->to_mb($size),
                        'overhead_mb' => $this->to_mb((int) $r['data_free']),
                    );
                }
            }

            $out['total_size_mb']     = $this->to_mb($total);
            $out['total_overhead_mb'] = $this->to_mb($free);
            $out['table_count']       = count($rows);
            $out['largest_tables']    = $large;
        }

        // WordPress 6.6 widened autoload from yes/no. Matching only 'yes'
        // silently under-reports on anything current.
        $on = "autoload IN ('yes','on','auto','auto-on')";

        $bytes = (int) $wpdb->get_var("SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} WHERE {$on}");
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options} WHERE {$on}");

        $out['autoloaded_kb']      = round($bytes / 1024, 1);
        $out['autoloaded_bytes']   = $bytes;
        $out['autoloaded_options'] = $count;

        return $out;
    }

    /**
     * What the site actually holds.
     *
     * Counted from the posts table rather than from wp_count_posts() so that
     * post types belonging to a deactivated plugin still appear. Rows left
     * behind by something no longer installed are the ones most worth seeing.
     *
     * @return array
     */
    public function content() {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT post_type, post_status, COUNT(*) AS n
               FROM {$wpdb->posts}
              GROUP BY post_type, post_status",
            ARRAY_A
        );

        $types = array();

        foreach ((array) $rows as $r) {
            $type   = $r['post_type'];
            $status = $r['post_status'];
            $n      = (int) $r['n'];

            if (!isset($types[$type])) {
                $types[$type] = array(
                    'published' => 0,
                    'draft'     => 0,
                    'trash'     => 0,
                    'other'     => 0,
                    'total'     => 0,
                );
            }

            if ('publish' === $status) {
                $types[$type]['published'] += $n;
            } elseif ('draft' === $status) {
                $types[$type]['draft'] += $n;
            } elseif ('trash' === $status) {
                $types[$type]['trash'] += $n;
            } else {
                $types[$type]['other'] += $n;
            }

            $types[$type]['total'] += $n;
        }

        // Revisions are attributed to the type of the post they revise, not to
        // 'revision', because "pages have 4,000 revisions" is actionable and
        // "there are 4,000 revisions" is not.
        $rev_rows = $wpdb->get_results(
            "SELECT p.post_type AS parent_type, COUNT(*) AS n
               FROM {$wpdb->posts} r
         INNER JOIN {$wpdb->posts} p ON p.ID = r.post_parent
              WHERE r.post_type = 'revision'
              GROUP BY p.post_type",
            ARRAY_A
        );

        $revisions = array();

        foreach ((array) $rev_rows as $r) {
            $revisions[$r['parent_type']] = (int) $r['n'];
        }

        arsort($revisions);

        $terms = array();

        $term_rows = $wpdb->get_results(
            "SELECT taxonomy, COUNT(*) AS n FROM {$wpdb->term_taxonomy} GROUP BY taxonomy",
            ARRAY_A
        );

        foreach ((array) $term_rows as $r) {
            $terms[$r['taxonomy']] = (int) $r['n'];
        }

        arsort($terms);

        $users = array('total_users' => 0, 'by_role' => array());

        if (function_exists('count_users')) {
            $counted = count_users();

            $users['total_users'] = isset($counted['total_users']) ? (int) $counted['total_users'] : 0;
            $users['by_role']     = isset($counted['avail_roles']) ? array_map('intval', $counted['avail_roles']) : array();
        }

        $attachments = isset($types['attachment']['total']) ? (int) $types['attachment']['total'] : 0;

        return array(
            'post_types'         => $types,
            'revisions_by_type'  => $revisions,
            'revisions_total'    => array_sum($revisions),
            'attachments'        => $attachments,
            'attachment_storage' => $this->attachment_storage($attachments),
            'terms_by_taxonomy'  => $terms,
            'terms_total'        => array_sum($terms),
            'users'              => $users,
        );
    }

    /**
     * Total bytes held by the media library, where postmeta knows.
     *
     * WordPress records a `filesize` inside the serialised attachment metadata
     * from 6.0 onward, but only for attachments written or regenerated since,
     * and it cannot be summed in SQL because it lives inside a serialised blob.
     * So this reads them in bounded batches and stops at a ceiling rather than
     * unserialising a hundred thousand rows on a web request.
     *
     * The result is therefore a floor, not a total, and says so. A partial
     * figure labelled partial is useful; one labelled as a total is a lie that
     * somebody will chart.
     *
     * @param int $attachments Total attachments, for reporting coverage.
     * @return array
     */
    protected function attachment_storage($attachments) {
        global $wpdb;

        $batch   = 500;
        $ceiling = 10000;
        $bytes   = 0;
        $seen    = 0;
        $known   = 0;

        for ($offset = 0; $offset < $ceiling; $offset += $batch) {
            $values = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT meta_value
                       FROM {$wpdb->postmeta}
                      WHERE meta_key = '_wp_attachment_metadata'
                      ORDER BY meta_id
                      LIMIT %d OFFSET %d",
                    $batch,
                    $offset
                )
            );

            if (empty($values)) {
                break;
            }

            foreach ($values as $value) {
                $seen++;

                // Untrusted only in the sense that a plugin may have written
                // something unexpected here; false for objects so a stray
                // serialised object is never instantiated by measuring it.
                $meta = @unserialize($value, array('allowed_classes' => false));

                if (is_array($meta) && isset($meta['filesize'])) {
                    $bytes += (int) $meta['filesize'];
                    $known++;
                }
            }

            if (count($values) < $batch) {
                break;
            }
        }

        return array(
            'known_bytes'    => $bytes,
            'known_mb'       => $this->to_mb($bytes),
            'measured'       => $seen,
            'with_filesize'  => $known,
            'attachments'    => (int) $attachments,
            'complete'       => $known > 0 && $known >= (int) $attachments,
            'note'           => 'A floor, not a total. WordPress only records a file size in attachment metadata from 6.0 onward, and this reads at most ' . $ceiling . ' records per call.',
        );
    }

    /**
     * The scheduled queue, and how far behind it is.
     *
     * An overdue queue is the single most common cause of a site that feels
     * broken while reporting nothing wrong: posts do not publish, mail does not
     * send, caches do not warm, and there is no error anywhere because nothing
     * failed - it simply never ran. WordPress fires cron off front-end traffic,
     * so a quiet site or a misconfigured DISABLE_WP_CRON leaves the queue to
     * pile up silently.
     *
     * @return array
     */
    public function cron() {
        $disabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;

        $out = array(
            'disable_wp_cron'        => (bool) $disabled,
            'events_scheduled'       => 0,
            'events_overdue'         => 0,
            'overdue_hooks'          => array(),
            'most_overdue'           => null,
            'next_event_at'          => null,
            'next_event_in_seconds'  => null,
        );

        if (!function_exists('_get_cron_array')) {
            $out['note'] = 'The cron array is not readable in this context.';

            return $out;
        }

        $cron = _get_cron_array();

        if (!is_array($cron)) {
            $out['note'] = 'No cron array is stored.';

            return $out;
        }

        $now      = time();
        $overdue  = array();
        $earliest = null;

        foreach ($cron as $ts => $hooks) {
            if (!is_array($hooks)) {
                continue;
            }

            $ts = (int) $ts;

            if (null === $earliest || $ts < $earliest) {
                $earliest = $ts;
            }

            foreach ($hooks as $hook => $sigs) {
                if (!is_array($sigs)) {
                    continue;
                }

                $n = count($sigs);
                $out['events_scheduled'] += $n;

                if ($ts >= $now) {
                    continue;
                }

                $out['events_overdue'] += $n;

                $late = $now - $ts;

                if (!isset($overdue[$hook]) || $late > $overdue[$hook]['late_seconds']) {
                    $overdue[$hook] = array(
                        'hook'         => $hook,
                        'events'       => 0,
                        'late_seconds' => $late,
                        'scheduled_at' => gmdate('c', $ts),
                    );
                }

                $overdue[$hook]['events'] += $n;
            }
        }

        usort($overdue, function ($a, $b) {
            return $b['late_seconds'] <=> $a['late_seconds'];
        });

        $out['overdue_hooks'] = $overdue;
        $out['most_overdue']  = isset($overdue[0]) ? $overdue[0] : null;

        if (null !== $earliest) {
            $out['next_event_at']         = gmdate('c', $earliest);
            $out['next_event_in_seconds'] = $earliest - $now;
        }

        // A handful of events a minute or two late is normal on a low-traffic
        // site and is not worth a red row. Hours late is a stuck queue.
        $out['backed_up'] = $out['events_overdue'] > 0
            && null !== $out['most_overdue']
            && $out['most_overdue']['late_seconds'] > 3600;

        return $out;
    }

    /* ---------------------------------------------------------------------
     * Verdicts
     * ------------------------------------------------------------------ */

    /**
     * The numbers above, reduced to rows a dashboard can colour.
     *
     * Each entry is {ok, message} and nothing else. Deliberately: the caller
     * should never have to know that 800 is the autoload budget or that 8.1 is
     * the PHP floor, because the moment it does, those thresholds live in two
     * places and drift apart. The message carries the number that decided it,
     * so a red row explains itself without a second call.
     *
     * @return array<string, array{ok: bool, message: string}>
     */
    public function health($runtime, $database, $content, $cron) {
        $checks = array();

        $autoload_kb = isset($database['autoloaded_kb']) ? (float) $database['autoloaded_kb'] : 0.0;
        $budget_kb   = round(self::AUTOLOAD_BUDGET / 1024, 1);

        $checks['autoload_within_budget'] = array(
            'ok'      => $autoload_kb <= $budget_kb,
            'message' => $autoload_kb <= $budget_kb
                ? sprintf('Autoloaded options total %s KB, inside the %s KB budget.', $autoload_kb, $budget_kb)
                : sprintf('Autoloaded options total %s KB against a %s KB budget. Every request pays this. Run db-autoload to see which options are responsible.', $autoload_kb, $budget_kb),
        );

        $overhead_mb = isset($database['total_overhead_mb']) ? (float) $database['total_overhead_mb'] : 0.0;
        $overhead_budget_mb = round(self::OVERHEAD_BUDGET / 1048576, 1);

        $checks['database_overhead'] = array(
            'ok'      => $overhead_mb <= $overhead_budget_mb,
            'message' => $overhead_mb <= $overhead_budget_mb
                ? sprintf('%s MB of reclaimable table overhead, which is not worth a rebuild.', $overhead_mb)
                : sprintf('%s MB of reclaimable table overhead. db-optimize will return it, but it locks each table while it rebuilds, so run it when the site is quiet.', $overhead_mb),
        );

        $revisions = isset($content['revisions_by_type']) ? (array) $content['revisions_by_type'] : array();
        $worst     = '';
        $worst_n   = 0;

        foreach ($revisions as $type => $n) {
            if ($n > $worst_n) {
                $worst   = $type;
                $worst_n = (int) $n;
            }
        }

        $checks['revision_volume'] = array(
            'ok'      => $worst_n <= self::REVISION_BUDGET,
            'message' => $worst_n <= self::REVISION_BUDGET
                ? sprintf('No post type holds more than %d revisions.', self::REVISION_BUDGET)
                : sprintf('%s holds %d revisions, against a threshold of %d. They are rows nothing reads until somebody rolls back; db-cleanup can remove them.', $worst, $worst_n, self::REVISION_BUDGET),
        );

        $backed_up = !empty($cron['backed_up']);
        $late      = isset($cron['most_overdue']['late_seconds']) ? (int) $cron['most_overdue']['late_seconds'] : 0;
        $late_hook = isset($cron['most_overdue']['hook']) ? $cron['most_overdue']['hook'] : '';

        $checks['cron_queue'] = array(
            'ok'      => !$backed_up,
            'message' => !$backed_up
                ? sprintf('%d scheduled events, %d of them due. The queue is draining.', (int) $cron['events_scheduled'], (int) $cron['events_overdue'])
                : sprintf('%d of %d scheduled events are overdue; %s is %s late. Nothing errors when cron stops - work simply never happens. Check DISABLE_WP_CRON and whether a real cron job is calling wp-cron.php.', (int) $cron['events_overdue'], (int) $cron['events_scheduled'], $late_hook, $this->duration($late)),
        );

        $php_ok = PHP_VERSION_ID >= 80100;

        $checks['php_version'] = array(
            'ok'      => $php_ok,
            'message' => $php_ok
                ? sprintf('PHP %s.', PHP_VERSION)
                : sprintf('PHP %s is below 8.1. Older branches are out of security support and measurably slower on the same code.', PHP_VERSION),
        );

        $cache_ok = !empty($runtime['object_cache_external']);

        $checks['object_cache'] = array(
            'ok'      => $cache_ok,
            'message' => $cache_ok
                ? 'A persistent object cache is in use.'
                : 'No persistent object cache. Every request rebuilds the same option and query results from the database, which is the cheapest large win available on most sites.',
        );

        return $checks;
    }

    /* ---------------------------------------------------------------------
     * History
     * ------------------------------------------------------------------ */

    /**
     * Reduce a snapshot to the handful of numbers worth keeping.
     *
     * Storing the whole snapshot would be easier and wrong: ninety copies of
     * every table name and every taxonomy count is a large option that grows
     * without limit, and none of it is the part anyone plots. Only figures that
     * mean something as a trend are kept.
     *
     * @param array $snapshot
     * @return array
     */
    protected function trim_snapshot($snapshot) {
        $posts = 0;

        foreach ((array) $snapshot['content']['post_types'] as $type => $counts) {
            if ('revision' === $type || 'attachment' === $type) {
                continue;
            }

            $posts += (int) $counts['total'];
        }

        return array(
            'recorded_at'         => gmdate('c'),
            'timestamp'           => time(),
            'database_size_mb'    => $snapshot['database']['total_size_mb'],
            'database_overhead_mb' => $snapshot['database']['total_overhead_mb'],
            'autoloaded_kb'       => $snapshot['database']['autoloaded_kb'],
            'autoloaded_options'  => $snapshot['database']['autoloaded_options'],
            'posts_total'         => $posts,
            'revisions_total'     => (int) $snapshot['content']['revisions_total'],
            'attachments'         => (int) $snapshot['content']['attachments'],
            'cron_events'         => (int) $snapshot['cron']['events_scheduled'],
            'cron_overdue'        => (int) $snapshot['cron']['events_overdue'],
        );
    }

    /**
     * Append one entry, oldest discarded once the cap is reached.
     *
     * @return array The entry that was stored.
     */
    public function record() {
        $entry   = $this->trim_snapshot($this->snapshot());
        $history = $this->history();

        $history[] = $entry;

        if (count($history) > self::MAX_ENTRIES) {
            $history = array_slice($history, -self::MAX_ENTRIES);
        }

        // The third argument is the whole reason this method is not a one-liner.
        // 'no' keeps the option out of the autoload set, which is what stops a
        // growing history from being read on every request for the life of the
        // site. update_option() passes it through to add_option() when the
        // option does not exist yet, so this is correct on the first call too.
        update_option(self::OPTION, $history, 'no');

        return $entry;
    }

    /**
     * Stored entries, oldest first.
     *
     * @return array
     */
    public function history() {
        $history = get_option(self::OPTION, array());

        return is_array($history) ? array_values($history) : array();
    }

    /**
     * Change over the last N entries, so nobody has to subtract by hand.
     *
     * Entries are not guaranteed to be one day apart - a site that was offline
     * records nothing - so the window is expressed in entries and the actual
     * span is reported alongside it rather than assumed.
     *
     * @param array $history
     * @param int   $window
     * @return array|null
     */
    protected function delta($history, $window) {
        $count = count($history);

        if ($count < 2) {
            return null;
        }

        $slice = array_slice($history, -max(2, (int) $window));
        $first = reset($slice);
        $last  = end($slice);

        $span = max(0, (int) $last['timestamp'] - (int) $first['timestamp']);

        return array(
            'entries'              => count($slice),
            'from'                 => $first['recorded_at'],
            'to'                   => $last['recorded_at'],
            'span_seconds'         => $span,
            'span_days'            => round($span / 86400, 1),
            'database_size_mb'     => round((float) $last['database_size_mb'] - (float) $first['database_size_mb'], 2),
            'autoloaded_kb'        => round((float) $last['autoloaded_kb'] - (float) $first['autoloaded_kb'], 1),
            'autoloaded_options'   => (int) $last['autoloaded_options'] - (int) $first['autoloaded_options'],
            'posts_total'          => (int) $last['posts_total'] - (int) $first['posts_total'],
            'revisions_total'      => (int) $last['revisions_total'] - (int) $first['revisions_total'],
            'cron_overdue'         => (int) $last['cron_overdue'] - (int) $first['cron_overdue'],
        );
    }

    /**
     * The stored history plus its arithmetic.
     *
     * @param bool $record Append a fresh entry before returning.
     * @return array
     */
    public function history_report($record = false) {
        $recorded = null;

        if ($record) {
            $recorded = $this->record();
        }

        $history = $this->history();

        return array(
            'option'         => self::OPTION,
            'autoload'       => 'no',
            'entries'        => count($history),
            'max_entries'    => self::MAX_ENTRIES,
            'recorded'       => (bool) $record,
            'recorded_entry' => $recorded,
            'next_scheduled' => wp_next_scheduled(self::CRON_HOOK) ? gmdate('c', wp_next_scheduled(self::CRON_HOOK)) : null,
            'history'        => $history,
            'deltas'         => array(
                'last_7'  => $this->delta($history, 7),
                'last_30' => $this->delta($history, 30),
            ),
            'note'           => 'Recorded daily by cron and capped at ' . self::MAX_ENTRIES . ' entries, oldest discarded first. A null delta means there is not yet enough history to compare.',
        );
    }

    /* ---------------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------------ */

    /**
     * Bytes from a PHP ini shorthand such as '256M'.
     *
     * A limit of -1 means unlimited and is passed straight through, because
     * reporting unlimited memory as 0 MB would read as the opposite of what it
     * is.
     *
     * @param string $value
     * @return int
     */
    protected function ini_bytes($value) {
        $value = trim((string) $value);

        if ('' === $value) {
            return 0;
        }

        if ('-1' === $value) {
            return -1;
        }

        $unit   = strtolower(substr($value, -1));
        $number = (float) $value;

        switch ($unit) {
            case 'g':
                $number *= 1024;
                // Falls through.
            case 'm':
                $number *= 1024;
                // Falls through.
            case 'k':
                $number *= 1024;
                break;
        }

        return (int) $number;
    }

    /**
     * Bytes as megabytes, with -1 preserved as -1.
     *
     * @param int $bytes
     * @return float|int
     */
    protected function to_mb($bytes) {
        $bytes = (int) $bytes;

        return $bytes < 0 ? -1 : round($bytes / 1048576, 2);
    }

    /**
     * Seconds as something readable in a sentence.
     *
     * @param int $seconds
     * @return string
     */
    protected function duration($seconds) {
        $seconds = max(0, (int) $seconds);

        if ($seconds < 120) {
            return $seconds . ' seconds';
        }

        if ($seconds < 7200) {
            return round($seconds / 60) . ' minutes';
        }

        if ($seconds < 172800) {
            return round($seconds / 3600) . ' hours';
        }

        return round($seconds / 86400) . ' days';
    }

    /* ---------------------------------------------------------------------
     * Abilities
     * ------------------------------------------------------------------ */

    private function register_snapshot() {
        $this->register(self::PREFIX . 'metrics-snapshot', array(
            'label'       => 'Site health snapshot',
            'description' => 'One structured reading of the whole site: PHP and cache configuration, database and autoload weight, content counts by post type and taxonomy, the scheduled queue and how far behind it is, plus a set of pass/fail checks with a plain-English message each. Read-only. Every number carries its unit in the key name, so this can be rendered as a dashboard without interpretation.',
            'category'    => self::CATEGORY,
            'input_schema' => array(
                'type' => 'object', 'properties' => array(), 'additionalProperties' => false,
            ),
            'output_schema' => array('type' => 'object'),
            'permission_callback' => array($this, 'can_manage'),
            'execute_callback'    => function () {
                return $this->snapshot();
            },
        ));
    }

    private function register_history() {
        $this->register(self::PREFIX . 'metrics-history', array(
            'label'       => 'Site health over time',
            'description' => 'The recorded history of the trimmed metrics - database size, autoload weight, content counts and cron backlog - with the change over the last 7 and 30 entries computed for you. Recorded daily by cron, capped at 90 entries. Read-only unless record is true, which appends a fresh entry first. Growth is what a single snapshot cannot show.',
            'category'    => self::CATEGORY,
            'input_schema' => array(
                'type'       => 'object',
                'properties' => array(
                    'record' => array(
                        'type'        => 'boolean',
                        'description' => 'Append a new entry before returning. Defaults to false, which only reads what is already stored.',
                    ),
                ),
                'additionalProperties' => false,
            ),
            'output_schema' => array('type' => 'object'),
            'permission_callback' => array($this, 'can_manage'),
            'execute_callback'    => function ($input) {
                $record = isset($input['record']) && $input['record'];

                return $this->history_report($record);
            },
        ));
    }
}
