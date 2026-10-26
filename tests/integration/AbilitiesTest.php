<?php
/**
 * The plugin's abilities (MCP surface): who may run them, and that every
 * ability able to change the site does nothing unless told dry_run: false.
 *
 * Uses WordPress's own Abilities API (core since 6.9). On an older WordPress
 * the plugin registers nothing, and the last test checks exactly that.
 *
 * @package AutoSEOManager\Tests
 */

class AbilitiesTest extends WP_UnitTestCase {

    const PREFIX = 'nyuchi-optimization/';

    /** Abilities that take dry_run, and so can change the site. */
    const DESTRUCTIVE = array(
        'yoast-update-settings',
        'db-cleanup',
        'media-repair',
        'db-write',
        'db-replace',
        'db-orphaned-tables-drop',
        'plugins-update',
        'plugins-toggle',
        'elementor-create-page',
        'elementor-update-page',
        'media-convert',
    );

    public function set_up() {
        parent::set_up();
        ASM_Test_HTTP::reset();
    }

    /**
     * @return string[] This plugin's ability names.
     */
    protected function registered_names() {
        if (!function_exists('wp_get_abilities')) {
            return array();
        }

        $names = array();

        foreach (wp_get_abilities() as $ability) {
            if (0 === strpos($ability->get_name(), self::PREFIX)) {
                $names[] = $ability->get_name();
            }
        }

        return $names;
    }

    /**
     * @param string $short Name without the prefix.
     * @return WP_Ability
     */
    protected function ability($short) {
        $ability = wp_get_ability(self::PREFIX . $short);
        $this->assertNotNull($ability, "Ability {$short} is not registered.");

        return $ability;
    }

    /**
     * Whether this WordPress has the Abilities API (6.9+). Without it the
     * plugin must load cleanly and register nothing, which is asserted here
     * instead of skipping the test.
     *
     * @return bool
     */
    protected function has_abilities_api() {
        if (function_exists('wp_get_abilities')) {
            return true;
        }

        $this->assertTrue(class_exists('AutoSEOAbilities'), 'The plugin should still load without the Abilities API.');
        $this->assertFalse(function_exists('wp_register_ability'));

        return false;
    }

    /* ------------------------------------------------------- permissions */

    public function test_every_ability_is_registered_with_a_permission_callback() {
        if (!$this->has_abilities_api()) {
            return;
        }

        $names = $this->registered_names();

        $this->assertGreaterThanOrEqual(30, count($names), 'The plugin\'s abilities did not register.');

        foreach (self::DESTRUCTIVE as $short) {
            $this->assertContains(self::PREFIX . $short, $names);
        }
    }

    public static function non_admin_roles() {
        return array(
            'logged out'  => array(null),
            'subscriber'  => array('subscriber'),
            'contributor' => array('contributor'),
            'author'      => array('author'),
            'editor'      => array('editor'),
        );
    }

    /**
     * @dataProvider non_admin_roles
     *
     * @param string|null $role Role, or null for no user.
     */
    public function test_non_admins_are_refused_every_ability($role) {
        if (!$this->has_abilities_api()) {
            return;
        }

        wp_set_current_user($role ? self::factory()->user->create(array('role' => $role)) : 0);

        $allowed = array();

        foreach ($this->registered_names() as $name) {
            $result = wp_get_ability($name)->check_permissions(array());

            if (true === $result) {
                $allowed[] = $name;
            }
        }

        $this->assertSame(array(), $allowed, 'These abilities let a ' . ($role ? $role : 'logged-out visitor') . ' through.');
    }

    public function test_an_administrator_is_allowed_every_ability() {
        if (!$this->has_abilities_api()) {
            return;
        }

        wp_set_current_user(self::factory()->user->create(array('role' => 'administrator')));

        $refused = array();

        foreach ($this->registered_names() as $name) {
            if (true !== wp_get_ability($name)->check_permissions(array())) {
                $refused[] = $name;
            }
        }

        $this->assertSame(array(), $refused);
    }

    public function test_the_rest_route_refuses_a_subscriber() {
        if (!$this->has_abilities_api()) {
            return;
        }

        update_option('asm_probe', 'old');
        wp_set_current_user(self::factory()->user->create(array('role' => 'subscriber')));

        $request = new WP_REST_Request('POST', '/wp-abilities/v1/abilities/' . self::PREFIX . 'db-write/run');
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(wp_json_encode(array('input' => array(
            'kind'    => 'option',
            'key'     => 'asm_probe',
            'value'   => 'new',
            'dry_run' => false,
        ))));

        $response = rest_do_request($request);

        // 404 while the abilities are not show_in_rest (they reach MCP
        // through the adapter, not this route); 401/403 if that changes.
        $this->assertContains($response->get_status(), array(401, 403, 404));
        $this->assertSame('old', get_option('asm_probe'));
    }

    /* --------------------------------------------------------- dry runs */

    /**
     * Run an ability as an administrator with $input, and assert it reported
     * a dry run (when it got that far) and that $unchanged still holds.
     *
     * @param string   $short     Ability.
     * @param array    $input     Input without dry_run.
     * @param callable $unchanged   Returns a snapshot of what must not change.
     * @param bool     $must_report The result must be an array with dry_run.
     */
    protected function assertDryRunByDefault($short, $input, $unchanged, $must_report = false) {
        if (!$this->has_abilities_api()) {
            return;
        }

        wp_set_current_user(self::factory()->user->create(array('role' => 'administrator')));

        $this->assertArrayNotHasKey('dry_run', $input);

        $before = call_user_func($unchanged);
        $result = $this->ability($short)->execute($input);
        $after  = call_user_func($unchanged);

        $this->assertSame($before, $after, "{$short} changed the site without dry_run: false.");

        if ($must_report) {
            $this->assertIsArray($result, "{$short} should report its plan.");
            $this->assertArrayHasKey('dry_run', $result);
        }

        if (is_array($result) && array_key_exists('dry_run', $result)) {
            $this->assertTrue($result['dry_run'], "{$short} did not report a dry run.");
        }
    }

    protected function option_raw($name) {
        global $wpdb;

        return $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name));
    }

    public function test_db_write_is_a_dry_run_by_default() {
        update_option('asm_probe', 'old');

        $this->assertDryRunByDefault(
            'db-write',
            array('kind' => 'option', 'key' => 'asm_probe', 'value' => 'new'),
            function () {
                return $this->option_raw('asm_probe');
            },
            true
        );
    }

    public function test_db_write_writes_when_told_to() {
        if (!$this->has_abilities_api()) {
            return;
        }

        update_option('asm_probe', 'old');
        wp_set_current_user(self::factory()->user->create(array('role' => 'administrator')));

        $result = $this->ability('db-write')->execute(array(
            'kind'    => 'option',
            'key'     => 'asm_probe',
            'value'   => 'new',
            'dry_run' => false,
        ));

        $this->assertNotWPError($result);
        $this->assertSame('new', $this->option_raw('asm_probe'), 'The dry-run default is meaningful only if false really writes.');
    }

    public function test_db_replace_is_a_dry_run_by_default() {
        update_option('asm_probe', 'see needle-123 here');

        $this->assertDryRunByDefault(
            'db-replace',
            array('from' => 'needle-123', 'to' => 'replaced'),
            function () {
                return $this->option_raw('asm_probe');
            },
            true
        );
    }

    public function test_db_cleanup_is_a_dry_run_by_default() {
        $post = self::factory()->post->create();
        wp_update_post(array('ID' => $post, 'post_content' => 'second'));
        wp_update_post(array('ID' => $post, 'post_content' => 'third'));

        $this->assertDryRunByDefault(
            'db-cleanup',
            array('targets' => array('revisions', 'auto_drafts', 'expired_transients')),
            function () use ($post) {
                return count(wp_get_post_revisions($post));
            }
        );
    }

    public function test_db_orphaned_tables_drop_is_a_dry_run_by_default() {
        global $wpdb;

        $table = $wpdb->prefix . 'asm_orphan_probe';
        $wpdb->query("CREATE TABLE {$table} (id INTEGER)"); // phpcs:ignore WordPress.DB
        $wpdb->query("INSERT INTO {$table} (id) VALUES (1)"); // phpcs:ignore WordPress.DB

        $this->assertDryRunByDefault(
            'db-orphaned-tables-drop',
            array('tables' => array($table)),
            function () use ($wpdb, $table) {
                return $wpdb->get_var("SELECT COUNT(*) FROM {$table}"); // phpcs:ignore WordPress.DB
            }
        );
    }

    public function test_plugins_toggle_is_a_dry_run_by_default() {
        $this->assertDryRunByDefault(
            'plugins-toggle',
            array('plugin' => 'hello.php', 'action' => 'activate'),
            function () {
                return $this->option_raw('active_plugins');
            }
        );
    }

    public function test_plugins_update_is_a_dry_run_by_default() {
        $this->assertDryRunByDefault(
            'plugins-update',
            array('plugins' => array('hello.php')),
            function () {
                return md5_file(WP_PLUGIN_DIR . '/hello.php');
            }
        );

        $downloads = array_filter(ASM_Test_HTTP::$requests, function ($r) {
            return false !== strpos($r['url'], 'downloads.wordpress.org');
        });
        $this->assertSame(array(), $downloads, 'A dry run must not download anything.');
    }

    public function test_media_repair_is_a_dry_run_by_default() {
        $attachment = self::factory()->attachment->create(array('file' => '2026/10/missing.jpg', 'post_mime_type' => 'image/jpeg'));

        $this->assertDryRunByDefault(
            'media-repair',
            array(),
            function () use ($attachment) {
                return array(get_post_meta($attachment, '_wp_attached_file', true), get_post_meta($attachment, '_wp_attachment_metadata', true));
            }
        );
    }

    public function test_media_convert_is_a_dry_run_by_default() {
        self::factory()->attachment->create(array('file' => '2026/10/photo.webp', 'post_mime_type' => 'image/webp'));

        $this->assertDryRunByDefault(
            'media-convert',
            array(),
            function () {
                global $wpdb;

                return $wpdb->get_results("SELECT ID, guid, post_mime_type FROM {$wpdb->posts} WHERE post_type = 'attachment' ORDER BY ID", ARRAY_A); // phpcs:ignore WordPress.DB
            }
        );
    }

    public function test_elementor_create_page_is_a_dry_run_by_default() {
        $this->assertDryRunByDefault(
            'elementor-create-page',
            array('title' => 'Probe page', 'elements' => array()),
            function () {
                return (int) wp_count_posts('page')->draft + (int) wp_count_posts('page')->publish;
            }
        );
    }

    public function test_elementor_update_page_is_a_dry_run_by_default() {
        $page = self::factory()->post->create(array('post_type' => 'page'));
        update_post_meta($page, '_elementor_data', '[]');

        $this->assertDryRunByDefault(
            'elementor-update-page',
            array('post_id' => $page, 'elements' => array()),
            function () use ($page) {
                return get_post_meta($page, '_elementor_data', true);
            }
        );
    }

    public function test_yoast_update_settings_is_a_dry_run_by_default() {
        update_option('wpseo', array('keyword_analysis_active' => true));

        $this->assertDryRunByDefault(
            'yoast-update-settings',
            array('group' => 'wpseo', 'changes' => array('keyword_analysis_active' => false)),
            function () {
                return $this->option_raw('wpseo');
            }
        );
    }

    public function test_every_ability_with_a_dry_run_flag_is_covered_here() {
        if (!$this->has_abilities_api()) {
            return;
        }

        $with_flag = array();

        foreach ($this->registered_names() as $name) {
            $schema = wp_get_ability($name)->get_input_schema();

            if (isset($schema['properties']['dry_run'])) {
                $with_flag[] = substr($name, strlen(self::PREFIX));
            }
        }

        sort($with_flag);
        $known = self::DESTRUCTIVE;
        sort($known);

        $this->assertSame($known, $with_flag, 'A new ability takes dry_run: add a default-is-dry-run test for it.');
    }
}
