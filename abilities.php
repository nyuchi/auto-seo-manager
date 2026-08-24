<?php
/**
 * Auto SEO Manager - WordPress Abilities API surface
 * File: abilities.php
 *
 * Exposes the same operations as the auto-seo/v1 REST routes as registered
 * abilities, so an AI client that speaks the Abilities API can read status,
 * change settings, trigger a generation run and manage the activity log
 * without knowing the plugin's route shapes.
 *
 * Every callback delegates to AutoSEOManager. Nothing about how SEO fields
 * are generated, how settings are sanitised or how the log is trimmed lives
 * here - this file is a translation layer only, so the two surfaces cannot
 * drift apart.
 *
 * The Abilities API ships separately from core. Every registration call is
 * wrapped in function_exists() so this file loads harmlessly on a site that
 * does not have it.
 *
 * @package AutoSEOManager
 * @version 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class AutoSEOAbilities {

    /**
     * Category slug every ability below is filed under.
     */
    const CATEGORY = 'nyuchi-optimization';

    /**
     * Ability name prefix. The Abilities API requires namespace/ability-name.
     */
    const PREFIX = 'nyuchi-optimization/';

    /**
     * The running AutoSEOManager instance.
     *
     * Passed in rather than constructed here: the main plugin file already
     * does `new AutoSEOManager()` and that constructor registers every hook,
     * so building a second one would double-register cron, admin and REST
     * callbacks.
     *
     * @var AutoSEOManager
     */
    private $plugin;

    /**
     * @param AutoSEOManager $plugin The live plugin instance.
     */
    public function __construct($plugin) {
        $this->plugin = $plugin;

        add_action('wp_abilities_api_categories_init', array($this, 'register_categories'));
        add_action('wp_abilities_api_init', array($this, 'register_abilities'));
    }

    /**
     * Register the category the abilities are grouped under.
     *
     * Categories must exist before any ability references them, which is why
     * the API fires this on its own earlier hook.
     */
    public function register_categories() {
        if (!function_exists('wp_register_ability_category')) {
            return;
        }

        wp_register_ability_category(
            self::CATEGORY,
            array(
                'label'       => 'Nyuchi WordPress Optimization',
                'description' => 'Site optimization in three parts. SEO: automated generation of Yoast SEO fields - titles, meta descriptions, focus keywords, Open Graph and Twitter card tags - with an activity log of every field written. Metadata: inspection and correction across post types. Database: per-table size and overhead, counts of rows nothing reads any more, the autoloaded options paid for on every request, and bounded cleanup of each.',
            )
        );
    }

    /**
     * Register every ability.
     */
    public function register_abilities() {
        if (!function_exists('wp_register_ability')) {
            return;
        }

        // Without a usable plugin instance every callback would fail at call
        // time; better to expose nothing than abilities that always error.
        if (!is_object($this->plugin) || !method_exists($this->plugin, 'rest_get_status')) {
            return;
        }

        $this->register_get_status();
        $this->register_get_settings();
        $this->register_update_settings();
        $this->register_generate_seo();
        $this->register_get_activity_log();
        $this->register_prune_activity_log();
    }

    /**
     * Guarded wrapper around wp_register_ability().
     *
     * The guard lives here rather than only at the call site above so that no
     * path through this file can reach an undefined function if the Abilities
     * API is deactivated between hooks.
     *
     * @param string $name Fully qualified ability name.
     * @param array  $args Ability definition.
     * @return void
     */
    private function register($name, $args) {
        if (!function_exists('wp_register_ability')) {
            return;
        }

        wp_register_ability($name, $args);
    }

    /* ---------------------------------------------------------------------
     * Abilities
     * ------------------------------------------------------------------ */

    /**
     * nyuchi-optimization/get-status
     */
    private function register_get_status() {
        $this->register(
            self::PREFIX . 'get-status',
            array(
                'label'       => 'Get SEO automation status',
                'description' => 'Report whether the SEO automation is switched on, whether Yoast SEO (which owns the fields it writes into) is active, how large the activity log has grown, which content-plugin integrations are detected and enabled, and when the scheduled jobs run next. Call this first to decide whether other abilities will do anything.',
                'category'    => self::CATEGORY,
                'input_schema' => array(
                    'type'                 => 'object',
                    'properties'           => array(),
                    'additionalProperties' => false,
                ),
                'output_schema' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'version'      => array(
                            'type'        => 'string',
                            'description' => 'Installed plugin version, e.g. "1.1.0".',
                        ),
                        'db_version'   => array(
                            'type'        => 'integer',
                            'description' => 'Schema revision of the activity log table. Lags the plugin version until an admin page load runs the upgrade.',
                        ),
                        'enabled'      => array(
                            'type'        => 'boolean',
                            'description' => 'Master switch. When false, scheduled and manual site-wide runs write nothing.',
                        ),
                        'yoast_active' => array(
                            'type'        => 'boolean',
                            'description' => 'Whether Yoast SEO is active. False means generation is impossible, because every field this plugin writes is a Yoast post meta key.',
                        ),
                        'log'          => $this->log_stats_schema(),
                        'integrations' => array(
                            'type'                 => 'object',
                            'description'          => 'Detected content plugins keyed by integration slug (yoast, woocommerce, acf, elementor, wp_travel and so on). Enabled integrations feed extra content into title and description generation.',
                            'additionalProperties' => array(
                                'type'       => 'object',
                                'properties' => array(
                                    'name'      => array(
                                        'type'        => 'string',
                                        'description' => 'Human-readable plugin name.',
                                    ),
                                    'available' => array(
                                        'type'        => 'boolean',
                                        'description' => 'Whether the plugin is installed and active on this site.',
                                    ),
                                    'enabled'   => array(
                                        'type'        => 'boolean',
                                        'description' => 'Whether the site owner has switched this integration on. An integration only runs when both available and enabled are true.',
                                    ),
                                    'required'  => array(
                                        'type'        => 'boolean',
                                        'description' => 'True only for Yoast SEO, without which the plugin cannot function.',
                                    ),
                                ),
                            ),
                        ),
                        'next_run'     => array(
                            'type'        => 'object',
                            'description' => 'Unix timestamps of the next scheduled run of each job. Null means the job is switched off or has never been scheduled.',
                            'properties'  => array(
                                'daily_update' => array(
                                    'type'        => array('integer', 'null'),
                                    'description' => 'Next site-wide generation pass.',
                                ),
                                'weekly_audit' => array(
                                    'type'        => array('integer', 'null'),
                                    'description' => 'Next audit of posts with missing or weak SEO fields.',
                                ),
                                'log_prune'    => array(
                                    'type'        => array('integer', 'null'),
                                    'description' => 'Next activity-log trim to the configured retention window.',
                                ),
                            ),
                        ),
                    ),
                    'required'   => array('version', 'enabled', 'yoast_active', 'log', 'integrations', 'next_run'),
                ),
                'execute_callback'    => array($this, 'execute_get_status'),
                'permission_callback' => array($this, 'can_manage'),
                'meta'                => array(
                    'readonly' => true,
                ),
            )
        );
    }

    /**
     * nyuchi-optimization/get-settings
     */
    private function register_get_settings() {
        $this->register(
            self::PREFIX . 'get-settings',
            array(
                'label'       => 'Get SEO automation settings',
                'description' => 'Read every setting that controls what the automation generates, how noisy the activity log is, and which post types and title templates it applies. Read these before calling update-settings so you change one value without guessing at the others.',
                'category'    => self::CATEGORY,
                'input_schema' => array(
                    'type'                 => 'object',
                    'properties'           => array(),
                    'additionalProperties' => false,
                ),
                'output_schema' => array(
                    'type'       => 'object',
                    'properties' => array_merge(
                        $this->settings_schema_properties(),
                        array(
                            'post_types'      => array(
                                'type'        => 'array',
                                'description' => 'Post type slugs the site-wide run walks through. Defaults to post and page; custom types must be added here to be touched.',
                                'items'       => array('type' => 'string'),
                            ),
                            'title_templates' => array(
                                'type'                 => 'object',
                                'description'          => 'SEO title pattern per post type slug, e.g. {"post": "%%title%% | Expert Guide | %%sitename%%"}. Placeholders are Yoast-style %%name%% tokens; integrations add their own, such as %%trip_price%% for WP Travel.',
                                'additionalProperties' => array('type' => 'string'),
                            ),
                        )
                    ),
                ),
                'execute_callback'    => array($this, 'execute_get_settings'),
                'permission_callback' => array($this, 'can_manage'),
                'meta'                => array(
                    'readonly' => true,
                ),
            )
        );
    }

    /**
     * nyuchi-optimization/update-settings
     */
    private function register_update_settings() {
        $this->register(
            self::PREFIX . 'update-settings',
            array(
                'label'       => 'Update SEO automation settings',
                'description' => 'Change one or more automation settings. Only the properties you send are written; everything else is left alone. Scheduled jobs are re-synchronised afterwards, so toggling daily_updates_enabled, weekly_audit_enabled or log_pruning_enabled takes effect immediately rather than at the next admin page load.',
                'category'    => self::CATEGORY,
                'input_schema' => array(
                    'type'                 => 'object',
                    'description'          => 'A partial settings object. Send only the keys you intend to change.',
                    'properties'           => $this->settings_schema_properties(),
                    'additionalProperties' => false,
                    'minProperties'        => 1,
                ),
                'output_schema' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'updated' => array(
                            'type'                 => 'object',
                            'description'          => 'The settings actually written, keyed by setting name, holding the sanitised value as stored. Booleans come back as 1 or 0 because that is how WordPress options store them.',
                            'additionalProperties' => true,
                        ),
                        'ignored' => array(
                            'type'        => 'array',
                            'description' => 'Names of submitted keys that are not writable and were skipped. Normally empty.',
                            'items'       => array('type' => 'string'),
                        ),
                    ),
                    'required'   => array('updated', 'ignored'),
                ),
                'execute_callback'    => array($this, 'execute_update_settings'),
                'permission_callback' => array($this, 'can_manage'),
            )
        );
    }

    /**
     * nyuchi-optimization/generate-seo
     */
    private function register_generate_seo() {
        $this->register(
            self::PREFIX . 'generate-seo',
            array(
                'label'       => 'Generate SEO fields',
                'description' => 'Generate and save missing Yoast SEO fields - SEO title, meta description, focus keyword and the additional meta tags - for one post, or for every published post of every configured post type when no post is given. Existing values are never overwritten; only empty fields are filled. The site-wide run walks the whole content set and can take a long time on a large site, so prefer passing post_id when you have one.',
                'category'    => self::CATEGORY,
                'input_schema' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'post_id' => array(
                            'type'        => 'integer',
                            'minimum'     => 1,
                            'description' => 'ID of a single post, page or custom post type item to generate for. Omit to run across the whole site.',
                        ),
                    ),
                    'additionalProperties' => false,
                ),
                'output_schema' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'scope'   => array(
                            'type'        => 'string',
                            'enum'        => array('post', 'site'),
                            'description' => '"post" when a single post was processed, "site" for a full pass.',
                        ),
                        'post_id' => array(
                            'type'        => array('integer', 'null'),
                            'description' => 'The post processed, or null for a site-wide run.',
                        ),
                        'updated' => array(
                            'type'        => array('boolean', 'null'),
                            'description' => 'For a single post: whether any field was written. False means every field was already filled in. Null for a site-wide run, where per-post results are recorded in the activity log instead.',
                        ),
                        'log'     => $this->log_stats_schema(),
                    ),
                    'required'   => array('scope', 'post_id', 'updated', 'log'),
                ),
                'execute_callback'    => array($this, 'execute_generate_seo'),
                'permission_callback' => array($this, 'can_generate_seo'),
            )
        );
    }

    /**
     * nyuchi-optimization/get-activity-log
     */
    private function register_get_activity_log() {
        $this->register(
            self::PREFIX . 'get-activity-log',
            array(
                'label'       => 'Get SEO activity log',
                'description' => 'Read the most recent entries from the activity log, newest first, together with the log summary. Each entry records one field the automation wrote or one run it completed, so this is how you confirm what a generate-seo call actually changed and diagnose why a post was skipped.',
                'category'    => self::CATEGORY,
                'input_schema' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'limit' => array(
                            'type'        => 'integer',
                            'minimum'     => 1,
                            'maximum'     => 500,
                            'default'     => 50,
                            'description' => 'How many of the newest entries to return.',
                        ),
                    ),
                    'additionalProperties' => false,
                ),
                'output_schema' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'stats'   => $this->log_stats_schema(),
                        'entries' => array(
                            'type'        => 'array',
                            'description' => 'Log entries, newest first.',
                            'items'       => array(
                                'type'       => 'object',
                                'properties' => array(
                                    'id'        => array(
                                        'type'        => 'integer',
                                        'description' => 'Row identifier, ascending with time.',
                                    ),
                                    'post_id'   => array(
                                        'type'        => 'integer',
                                        'description' => 'Post the entry concerns, or 0 for site-wide events such as a bulk run.',
                                    ),
                                    'action'    => array(
                                        'type'        => 'string',
                                        'description' => 'What happened, e.g. title_update, description_update, keyword_update, bulk_update, or an integration_* entry recorded only at verbose log level.',
                                    ),
                                    'status'    => array(
                                        'type'        => 'string',
                                        'description' => 'Outcome, e.g. success, completed, info, error. Errors are recorded at every log level except off.',
                                    ),
                                    'details'   => array(
                                        'type'        => 'string',
                                        'description' => 'Free-text detail, such as the keyword that was set or the number of posts a bulk run updated.',
                                    ),
                                    'timestamp' => array(
                                        'type'        => 'string',
                                        'description' => 'Site-local MySQL datetime, "YYYY-MM-DD HH:MM:SS".',
                                    ),
                                ),
                            ),
                        ),
                    ),
                    'required'   => array('stats', 'entries'),
                ),
                'execute_callback'    => array($this, 'execute_get_activity_log'),
                'permission_callback' => array($this, 'can_manage'),
                'meta'                => array(
                    'readonly' => true,
                ),
            )
        );
    }

    /**
     * nyuchi-optimization/prune-activity-log
     */
    private function register_prune_activity_log() {
        $this->register(
            self::PREFIX . 'prune-activity-log',
            array(
                'label'       => 'Prune SEO activity log',
                'description' => 'Shrink the activity log. By default this applies the configured retention window and row cap, the same trim the scheduled job performs. Set purge to true to delete every entry instead. Deletion is permanent and the log is the only record of what the automation has written, so purge only when explicitly asked.',
                'category'    => self::CATEGORY,
                'input_schema' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'purge' => array(
                            'type'        => 'boolean',
                            'default'     => false,
                            'description' => 'True deletes the entire log irreversibly. False applies the configured log_retention_days window and log_max_rows cap, keeping recent history.',
                        ),
                    ),
                    'additionalProperties' => false,
                ),
                'output_schema' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'deleted' => array(
                            'type'        => array('integer', 'null'),
                            'description' => 'Number of entries removed. Null if the count could not be determined.',
                        ),
                        'purged'  => array(
                            'type'        => 'boolean',
                            'description' => 'Whether the whole log was dropped rather than trimmed.',
                        ),
                        'stats'   => $this->log_stats_schema(),
                    ),
                    'required'   => array('deleted', 'purged', 'stats'),
                ),
                'execute_callback'    => array($this, 'execute_prune_activity_log'),
                'permission_callback' => array($this, 'can_manage'),
                'meta'                => array(
                    'destructive' => true,
                ),
            )
        );
    }

    /* ---------------------------------------------------------------------
     * Permission callbacks
     * ------------------------------------------------------------------ */

    /**
     * Every ability here reads or writes site-wide options, so the bar is the
     * same one the REST routes and the admin screen use.
     *
     * @param array $input Ability input.
     * @return true|WP_Error
     */
    public function can_manage($input = array()) {
        if (!current_user_can('manage_options')) {
            return new WP_Error(
                'auto_seo_forbidden',
                'You need the manage_options capability to use the Nyuchi SEO abilities.',
                array('status' => 403)
            );
        }

        return true;
    }

    /**
     * Generation additionally needs edit rights on the specific post.
     *
     * manage_options alone is not enough here: the single-post branch writes
     * post meta, and post-level capabilities can be filtered per post.
     *
     * @param array $input Ability input.
     * @return true|WP_Error
     */
    public function can_generate_seo($input = array()) {
        $allowed = $this->can_manage($input);

        if (is_wp_error($allowed)) {
            return $allowed;
        }

        $post_id = $this->input_int($input, 'post_id', 0);

        if ($post_id > 0 && !current_user_can('edit_post', $post_id)) {
            return new WP_Error(
                'auto_seo_forbidden',
                sprintf('You cannot edit post %d.', $post_id),
                array('status' => 403)
            );
        }

        return true;
    }

    /* ---------------------------------------------------------------------
     * Execute callbacks
     * ------------------------------------------------------------------ */

    /**
     * @param array $input Ability input.
     * @return array|WP_Error
     */
    public function execute_get_status($input = array()) {
        return $this->unwrap($this->plugin->rest_get_status());
    }

    /**
     * @param array $input Ability input.
     * @return array|WP_Error
     */
    public function execute_get_settings($input = array()) {
        return $this->unwrap($this->plugin->rest_get_settings());
    }

    /**
     * Writes settings through the plugin's own REST handler.
     *
     * That handler owns the per-type sanitising, the log_level whitelist check
     * (which returns the WP_Error naming the valid values) and the follow-up
     * call to schedule_seo_updates(). Reimplementing any of it here would be a
     * second copy to keep in step.
     *
     * @param array $input Ability input.
     * @return array|WP_Error
     */
    public function execute_update_settings($input = array()) {
        if (!is_array($input) || empty($input)) {
            return new WP_Error(
                'auto_seo_no_settings',
                'Provide at least one setting to change.',
                array('status' => 400)
            );
        }

        $request = $this->build_request($input);

        if (is_wp_error($request)) {
            return $request;
        }

        return $this->unwrap($this->plugin->rest_update_settings($request));
    }

    /**
     * @param array $input Ability input.
     * @return array|WP_Error
     */
    public function execute_generate_seo($input = array()) {
        // Checked up front so the caller gets a specific reason rather than a
        // run that silently writes nothing: every field written is Yoast meta.
        if (!$this->plugin->is_yoast_active()) {
            return new WP_Error(
                'auto_seo_yoast_missing',
                'Yoast SEO is not active. The SEO module writes into Yoast SEO fields, so generation would have no effect until Yoast is installed and activated.',
                array('status' => 409)
            );
        }

        $post_id = $this->input_int($input, 'post_id', 0);

        if ($post_id > 0 && !get_post($post_id)) {
            return new WP_Error(
                'auto_seo_no_post',
                sprintf('No post with ID %d.', $post_id),
                array('status' => 404)
            );
        }

        $request = $this->build_request($post_id > 0 ? array('post_id' => $post_id) : array());

        if (is_wp_error($request)) {
            return $request;
        }

        $result = $this->unwrap($this->plugin->rest_run_update($request));

        if (is_wp_error($result)) {
            return $result;
        }

        // The REST handler returns a different key set per branch. The ability
        // contract is one fixed shape, so fill in the branch that is missing.
        $scope = isset($result['scope']) ? $result['scope'] : 'site';

        return array(
            'scope'   => $scope,
            'post_id' => isset($result['post_id']) ? (int) $result['post_id'] : null,
            'updated' => isset($result['updated']) ? (bool) $result['updated'] : null,
            'log'     => isset($result['log']) ? $result['log'] : $this->plugin->get_log_stats(),
        );
    }

    /**
     * @param array $input Ability input.
     * @return array|WP_Error
     */
    public function execute_get_activity_log($input = array()) {
        $limit = $this->input_int($input, 'limit', 50);
        $limit = min(500, max(1, $limit));

        $entries = $this->plugin->get_log_entries($limit);

        return array(
            'stats'   => $this->plugin->get_log_stats(),
            'entries' => $this->normalise_entries($entries),
        );
    }

    /**
     * @param array $input Ability input.
     * @return array|WP_Error
     */
    public function execute_prune_activity_log($input = array()) {
        $purge = !empty($input['purge']) && 'false' !== $input['purge'];

        if ($purge) {
            // rest_purge_logs() reports the row count it dropped, which
            // purge_log() cannot do on its own because it TRUNCATEs.
            $result = $this->unwrap($this->plugin->rest_purge_logs());

            if (is_wp_error($result)) {
                return $result;
            }

            return array(
                'deleted' => isset($result['purged_rows']) ? (int) $result['purged_rows'] : null,
                'purged'  => true,
                'stats'   => isset($result['stats']) ? $result['stats'] : $this->plugin->get_log_stats(),
            );
        }

        $deleted = $this->plugin->prune_log();

        return array(
            'deleted' => null === $deleted ? null : (int) $deleted,
            'purged'  => false,
            'stats'   => $this->plugin->get_log_stats(),
        );
    }

    /* ---------------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------------ */

    /**
     * Build a WP_REST_Request carrying the ability input.
     *
     * The plugin's handlers are typed against WP_REST_Request. Handing them one
     * is what lets the abilities reuse their sanitising and side effects
     * verbatim instead of forking the logic. rest_update_settings() reads
     * get_json_params() first and falls back to get_params(), and a
     * synthesised request has no JSON body, so set_param() is what it sees.
     *
     * @param array $params Parameters to carry.
     * @return WP_REST_Request|WP_Error
     */
    private function build_request($params) {
        if (!class_exists('WP_REST_Request')) {
            return new WP_Error(
                'auto_seo_rest_unavailable',
                'The WordPress REST API infrastructure is not loaded, so this ability cannot run.',
                array('status' => 500)
            );
        }

        $request = new WP_REST_Request('POST', '/auto-seo/v1/abilities');

        foreach ((array) $params as $key => $value) {
            $request->set_param($key, $value);
        }

        return $request;
    }

    /**
     * Reduce a REST handler return value to plain data.
     *
     * @param mixed $result WP_REST_Response, WP_Error or raw data.
     * @return mixed
     */
    private function unwrap($result) {
        if (is_wp_error($result)) {
            return $result;
        }

        if ($result instanceof WP_REST_Response) {
            return $result->get_data();
        }

        return $result;
    }

    /**
     * Read an integer out of the ability input.
     *
     * @param array      $input   Ability input.
     * @param string     $key     Property name.
     * @param int        $default Value when absent.
     * @return int
     */
    private function input_int($input, $key, $default = 0) {
        if (!is_array($input) || !isset($input[$key]) || '' === $input[$key]) {
            return $default;
        }

        return (int) $input[$key];
    }

    /**
     * Turn $wpdb rows into plain arrays with typed scalars.
     *
     * $wpdb hands back stdClass objects whose every column is a string, which
     * would not match the declared output schema.
     *
     * @param array $entries Rows from get_log_entries().
     * @return array
     */
    private function normalise_entries($entries) {
        $out = array();

        foreach ((array) $entries as $entry) {
            $row = (array) $entry;

            $out[] = array(
                'id'        => isset($row['id']) ? (int) $row['id'] : 0,
                'post_id'   => isset($row['post_id']) ? (int) $row['post_id'] : 0,
                'action'    => isset($row['action']) ? (string) $row['action'] : '',
                'status'    => isset($row['status']) ? (string) $row['status'] : '',
                'details'   => isset($row['details']) ? (string) $row['details'] : '',
                'timestamp' => isset($row['timestamp']) ? (string) $row['timestamp'] : '',
            );
        }

        return $out;
    }

    /**
     * Valid log_level values.
     *
     * Read off AutoSEOManager so the enum cannot drift from the ladder the
     * plugin actually enforces, with a literal fallback because schemas are
     * built at registration time and this file must never fatal.
     *
     * @return array
     */
    private function log_levels() {
        if (class_exists('AutoSEOManager') && defined('AutoSEOManager::LOG_LEVELS')) {
            return array_keys(AutoSEOManager::LOG_LEVELS);
        }

        return array('off', 'errors', 'actions', 'verbose');
    }

    /**
     * Schema for the log summary, shared by three abilities.
     *
     * @return array
     */
    private function log_stats_schema() {
        return array(
            'type'        => 'object',
            'description' => 'Summary of the activity log table.',
            'properties'  => array(
                'rows'    => array(
                    'type'        => 'integer',
                    'description' => 'Total entries currently stored.',
                ),
                'size_mb' => array(
                    'type'        => 'number',
                    'description' => 'On-disk size of the log table in megabytes, data plus indexes, rounded to one decimal.',
                ),
                'oldest'  => array(
                    'type'        => array('string', 'null'),
                    'description' => 'Timestamp of the oldest entry as a MySQL datetime, or null when the log is empty.',
                ),
                'level'   => array(
                    'type'        => 'string',
                    'description' => 'Log verbosity currently in force.',
                    'enum'        => $this->log_levels(),
                ),
            ),
        );
    }

    /**
     * Schema properties for the writable settings.
     *
     * This mirrors AutoSEOManager::rest_writable_settings(), which is private
     * and so cannot be called from here. The mirror is deliberately limited to
     * the key list and the types; the sanitising and the writing still happen
     * in the plugin. If a setting is added there, add it here too.
     *
     * @return array
     */
    private function settings_schema_properties() {
        return array(
            'auto_seo_enabled'         => array(
                'type'        => 'boolean',
                'description' => 'Master switch for the automation. When off, scheduled and site-wide runs do nothing.',
            ),
            'auto_meta_description'    => array(
                'type'        => 'boolean',
                'description' => 'Generate a Yoast meta description for posts that have none, built from the post content and whatever the active integrations can contribute.',
            ),
            'auto_focus_keywords'      => array(
                'type'        => 'boolean',
                'description' => 'Set the Yoast focus keyword on posts that have none, extracted from the post title, taxonomies and content.',
            ),
            'auto_generate_keywords'   => array(
                'type'        => 'boolean',
                'description' => 'Generate a meta keywords tag alongside the other additional meta tags.',
            ),
            'auto_og_tags'             => array(
                'type'        => 'boolean',
                'description' => 'Generate Open Graph tags (title, description, image, type) for link previews on Facebook, LinkedIn and similar.',
            ),
            'auto_twitter_cards'       => array(
                'type'        => 'boolean',
                'description' => 'Generate Twitter card tags for link previews on X/Twitter.',
            ),
            'auto_additional_meta'     => array(
                'type'        => 'boolean',
                'description' => 'Write the extra meta tags - author, robots, keywords, Open Graph and Twitter - during a generation run. Turning this off leaves only title, description and focus keyword.',
            ),
            'daily_updates_enabled'    => array(
                'type'        => 'boolean',
                'description' => 'Run the site-wide generation pass once a day. Changing this reschedules or clears the cron event immediately.',
            ),
            'weekly_audit_enabled'     => array(
                'type'        => 'boolean',
                'description' => 'Run the weekly audit that reports posts with missing or weak SEO fields.',
            ),
            'audit_email_enabled'      => array(
                'type'        => 'boolean',
                'description' => 'Email the weekly audit report to audit_email. Has no effect while weekly_audit_enabled is off.',
            ),
            'log_pruning_enabled'      => array(
                'type'        => 'boolean',
                'description' => 'Run the daily job that trims the activity log to log_retention_days and log_max_rows. Turning this off lets the log grow without bound.',
            ),
            'log_level'                => array(
                'type'        => 'string',
                'enum'        => $this->log_levels(),
                'description' => 'Log verbosity. "off" records nothing, "errors" only failures, "actions" each field written and each run completed, "verbose" adds per-request integration chatter that grows the table quickly.',
            ),
            'log_retention_days'       => array(
                'type'        => 'integer',
                'minimum'     => 0,
                'description' => 'Delete log entries older than this many days when pruning. 0 disables the age-based trim, leaving only the log_max_rows cap.',
            ),
            'log_max_rows'             => array(
                'type'        => 'integer',
                'minimum'     => 0,
                'description' => 'Hard ceiling on log entries; pruning drops the oldest rows beyond it. 0 disables the cap.',
            ),
            'max_description_length'   => array(
                'type'        => 'integer',
                'minimum'     => 0,
                'description' => 'Character budget for generated meta descriptions. 155 keeps them inside the usual Google truncation point.',
            ),
            'audit_email'              => array(
                'type'        => 'string',
                'format'      => 'email',
                'description' => 'Address the weekly audit report is sent to. Defaults to the site admin email.',
            ),
            'site_author'              => array(
                'type'        => 'string',
                'description' => 'Value used for the author meta tag when a post has no more specific author.',
            ),
            'robots_default'           => array(
                'type'        => 'string',
                'description' => 'Default robots directive written into the robots meta tag, e.g. "index, follow" or "noindex, nofollow" for a staging site.',
            ),
            'twitter_username'         => array(
                'type'        => 'string',
                'description' => 'Site X/Twitter handle used for twitter:site in generated cards. With or without the leading @.',
            ),
            'google_site_verification' => array(
                'type'        => 'string',
                'description' => 'Google Search Console verification token, output as a google-site-verification meta tag. Empty means no tag.',
            ),
            'default_og_image'         => array(
                'type'        => 'string',
                'format'      => 'uri',
                'description' => 'Absolute URL of the image used for Open Graph and Twitter cards when a post has no featured image.',
            ),
        );
    }
}
