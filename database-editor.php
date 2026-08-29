<?php
/**
 * Read and write individual database values.
 *
 * The database module reports and deletes in bulk. This is the other half:
 * reading one value, finding where a value lives, and changing it.
 *
 * Two things make this safer than opening a SQL console.
 *
 * The first is that every write defaults to a dry run and returns the previous
 * value, so a change can always be put back. Nothing here is a one-way door.
 *
 * The second is serialisation. WordPress stores arrays and objects as PHP
 * serialised strings, which encode the byte length of every string inside them
 * - s:11:"hello world". A plain find-and-replace changes the text without
 * changing the recorded length, and the result no longer unserialises. It does
 * not error either: WordPress gets false back and treats the option as empty,
 * so a site quietly loses settings and nobody connects it to the replace that
 * ran a week earlier. Every replace here unserialises first, walks the
 * structure, and re-serialises.
 *
 * @package AutoSEOManager
 */

if (!defined('ABSPATH')) {
    exit;
}

class AutoSEODatabaseEditor {

    const CATEGORY = 'nyuchi-optimization';
    const PREFIX   = 'nyuchi-optimization/';

    /**
     * Options that decide whether the site loads at all.
     *
     * Writing any of these through an automation client is almost always a
     * mistake, and the two url ones can make the admin unreachable, which is a
     * bad moment to discover the change was a typo. They are writable, but only
     * when asked for by name.
     */
    const GUARDED = array(
        'siteurl', 'home', 'active_plugins', 'template', 'stylesheet',
        'users_can_register', 'default_role', 'admin_email', 'db_version',
        'cron', 'rewrite_rules', 'wp_user_roles',
    );

    const MAX_ROWS = 200;

    public function __construct() {
        add_action('wp_abilities_api_init', array($this, 'register_abilities'));
    }

    public function can_manage() {
        return current_user_can('manage_options');
    }

    private function register($name, $args) {
        if (function_exists('wp_register_ability')) {
            wp_register_ability($name, $args);
        }
    }

    /* ---------------------------------------------------------------------
     * Serialisation-safe replace
     * ------------------------------------------------------------------ */

    /**
     * Replace inside a value without breaking its structure.
     *
     * Walks arrays and objects rather than treating the whole thing as text,
     * so the length prefixes PHP writes into a serialised string stay correct.
     *
     * @param mixed  $value Value to walk.
     * @param string $from  Needle.
     * @param string $to    Replacement.
     * @param int    $hits  Running count, by reference.
     * @return mixed
     */
    public static function deep_replace($value, $from, $to, &$hits = 0) {
        if (is_string($value)) {
            $n = substr_count($value, $from);

            if ($n) {
                $hits += $n;

                return str_replace($from, $to, $value);
            }

            return $value;
        }

        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $value[$k] = self::deep_replace($v, $from, $to, $hits);
            }

            return $value;
        }

        if (is_object($value)) {
            // Clone rather than mutate: the caller may still be holding the
            // original, and an in-place edit would change it underneath them.
            $copy = clone $value;

            foreach (get_object_vars($copy) as $k => $v) {
                $copy->$k = self::deep_replace($v, $from, $to, $hits);
            }

            return $copy;
        }

        return $value;
    }

    /* ---------------------------------------------------------------------
     * Reading
     * ------------------------------------------------------------------ */

    /**
     * Read one stored value.
     *
     * @param string $kind option|post_meta|term_meta|user_meta
     * @param string $key  Key name.
     * @param int    $id   Object id, ignored for options.
     * @return array
     */
    public function read($kind, $key, $id = 0) {
        global $wpdb;

        switch ($kind) {
            case 'option':
                $row = $wpdb->get_row(
                    $wpdb->prepare("SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s", $key),
                    ARRAY_A
                );

                if (null === $row) {
                    return array('found' => false, 'kind' => $kind, 'key' => $key);
                }

                $value = maybe_unserialize($row['option_value']);

                return array(
                    'found'      => true,
                    'kind'       => $kind,
                    'key'        => $key,
                    'type'       => gettype($value),
                    'serialised' => is_serialized($row['option_value']),
                    'autoload'   => $row['autoload'],
                    'bytes'      => strlen($row['option_value']),
                    'value'      => $value,
                );

            case 'post_meta':
            case 'term_meta':
            case 'user_meta':
                $fn    = str_replace('_meta', '', $kind);
                $value = get_metadata($fn, (int) $id, $key, true);

                return array(
                    'found' => '' !== $value && null !== $value,
                    'kind'  => $kind,
                    'key'   => $key,
                    'id'    => (int) $id,
                    'type'  => gettype($value),
                    'value' => $value,
                );
        }

        return array('error' => 'Unknown kind. Use option, post_meta, term_meta or user_meta.');
    }

    /**
     * Find where a string appears.
     *
     * Read-only, and deliberately the step before a replace: knowing how many
     * rows a change will touch, and which, is the difference between a
     * considered edit and a hopeful one.
     *
     * @param string $needle    What to look for.
     * @param array  $in        Which tables: options, postmeta, posts, termmeta.
     * @param bool   $keys_only Match option and meta keys rather than values.
     * @return array
     */
    public function find($needle, $in = array('options', 'postmeta'), $keys_only = false) {
        global $wpdb;

        $like = '%' . $wpdb->esc_like($needle) . '%';
        $out  = array();

        foreach ((array) $in as $table) {
            switch ($table) {
                case 'options':
                    $col  = $keys_only ? 'option_name' : 'option_value';
                    $rows = $wpdb->get_results(
                        $wpdb->prepare(
                            "SELECT option_name AS k, LENGTH(option_value) AS bytes
                               FROM {$wpdb->options} WHERE {$col} LIKE %s LIMIT %d",
                            $like,
                            self::MAX_ROWS
                        ),
                        ARRAY_A
                    );
                    break;

                case 'postmeta':
                    $col  = $keys_only ? 'meta_key' : 'meta_value';
                    $rows = $wpdb->get_results(
                        $wpdb->prepare(
                            "SELECT CONCAT(post_id, ':', meta_key) AS k, LENGTH(meta_value) AS bytes
                               FROM {$wpdb->postmeta} WHERE {$col} LIKE %s LIMIT %d",
                            $like,
                            self::MAX_ROWS
                        ),
                        ARRAY_A
                    );
                    break;

                case 'termmeta':
                    $col  = $keys_only ? 'meta_key' : 'meta_value';
                    $rows = $wpdb->get_results(
                        $wpdb->prepare(
                            "SELECT CONCAT(term_id, ':', meta_key) AS k, LENGTH(meta_value) AS bytes
                               FROM {$wpdb->termmeta} WHERE {$col} LIKE %s LIMIT %d",
                            $like,
                            self::MAX_ROWS
                        ),
                        ARRAY_A
                    );
                    break;

                case 'posts':
                    $rows = $wpdb->get_results(
                        $wpdb->prepare(
                            "SELECT CONCAT(ID, ':', post_type) AS k, LENGTH(post_content) AS bytes
                               FROM {$wpdb->posts} WHERE post_content LIKE %s LIMIT %d",
                            $like,
                            self::MAX_ROWS
                        ),
                        ARRAY_A
                    );
                    break;

                default:
                    continue 2;
            }

            $out[$table] = array(
                'matches' => is_array($rows) ? count($rows) : 0,
                'rows'    => is_array($rows) ? $rows : array(),
                'capped'  => is_array($rows) && count($rows) >= self::MAX_ROWS,
            );
        }

        return array(
            'needle'  => $needle,
            'scope'   => $keys_only ? 'keys' : 'values',
            'results' => $out,
            'note'    => 'Read-only. A capped result means there are more than ' . self::MAX_ROWS . ' matches, not that there are exactly that many.',
        );
    }

    /* ---------------------------------------------------------------------
     * Writing
     * ------------------------------------------------------------------ */

    /**
     * Set one stored value.
     *
     * @param string $kind    option|post_meta|term_meta|user_meta
     * @param string $key     Key name.
     * @param mixed  $value   New value.
     * @param int    $id      Object id.
     * @param bool   $dry_run Report only.
     * @param bool   $force   Required for a guarded option.
     * @return array
     */
    public function write($kind, $key, $value, $id = 0, $dry_run = true, $force = false) {
        $before = $this->read($kind, $key, $id);

        if ('option' === $kind && in_array($key, self::GUARDED, true) && !$force) {
            return array(
                'written' => false,
                'error'   => sprintf(
                    'Refusing to write "%s" without force. Changing it can make the site or the admin unreachable, so it has to be asked for by name.',
                    $key
                ),
                'current' => isset($before['value']) ? $before['value'] : null,
            );
        }

        if ($dry_run) {
            return array(
                'dry_run' => true,
                'kind'    => $kind,
                'key'     => $key,
                'from'    => isset($before['value']) ? $before['value'] : null,
                'to'      => $value,
                'existed' => !empty($before['found']),
                'note'    => 'Nothing was changed. Call again with dry_run false to apply.',
            );
        }

        // WordPress expects slashed input here and unslashes on the way in, because
        // it was written for form posts, which arrive slashed. A value that came
        // from an API call never was, so every backslash in it loses one level:
        // a JSON string's \" becomes " and its \n becomes a literal n, and what
        // lands in the database no longer parses. Slashing first cancels that out.
        $slashed = wp_slash($value);

        if ('option' === $kind) {
            $ok = update_option($key, $slashed);
        } else {
            $ok = update_metadata(str_replace('_meta', '', $kind), (int) $id, $key, $slashed);
        }

        return array(
            'written'  => (bool) $ok,
            'kind'     => $kind,
            'key'      => $key,
            // Returned so the change can be undone without having read it first.
            'previous' => isset($before['value']) ? $before['value'] : null,
            'current'  => $value,
            'note'     => $ok
                ? 'Keep "previous" if you may need to put this back.'
                : 'No row was updated. WordPress reports false both when the write fails and when the new value is identical to the old one.',
        );
    }

    /**
     * Replace a string across options, meta and post content.
     *
     * @param string $from    Needle.
     * @param string $to      Replacement.
     * @param array  $in      Tables to touch.
     * @param bool   $dry_run Report only.
     * @return array
     */
    public function replace($from, $to, $in = array('options', 'postmeta'), $dry_run = true) {
        global $wpdb;

        if ('' === $from) {
            return array('error' => 'Nothing to search for.');
        }

        $like    = '%' . $wpdb->esc_like($from) . '%';
        $report  = array();
        $changed = 0;

        foreach ((array) $in as $table) {
            $rows = array();

            if ('options' === $table) {
                $rows = $wpdb->get_results(
                    $wpdb->prepare("SELECT option_name AS id, option_value AS val FROM {$wpdb->options} WHERE option_value LIKE %s LIMIT %d", $like, self::MAX_ROWS),
                    ARRAY_A
                );
            } elseif ('postmeta' === $table) {
                $rows = $wpdb->get_results(
                    $wpdb->prepare("SELECT meta_id AS id, meta_value AS val FROM {$wpdb->postmeta} WHERE meta_value LIKE %s LIMIT %d", $like, self::MAX_ROWS),
                    ARRAY_A
                );
            } elseif ('termmeta' === $table) {
                $rows = $wpdb->get_results(
                    $wpdb->prepare("SELECT meta_id AS id, meta_value AS val FROM {$wpdb->termmeta} WHERE meta_value LIKE %s LIMIT %d", $like, self::MAX_ROWS),
                    ARRAY_A
                );
            } elseif ('posts' === $table) {
                $rows = $wpdb->get_results(
                    $wpdb->prepare("SELECT ID AS id, post_content AS val FROM {$wpdb->posts} WHERE post_content LIKE %s LIMIT %d", $like, self::MAX_ROWS),
                    ARRAY_A
                );
            } else {
                continue;
            }

            $hits = 0;
            $done = 0;

            foreach ((array) $rows as $r) {
                $was = maybe_unserialize($r['val']);
                $n   = 0;
                $now = self::deep_replace($was, $from, $to, $n);

                if (!$n) {
                    continue;
                }

                $hits += $n;

                if ($dry_run) {
                    continue;
                }

                if ('options' === $table) {
                    update_option($r['id'], wp_slash($now));
                } elseif ('posts' === $table) {
                    $wpdb->update($wpdb->posts, array('post_content' => $now), array('ID' => (int) $r['id']));
                    clean_post_cache((int) $r['id']);
                } else {
                    $t = ('postmeta' === $table) ? $wpdb->postmeta : $wpdb->termmeta;
                    $wpdb->update($t, array('meta_value' => maybe_serialize($now)), array('meta_id' => (int) $r['id']));
                }

                $done++;
                $changed++;
            }

            $report[$table] = array(
                'rows_examined'  => is_array($rows) ? count($rows) : 0,
                'occurrences'    => $hits,
                'rows_changed'   => $done,
                'capped'         => is_array($rows) && count($rows) >= self::MAX_ROWS,
            );
        }

        if (!$dry_run) {
            wp_cache_flush();
        }

        return array(
            'dry_run' => (bool) $dry_run,
            'from'    => $from,
            'to'      => $to,
            'report'  => $report,
            'rows_changed' => $changed,
            'note'    => $dry_run
                ? 'Nothing was changed. Call again with dry_run false to apply.'
                : 'Values were unserialised before replacing and re-serialised after, so nested arrays keep their byte-length prefixes. A capped table has more matches left - run it again.',
        );
    }

    /* ---------------------------------------------------------------------
     * Abilities
     * ------------------------------------------------------------------ */

    public function register_abilities() {
        $this->register(self::PREFIX . 'db-read', array(
            'label'       => 'Read a stored value',
            'description' => 'Read one option, post meta, term meta or user meta value, with its type, whether it is serialised, its size, and for options whether it is autoloaded. Read-only.',
            'category'    => self::CATEGORY,
            'input_schema' => array(
                'type'       => 'object',
                'properties' => array(
                    'kind' => array('type' => 'string', 'enum' => array('option', 'post_meta', 'term_meta', 'user_meta')),
                    'key'  => array('type' => 'string', 'description' => 'Option or meta key.'),
                    'id'   => array('type' => 'integer', 'description' => 'Post, term or user id. Ignored for options.'),
                ),
                'required'             => array('kind', 'key'),
                'additionalProperties' => false,
            ),
            'output_schema' => array('type' => 'object'),
            'permission_callback' => array($this, 'can_manage'),
            'execute_callback'    => function ($input) {
                return $this->read($input['kind'], $input['key'], isset($input['id']) ? (int) $input['id'] : 0);
            },
        ));

        $this->register(self::PREFIX . 'db-find', array(
            'label'       => 'Find where a value lives',
            'description' => 'Search options, post meta, term meta and post content for a string, and report which rows hold it. Read-only, and the sensible step before a replace: it says how many rows a change would touch.',
            'category'    => self::CATEGORY,
            'input_schema' => array(
                'type'       => 'object',
                'properties' => array(
                    'needle' => array('type' => 'string'),
                    'in'     => array('type' => 'array', 'items' => array('type' => 'string', 'enum' => array('options', 'postmeta', 'termmeta', 'posts'))),
                    'keys_only' => array('type' => 'boolean', 'description' => 'Match key names rather than values.'),
                ),
                'required'             => array('needle'),
                'additionalProperties' => false,
            ),
            'output_schema' => array('type' => 'object'),
            'permission_callback' => array($this, 'can_manage'),
            'execute_callback'    => function ($input) {
                return $this->find(
                    $input['needle'],
                    isset($input['in']) ? (array) $input['in'] : array('options', 'postmeta'),
                    !empty($input['keys_only'])
                );
            },
        ));

        $this->register(self::PREFIX . 'db-write', array(
            'label'       => 'Write a stored value',
            'description' => 'Set one option or meta value. Defaults to a dry run; returns the previous value so the change can be reversed without having read it first. Options that can make the site or admin unreachable are refused unless force is set.',
            'category'    => self::CATEGORY,
            'input_schema' => array(
                'type'       => 'object',
                'properties' => array(
                    'kind'    => array('type' => 'string', 'enum' => array('option', 'post_meta', 'term_meta', 'user_meta')),
                    'key'     => array('type' => 'string'),
                    'value'   => array('description' => 'New value. Arrays and objects are stored serialised by WordPress.'),
                    'id'      => array('type' => 'integer'),
                    'dry_run' => array('type' => 'boolean', 'description' => 'Defaults to true.'),
                    'force'   => array('type' => 'boolean', 'description' => 'Required to write a guarded option such as siteurl or active_plugins.'),
                ),
                'required'             => array('kind', 'key', 'value'),
                'additionalProperties' => false,
            ),
            'output_schema' => array('type' => 'object'),
            'permission_callback' => array($this, 'can_manage'),
            'execute_callback'    => function ($input) {
                return $this->write(
                    $input['kind'],
                    $input['key'],
                    $input['value'],
                    isset($input['id']) ? (int) $input['id'] : 0,
                    !isset($input['dry_run']) || (bool) $input['dry_run'],
                    !empty($input['force'])
                );
            },
        ));

        $this->register(self::PREFIX . 'db-replace', array(
            'label'       => 'Replace a string across the database',
            'description' => 'Find and replace across options, meta and post content. Values are unserialised before replacing and re-serialised after, so nested arrays are not corrupted the way a plain SQL replace corrupts them. Defaults to a dry run.',
            'category'    => self::CATEGORY,
            'input_schema' => array(
                'type'       => 'object',
                'properties' => array(
                    'from'    => array('type' => 'string'),
                    'to'      => array('type' => 'string'),
                    'in'      => array('type' => 'array', 'items' => array('type' => 'string', 'enum' => array('options', 'postmeta', 'termmeta', 'posts'))),
                    'dry_run' => array('type' => 'boolean', 'description' => 'Defaults to true.'),
                ),
                'required'             => array('from', 'to'),
                'additionalProperties' => false,
            ),
            'output_schema' => array('type' => 'object'),
            'permission_callback' => array($this, 'can_manage'),
            'execute_callback'    => function ($input) {
                return $this->replace(
                    $input['from'],
                    $input['to'],
                    isset($input['in']) ? (array) $input['in'] : array('options', 'postmeta'),
                    !isset($input['dry_run']) || (bool) $input['dry_run']
                );
            },
        ));
    }
}
