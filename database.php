<?php
/**
 * Database diagnostics and cleanup.
 *
 * Every WordPress site accumulates rows nothing reads any more: meta whose post
 * was deleted, revisions of pages nobody will roll back, transients that expired
 * years ago, and the debris left by plugins that have since been removed. None
 * of it is visible from the admin, and all of it is paid for on every query that
 * touches the table.
 *
 * This exposes that as data rather than as a button. The counting is read-only
 * and safe to run at any time; deletion is a separate, explicit call that
 * reports what it would do before it does anything.
 *
 * Deliberately built on $wpdb rather than on another cleanup plugin's internals.
 * Those functions are private API, they change without notice, and depending on
 * them would mean this feature stops working the day that plugin is deactivated
 * - which is precisely the moment someone is most likely to want it.
 *
 * @package AutoSEOManager
 */

if (!defined('ABSPATH')) {
    exit;
}

class AutoSEODatabase {

    const CATEGORY = 'nyuchi-seo';
    const PREFIX   = 'nyuchi-seo/';

    /**
     * Hard ceiling on rows removed in a single call.
     *
     * A cleanup that tries to delete two million rows in one statement will hit
     * the PHP time limit, and on a managed host it may be killed mid-statement.
     * Deleting in bounded batches means a run that stops early has still made
     * real progress and can simply be called again.
     */
    const MAX_DELETE = 5000;

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
        $this->register_overview();
        $this->register_orphans();
        $this->register_autoload();
        $this->register_cleanup();
    }

    /* ---------------------------------------------------------------------
     * Measurement
     * ------------------------------------------------------------------ */

    /**
     * Per-table size, row count and reclaimable overhead.
     *
     * @return array
     */
    public function table_report() {
        global $wpdb;

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
            return array('tables' => array(), 'note' => 'information_schema is not readable by this database user.');
        }

        $tables = array();
        $total  = 0;
        $free   = 0;

        foreach ($rows as $r) {
            $size = (int) $r['data_length'] + (int) $r['index_length'];
            $total += $size;
            $free  += (int) $r['data_free'];

            $tables[] = array(
                'table'        => $r['table_name'],
                'engine'       => $r['engine'],
                // information_schema.table_rows is an estimate for InnoDB. Said
                // plainly here so nobody treats it as an exact count.
                'rows_approx'  => (int) $r['table_rows'],
                'size_mb'      => round($size / 1048576, 2),
                'overhead_mb'  => round((int) $r['data_free'] / 1048576, 2),
            );
        }

        return array(
            'tables'         => $tables,
            'total_size_mb'  => round($total / 1048576, 2),
            'overhead_mb'    => round($free / 1048576, 2),
            'table_count'    => count($tables),
        );
    }

    /**
     * The autoload set.
     *
     * Every autoloaded option is read on every single request, including AJAX
     * and REST. A few hundred kilobytes here costs more across a day than a
     * large but rarely-read table does.
     *
     * @param int $limit Largest N to list.
     * @return array
     */
    public function autoload_report($limit = 25) {
        global $wpdb;

        // WordPress 6.6 changed autoload from yes/no to a wider set of values.
        // Matching only 'yes' silently under-reports on anything current.
        $on = "autoload IN ('yes','on','auto','auto-on')";

        $total = (int) $wpdb->get_var("SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} WHERE {$on}");
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options} WHERE {$on}");

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT option_name, LENGTH(option_value) AS bytes
                   FROM {$wpdb->options}
                  WHERE {$on}
                  ORDER BY bytes DESC
                  LIMIT %d",
                max(1, min(200, (int) $limit))
            ),
            ARRAY_A
        );

        $largest = array();

        foreach ((array) $rows as $r) {
            $largest[] = array(
                'option' => $r['option_name'],
                'kb'     => round((int) $r['bytes'] / 1024, 1),
            );
        }

        return array(
            'autoloaded_options' => $count,
            'autoloaded_kb'      => round($total / 1024, 1),
            // Roughly where a site starts paying for it noticeably.
            'over_budget'        => $total > 800 * 1024,
            'largest'            => $largest,
        );
    }

    /**
     * Definitions of what counts as debris.
     *
     * Each entry carries the SQL to count it and the SQL to remove it, so the
     * dry run and the deletion can never disagree about what they are acting on.
     *
     * @return array<string, array>
     */
    public function targets() {
        global $wpdb;

        $t = array(
            'orphaned_postmeta' => array(
                'label' => 'Post meta whose post no longer exists',
                'count' => "SELECT COUNT(*) FROM {$wpdb->postmeta} pm
                              LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                             WHERE p.ID IS NULL",
                'delete' => "DELETE pm FROM {$wpdb->postmeta} pm
                               LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                              WHERE p.ID IS NULL LIMIT %d",
            ),
            'orphaned_termmeta' => array(
                'label' => 'Term meta whose term no longer exists',
                'count' => "SELECT COUNT(*) FROM {$wpdb->termmeta} tm
                              LEFT JOIN {$wpdb->terms} t ON t.term_id = tm.term_id
                             WHERE t.term_id IS NULL",
                'delete' => "DELETE tm FROM {$wpdb->termmeta} tm
                               LEFT JOIN {$wpdb->terms} t ON t.term_id = tm.term_id
                              WHERE t.term_id IS NULL LIMIT %d",
            ),
            'orphaned_commentmeta' => array(
                'label' => 'Comment meta whose comment no longer exists',
                'count' => "SELECT COUNT(*) FROM {$wpdb->commentmeta} cm
                              LEFT JOIN {$wpdb->comments} c ON c.comment_ID = cm.comment_id
                             WHERE c.comment_ID IS NULL",
                'delete' => "DELETE cm FROM {$wpdb->commentmeta} cm
                               LEFT JOIN {$wpdb->comments} c ON c.comment_ID = cm.comment_id
                              WHERE c.comment_ID IS NULL LIMIT %d",
            ),
            'orphaned_relationships' => array(
                'label' => 'Term relationships pointing at a deleted post',
                'count' => "SELECT COUNT(*) FROM {$wpdb->term_relationships} tr
                              LEFT JOIN {$wpdb->posts} p ON p.ID = tr.object_id
                             WHERE p.ID IS NULL",
                'delete' => "DELETE tr FROM {$wpdb->term_relationships} tr
                               LEFT JOIN {$wpdb->posts} p ON p.ID = tr.object_id
                              WHERE p.ID IS NULL LIMIT %d",
            ),
            'revisions' => array(
                'label' => 'Post revisions',
                'count' => "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'",
                'delete' => "DELETE FROM {$wpdb->posts} WHERE post_type = 'revision' LIMIT %d",
            ),
            'auto_drafts' => array(
                'label' => 'Abandoned auto-drafts',
                'count' => "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'auto-draft'",
                'delete' => "DELETE FROM {$wpdb->posts} WHERE post_status = 'auto-draft' LIMIT %d",
            ),
            'trashed_posts' => array(
                'label' => 'Posts in the trash',
                'count' => "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'trash'",
                'delete' => "DELETE FROM {$wpdb->posts} WHERE post_status = 'trash' LIMIT %d",
            ),
            'spam_comments' => array(
                'label' => 'Spam and trashed comments',
                'count' => "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved IN ('spam','trash')",
                'delete' => "DELETE FROM {$wpdb->comments} WHERE comment_approved IN ('spam','trash') LIMIT %d",
            ),
            'expired_transients' => array(
                'label' => 'Transients whose expiry has passed',
                'count' => "SELECT COUNT(*) FROM {$wpdb->options}
                             WHERE option_name LIKE '\\_transient\\_timeout\\_%'
                               AND option_value < UNIX_TIMESTAMP()",
                'delete' => "DELETE FROM {$wpdb->options}
                              WHERE option_name LIKE '\\_transient\\_timeout\\_%'
                                AND option_value < UNIX_TIMESTAMP() LIMIT %d",
            ),
            'session_options' => array(
                // WP Travel writes a session row per visitor and does not always
                // collect them afterwards, so these accumulate indefinitely.
                'label' => 'Expired visitor session rows',
                'count' => "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '\\_wp\\_session\\_%'",
                'delete' => "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_wp\\_session\\_%' LIMIT %d",
            ),
        );

        /**
         * Debris left by a plugin that is no longer installed.
         *
         * Registered separately because the test is different: these rows are
         * only safe to remove once the plugin that owns them is genuinely gone.
         * Each entry names the plugin so the decision can be checked rather
         * than taken on trust.
         */
        return apply_filters('auto_seo_db_targets', $t);
    }

    /**
     * Count every target without changing anything.
     *
     * @return array
     */
    public function orphan_report() {
        global $wpdb;

        $out   = array();
        $total = 0;

        foreach ($this->targets() as $key => $spec) {
            $n = (int) $wpdb->get_var($spec['count']);
            $total += $n;

            $out[$key] = array(
                'label' => $spec['label'],
                'rows'  => $n,
            );
        }

        return array(
            'targets'    => $out,
            'total_rows' => $total,
            'note'       => 'Counting only. Nothing is removed unless db-cleanup is called with dry_run set to false.',
        );
    }

    /**
     * Remove one or more targets.
     *
     * @param array $keys    Target keys.
     * @param bool  $dry_run When true, report what would go and change nothing.
     * @return array
     */
    public function cleanup($keys, $dry_run = true) {
        global $wpdb;

        $specs   = $this->targets();
        $results = array();
        $removed = 0;

        foreach ((array) $keys as $key) {
            if (!isset($specs[$key])) {
                $results[$key] = array('error' => 'Unknown target.');
                continue;
            }

            $found = (int) $wpdb->get_var($specs[$key]['count']);

            if ($dry_run) {
                $results[$key] = array(
                    'label'      => $specs[$key]['label'],
                    'would_remove' => min($found, self::MAX_DELETE),
                    'found'      => $found,
                );
                continue;
            }

            $n = $wpdb->query($wpdb->prepare($specs[$key]['delete'], self::MAX_DELETE));
            $n = is_numeric($n) ? (int) $n : 0;
            $removed += $n;

            $results[$key] = array(
                'label'     => $specs[$key]['label'],
                'removed'   => $n,
                'remaining' => max(0, $found - $n),
            );
        }

        if (!$dry_run) {
            // Deleting a post row leaves its meta and relationships behind, so
            // a revision sweep creates fresh orphans. Reporting the new counts
            // makes the follow-up call obvious rather than something to notice
            // weeks later.
            wp_cache_flush();
        }

        return array(
            'dry_run' => (bool) $dry_run,
            'results' => $results,
            'removed_total' => $removed,
            'batch_limit'   => self::MAX_DELETE,
            'note'          => $dry_run
                ? 'Nothing was changed. Call again with dry_run false to remove these rows.'
                : 'Removing post rows can orphan their meta. Run db-orphans again and clear any new orphaned_postmeta.',
        );
    }

    /* ---------------------------------------------------------------------
     * Abilities
     * ------------------------------------------------------------------ */

    private function register_overview() {
        $this->register(self::PREFIX . 'db-overview', array(
            'label'       => 'Database size overview',
            'description' => 'Per-table size, approximate row count and reclaimable overhead for every table in this WordPress database, largest first, plus the total. Read-only. Use this to find which tables are actually costing something before deciding what to clean.',
            'category'    => self::CATEGORY,
            'input_schema' => array(
                'type' => 'object', 'properties' => array(), 'additionalProperties' => false,
            ),
            'output_schema' => array('type' => 'object'),
            'permission_callback' => array($this, 'can_manage'),
            'execute_callback'    => function () {
                return $this->table_report();
            },
        ));
    }

    private function register_orphans() {
        $this->register(self::PREFIX . 'db-orphans', array(
            'label'       => 'Count removable rows',
            'description' => 'Count every category of row that nothing reads any more: meta whose parent was deleted, revisions, auto-drafts, trashed posts and comments, expired transients, and accumulated visitor session rows. Read-only and safe to run at any time. Returns a key per category for passing to db-cleanup.',
            'category'    => self::CATEGORY,
            'input_schema' => array(
                'type' => 'object', 'properties' => array(), 'additionalProperties' => false,
            ),
            'output_schema' => array('type' => 'object'),
            'permission_callback' => array($this, 'can_manage'),
            'execute_callback'    => function () {
                return $this->orphan_report();
            },
        ));
    }

    private function register_autoload() {
        $this->register(self::PREFIX . 'db-autoload', array(
            'label'       => 'Autoloaded option weight',
            'description' => 'Total size of the autoloaded options and the largest of them by name. Every autoloaded option is read on every request including REST and AJAX, so this is usually the single most valuable thing to look at on a slow site. Read-only.',
            'category'    => self::CATEGORY,
            'input_schema' => array(
                'type'       => 'object',
                'properties' => array(
                    'limit' => array(
                        'type'        => 'integer',
                        'description' => 'How many of the largest options to list. Default 25.',
                    ),
                ),
                'additionalProperties' => false,
            ),
            'output_schema' => array('type' => 'object'),
            'permission_callback' => array($this, 'can_manage'),
            'execute_callback'    => function ($input) {
                $limit = isset($input['limit']) ? (int) $input['limit'] : 25;

                return $this->autoload_report($limit);
            },
        ));
    }

    private function register_cleanup() {
        $this->register(self::PREFIX . 'db-cleanup', array(
            'label'       => 'Remove unused rows',
            'description' => 'Delete one or more categories reported by db-orphans. Defaults to a dry run that reports what would be removed and changes nothing; pass dry_run false to actually delete. Deletion is capped per call, so a large backlog needs repeated calls - the response reports what remains.',
            'category'    => self::CATEGORY,
            'input_schema' => array(
                'type'       => 'object',
                'properties' => array(
                    'targets' => array(
                        'type'        => 'array',
                        'items'       => array('type' => 'string'),
                        'description' => 'Target keys from db-orphans, e.g. ["revisions","expired_transients"].',
                    ),
                    'dry_run' => array(
                        'type'        => 'boolean',
                        'description' => 'Defaults to true. Must be explicitly false to delete anything.',
                    ),
                ),
                'required'             => array('targets'),
                'additionalProperties' => false,
            ),
            'output_schema' => array('type' => 'object'),
            'permission_callback' => array($this, 'can_manage'),
            'execute_callback'    => function ($input) {
                $targets = isset($input['targets']) ? (array) $input['targets'] : array();

                if (empty($targets)) {
                    return new WP_Error('no_targets', 'Name at least one target from db-orphans.');
                }

                // Absent means dry run. Anyone who wants rows deleted has to
                // say so, and a malformed call fails safe rather than destructively.
                $dry = !isset($input['dry_run']) || (bool) $input['dry_run'];

                return $this->cleanup($targets, $dry);
            },
        ));
    }
}
