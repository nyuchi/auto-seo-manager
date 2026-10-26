<?php
/**
 * The plugin's head tags next to an SEO plugin (#31, fixed in #42).
 *
 * Before #42 the plugin printed its own robots, keywords, Open Graph and
 * Twitter tags on every page, next to Yoast's, with raw Yoast templates such
 * as "%%sitename%% - %%sitedesc%%" in them. Now it prints none while Yoast,
 * Rank Math, All in One SEO or SEOPress is active, never a robots tag, and
 * otherwise only when the fallback_meta_output option (off by default) is on.
 *
 * Pages are rendered for real: go_to() a URL, then wp_head. A test that needs
 * an SEO plugin present runs in its own process and loads a stand-in from
 * tests/fixtures/seo-plugins, because a constant or class cannot be removed
 * again afterwards.
 *
 * @package AutoSEOManager\Tests
 */

class HeadMetaTest extends WP_UnitTestCase {

    /** @var int Front page carrying the meta a pre-#42 site has stored. */
    protected $front;

    public function set_up() {
        parent::set_up();
        ASM_Test_HTTP::reset();

        update_option('blogname', 'Iconic Expeditions');
        update_option('blogdescription', 'Luxury African safaris');

        update_option('auto_seo_auto_og_tags', 1);
        update_option('auto_seo_auto_twitter_cards', 1);
        update_option('auto_seo_site_author', 'Iconic Expeditions');
        update_option('auto_seo_twitter_username', 'iconic_exped');

        // The live-site state #31 was reported from: the plugin's legacy meta
        // on the front page, with Yoast templates left unresolved.
        $this->front = self::factory()->post->create(array(
            'post_type'  => 'page',
            'post_title' => 'Home',
        ));

        foreach (array(
            '_auto_seo_robots'         => 'index, follow',
            '_auto_seo_keywords'       => 'safari, africa',
            '_auto_seo_og_title'       => '%%sitename%% - %%sitedesc%%',
            '_auto_seo_og_description' => 'Desc',
            '_auto_seo_og_type'        => 'website',
            '_auto_seo_twitter_card'   => 'summary_large_image',
            '_auto_seo_twitter_title'  => '%%sitename%% - %%sitedesc%%',
            '_yoast_wpseo_title'       => '%%sitename%% - %%sitedesc%%',
        ) as $key => $value) {
            update_post_meta($this->front, $key, $value);
        }

        update_option('show_on_front', 'page');
        update_option('page_on_front', $this->front);
    }

    /**
     * @return AutoSEOManager The instance the plugin hooked into WordPress.
     */
    protected function plugin() {
        return $GLOBALS['auto_seo_manager'];
    }

    /**
     * Load a stand-in for an SEO plugin. Only call from a test that runs in
     * its own process.
     *
     * @param string $name yoast | yoast-replace-vars | rankmath | aioseo | seopress.
     */
    protected function load_seo_plugin($name) {
        require_once ASM_TEST_ROOT . '/tests/fixtures/seo-plugins/' . $name . '.php';
    }

    /**
     * The whole <head> WordPress prints for $url.
     *
     * @param string $url URL to render.
     * @return string
     */
    protected function head($url) {
        $this->go_to($url);

        ob_start();
        do_action('wp_head');

        return ob_get_clean();
    }

    /**
     * Number of <meta name|property="$attr"> tags, with either quote style.
     */
    protected function count_tag($html, $attr) {
        return preg_match_all('/<meta\s+(?:name|property)=["\']' . preg_quote($attr, '/') . '["\']/', $html);
    }

    /**
     * The robots tags core's wp_robots prints on its own for the current page.
     */
    protected function core_robots_tags() {
        ob_start();
        wp_robots();

        return $this->count_tag(ob_get_clean(), 'robots');
    }

    /** Tags only this plugin prints. */
    const PLUGIN_TAGS = array(
        'keywords', 'author', 'og:title', 'og:description', 'og:type', 'og:site_name',
        'twitter:card', 'twitter:title', 'twitter:site', 'google-site-verification',
    );

    /**
     * $html carries none of the plugin's tags, and no robots tag beyond
     * core's own.
     */
    protected function assertNoPluginTags($html, $label) {
        foreach (self::PLUGIN_TAGS as $tag) {
            $this->assertSame(0, $this->count_tag($html, $tag), "{$label}: {$tag} printed.\n{$html}");
        }

        $this->assertSame($this->core_robots_tags(), $this->count_tag($html, 'robots'), "{$label}: a second robots tag.\n{$html}");
    }

    /**
     * Every kind of page the plugin used to print tags on.
     *
     * @return string[] Label => URL.
     */
    protected function pages() {
        $post = self::factory()->post->create(array('post_title' => 'Victoria Falls'));

        return array(
            'front page' => home_url('/'),
            'single post' => get_permalink($post),
            'search'     => home_url('/?s=falls'),
            '404'        => home_url('/?p=999999'),
        );
    }

    /* ------------------------------------------------- with an SEO plugin */

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_nothing_is_printed_next_to_yoast_even_with_the_fallback_on() {
        $this->load_seo_plugin('yoast');
        update_option('auto_seo_fallback_meta_output', 1);

        $this->assertTrue($this->plugin()->external_seo_plugin_active(), 'Yoast is detected.');

        foreach ($this->pages() as $label => $url) {
            $this->assertNoPluginTags($this->head($url), "Yoast, {$label}");
        }
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_next_to_yoast_only_the_verification_tag_is_printed_and_only_on_the_front_page() {
        $this->load_seo_plugin('yoast');
        update_option('auto_seo_google_site_verification', 'abc123');

        $html = $this->head(home_url('/'));
        $this->assertSame(1, $this->count_tag($html, 'google-site-verification'));
        $this->assertStringContainsString('<meta name="google-site-verification" content="abc123">', $html);

        foreach (array('og:title', 'twitter:site', 'keywords', 'author') as $tag) {
            $this->assertSame(0, $this->count_tag($html, $tag), "{$tag} printed next to Yoast.");
        }

        $this->assertSame($this->core_robots_tags(), $this->count_tag($html, 'robots'));

        $post = self::factory()->post->create();
        $this->assertSame(0, $this->count_tag($this->head(get_permalink($post)), 'google-site-verification'));
    }

    public static function other_seo_plugins() {
        return array(
            'Rank Math'      => array('rankmath'),
            'All in One SEO' => array('aioseo'),
            'SEOPress'       => array('seopress'),
        );
    }

    /**
     * @dataProvider other_seo_plugins
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     *
     * @param string $fixture Stand-in to load.
     */
    public function test_nothing_is_printed_next_to_another_seo_plugin($fixture) {
        $this->load_seo_plugin($fixture);
        update_option('auto_seo_fallback_meta_output', 1);

        $this->assertTrue($this->plugin()->external_seo_plugin_active(), "{$fixture} is detected.");

        foreach ($this->pages() as $label => $url) {
            $this->assertNoPluginTags($this->head($url), "{$fixture}, {$label}");
        }
    }

    /* ---------------------------------------------- without an SEO plugin */

    public function test_the_fallback_is_off_by_default_and_prints_nothing() {
        $this->assertFalse($this->plugin()->external_seo_plugin_active());

        // A fresh install's defaults.
        $defaults = new ReflectionMethod($this->plugin(), 'set_default_options');
        $defaults->invoke($this->plugin());
        $this->assertSame(0, (int) get_option('auto_seo_fallback_meta_output'));

        foreach ($this->pages() as $label => $url) {
            $this->assertNoPluginTags($this->head($url), "no SEO plugin, fallback off, {$label}");
        }
    }

    public function test_the_fallback_on_a_singular_page_resolves_variables_and_prints_no_robots() {
        update_option('auto_seo_fallback_meta_output', 1);

        $html = $this->head(home_url('/'));

        $this->assertSame($this->core_robots_tags(), $this->count_tag($html, 'robots'), "A robots tag beside core's.\n{$html}");
        $this->assertStringNotContainsString('%%', $html, 'A raw %%variable%% reached the page.');
        $this->assertSame(1, $this->count_tag($html, 'og:title'));
        $this->assertStringContainsString('<meta property="og:title" content="Iconic Expeditions - Luxury African safaris">', $html);
        $this->assertSame(1, $this->count_tag($html, 'og:site_name'));
        $this->assertSame(1, $this->count_tag($html, 'twitter:site'));
    }

    public function test_the_fallback_on_an_archive_prints_author_and_twitter_site_but_no_robots() {
        update_option('auto_seo_fallback_meta_output', 1);

        foreach (array('search' => home_url('/?s=falls'), '404' => home_url('/?p=999999')) as $label => $url) {
            $html = $this->head($url);

            $this->assertSame($this->core_robots_tags(), $this->count_tag($html, 'robots'), "{$label}: a robots tag beside core's.\n{$html}");
            $this->assertSame(1, $this->count_tag($html, 'author'), $label);
            $this->assertSame(1, $this->count_tag($html, 'twitter:site'), $label);
            $this->assertSame(0, $this->count_tag($html, 'og:title'), $label);
        }
    }

    /* ------------------------------------------------------------ filters */

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_the_detection_and_output_filters_have_the_final_say() {
        $this->load_seo_plugin('yoast');
        update_option('auto_seo_fallback_meta_output', 1);

        add_filter('auto_seo_external_seo_plugin_active', '__return_false');
        $this->assertFalse($this->plugin()->external_seo_plugin_active(), 'The detection filter is honoured.');
        $this->assertSame(1, $this->count_tag($this->head(home_url('/')), 'og:title'), 'Overriding detection enables the fallback.');

        add_filter('auto_seo_output_head_meta', '__return_false');
        $this->assertNoPluginTags($this->head(home_url('/')), 'auto_seo_output_head_meta false');

        remove_all_filters('auto_seo_output_head_meta');
        remove_all_filters('auto_seo_external_seo_plugin_active');
        update_option('auto_seo_fallback_meta_output', 0);
        add_filter('auto_seo_output_head_meta', '__return_true');
        $this->assertSame(1, $this->count_tag($this->head(home_url('/')), 'og:title'), 'auto_seo_output_head_meta true forces output.');
    }

    /* ----------------------------------------------------------- resolver */

    public function test_variables_are_resolved_and_unknown_ones_dropped() {
        $post = get_post(self::factory()->post->create(array('post_title' => 'Victoria Falls')));

        $cases = array(
            array('%%sitename%% - %%sitedesc%%', 'Iconic Expeditions - Luxury African safaris'),
            array('%%title%% %%sep%% %%sitename%%', 'Victoria Falls - Iconic Expeditions'),
            array('%%title%% | %%unknown_var%%', 'Victoria Falls'),
            array('%%pt_single%% - %%sitename%%', 'Iconic Expeditions'),
            array('Plain title', 'Plain title'),
            array("Caf\u{00E9} \u{0100} %%sep%%", "Caf\u{00E9} \u{0100}"),
        );

        foreach ($cases as list($in, $want)) {
            $this->assertSame($want, $this->plugin()->resolve_seo_vars($in, $post), "resolve('{$in}')");
        }

        $this->assertSame('', $this->plugin()->resolve_seo_vars(null, $post));
        $this->assertSame('', $this->plugin()->resolve_seo_vars(array('x'), $post));
        $this->assertStringNotContainsString('%%', $this->plugin()->resolve_seo_vars("bad \xC3 utf8 %%x%%", $post), 'Invalid UTF-8 left a variable.');
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_yoasts_own_replacement_is_used_first() {
        $this->load_seo_plugin('yoast-replace-vars');
        $post = get_post(self::factory()->post->create(array('post_title' => 'Victoria Falls')));

        $this->assertSame('Iconic Expeditions - FROM YOAST', $this->plugin()->resolve_seo_vars('%%sitename%% - %%sitedesc%%', $post));
    }

    /* ------------------------------------------------------------ storage */

    /**
     * @return string[] _auto_seo_* meta keys stored on $post_id.
     */
    protected function stored_keys($post_id) {
        return array_values(array_filter(array_keys(get_post_meta($post_id)), function ($key) {
            return 0 === strpos($key, '_auto_seo_');
        }));
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_no_head_meta_is_stored_while_yoast_is_active() {
        $this->load_seo_plugin('yoast');
        update_option('auto_seo_auto_additional_meta', 1);
        update_option('auto_seo_auto_generate_keywords', 1);

        $post = self::factory()->post->create(array('post_title' => 'Trip'));
        update_post_meta($post, '_yoast_wpseo_title', '%%sitename%% - %%sitedesc%%');
        update_post_meta($post, '_yoast_wpseo_metadesc', 'd');
        update_post_meta($post, '_yoast_wpseo_focuskw', 'k');

        $this->plugin()->update_post_seo_data($post);

        $this->assertSame(array(), $this->stored_keys($post));
    }

    public function test_without_an_seo_plugin_no_robots_is_stored_and_titles_are_resolved() {
        update_option('auto_seo_auto_generate_keywords', 0);

        $post = self::factory()->post->create(array('post_title' => 'Trip'));
        update_post_meta($post, '_yoast_wpseo_title', '%%sitename%% - %%sitedesc%%');
        update_post_meta($post, '_yoast_wpseo_metadesc', 'Desc');

        $method = new ReflectionMethod($this->plugin(), 'update_additional_meta_tags');
        $method->invoke($this->plugin(), $post);

        $this->assertSame('', get_post_meta($post, '_auto_seo_robots', true), '_auto_seo_robots is stored.');
        $this->assertSame('Iconic Expeditions - Luxury African safaris', get_post_meta($post, '_auto_seo_og_title', true));
        $this->assertSame('Iconic Expeditions - Luxury African safaris', get_post_meta($post, '_auto_seo_twitter_title', true));
    }

    /* ---------------------------------------------------------- DB upgrade */

    public function test_db_upgrade_3_deletes_the_legacy_head_meta_and_nothing_else() {
        update_option('auto_seo_db_version', 2);
        update_post_meta($this->front, '_unrelated', 'keep');

        $this->plugin()->maybe_upgrade_db();

        $this->assertSame(array(), $this->stored_keys($this->front));
        $this->assertSame('keep', get_post_meta($this->front, '_unrelated', true));
        $this->assertSame('%%sitename%% - %%sitedesc%%', get_post_meta($this->front, '_yoast_wpseo_title', true), "Yoast's own meta is left alone.");
        $this->assertSame(3, (int) get_option('auto_seo_db_version'));
    }
}
