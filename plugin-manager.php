<?php
/**
 * Plugin inventory, update checks and updates.
 *
 * The plugins screen answers "what is installed" and little else. What actually
 * matters on a site someone else maintains is narrower and harder to see: which
 * plugins have an update waiting, which of them are even capable of receiving
 * one, and what happens to the site if an update goes wrong halfway through.
 *
 * That last question is why this is split into four abilities rather than one.
 * Listing is read-only and cheap, so it can be called freely. Checking for
 * updates makes outbound HTTP requests, so it is deliberate. Updating and
 * toggling change a live site, so they are dry runs unless told otherwise and
 * they verify the result afterwards instead of trusting the return value.
 *
 * The genuinely dangerous case has its own guard: a plugin that has no update
 * source at all. It reports a version, it never changes, and nothing anywhere
 * in WordPress says so. Surfacing those is most of the value here.
 *
 * @package Nyuchi_WordPress_Optimization
 */

if (!defined('ABSPATH')) {
    exit;
}

class AutoSEOPluginManager {

    const CATEGORY = 'nyuchi-optimization';
    const PREFIX   = 'nyuchi-optimization/';

    /**
     * Hard ceiling on plugins updated in a single call.
     *
     * Each update is a download, an unzip and a directory swap, and a managed
     * host will kill the request long before a queue of thirty finishes. A
     * bounded batch that reports what it did not get to can simply be called
     * again; a request killed mid-swap leaves a plugin directory half-written.
     */
    const MAX_UPDATE = 10;

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
        $this->register_list();
        $this->register_check_updates();
        $this->register_update();
        $this->register_toggle();
    }

    /* ---------------------------------------------------------------------
     * Shared helpers
     * ------------------------------------------------------------------ */

    /**
     * get_plugins() and friends live in the admin and are not loaded for REST
     * or cron requests, which is exactly where these abilities run.
     */
    protected function load_plugin_api() {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
    }

    /**
     * Directory of the plugin this file belongs to.
     *
     * Derived rather than hardcoded so it survives being installed under a
     * renamed directory, which happens whenever someone installs from a zip
     * built off a branch.
     *
     * @return string
     */
    protected function self_dir() {
        return dirname(plugin_basename(__FILE__));
    }

    /**
     * Whether a plugin file identifier refers to this plugin.
     *
     * @param string $file Plugin file identifier, e.g. "akismet/akismet.php".
     * @return bool
     */
    protected function is_self($file) {
        return dirname($file) === $this->self_dir();
    }

    /**
     * The update transient, split into the two halves that matter.
     *
     * `response` holds plugins with an update waiting. `no_update` holds
     * plugins that were checked and found current - which is the only reliable
     * evidence that a plugin has a working update source at all. A plugin in
     * neither list was never checked by anything.
     *
     * @return array{response: array, no_update: array, checked: bool, last_checked: ?string}
     */
    protected function update_state() {
        $t = get_site_transient('update_plugins');

        $response  = (is_object($t) && isset($t->response) && is_array($t->response)) ? $t->response : array();
        $no_update = (is_object($t) && isset($t->no_update) && is_array($t->no_update)) ? $t->no_update : array();

        return array(
            'response'     => $response,
            'no_update'    => $no_update,
            // With nothing cached at all, absence from both lists means nothing
            // has run yet rather than that a plugin cannot update. Recording
            // that here keeps the source heuristic from crying wolf.
            'checked'      => (!empty($response) || !empty($no_update)),
            'last_checked' => (is_object($t) && !empty($t->last_checked)) ? gmdate('c', (int) $t->last_checked) : null,
        );
    }

    /**
     * Where, if anywhere, a plugin's updates would come from.
     *
     * WordPress makes no distinction in the admin between a plugin from the
     * directory, a commercial plugin with its own update server, and a plugin
     * someone copied into wp-content by hand. The third kind never updates and
     * never says so, so it stays on whatever version it was the day it landed -
     * including through a security release.
     *
     * Attribution is a heuristic and is labelled as one: an entry in the update
     * transient proves something is checking, and the wordpress.org hostname in
     * that entry proves what. The Update URI header covers plugins that declare
     * a custom updater but have not been checked yet.
     *
     * @param array        $data    Plugin headers from get_plugins().
     * @param object|array|null $entry Matching transient entry, if any.
     * @return array{hosted: string, detail: string}
     */
    protected function update_source($data, $entry) {
        $uri = isset($data['UpdateURI']) ? trim((string) $data['UpdateURI']) : '';

        if (is_object($entry) || is_array($entry)) {
            $entry = (array) $entry;
            $url   = '';

            foreach (array('url', 'id', 'package') as $key) {
                if (!empty($entry[$key]) && is_string($entry[$key])) {
                    $url = $entry[$key];
                    break;
                }
            }

            if (false !== strpos($url, 'wordpress.org') || false !== strpos($url, 'w.org/')) {
                return array('hosted' => 'wordpress.org', 'detail' => 'wordpress.org plugin directory');
            }

            return array(
                'hosted' => 'external',
                'detail' => '' !== $uri ? $uri : 'A plugin or theme is supplying updates for this via a filter.',
            );
        }

        if ('' !== $uri) {
            if (false !== strpos($uri, 'wordpress.org') || false !== strpos($uri, 'w.org/')) {
                return array('hosted' => 'wordpress.org', 'detail' => 'Update URI header points at wordpress.org.');
            }

            return array('hosted' => 'external', 'detail' => $uri);
        }

        return array(
            'hosted' => 'none',
            'detail' => 'Nothing checks this plugin for updates. It will stay on its current version indefinitely.',
        );
    }

    /* ---------------------------------------------------------------------
     * Inventory
     * ------------------------------------------------------------------ */

    /**
     * Everything installed, with the parts the plugins screen leaves out.
     *
     * Read-only. Update information comes from whatever is already cached, so
     * this never reaches the network - see plugins-check-updates for that.
     *
     * @return array
     */
    public function inventory() {
        $this->load_plugin_api();

        $state = $this->update_state();
        $all   = get_plugins();
        $mu    = function_exists('get_mu_plugins') ? get_mu_plugins() : array();

        $plugins   = array();
        $active    = 0;
        $inactive  = 0;
        $updatable = 0;
        $unsourced = array();

        foreach ($all as $file => $data) {
            $entry = $this->describe($file, $data, false, $state);

            if ($entry['update_available']) {
                $updatable++;
            }

            if ($entry['active']) {
                $active++;
            } else {
                $inactive++;
            }

            if ('none' === $entry['hosted']) {
                $unsourced[] = array(
                    'file'    => $file,
                    'name'    => $entry['name'],
                    'version' => $entry['version'],
                    'active'  => $entry['active'],
                );
            }

            $plugins[] = $entry;
        }

        foreach ($mu as $file => $data) {
            // Must-use plugins load unconditionally and are never checked for
            // updates, so they are listed for completeness but deliberately
            // kept out of the counts and out of the unsourced warning - having
            // no update source is the normal state for them, not a finding.
            $plugins[] = $this->describe($file, $data, true, $state);
        }

        return array(
            'plugins' => $plugins,
            'summary' => array(
                'total'            => count($all),
                'active'           => $active,
                'inactive'         => $inactive,
                'updates_available' => $updatable,
                'must_use'         => count($mu),
                'no_update_source' => $unsourced,
            ),
            'update_data' => array(
                'checked'      => $state['checked'],
                'last_checked' => $state['last_checked'],
            ),
            'note' => $state['checked']
                ? 'Update information is read from cache and may be up to twelve hours old. Call plugins-check-updates for a fresh answer.'
                : 'No update check has been cached, so update availability and hosting are unknown for every plugin here. Call plugins-check-updates first.',
        );
    }

    /**
     * One plugin, flattened.
     *
     * @param string $file     Plugin file identifier.
     * @param array  $data     Headers from get_plugins() / get_mu_plugins().
     * @param bool   $must_use Whether it came from the mu-plugins directory.
     * @param array  $state    Result of update_state().
     * @return array
     */
    protected function describe($file, $data, $must_use, $state) {
        $update = isset($state['response'][$file]) ? $state['response'][$file] : null;
        $entry  = null !== $update
            ? $update
            : (isset($state['no_update'][$file]) ? $state['no_update'][$file] : null);

        $source = $must_use
            ? array('hosted' => 'must-use', 'detail' => 'Must-use plugins are not checked for updates by WordPress.')
            : $this->update_source($data, $entry);

        if (!$must_use && !$state['checked'] && 'external' !== $source['hosted'] && 'wordpress.org' !== $source['hosted']) {
            $source = array('hosted' => 'unknown', 'detail' => 'No cached update check to judge from.');
        }

        $new_version = null;

        if (null !== $update) {
            $u = (array) $update;
            $new_version = isset($u['new_version']) ? (string) $u['new_version'] : null;
        }

        return array(
            'file'             => $file,
            // A single-file plugin has no directory, so dirname() gives "." and
            // the filename is the only stable handle it has.
            'slug'             => ('.' === dirname($file)) ? basename($file, '.php') : dirname($file),
            'name'             => isset($data['Name']) ? $data['Name'] : $file,
            'version'          => isset($data['Version']) ? $data['Version'] : '',
            'author'           => isset($data['Author']) ? wp_strip_all_tags($data['Author']) : '',
            'active'           => $must_use ? true : is_plugin_active($file),
            'status'           => $must_use ? 'must-use' : (is_plugin_active($file) ? 'active' : 'inactive'),
            'must_use'         => (bool) $must_use,
            'update_available' => (null !== $update),
            'new_version'      => $new_version,
            'hosted'           => $source['hosted'],
            'update_source'    => $source['detail'],
            'is_this_plugin'   => $this->is_self($file),
        );
    }

    /**
     * Force a fresh update check.
     *
     * Separate from plugins-list on purpose. This deletes the cached result and
     * makes WordPress ask wordpress.org about every installed plugin, plus one
     * request per plugin carrying its own update server. On a site with forty
     * plugins that is seconds of blocking HTTP, and any of those hosts being
     * slow or down is time spent waiting. Listing has to stay cheap enough to
     * call without thinking about it, so the network cost lives here instead.
     *
     * @return array
     */
    public function check_updates() {
        $this->load_plugin_api();

        delete_site_transient('update_plugins');
        wp_update_plugins();

        $state = $this->update_state();
        $all   = get_plugins();
        $found = array();

        foreach ($state['response'] as $file => $update) {
            $u = (array) $update;

            $found[] = array(
                'file'        => $file,
                'name'        => isset($all[$file]['Name']) ? $all[$file]['Name'] : $file,
                'version'     => isset($all[$file]['Version']) ? $all[$file]['Version'] : '',
                'new_version' => isset($u['new_version']) ? (string) $u['new_version'] : '',
                'active'      => is_plugin_active($file),
                // No package URL means the update is visible but not
                // downloadable - the usual cause is an expired licence.
                'installable' => !empty($u['package']),
            );
        }

        return array(
            'checked'      => count($all),
            'updates'      => $found,
            'update_count' => count($found),
            'last_checked' => $state['last_checked'],
            'note'         => 'Nothing was installed. Pass these file identifiers to plugins-update to act on them.',
        );
    }

    /* ---------------------------------------------------------------------
     * Updating
     * ------------------------------------------------------------------ */

    /**
     * Update named plugins.
     *
     * @param string[] $files   Plugin file identifiers.
     * @param bool     $dry_run When true, report the plan and change nothing.
     * @param bool     $force   Permit updating this plugin from within itself.
     * @return array
     */
    public function update($files, $dry_run = true, $force = false) {
        $this->load_plugin_api();

        $files = array_values(array_unique(array_filter(array_map('strval', (array) $files))));
        $queue = array_slice($files, 0, self::MAX_UPDATE);
        $rest  = array_slice($files, self::MAX_UPDATE);

        $state = $this->update_state();
        $all   = get_plugins();

        $results = array();
        $run     = array();

        foreach ($queue as $file) {
            if (!isset($all[$file])) {
                $results[$file] = array(
                    'status' => 'skipped',
                    'reason' => 'Not installed. Use the file identifier from plugins-list, e.g. "akismet/akismet.php".',
                );
                continue;
            }

            $name    = isset($all[$file]['Name']) ? $all[$file]['Name'] : $file;
            $current = isset($all[$file]['Version']) ? $all[$file]['Version'] : '';

            if (!isset($state['response'][$file])) {
                // Reported per plugin rather than as a failed call: one stale
                // entry in a list of ten should not stop the other nine.
                $results[$file] = array(
                    'name'    => $name,
                    'version' => $current,
                    'status'  => 'skipped',
                    'reason'  => $state['checked']
                        ? 'No update available.'
                        : 'No cached update check. Call plugins-check-updates first.',
                );
                continue;
            }

            $u      = (array) $state['response'][$file];
            $target = isset($u['new_version']) ? (string) $u['new_version'] : '';

            if ($this->is_self($file) && !$force) {
                // Updating this plugin from inside one of its own files means
                // the directory being executed is deleted and replaced while
                // PHP still has more of it to include, which fatals the request
                // and can leave the update half-applied. Do it from the admin,
                // or pass force and accept that the response may never arrive.
                $results[$file] = array(
                    'name'          => $name,
                    'version'       => $current,
                    'new_version'   => $target,
                    'status'        => 'refused',
                    'reason'        => 'This is the plugin providing this ability. Updating it mid-request replaces the running code and can fatal. Update it from wp-admin, or pass force true.',
                );
                continue;
            }

            $plan = array(
                'name'        => $name,
                'version'     => $current,
                'new_version' => $target,
                'was_active'  => is_plugin_active($file),
            );

            if ($dry_run) {
                $results[$file] = array_merge($plan, array('status' => 'would_update'));
                continue;
            }

            $results[$file] = $plan;
            $run[]          = $file;
        }

        if (!$dry_run && !empty($run)) {
            $this->run_updates($run, $results);
        }

        return array(
            'dry_run'   => (bool) $dry_run,
            'results'   => $results,
            'not_attempted' => $rest,
            'batch_limit'   => self::MAX_UPDATE,
            'note'      => $dry_run
                ? 'Nothing was changed. Call again with dry_run false to install these updates.'
                : 'Check active_now on every result. WordPress deactivates a plugin whose update fails partway through, and it stays deactivated until something reactivates it.',
        );
    }

    /**
     * Perform the upgrades, recording what actually happened to each plugin.
     *
     * @param string[] $files   Plugins to upgrade.
     * @param array    $results Per-plugin result rows, modified in place.
     */
    protected function run_updates($files, &$results) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/misc.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

        // Automatic_Upgrader_Skin collects its output instead of echoing it.
        // The default skin prints HTML straight to the response, which would
        // corrupt a REST body and lose the messages we want to report.
        $skin     = new Automatic_Upgrader_Skin();
        $upgrader = new Plugin_Upgrader($skin);

        foreach ($files as $file) {
            $was_active   = is_plugin_active($file);
            $skin->result = null;

            $outcome = $upgrader->upgrade($file);
            $error   = null;

            // Three different failure shapes, and only the first is obvious.
            // A WP_Error can arrive as the return value or be left on the skin,
            // and a plain false means the upgrader declined before it started.
            if (is_wp_error($outcome)) {
                $error = $outcome->get_error_message();
            } elseif (is_wp_error($skin->result)) {
                $error = $skin->result->get_error_message();
            } elseif (false === $outcome || null === $outcome) {
                $messages = method_exists($skin, 'get_upgrade_messages') ? $skin->get_upgrade_messages() : array();
                $error    = !empty($messages)
                    ? implode(' ', array_map('wp_strip_all_tags', $messages))
                    : 'The upgrader declined the update without giving a reason. Check filesystem permissions on wp-content/plugins.';
            }

            // The upgrader clears the plugin cache but the headers read before
            // the loop are now stale, so the installed version is re-read from
            // disk rather than assumed to equal the target.
            wp_cache_delete('plugins', 'plugins');

            $installed = function_exists('get_plugin_data')
                ? get_plugin_data(WP_PLUGIN_DIR . '/' . $file, false, false)
                : array();

            $active_now = is_plugin_active($file);

            $results[$file] = array_merge(
                isset($results[$file]) ? $results[$file] : array(),
                array(
                    'status'            => (null === $error) ? 'updated' : 'failed',
                    'error'             => $error,
                    'installed_version' => isset($installed['Version']) ? $installed['Version'] : null,
                    'was_active'        => $was_active,
                    'active_now'        => $active_now,
                    // The case worth shouting about: the call succeeded on
                    // paper and the site lost a plugin it was running.
                    'deactivated_by_update' => ($was_active && !$active_now),
                )
            );
        }
    }

    /* ---------------------------------------------------------------------
     * Activation
     * ------------------------------------------------------------------ */

    /**
     * Activate or deactivate one plugin.
     *
     * @param string $action  'activate' or 'deactivate'.
     * @param bool   $dry_run When true, report the plan and change nothing.
     * @param bool   $force   Permit deactivating this plugin from within itself.
     * @return array|WP_Error
     */
    public function toggle($file, $action, $dry_run = true, $force = false) {
        $this->load_plugin_api();

        if (!in_array($action, array('activate', 'deactivate'), true)) {
            return new WP_Error('bad_action', 'Action must be either activate or deactivate.');
        }

        $all = get_plugins();

        if (!isset($all[$file])) {
            // Must-use plugins land here too, which is correct: they load from
            // disk unconditionally and cannot be toggled at all.
            return new WP_Error(
                'not_installed',
                'No such plugin. Use the file identifier from plugins-list, e.g. "akismet/akismet.php". Must-use plugins cannot be activated or deactivated.'
            );
        }

        $name   = isset($all[$file]['Name']) ? $all[$file]['Name'] : $file;
        $before = is_plugin_active($file);
        $want   = ('activate' === $action);

        if (!$want && $this->is_self($file) && !$force) {
            // Deactivating this plugin removes the abilities being used to
            // deactivate it, so there is no way back in through the same route.
            return array(
                'plugin' => $file,
                'name'   => $name,
                'status' => 'refused',
                'reason' => 'This is the plugin providing this ability. Deactivating it removes every ability in this category, including the one that would switch it back on. Do it from wp-admin, or pass force true.',
                'active' => $before,
            );
        }

        if ($before === $want) {
            return array(
                'plugin'  => $file,
                'name'    => $name,
                'status'  => 'no_change',
                'reason'  => $want ? 'Already active.' : 'Already inactive.',
                'active'  => $before,
                'dry_run' => (bool) $dry_run,
            );
        }

        if ($dry_run) {
            return array(
                'plugin'       => $file,
                'name'         => $name,
                'status'       => $want ? 'would_activate' : 'would_deactivate',
                'active'       => $before,
                'dry_run'      => true,
                'note'         => 'Nothing was changed. Call again with dry_run false to apply this.',
            );
        }

        $error = null;

        if ($want) {
            // activate_plugin() includes the plugin file to check it for fatals
            // before committing. That check is the point, but it also means a
            // plugin that fatals on load takes this request down with it - the
            // caller sees a dead connection rather than an error message.
            $result = activate_plugin($file);

            if (is_wp_error($result)) {
                $error = $result->get_error_message();
            }
        } else {
            deactivate_plugins(array($file));
        }

        // deactivate_plugins() returns nothing and activate_plugin() returning
        // null only means it did not object, so neither is evidence of the end
        // state. Re-read it.
        $after = is_plugin_active($file);

        return array(
            'plugin'   => $file,
            'name'     => $name,
            'action'   => $action,
            'status'   => ($after === $want && null === $error) ? 'done' : 'failed',
            'error'    => $error,
            'active_before' => $before,
            'active_now'    => $after,
            'confirmed'     => ($after === $want),
            'dry_run'       => false,
        );
    }

    /* ---------------------------------------------------------------------
     * Abilities
     * ------------------------------------------------------------------ */

    private function register_list() {
        $this->register(self::PREFIX . 'plugins-list', array(
            'label'       => 'Installed plugins',
            'description' => 'Every installed plugin with its version, active state, author, must-use status, whether an update is waiting and where its updates come from. Read-only and cheap - update information is read from cache and no network request is made. The summary flags any plugin with no update source at all, which is a plugin that will never receive a security fix.',
            'category'    => self::CATEGORY,
            'input_schema' => array(
                'type' => 'object', 'properties' => array(), 'additionalProperties' => false,
            ),
            'output_schema' => array('type' => 'object'),
            'permission_callback' => array($this, 'can_manage'),
            'execute_callback'    => function () {
                return $this->inventory();
            },
        ));
    }

    private function register_check_updates() {
        $this->register(self::PREFIX . 'plugins-check-updates', array(
            'label'       => 'Check for plugin updates',
            'description' => 'Discard the cached update data and ask wordpress.org and every custom update server for current versions, then report what is available. Slow - this makes one or more outbound HTTP requests and blocks until they answer - so use plugins-list for routine questions and call this only when the cached answer is too old to trust. Installs nothing.',
            'category'    => self::CATEGORY,
            'input_schema' => array(
                'type' => 'object', 'properties' => array(), 'additionalProperties' => false,
            ),
            'output_schema' => array('type' => 'object'),
            'permission_callback' => array($this, 'can_manage'),
            'execute_callback'    => function () {
                return $this->check_updates();
            },
        ));
    }

    private function register_update() {
        $this->register(self::PREFIX . 'plugins-update', array(
            'label'       => 'Update plugins',
            'description' => 'Install pending updates for named plugins. Defaults to a dry run reporting current version, target version and active state; pass dry_run false to actually update. Plugins must be named individually - there is no update-everything. Capped per call, reports success or the real error per plugin, and reports whether each plugin is still active afterwards.',
            'category'    => self::CATEGORY,
            'input_schema' => array(
                'type'       => 'object',
                'properties' => array(
                    'plugins' => array(
                        'type'        => 'array',
                        'items'       => array('type' => 'string'),
                        'description' => 'Plugin file identifiers from plugins-list, e.g. ["akismet/akismet.php"].',
                    ),
                    'dry_run' => array(
                        'type'        => 'boolean',
                        'description' => 'Defaults to true. Must be explicitly false to install anything.',
                    ),
                    'force' => array(
                        'type'        => 'boolean',
                        'description' => 'Permit updating the plugin that provides these abilities from within itself. Risks a fatal mid-request.',
                    ),
                ),
                'required'             => array('plugins'),
                'additionalProperties' => false,
            ),
            'output_schema' => array('type' => 'object'),
            'permission_callback' => array($this, 'can_manage'),
            'execute_callback'    => function ($input) {
                $plugins = isset($input['plugins']) ? (array) $input['plugins'] : array();

                if (empty($plugins)) {
                    return new WP_Error('no_plugins', 'Name at least one plugin file identifier from plugins-list. Updating everything is deliberately not offered.');
                }

                // Absent means dry run, so a malformed call reports rather than
                // rewrites the plugins directory.
                $dry = !isset($input['dry_run']) || (bool) $input['dry_run'];

                return $this->update($plugins, $dry, !empty($input['force']));
            },
        ));
    }

    private function register_toggle() {
        $this->register(self::PREFIX . 'plugins-toggle', array(
            'label'       => 'Activate or deactivate a plugin',
            'description' => 'Switch one plugin on or off. Defaults to a dry run; pass dry_run false to apply. Surfaces any activation error and confirms the resulting state by re-reading it rather than trusting the call. Refuses to deactivate the plugin providing these abilities unless force is set.',
            'category'    => self::CATEGORY,
            'input_schema' => array(
                'type'       => 'object',
                'properties' => array(
                    'plugin' => array(
                        'type'        => 'string',
                        'description' => 'Plugin file identifier from plugins-list, e.g. "akismet/akismet.php".',
                    ),
                    'action' => array(
                        'type'        => 'string',
                        'enum'        => array('activate', 'deactivate'),
                        'description' => 'Whether to activate or deactivate.',
                    ),
                    'dry_run' => array(
                        'type'        => 'boolean',
                        'description' => 'Defaults to true. Must be explicitly false to change anything.',
                    ),
                    'force' => array(
                        'type'        => 'boolean',
                        'description' => 'Permit deactivating the plugin that provides these abilities.',
                    ),
                ),
                'required'             => array('plugin', 'action'),
                'additionalProperties' => false,
            ),
            'output_schema' => array('type' => 'object'),
            'permission_callback' => array($this, 'can_manage'),
            'execute_callback'    => function ($input) {
                $file   = isset($input['plugin']) ? (string) $input['plugin'] : '';
                $action = isset($input['action']) ? (string) $input['action'] : '';

                if ('' === $file) {
                    return new WP_Error('no_plugin', 'Name the plugin file identifier from plugins-list.');
                }

                $dry = !isset($input['dry_run']) || (bool) $input['dry_run'];

                return $this->toggle($file, $action, $dry, !empty($input['force']));
            },
        ));
    }
}
