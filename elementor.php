<?php
/**
 * Elementor page construction.
 *
 * Elementor keeps a page's entire layout in a single post meta value,
 * `_elementor_data`, holding a JSON string of nested element objects. None of
 * that structure is documented as public API, which is why building Elementor
 * pages programmatically is usually avoided - and why the attempts that do get
 * made tend to produce pages that come out empty, render with the previous
 * page's styling, or make the editor behave strangely once opened.
 *
 * Three causes account for nearly all of it, and each is handled here rather
 * than left to whoever is calling:
 *
 * - Element IDs have to be unique across the page. Duplicates raise no error;
 *   the editor simply starts selecting, moving and deleting the wrong element.
 * - A `widgetType` that is not registered is written out perfectly happily and
 *   then renders nothing, so the page comes back looking like the work never
 *   happened.
 * - Elementor compiles a CSS file per post and keeps serving it until it is
 *   told the post changed. Writing `_elementor_data` without clearing that
 *   cache is the single most common reason a programmatic edit appears not to
 *   have worked.
 *
 * The input format is deliberately not Elementor's. A caller describes what it
 * wants - a container holding a heading and a button - and this expands that
 * into the full element objects, generating IDs, filling in the column
 * arithmetic that legacy sections require, and refusing widget types the site
 * has never heard of before anything is written.
 *
 * Elementor is treated throughout as something that might not be there. Every
 * ability checks first and returns a plain, structured "not active" answer,
 * because one of our plugins has no business taking a site down because one of
 * theirs was switched off.
 *
 * @package Nyuchi_WordPress_Optimization
 */

if (!defined('ABSPATH')) {
    exit;
}

class AutoSEOElementor {

    const CATEGORY = 'nyuchi-optimization';
    const PREFIX   = 'nyuchi-optimization/';

    /**
     * Length of a generated element ID.
     *
     * Elementor's own editor mints seven lowercase hex characters and its
     * selectors are built from them (`.elementor-element-a1b2c3d`). Anything
     * longer or shorter still works, but matching the convention means data
     * written from here is indistinguishable from data the editor wrote, which
     * matters the first time somebody opens the page and starts editing.
     */
    const ID_LENGTH = 7;

    /**
     * Column widths Elementor actually has a stylesheet rule for.
     *
     * `_column_size` is never used as a number. Elementor turns it into an
     * `elementor-col-33` class, and those classes exist only for this fixed
     * set. Dividing 100 by three and putting the remainder on the last column
     * gives an arithmetically tidy 34 that matches no rule at all, so that
     * column ends up with no width and the row collapses - which looks exactly
     * like the layout having been written wrong. Snap to what the stylesheet
     * knows instead of to what the arithmetic says.
     */
    const COLUMN_SIZES = array(1 => 100, 2 => 50, 3 => 33, 4 => 25, 5 => 20, 6 => 16, 7 => 14, 8 => 12, 9 => 11, 10 => 10);

    /**
     * How deep an element tree is allowed to go.
     *
     * Inner sections and nested containers are legitimate, but a recursive
     * walk over caller-supplied data needs a floor under it - a description
     * that accidentally references itself would otherwise recurse until PHP
     * runs out of stack, which on a web request means a blank 500 with nothing
     * in the log to explain it.
     */
    const MAX_DEPTH = 20;

    /**
     * The four element types Elementor understands.
     *
     * `container` is the flexbox model introduced in 3.6 and default since
     * 3.16. `section`/`column` are the legacy grid. They can coexist on one
     * site but not sensibly within one layout, which is why elementor-status
     * reports which one to generate.
     */
    const EL_TYPES = array('container', 'section', 'column', 'widget');

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
        $this->register_status();
        $this->register_read_page();
        $this->register_create_page();
        $this->register_update_page();
        $this->register_widget_schema();
    }

    /* ---------------------------------------------------------------------
     * Is Elementor even here
     * ------------------------------------------------------------------ */

    /**
     * Whether Elementor is loaded and has finished booting.
     *
     * `class_exists` alone is not enough. The class can be autoloadable while
     * `Plugin::$instance` is still null - during a plugin activation sweep, or
     * if Elementor bailed out of its own init because of a failed requirement.
     * Every access to the instance in this file goes through this check first.
     *
     * @return bool
     */
    public static function is_active() {
        return class_exists('\Elementor\Plugin') && isset(\Elementor\Plugin::$instance);
    }

    /**
     * Whether Elementor Pro is loaded.
     *
     * Worth reporting separately because roughly a third of the widget names
     * people expect - forms, posts, nav menu, theme parts - only exist with
     * Pro, and "widget not found" is a confusing answer when the real answer
     * is "that one is a Pro widget and Pro is not installed".
     *
     * @return bool
     */
    public static function is_pro_active() {
        return defined('ELEMENTOR_PRO_VERSION') || class_exists('\ElementorPro\Plugin');
    }

    /**
     * Elementor's version, read from Elementor rather than assumed.
     *
     * This value ends up in `_elementor_version` on every post written here.
     * Hardcoding it would mean pages claiming to have been saved by a version
     * of Elementor that never touched them, which is exactly the metadata
     * Elementor's own upgrade routines consult when deciding whether a post
     * needs migrating.
     *
     * @return string Empty string when Elementor is absent.
     */
    public static function version() {
        if (defined('ELEMENTOR_VERSION')) {
            return (string) ELEMENTOR_VERSION;
        }

        if (self::is_active() && method_exists(\Elementor\Plugin::$instance, 'get_version')) {
            return (string) \Elementor\Plugin::$instance->get_version();
        }

        return '';
    }

    /**
     * The answer every ability gives when Elementor is not available.
     *
     * Structured rather than an exception or a WP_Error, because "Elementor is
     * not active" is a fact about the site, not a failure of the call. A
     * caller that gets this back can report it, or offer to activate the
     * plugin, without having to parse an error string.
     *
     * @return array
     */
    protected function unavailable() {
        $installed = false;

        if (!function_exists('get_plugins') && file_exists(ABSPATH . 'wp-admin/includes/plugin.php')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        if (function_exists('get_plugins')) {
            foreach (array_keys(get_plugins()) as $file) {
                if ('elementor' === dirname($file)) {
                    $installed = true;
                    break;
                }
            }
        }

        return array(
            'elementor_active' => false,
            'error'            => 'elementor_not_active',
            'installed'        => $installed,
            'message'          => $installed
                ? 'Elementor is installed on this site but not active. Activate it and call again.'
                : 'Elementor is not installed on this site. These abilities read and write Elementor\'s own post meta and have nothing to act on without it.',
            'note'             => 'Nothing was read or written. This plugin does not depend on Elementor and every other ability continues to work normally.',
        );
    }

    /* ---------------------------------------------------------------------
     * What the site can build with
     * ------------------------------------------------------------------ */

    /**
     * Experiment flags that change what valid output looks like.
     *
     * The container experiment is the one that matters: with it on, a layout
     * should be built from `container` elements, and with it off, from
     * `section` and `column`. Generating the wrong one produces a page that
     * technically saves and then renders as an unstyled stack.
     *
     * @return array
     */
    public function experiments() {
        if (!self::is_active()) {
            return array('available' => false);
        }

        $plugin = \Elementor\Plugin::$instance;

        if (!isset($plugin->experiments) || !is_object($plugin->experiments)) {
            return array(
                'available' => false,
                'note'      => 'This Elementor build exposes no experiments manager.',
            );
        }

        $manager  = $plugin->experiments;
        $features = array();
        $raw      = array();

        if (method_exists($manager, 'get_features')) {
            $raw = (array) $manager->get_features();
        }

        foreach ($raw as $id => $feature) {
            if (!is_array($feature)) {
                continue;
            }

            $features[$id] = array(
                'title'   => isset($feature['title']) ? (string) $feature['title'] : (string) $id,
                'release' => isset($feature['release']) ? (string) $feature['release'] : '',
                'active'  => method_exists($manager, 'is_feature_active') ? (bool) $manager->is_feature_active($id) : null,
            );
        }

        // Elementor stabilised containers and eventually stopped shipping the
        // flag at all. A missing flag on a recent version therefore means
        // "containers, permanently" rather than "containers off", and reading
        // it the other way would generate legacy sections for every modern
        // site.
        $has_flag = array_key_exists('container', $features);
        $version  = self::version();

        if ($has_flag) {
            $containers = (bool) $features['container']['active'];
        } else {
            $containers = '' === $version || version_compare($version, '3.16', '>=');
        }

        return array(
            'available'                   => true,
            'features'                    => $features,
            'container_experiment_present' => $has_flag,
            'containers_active'           => $containers,
            'recommended_layout'          => $containers ? 'container' : 'section',
            'note'                        => $containers
                ? 'Build layouts from container elements. Sections and columns still render but are the legacy model.'
                : 'Containers are off on this site. Build layouts from section elements holding column elements holding widgets.',
        );
    }

    /**
     * Which layout model to generate for this site.
     *
     * @return string 'container' or 'section'.
     */
    public function layout_model() {
        $exp = $this->experiments();

        return isset($exp['recommended_layout']) ? $exp['recommended_layout'] : 'container';
    }

    /**
     * Every widget type registered on this site.
     *
     * Asking Elementor rather than keeping a list means Pro widgets, third
     * party widgets and our own all appear without this file knowing anything
     * about them, and a widget that was removed stops being offered the moment
     * it goes.
     *
     * @return array<string, array> Keyed by widget name.
     */
    public function widget_types() {
        if (!self::is_active()) {
            return array();
        }

        $plugin = \Elementor\Plugin::$instance;

        if (!isset($plugin->widgets_manager) || !is_object($plugin->widgets_manager)) {
            return array();
        }

        try {
            $widgets = $plugin->widgets_manager->get_widget_types();
        } catch (\Throwable $e) {
            // A single broken third-party widget can throw while the registry
            // is being built. Losing the list is survivable; taking the
            // request down with it is not.
            return array();
        }

        if (!is_array($widgets)) {
            return array();
        }

        $out = array();

        foreach ($widgets as $name => $widget) {
            if (!is_object($widget)) {
                continue;
            }

            $out[(string) $name] = array(
                'name'       => (string) $name,
                'title'      => method_exists($widget, 'get_title') ? (string) $widget->get_title() : (string) $name,
                'categories' => method_exists($widget, 'get_categories') ? (array) $widget->get_categories() : array(),
                'icon'       => method_exists($widget, 'get_icon') ? (string) $widget->get_icon() : '',
            );
        }

        ksort($out);

        return $out;
    }

    /**
     * Kits, and the global styles the active one defines.
     *
     * An Elementor kit is a post holding the site's global colours and
     * typography. Settings can reference them by ID instead of carrying a
     * literal value, which is what keeps a generated page in step with the
     * rest of the site when the palette later changes - so the IDs are worth
     * reporting even though nothing here writes them automatically.
     *
     * @return array
     */
    public function kits() {
        if (!self::is_active()) {
            return array();
        }

        $plugin    = \Elementor\Plugin::$instance;
        $active_id = 0;
        $globals   = array();

        if (isset($plugin->kits_manager) && is_object($plugin->kits_manager)) {
            try {
                if (method_exists($plugin->kits_manager, 'get_active_id')) {
                    $active_id = (int) $plugin->kits_manager->get_active_id();
                }

                if (method_exists($plugin->kits_manager, 'get_active_kit')) {
                    $kit = $plugin->kits_manager->get_active_kit();

                    if (is_object($kit) && method_exists($kit, 'get_settings')) {
                        foreach (array('system_colors', 'custom_colors', 'system_typography', 'custom_typography') as $group) {
                            $items = $kit->get_settings($group);

                            if (!is_array($items)) {
                                continue;
                            }

                            foreach ($items as $item) {
                                if (!is_array($item) || !isset($item['_id'])) {
                                    continue;
                                }

                                $globals[$group][] = array(
                                    'id'    => (string) $item['_id'],
                                    'title' => isset($item['title']) ? (string) $item['title'] : '',
                                    'value' => isset($item['color']) ? (string) $item['color'] : null,
                                );
                            }
                        }
                    }
                }
            } catch (\Throwable $e) {
                $globals = array();
            }
        }

        $posts = get_posts(array(
            'post_type'      => 'elementor_library',
            'post_status'    => 'any',
            'posts_per_page' => 50,
            'meta_key'       => '_elementor_template_type',
            'meta_value'     => 'kit',
            'fields'         => 'ids',
        ));

        $list = array();

        foreach ((array) $posts as $id) {
            $list[] = array(
                'id'     => (int) $id,
                'title'  => get_the_title($id),
                'active' => ((int) $id === $active_id),
            );
        }

        return array(
            'active_kit_id' => $active_id,
            'kits'          => $list,
            'globals'       => $globals,
            'note'          => 'A settings value may reference a global instead of a literal, in the form {"__globals__":{"title_color":"globals/colors?id=primary"}}.',
        );
    }

    /**
     * The whole picture: what is installed, what is switched on, what can be built.
     *
     * @return array
     */
    public function status() {
        if (!self::is_active()) {
            return $this->unavailable();
        }

        $widgets = $this->widget_types();

        return array(
            'elementor_active'  => true,
            'version'           => self::version(),
            'pro_active'        => self::is_pro_active(),
            'pro_version'       => defined('ELEMENTOR_PRO_VERSION') ? ELEMENTOR_PRO_VERSION : null,
            'experiments'       => $this->experiments(),
            'widget_count'      => count($widgets),
            'widget_types'      => array_keys($widgets),
            'widgets'           => $widgets,
            'kits'              => $this->kits(),
            'meta_keys'         => array(
                '_elementor_data'          => 'JSON string holding the whole layout.',
                '_elementor_edit_mode'     => 'Must be "builder" or Elementor ignores the data entirely.',
                '_elementor_template_type' => 'Usually "wp-page" for pages and "wp-post" for posts.',
                '_elementor_version'       => 'Version that last saved the post. Used by Elementor\'s own upgrade routines.',
            ),
            'note'              => 'Use elementor-widget-schema to find the settings a given widget accepts before building with it.',
        );
    }

    /* ---------------------------------------------------------------------
     * Reading an existing page
     * ------------------------------------------------------------------ */

    /**
     * Decode the stored layout for a post.
     *
     * The meta is stored slashed, because `update_post_meta` strips one level
     * of slashes on the way in. `get_post_meta` returns it unslashed again, so
     * a straight decode is normally right - but a post written by something
     * that got the slashing wrong will only decode after unslashing, and
     * falling back is cheaper than telling a caller their page is corrupt.
     *
     * @param int $post_id Post ID.
     * @return array|null Null when there is nothing to decode.
     */
    public function read_data($post_id) {
        $raw = get_post_meta((int) $post_id, '_elementor_data', true);

        if (!is_string($raw) || '' === trim($raw)) {
            return null;
        }

        $data = json_decode($raw, true);

        if (!is_array($data)) {
            $data = json_decode(wp_unslash($raw), true);
        }

        return is_array($data) ? $data : null;
    }

    /**
     * A short human-readable hint about what an element contains.
     *
     * An outline of forty widgets that all read "widget/heading" is not much
     * use for deciding which one to patch. Pulling the first bit of text out
     * of the settings makes the tree recognisable as the actual page.
     *
     * @param array $element One element object.
     * @return string
     */
    protected function element_label($element) {
        if (empty($element['settings'])) {
            return '';
        }

        // Settings arrive as an array when decoded from the stored JSON and as
        // an object when freshly expanded, and the outline is built from both.
        $settings = (array) $element['settings'];

        foreach (array('title', 'text', 'editor', 'heading', 'title_text', 'description_text', 'html') as $key) {
            if (!empty($settings[$key]) && is_string($settings[$key])) {
                return wp_trim_words(wp_strip_all_tags($settings[$key]), 8, '...');
            }
        }

        return '';
    }

    /**
     * Collapse an element tree to an outline.
     *
     * `_elementor_data` for a real page runs to tens of thousands of
     * characters, almost all of it style settings. A caller deciding what to
     * change needs the shape - what is where, and under which ID - not the
     * blob, so the outline is returned alongside the full structure rather
     * than instead of it.
     *
     * @param array $elements Element objects.
     * @param int   $depth    Current depth.
     * @return array
     */
    public function outline($elements, $depth = 0) {
        $out = array();

        if ($depth > self::MAX_DEPTH) {
            return $out;
        }

        foreach ((array) $elements as $element) {
            if (!is_array($element)) {
                continue;
            }

            $node = array(
                'id'     => isset($element['id']) ? (string) $element['id'] : null,
                'elType' => isset($element['elType']) ? (string) $element['elType'] : null,
            );

            if (!empty($element['widgetType'])) {
                $node['widgetType'] = (string) $element['widgetType'];
            }

            $label = $this->element_label($element);

            if ('' !== $label) {
                $node['label'] = $label;
            }

            if (!empty($element['elements']) && is_array($element['elements'])) {
                $node['children'] = $this->outline($element['elements'], $depth + 1);
            }

            $out[] = $node;
        }

        return $out;
    }

    /**
     * Every element ID present in a tree.
     *
     * Needed for two things: telling a caller which IDs are patchable, and
     * making sure a newly generated ID does not collide with one already on
     * the page.
     *
     * @param array $elements Element objects.
     * @param array $found    Accumulator, keyed by ID.
     * @return array
     */
    public function collect_ids($elements, $found = array()) {
        foreach ((array) $elements as $element) {
            if (!is_array($element)) {
                continue;
            }

            if (!empty($element['id'])) {
                $found[(string) $element['id']] = true;
            }

            if (!empty($element['elements']) && is_array($element['elements'])) {
                $found = $this->collect_ids($element['elements'], $found);
            }
        }

        return $found;
    }

    /**
     * Count elements and note which widget types a page uses.
     *
     * @param array $elements Element objects.
     * @param array $acc      Accumulator.
     * @return array
     */
    protected function tally($elements, $acc = array('elements' => 0, 'widgets' => array())) {
        foreach ((array) $elements as $element) {
            if (!is_array($element)) {
                continue;
            }

            $acc['elements']++;

            if (!empty($element['widgetType'])) {
                $type = (string) $element['widgetType'];
                $acc['widgets'][$type] = isset($acc['widgets'][$type]) ? $acc['widgets'][$type] + 1 : 1;
            }

            if (!empty($element['elements']) && is_array($element['elements'])) {
                $acc = $this->tally($element['elements'], $acc);
            }
        }

        return $acc;
    }

    /**
     * Everything known about one Elementor post.
     *
     * @param int $post_id Post ID.
     * @return array
     */
    public function read_page($post_id) {
        if (!self::is_active()) {
            return $this->unavailable();
        }

        $post_id = (int) $post_id;
        $post    = get_post($post_id);

        if (!$post) {
            return array(
                'error'   => 'post_not_found',
                'post_id' => $post_id,
                'message' => 'No post exists with that ID.',
            );
        }

        if (!current_user_can('read_post', $post_id)) {
            return array(
                'error'   => 'cannot_read',
                'post_id' => $post_id,
                'message' => 'The current user cannot read that post.',
            );
        }

        $data      = $this->read_data($post_id);
        $edit_mode = get_post_meta($post_id, '_elementor_edit_mode', true);

        if (null === $data) {
            return array(
                'post_id'       => $post_id,
                'title'         => $post->post_title,
                'post_type'     => $post->post_type,
                'built_with_elementor' => false,
                'edit_mode'     => $edit_mode ? $edit_mode : null,
                'message'       => 'This post has no _elementor_data, so it was not built with Elementor - or was built and then reverted to the block editor.',
            );
        }

        $tally = $this->tally($data);

        return array(
            'post_id'              => $post_id,
            'title'                => $post->post_title,
            'post_type'            => $post->post_type,
            'post_status'          => $post->post_status,
            'built_with_elementor' => ('builder' === $edit_mode),
            'edit_mode'            => $edit_mode ? $edit_mode : null,
            'template_type'        => get_post_meta($post_id, '_elementor_template_type', true),
            'elementor_version'    => get_post_meta($post_id, '_elementor_version', true),
            'page_template'        => get_post_meta($post_id, '_wp_page_template', true),
            'element_count'        => $tally['elements'],
            'widget_usage'         => $tally['widgets'],
            'element_ids'          => array_keys($this->collect_ids($data)),
            'summary'              => $this->outline($data),
            'elements'             => $data,
            'edit_url'             => admin_url('post.php?post=' . $post_id . '&action=elementor'),
            'note'                 => 'summary is the tree shape only. Pass any id from it to elementor-update-page as a patch target.',
        );
    }

    /* ---------------------------------------------------------------------
     * Turning a description into Elementor data
     * ------------------------------------------------------------------ */

    /**
     * Mint an element ID that is not already in use on this page.
     *
     * Uniqueness is not cosmetic. Elementor's editor keys its element models,
     * its rendered markup and its per-element CSS rules off this ID. Two
     * elements sharing one means selecting either highlights both, deleting
     * either can remove the wrong one, and a style set on one silently applies
     * to the other. None of it errors, so it is only ever discovered by a
     * confused human in the editor - which is why the used set is threaded
     * through the whole expansion rather than trusting randomness.
     *
     * @param array $used Accumulator of IDs already taken, by reference.
     * @return string
     */
    protected function generate_id(&$used) {
        do {
            $id = substr(md5(uniqid((string) mt_rand(), true)), 0, self::ID_LENGTH);
        } while (isset($used[$id]));

        $used[$id] = true;

        return $id;
    }

    /**
     * Widget names closest to one that was not recognised.
     *
     * A typo and a Pro-only widget produce the same "not registered" outcome,
     * and the difference matters, so the suggestions are ranked with substring
     * matches first - "head" should surface "heading" ahead of whatever
     * happens to be four edits away.
     *
     * @param string   $needle    The name that was not found.
     * @param string[] $haystack  Registered names.
     * @param int      $limit     How many to return.
     * @return string[]
     */
    protected function closest($needle, $haystack, $limit = 5) {
        $needle = strtolower((string) $needle);
        $scored = array();

        foreach ($haystack as $candidate) {
            $lower = strtolower((string) $candidate);

            if ('' !== $needle && false !== strpos($lower, $needle)) {
                $scored[$candidate] = -1;
                continue;
            }

            $scored[$candidate] = levenshtein($needle, $lower);
        }

        asort($scored);

        return array_slice(array_keys($scored), 0, $limit);
    }

    /**
     * Expand the simplified description into real Elementor elements.
     *
     * The simplified form is the public interface of this file, so it is
     * forgiving on the way in and strict on the way out. A node is:
     *
     *   type     - container | section | column | widget, or a widget name
     *              used directly as a shorthand. Optional: a node naming a
     *              widget is a widget, and a node with children is a layout
     *              element in whichever model this site uses.
     *   widget   - widget type name, for widget nodes.
     *   settings - passed through to Elementor untouched.
     *   children - nested nodes.
     *   id       - optional; supply one only to keep an existing element's ID.
     *
     * `elType`, `widgetType` and `elements` are accepted as aliases so raw
     * Elementor data can be handed straight back in without translation.
     *
     * @param array       $nodes         Simplified nodes.
     * @param string      $layout        'container' or 'section'.
     * @param array|null  $valid_widgets Registered widget names, or null to skip validation.
     * @param array       $used          IDs already taken, by reference.
     * @param array       $errors        Fatal problems, by reference.
     * @param array       $warnings      Things that were fixed up, by reference.
     * @param int         $depth         Current depth.
     * @param string      $path          Human-readable position, for messages.
     * @param bool        $inner         Whether these nodes sit below the top level.
     * @return array Full Elementor element objects.
     */
    protected function expand($nodes, $layout, $valid_widgets, &$used, &$errors, &$warnings, $depth = 0, $path = 'root', $inner = false) {
        $out = array();

        if ($depth > self::MAX_DEPTH) {
            $errors[] = array(
                'error'   => 'too_deep',
                'path'    => $path,
                'message' => 'Element nesting went past ' . self::MAX_DEPTH . ' levels. Check the description for an element that contains itself.',
            );

            return $out;
        }

        foreach ((array) $nodes as $index => $node) {
            $here = $path . '[' . $index . ']';

            if (!is_array($node)) {
                $errors[] = array(
                    'error'   => 'not_an_object',
                    'path'    => $here,
                    'message' => 'Each element must be an object, not a ' . gettype($node) . '.',
                );
                continue;
            }

            $type   = isset($node['type']) ? (string) $node['type'] : (isset($node['elType']) ? (string) $node['elType'] : '');
            $widget = isset($node['widget']) ? (string) $node['widget'] : (isset($node['widgetType']) ? (string) $node['widgetType'] : '');

            $children = array();

            if (isset($node['children']) && is_array($node['children'])) {
                $children = $node['children'];
            } elseif (isset($node['elements']) && is_array($node['elements'])) {
                $children = $node['elements'];
            }

            // A type naming a widget rather than an elType is the shorthand
            // people reach for first, so honour it instead of rejecting it.
            if ('' !== $type && !in_array($type, self::EL_TYPES, true)) {
                if ('' === $widget) {
                    $widget = $type;
                }

                $type = 'widget';
            }

            if ('' === $type) {
                $type = ('' !== $widget) ? 'widget' : $layout;
            }

            if (!in_array($type, self::EL_TYPES, true)) {
                $errors[] = array(
                    'error'   => 'unknown_element_type',
                    'path'    => $here,
                    'message' => 'Element type "' . $type . '" is not one of: ' . implode(', ', self::EL_TYPES) . '.',
                );
                continue;
            }

            if ('widget' === $type) {
                if ('' === $widget) {
                    $errors[] = array(
                        'error'   => 'missing_widget_type',
                        'path'    => $here,
                        'message' => 'A widget element needs a widget name, e.g. {"type":"widget","widget":"heading"}.',
                    );
                    continue;
                }

                // Refusing here is the whole point. Elementor stores an
                // unrecognised widgetType without complaint and then renders
                // nothing for it, so the page comes back looking untouched
                // with no clue as to why.
                if (is_array($valid_widgets) && !in_array($widget, $valid_widgets, true)) {
                    $suggestions = $this->closest($widget, $valid_widgets);

                    $errors[] = array(
                        'error'       => 'unknown_widget_type',
                        'path'        => $here,
                        'widget'      => $widget,
                        'suggestions' => $suggestions,
                        'message'     => 'No widget named "' . $widget . '" is registered on this site. Closest registered names: '
                            . (empty($suggestions) ? 'none' : implode(', ', $suggestions))
                            . '. If you expected an Elementor Pro widget, check elementor-status for whether Pro is active.',
                    );
                    continue;
                }

                if (!empty($children)) {
                    $errors[] = array(
                        'error'   => 'widget_with_children',
                        'path'    => $here,
                        'message' => 'A widget cannot contain other elements. Put them in a ' . $layout . ' alongside it.',
                    );
                    continue;
                }
            }

            $settings = array();

            if (isset($node['settings'])) {
                if (is_array($node['settings'])) {
                    $settings = $node['settings'];
                } else {
                    $warnings[] = array(
                        'path'    => $here,
                        'message' => 'settings was a ' . gettype($node['settings']) . ' and has been ignored; it must be an object.',
                    );
                }
            }

            // A supplied ID is honoured only if it looks like an Elementor ID
            // and is still free. Anything else gets a fresh one rather than a
            // collision - see generate_id for why that matters.
            $id = '';

            if (!empty($node['id']) && is_scalar($node['id'])) {
                $candidate = (string) $node['id'];

                if (preg_match('/^[a-zA-Z0-9]{1,16}$/', $candidate) && !isset($used[$candidate])) {
                    $id = $candidate;
                    $used[$id] = true;
                } else {
                    $warnings[] = array(
                        'path'    => $here,
                        'message' => 'Supplied id "' . $candidate . '" was rejected (duplicate or not alphanumeric) and replaced with a generated one.',
                    );
                }
            }

            if ('' === $id) {
                $id = $this->generate_id($used);
            }

            // A section's columns share the section's own inner flag - they
            // are not a further level of nesting as far as Elementor is
            // concerned. Everything else nested below anything is inner.
            $child_inner = ('section' === $type) ? $inner : true;

            $expanded = $this->expand($children, $layout, $valid_widgets, $used, $errors, $warnings, $depth + 1, $here, $child_inner);

            $element = array(
                'id'       => $id,
                'elType'   => $type,
                'settings' => (object) $settings,
                'elements' => $expanded,
            );

            if ('widget' === $type) {
                $element['widgetType'] = $widget;
            } else {
                // Elementor distinguishes a top-level section or container from
                // one nested inside another, and the editor uses the flag when
                // deciding what may be dropped where. Getting it wrong on a
                // top-level element makes it undraggable in the editor.
                $element['isInner'] = (bool) $inner;
            }

            if ('section' === $type) {
                $element['elements'] = $this->normalise_section_children($element['elements'], $used, $warnings, $here, $inner);
            }

            $out[] = $element;
        }

        return $out;
    }

    /**
     * Make a legacy section's children legal.
     *
     * A section may only contain columns. A widget placed directly in one is
     * accepted by the JSON and then rendered by nothing, which is a silent
     * failure people spend a long time on - so loose children are wrapped in a
     * column rather than refused.
     *
     * Elementor also expects every column to declare `_column_size`, a
     * percentage. Omit it and the columns collapse to zero width, giving a
     * page that saves cleanly and displays as an empty band.
     *
     * @param array  $children Expanded children.
     * @param array  $used     IDs in use, by reference.
     * @param array  $warnings By reference.
     * @param string $path     Position, for messages.
     * @param bool   $inner    Whether the section itself is nested.
     * @return array
     */
    protected function normalise_section_children($children, &$used, &$warnings, $path, $inner) {
        $columns = array();
        $loose   = array();

        foreach ((array) $children as $child) {
            if (isset($child['elType']) && 'column' === $child['elType']) {
                if (!empty($loose)) {
                    $columns[] = $this->wrap_in_column($loose, $used, $inner);
                    $loose     = array();
                }

                $columns[] = $child;
                continue;
            }

            $loose[] = $child;
        }

        if (!empty($loose)) {
            $columns[] = $this->wrap_in_column($loose, $used, $inner);

            $warnings[] = array(
                'path'    => $path,
                'message' => 'A section can only hold columns, so ' . count($loose) . ' element(s) placed directly in it were wrapped in a column.',
            );
        }

        $count = count($columns);

        if (0 === $count) {
            return $columns;
        }

        if (isset(self::COLUMN_SIZES[$count])) {
            $size = self::COLUMN_SIZES[$count];
        } else {
            $size = self::COLUMN_SIZES[10];

            $warnings[] = array(
                'path'    => $path,
                'message' => 'A section holding ' . $count . ' columns has no matching Elementor width class. Each was set to ' . $size . '%, which will need adjusting in the editor - Elementor itself does not offer more than ten columns in a row.',
            );
        }

        foreach ($columns as $i => $column) {
            $settings = isset($column['settings']) ? (array) $column['settings'] : array();

            if (!isset($settings['_column_size'])) {
                $settings['_column_size'] = $size;
            }

            if (!array_key_exists('_inline_size', $settings)) {
                // Null means "use _column_size". A number here would pin the
                // column to a manually dragged width.
                $settings['_inline_size'] = null;
            }

            $columns[$i]['settings'] = (object) $settings;
        }

        return $columns;
    }

    /**
     * Put loose elements into a column of their own.
     *
     * @param array $elements Elements to wrap.
     * @param array $used     IDs in use, by reference.
     * @param bool  $inner    Whether the parent section is nested.
     * @return array
     */
    protected function wrap_in_column($elements, &$used, $inner) {
        return array(
            'id'       => $this->generate_id($used),
            'elType'   => 'column',
            'settings' => (object) array(),
            'elements' => array_values($elements),
            'isInner'  => (bool) $inner,
        );
    }

    /* ---------------------------------------------------------------------
     * Writing
     * ------------------------------------------------------------------ */

    /**
     * Keep every element's settings encoding as a JSON object.
     *
     * PHP cannot tell an empty JSON object from an empty JSON array once it
     * has been decoded, so an element stored as `"settings":{}` comes back as
     * an empty PHP array and re-encodes as `"settings":[]`. Elementor's editor
     * expects an object there, and a patch that touched one heading has no
     * business quietly changing the shape of every other element on the page.
     *
     * @param array $elements Element objects.
     * @return array
     */
    protected function objectify_settings($elements) {
        foreach ((array) $elements as $i => $element) {
            if (!is_array($element)) {
                continue;
            }

            $elements[$i]['settings'] = isset($element['settings']) ? (object) $element['settings'] : (object) array();

            if (isset($element['elements']) && is_array($element['elements'])) {
                $elements[$i]['elements'] = $this->objectify_settings($element['elements']);
            }
        }

        return $elements;
    }

    /**
     * Store a layout on a post.
     *
     * `update_post_meta` runs the value through `stripslashes`, so JSON handed
     * to it unslashed loses every backslash it contains - which in practice
     * means every escaped quote inside a widget's HTML, and a `_elementor_data`
     * that no longer parses. Elementor slashes on the way in for this reason
     * and so does this.
     *
     * @param int   $post_id Post ID.
     * @param array $data    Full element objects.
     * @return string The JSON that was written.
     */
    protected function write_data($post_id, $data) {
        $json = wp_json_encode($this->objectify_settings($data));

        update_post_meta((int) $post_id, '_elementor_data', wp_slash($json));

        return $json;
    }

    /**
     * Drop Elementor's compiled CSS and rendered markup for a post.
     *
     * Elementor compiles each post's element styles into a file once and
     * serves that file until something invalidates it. Change the data without
     * doing this and the browser gets the new structure wearing the old
     * layout's CSS: widgets that have moved keep their previous positioning,
     * and widgets that are new arrive unstyled. It is the single most common
     * reason a programmatic Elementor edit looks like it did nothing.
     *
     * Recent versions also cache rendered element HTML in post meta, which has
     * exactly the same failure mode, so that goes too.
     *
     * @param int $post_id Post ID.
     * @return array What was cleared.
     */
    public function clear_css_cache($post_id) {
        $post_id = (int) $post_id;
        $cleared = array();

        if (class_exists('\Elementor\Core\Files\CSS\Post')) {
            try {
                $css = \Elementor\Core\Files\CSS\Post::create($post_id);

                if (is_object($css) && method_exists($css, 'delete')) {
                    $css->delete();
                    $cleared[] = 'post_css_file';
                }
            } catch (\Throwable $e) {
                $cleared[] = 'post_css_file_failed:' . $e->getMessage();
            }
        }

        // Only fall back to the global sweep if the per-post delete was not
        // available. It rebuilds every post's CSS on next view, which is
        // correct but far heavier than the situation calls for.
        if (empty($cleared) && self::is_active()) {
            $plugin = \Elementor\Plugin::$instance;

            if (isset($plugin->files_manager) && is_object($plugin->files_manager) && method_exists($plugin->files_manager, 'clear_cache')) {
                try {
                    $plugin->files_manager->clear_cache();
                    $cleared[] = 'files_manager_global';
                } catch (\Throwable $e) {
                    $cleared[] = 'files_manager_failed:' . $e->getMessage();
                }
            }
        }

        // Belt and braces: these two meta values are what the caches above are
        // recorded in, and a stale one survives a failed delete.
        delete_post_meta($post_id, '_elementor_css');
        delete_post_meta($post_id, '_elementor_element_cache');
        $cleared[] = 'post_meta';

        return $cleared;
    }

    /**
     * Template types Elementor recognises for ordinary content.
     *
     * @param string $post_type Post type.
     * @return string
     */
    protected function template_type_for($post_type) {
        return ('page' === $post_type) ? 'wp-page' : 'wp-post';
    }

    /**
     * Build a page from a description.
     *
     * @param array $input Ability input.
     * @return array
     */
    public function create_page($input) {
        if (!self::is_active()) {
            return $this->unavailable();
        }

        $title = isset($input['title']) ? trim((string) $input['title']) : '';

        if ('' === $title) {
            return array(
                'error'   => 'missing_title',
                'message' => 'A title is required.',
            );
        }

        $post_type = isset($input['post_type']) ? (string) $input['post_type'] : 'page';

        if (!post_type_exists($post_type)) {
            return array(
                'error'   => 'unknown_post_type',
                'message' => 'No post type "' . $post_type . '" is registered on this site.',
            );
        }

        $status  = isset($input['status']) ? (string) $input['status'] : 'draft';
        $allowed = array('draft', 'pending', 'private', 'publish');

        if (!in_array($status, $allowed, true)) {
            return array(
                'error'   => 'unknown_status',
                'message' => 'status must be one of: ' . implode(', ', $allowed) . '.',
            );
        }

        $elements = isset($input['elements']) && is_array($input['elements']) ? $input['elements'] : null;

        if (null === $elements || empty($elements)) {
            return array(
                'error'   => 'missing_elements',
                'message' => 'elements is required and must be a non-empty array describing the layout.',
                'example' => $this->format_example(),
            );
        }

        // Dry run unless explicitly told otherwise, matching the rest of this
        // plugin: a malformed call should show its work, not create posts.
        $dry_run = !isset($input['dry_run']) || (bool) $input['dry_run'];

        $registered = array_keys($this->widget_types());
        $used       = array();
        $errors     = array();
        $warnings   = array();

        $data = $this->expand(
            $elements,
            $this->layout_model(),
            empty($registered) ? null : $registered,
            $used,
            $errors,
            $warnings
        );

        if (!empty($errors)) {
            return array(
                'error'    => 'invalid_elements',
                'message'  => count($errors) . ' problem(s) in the element description. Nothing was created.',
                'errors'   => $errors,
                'warnings' => $warnings,
            );
        }

        $post_args = array(
            'post_title'   => $title,
            'post_type'    => $post_type,
            'post_status'  => $status,
            // Elementor renders from meta, not from post_content. Leaving this
            // empty is what the editor itself does; putting the text here as
            // well would double it up for anything reading the raw content.
            'post_content' => '',
        );

        $template_type = $this->template_type_for($post_type);

        $meta = array(
            '_elementor_edit_mode'     => 'builder',
            '_elementor_template_type' => $template_type,
            '_elementor_version'       => self::version(),
        );

        $template = isset($input['template']) ? (string) $input['template'] : '';

        if ('' !== $template) {
            $meta['_wp_page_template'] = $template;

            $known = array('default', 'elementor_canvas', 'elementor_header_footer', 'elementor_theme');

            if (!in_array($template, $known, true)) {
                $templates = wp_get_theme()->get_page_templates(null, $post_type);

                if (!isset($templates[$template])) {
                    $warnings[] = array(
                        'path'    => 'template',
                        'message' => 'Template "' . $template . '" is neither an Elementor page template nor one the active theme registers. It will be stored, but WordPress will fall back to the default if it does not resolve.',
                    );
                }
            }
        }

        $tally = $this->tally($data);

        if ($dry_run) {
            return array(
                'dry_run'              => true,
                'post_args'            => $post_args,
                'meta'                 => $meta,
                'layout_model'         => $this->layout_model(),
                'element_count'        => $tally['elements'],
                'widget_usage'         => $tally['widgets'],
                'summary'              => $this->outline($data),
                'elements'             => $data,
                'elementor_data'       => wp_json_encode($data),
                'warnings'             => $warnings,
                'note'                 => 'Nothing was created. elementor_data is exactly the string that would be written to _elementor_data. Call again with dry_run false to create the page.',
            );
        }

        if (!current_user_can('publish_posts') && 'publish' === $status) {
            return array(
                'error'   => 'cannot_publish',
                'message' => 'The current user cannot publish. Create it as a draft instead.',
            );
        }

        $post_id = wp_insert_post($post_args, true);

        if (is_wp_error($post_id)) {
            return array(
                'error'   => 'insert_failed',
                'message' => $post_id->get_error_message(),
            );
        }

        $json = $this->write_data($post_id, $data);

        foreach ($meta as $key => $value) {
            update_post_meta($post_id, $key, $value);
        }

        // A brand new post has no compiled CSS, but a recycled ID can, and the
        // cost of being sure is one meta delete.
        $cleared = $this->clear_css_cache($post_id);

        return array(
            'dry_run'       => false,
            'post_id'       => (int) $post_id,
            'title'         => $title,
            'post_type'     => $post_type,
            'post_status'   => $status,
            'element_count' => $tally['elements'],
            'widget_usage'  => $tally['widgets'],
            'summary'       => $this->outline($data),
            'bytes_written' => strlen($json),
            'cache_cleared' => $cleared,
            'warnings'      => $warnings,
            'edit_url'      => admin_url('post.php?post=' . (int) $post_id . '&action=elementor'),
            'permalink'     => get_permalink($post_id),
        );
    }

    /**
     * A worked example of the simplified format.
     *
     * Returned with the "you did not give me any elements" error because that
     * is the moment somebody needs it.
     *
     * @return array
     */
    protected function format_example() {
        return array(
            array(
                'type'     => 'container',
                'settings' => array('content_width' => 'boxed'),
                'children' => array(
                    array(
                        'type'     => 'widget',
                        'widget'   => 'heading',
                        'settings' => array('title' => 'Hello', 'header_size' => 'h1'),
                    ),
                    array(
                        'type'     => 'widget',
                        'widget'   => 'text-editor',
                        'settings' => array('editor' => '<p>Some copy.</p>'),
                    ),
                ),
            ),
        );
    }

    /* ---------------------------------------------------------------------
     * Changing an existing page
     * ------------------------------------------------------------------ */

    /**
     * Find one element by ID anywhere in the tree and merge settings into it.
     *
     * A targeted patch exists so that changing a heading does not mean
     * regenerating the page. Everything the patch does not name is left byte
     * for byte as it was, including element IDs - which is what keeps the
     * post's compiled CSS, any custom CSS keyed to an element, and anything
     * else referencing those IDs still pointing at the right elements.
     *
     * The merge is deliberately shallow. Elementor's grouped controls store a
     * whole object per value, and merging into them key by key would produce
     * half-set groups that render unpredictably; passing the group whole is
     * both simpler to reason about and what the editor does.
     *
     * @param array  $elements  Element objects, by reference.
     * @param string $target_id ID to find.
     * @param array  $settings  Settings to merge.
     * @param bool   $found     Set true when the target is hit, by reference.
     * @return void
     */
    protected function patch_tree(&$elements, $target_id, $settings, &$found) {
        foreach ($elements as $i => $element) {
            if (!is_array($element)) {
                continue;
            }

            if (isset($element['id']) && (string) $element['id'] === $target_id) {
                $current = isset($element['settings']) ? (array) $element['settings'] : array();

                $elements[$i]['settings'] = (object) array_merge($current, $settings);
                $found = true;

                return;
            }

            if (!empty($element['elements']) && is_array($element['elements'])) {
                $this->patch_tree($elements[$i]['elements'], $target_id, $settings, $found);

                if ($found) {
                    return;
                }
            }
        }
    }

    /**
     * Modify an existing Elementor page.
     *
     * @param array $input Ability input.
     * @return array
     */
    public function update_page($input) {
        if (!self::is_active()) {
            return $this->unavailable();
        }

        $post_id = isset($input['post_id']) ? (int) $input['post_id'] : 0;
        $post    = $post_id ? get_post($post_id) : null;

        if (!$post) {
            return array(
                'error'   => 'post_not_found',
                'post_id' => $post_id,
                'message' => 'No post exists with that ID.',
            );
        }

        if (!current_user_can('edit_post', $post_id)) {
            return array(
                'error'   => 'cannot_edit',
                'post_id' => $post_id,
                'message' => 'The current user cannot edit that post.',
            );
        }

        $existing_raw = get_post_meta($post_id, '_elementor_data', true);
        $existing     = $this->read_data($post_id);

        $replacement = isset($input['elements']) && is_array($input['elements']) ? $input['elements'] : null;
        $patch       = isset($input['patch']) && is_array($input['patch']) ? $input['patch'] : null;

        if (null === $replacement && null === $patch) {
            return array(
                'error'   => 'nothing_to_do',
                'message' => 'Give either elements (a full replacement layout) or patch (an id plus settings to merge).',
            );
        }

        if (null !== $replacement && null !== $patch) {
            return array(
                'error'   => 'ambiguous_request',
                'message' => 'Give elements or patch, not both - a full replacement would discard whatever the patch targeted.',
            );
        }

        $dry_run  = !isset($input['dry_run']) || (bool) $input['dry_run'];
        $warnings = array();

        if (null !== $patch) {
            if (null === $existing) {
                return array(
                    'error'   => 'not_an_elementor_page',
                    'post_id' => $post_id,
                    'message' => 'That post has no _elementor_data, so there is no element tree to patch. Pass elements to build one.',
                );
            }

            $target = isset($patch['id']) ? (string) $patch['id'] : '';

            if ('' === $target) {
                return array(
                    'error'   => 'missing_patch_id',
                    'message' => 'patch needs an id. Call elementor-read-page for the element ids on this post.',
                );
            }

            $settings = isset($patch['settings']) && is_array($patch['settings']) ? $patch['settings'] : array();

            if (empty($settings)) {
                return array(
                    'error'   => 'missing_patch_settings',
                    'message' => 'patch needs a settings object holding the values to merge.',
                );
            }

            $data  = $existing;
            $found = false;

            $this->patch_tree($data, $target, $settings, $found);

            if (!$found) {
                return array(
                    'error'      => 'element_not_found',
                    'element_id' => $target,
                    'post_id'    => $post_id,
                    'message'    => 'No element with id "' . $target . '" exists on post ' . $post_id . '. Nothing was changed.',
                    'available_ids' => array_keys($this->collect_ids($existing)),
                );
            }
        } else {
            $registered = array_keys($this->widget_types());
            $errors     = array();

            // Regenerating IDs from scratch is right for a full replacement,
            // but they still must not collide with anything the old tree left
            // behind in custom CSS or in a caller's own notes, so the existing
            // set is seeded as taken.
            $used = $existing ? $this->collect_ids($existing) : array();

            $data = $this->expand(
                $replacement,
                $this->layout_model(),
                empty($registered) ? null : $registered,
                $used,
                $errors,
                $warnings
            );

            if (!empty($errors)) {
                return array(
                    'error'    => 'invalid_elements',
                    'message'  => count($errors) . ' problem(s) in the element description. Nothing was changed.',
                    'errors'   => $errors,
                    'warnings' => $warnings,
                );
            }
        }

        $data  = $this->objectify_settings($data);
        $tally = $this->tally($data);

        // Handed back on every call, dry run or not. There is no revision
        // history for post meta, so this string is the only undo a caller
        // has - keep it if the result needs reverting, because the next write
        // to this post will overwrite it with no further warning.
        $previous = is_string($existing_raw) ? $existing_raw : '';

        if ($dry_run) {
            return array(
                'dry_run'                => true,
                'post_id'                => $post_id,
                'mode'                   => (null !== $patch) ? 'patch' : 'replace',
                'element_count'          => $tally['elements'],
                'widget_usage'           => $tally['widgets'],
                'summary'                => $this->outline($data),
                'elements'               => $data,
                'elementor_data'         => wp_json_encode($data),
                'previous_elementor_data' => $previous,
                'warnings'               => $warnings,
                'note'                   => 'Nothing was changed. Call again with dry_run false to write. previous_elementor_data is the current stored value and is the only way to revert.',
            );
        }

        $json    = $this->write_data($post_id, $data);
        $cleared = $this->clear_css_cache($post_id);

        update_post_meta($post_id, '_elementor_edit_mode', 'builder');
        update_post_meta($post_id, '_elementor_version', self::version());

        if (!get_post_meta($post_id, '_elementor_template_type', true)) {
            update_post_meta($post_id, '_elementor_template_type', $this->template_type_for($post->post_type));
        }

        return array(
            'dry_run'                 => false,
            'post_id'                 => $post_id,
            'mode'                    => (null !== $patch) ? 'patch' : 'replace',
            'element_count'           => $tally['elements'],
            'widget_usage'            => $tally['widgets'],
            'summary'                 => $this->outline($data),
            'bytes_written'           => strlen($json),
            'cache_cleared'           => $cleared,
            'previous_elementor_data' => $previous,
            'warnings'                => $warnings,
            'edit_url'                => admin_url('post.php?post=' . $post_id . '&action=elementor'),
            'permalink'               => get_permalink($post_id),
            'note'                    => 'previous_elementor_data is the value that was replaced. Post meta has no revision history, so this is the only undo - to revert, pass it back as elements after decoding it.',
        );
    }

    /* ---------------------------------------------------------------------
     * What a widget accepts
     * ------------------------------------------------------------------ */

    /**
     * Control types that carry no value.
     *
     * Section markers, dividers and inline help are part of the editor's
     * layout rather than the widget's data. Returning them alongside real
     * controls would triple the size of the answer with keys that can never
     * appear in settings.
     */
    const PRESENTATIONAL = array('section', 'divider', 'heading', 'raw_html', 'notice', 'alert', 'deprecated_notice', 'tab', 'tabs');

    /**
     * The controls a widget registers, which is the same thing as the settings
     * keys it will accept.
     *
     * Without this a caller is guessing at key names, and a guessed key is
     * stored silently and ignored - the widget renders with its defaults and
     * nothing anywhere says why.
     *
     * @param string $widget_type Widget name.
     * @param string $tab         'content', 'style', 'advanced' or 'all'.
     * @return array
     */
    public function widget_schema($widget_type, $tab = 'content') {
        if (!self::is_active()) {
            return $this->unavailable();
        }

        $plugin = \Elementor\Plugin::$instance;

        if (!isset($plugin->widgets_manager) || !is_object($plugin->widgets_manager)) {
            return array(
                'error'   => 'no_widgets_manager',
                'message' => 'Elementor is active but its widget manager is not available in this request.',
            );
        }

        $widget_type = (string) $widget_type;
        $registered  = array_keys($this->widget_types());

        try {
            $widget = $plugin->widgets_manager->get_widget_types($widget_type);
        } catch (\Throwable $e) {
            $widget = null;
        }

        if (!is_object($widget)) {
            $suggestions = $this->closest($widget_type, $registered);

            return array(
                'error'       => 'unknown_widget_type',
                'widget_type' => $widget_type,
                'suggestions' => $suggestions,
                'message'     => 'No widget named "' . $widget_type . '" is registered. Closest registered names: '
                    . (empty($suggestions) ? 'none' : implode(', ', $suggestions)) . '.',
            );
        }

        try {
            $controls = method_exists($widget, 'get_controls') ? (array) $widget->get_controls() : array();
        } catch (\Throwable $e) {
            return array(
                'error'       => 'controls_unavailable',
                'widget_type' => $widget_type,
                'message'     => 'The widget is registered but its controls could not be read: ' . $e->getMessage(),
            );
        }

        $out      = array();
        $sections = array();
        $skipped  = 0;
        $section  = '';

        foreach ($controls as $name => $control) {
            if (!is_array($control)) {
                continue;
            }

            $type = isset($control['type']) ? (string) $control['type'] : '';

            if ('section' === $type) {
                $section = (string) $name;
                $sections[$section] = isset($control['label']) ? (string) $control['label'] : $section;
            }

            if (in_array($type, self::PRESENTATIONAL, true)) {
                $skipped++;
                continue;
            }

            $control_tab = isset($control['tab']) ? (string) $control['tab'] : 'content';

            if ('all' !== $tab && $control_tab !== $tab) {
                continue;
            }

            $entry = array(
                'name'    => (string) $name,
                'type'    => $type,
                'label'   => isset($control['label']) ? (string) $control['label'] : '',
                'tab'     => $control_tab,
                'section' => isset($control['section']) ? (string) $control['section'] : $section,
                'default' => isset($control['default']) ? $control['default'] : null,
            );

            // Only a select-style control has a fixed set of valid values, and
            // it is the one case where guessing is guaranteed to fail: an
            // unrecognised option is stored and then rendered as nothing.
            if (!empty($control['options']) && is_array($control['options'])) {
                $entry['options'] = $control['options'];
            }

            if (isset($control['description']) && '' !== $control['description']) {
                $entry['description'] = (string) $control['description'];
            }

            // A conditional control only applies when another control holds a
            // particular value, so setting it on its own does nothing.
            if (!empty($control['condition']) && is_array($control['condition'])) {
                $entry['condition'] = $control['condition'];
            }

            $out[(string) $name] = $entry;
        }

        return array(
            'widget_type'   => $widget_type,
            'title'         => method_exists($widget, 'get_title') ? (string) $widget->get_title() : $widget_type,
            'categories'    => method_exists($widget, 'get_categories') ? (array) $widget->get_categories() : array(),
            'tab_filter'    => $tab,
            'sections'      => $sections,
            'control_count' => count($out),
            'controls'      => $out,
            'presentational_skipped' => $skipped,
            'note'          => 'These names are the keys a settings object may use. Values omitted fall back to default. Style and advanced controls are excluded unless tab is set to style, advanced or all.',
        );
    }

    /* ---------------------------------------------------------------------
     * Abilities
     * ------------------------------------------------------------------ */

    private function register_status() {
        $this->register(self::PREFIX . 'elementor-status', array(
            'label'       => 'Elementor capability report',
            'description' => 'Whether Elementor and Elementor Pro are active, the version, which layout experiments are on (containers versus legacy sections, which changes what a valid layout looks like), every registered widget type, and the kits and global styles available. Read-only. Call this first - it tells you what this site can actually be built with.',
            'category'    => self::CATEGORY,
            'input_schema' => array(
                'type' => 'object', 'properties' => array(), 'additionalProperties' => false,
            ),
            'output_schema' => array('type' => 'object'),
            'permission_callback' => array($this, 'can_manage'),
            'execute_callback'    => function () {
                return $this->status();
            },
        ));
    }

    private function register_read_page() {
        $this->register(self::PREFIX . 'elementor-read-page', array(
            'label'       => 'Read an Elementor page',
            'description' => 'Return the decoded Elementor layout for a post, with its template type, edit mode and saved version. Includes a summary giving the element tree as a nested outline of ids and types, so a page can be understood without parsing the full data. Read-only.',
            'category'    => self::CATEGORY,
            'input_schema' => array(
                'type'       => 'object',
                'properties' => array(
                    'post_id' => array(
                        'type'        => 'integer',
                        'description' => 'The post, page or template to read.',
                    ),
                ),
                'required'             => array('post_id'),
                'additionalProperties' => false,
            ),
            'output_schema' => array('type' => 'object'),
            'permission_callback' => array($this, 'can_manage'),
            'execute_callback'    => function ($input) {
                return $this->read_page(isset($input['post_id']) ? $input['post_id'] : 0);
            },
        ));
    }

    private function register_create_page() {
        $this->register(self::PREFIX . 'elementor-create-page', array(
            'label'       => 'Create an Elementor page',
            'description' => 'Build a complete Elementor page from a simplified layout description, generating unique element ids and setting the four meta keys Elementor requires. Widget types are checked against what is registered and unknown ones are refused with the closest matches named. Defaults to a dry run that returns the exact data it would write and creates nothing.',
            'category'    => self::CATEGORY,
            'input_schema' => array(
                'type'       => 'object',
                'properties' => array(
                    'title' => array(
                        'type'        => 'string',
                        'description' => 'Post title.',
                    ),
                    'status' => array(
                        'type'        => 'string',
                        'enum'        => array('draft', 'pending', 'private', 'publish'),
                        'description' => 'Defaults to draft.',
                    ),
                    'post_type' => array(
                        'type'        => 'string',
                        'description' => 'Defaults to page. Any registered post type is accepted.',
                    ),
                    'elements' => array(
                        'type'        => 'array',
                        'items'       => array('type' => 'object'),
                        'description' => 'The layout. Each node: {"type":"container|section|column|widget", "widget":"heading", "settings":{...}, "children":[...]}. type may be omitted - a node naming a widget is a widget, a node with children is a layout element in whichever model this site uses. A widget name may also be used directly as type. Call elementor-widget-schema for the settings keys a widget accepts.',
                    ),
                    'template' => array(
                        'type'        => 'string',
                        'description' => 'Optional page template, e.g. elementor_canvas or elementor_header_footer.',
                    ),
                    'dry_run' => array(
                        'type'        => 'boolean',
                        'description' => 'Defaults to true. Must be explicitly false to create anything.',
                    ),
                ),
                'required'             => array('title', 'elements'),
                'additionalProperties' => false,
            ),
            'output_schema' => array('type' => 'object'),
            'permission_callback' => array($this, 'can_manage'),
            'execute_callback'    => function ($input) {
                return $this->create_page(is_array($input) ? $input : array());
            },
        ));
    }

    private function register_update_page() {
        $this->register(self::PREFIX . 'elementor-update-page', array(
            'label'       => 'Update an Elementor page',
            'description' => 'Change an existing Elementor page, either by replacing the layout wholesale or by patching one element found by id and merging settings into it. Clears Elementor\'s compiled CSS for the post afterwards, without which the page renders with stale styling. Always returns the previous data, which is the only way to revert. Defaults to a dry run.',
            'category'    => self::CATEGORY,
            'input_schema' => array(
                'type'       => 'object',
                'properties' => array(
                    'post_id' => array(
                        'type'        => 'integer',
                        'description' => 'The post to change.',
                    ),
                    'elements' => array(
                        'type'        => 'array',
                        'items'       => array('type' => 'object'),
                        'description' => 'Full replacement layout in the same simplified format as elementor-create-page. Mutually exclusive with patch.',
                    ),
                    'patch' => array(
                        'type'       => 'object',
                        'properties' => array(
                            'id'       => array(
                                'type'        => 'string',
                                'description' => 'Element id, from elementor-read-page.',
                            ),
                            'settings' => array(
                                'type'        => 'object',
                                'description' => 'Settings merged over that element\'s existing settings. Shallow - pass a grouped control value whole.',
                            ),
                        ),
                        'description' => 'A targeted change leaving the rest of the page untouched. Mutually exclusive with elements.',
                    ),
                    'dry_run' => array(
                        'type'        => 'boolean',
                        'description' => 'Defaults to true. Must be explicitly false to write anything.',
                    ),
                ),
                'required'             => array('post_id'),
                'additionalProperties' => false,
            ),
            'output_schema' => array('type' => 'object'),
            'permission_callback' => array($this, 'can_manage'),
            'execute_callback'    => function ($input) {
                return $this->update_page(is_array($input) ? $input : array());
            },
        ));
    }

    private function register_widget_schema() {
        $this->register(self::PREFIX . 'elementor-widget-schema', array(
            'label'       => 'Widget settings schema',
            'description' => 'Return the controls a given Elementor widget registers - name, type, default and, for selects, the permitted options. These are exactly the keys a settings object may use, so this is what makes valid widget settings possible without guessing. Content controls only by default; pass tab to include style or advanced. Read-only.',
            'category'    => self::CATEGORY,
            'input_schema' => array(
                'type'       => 'object',
                'properties' => array(
                    'widget_type' => array(
                        'type'        => 'string',
                        'description' => 'Widget name as listed by elementor-status, e.g. "heading".',
                    ),
                    'tab' => array(
                        'type'        => 'string',
                        'enum'        => array('content', 'style', 'advanced', 'all'),
                        'description' => 'Which control tab to return. Defaults to content, which is where the settings that change what a widget says live. Style sets are large.',
                    ),
                ),
                'required'             => array('widget_type'),
                'additionalProperties' => false,
            ),
            'output_schema' => array('type' => 'object'),
            'permission_callback' => array($this, 'can_manage'),
            'execute_callback'    => function ($input) {
                $type = isset($input['widget_type']) ? (string) $input['widget_type'] : '';

                if ('' === $type) {
                    return new WP_Error('missing_widget_type', 'Name a widget type. elementor-status lists every one registered on this site.');
                }

                $tab = isset($input['tab']) ? (string) $input['tab'] : 'content';

                if (!in_array($tab, array('content', 'style', 'advanced', 'all'), true)) {
                    $tab = 'content';
                }

                return $this->widget_schema($type, $tab);
            },
        ));
    }
}
