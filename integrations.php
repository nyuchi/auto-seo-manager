<?php
/**
 * Auto SEO Manager - Plugin Integrations
 * File: integrations.php
 * 
 * This file handles integrations with popular WordPress plugins
 * to enhance SEO automation capabilities.
 * 
 * @package AutoSEOManager
 * @version 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class AutoSEOIntegrations {
    
    private static $main_plugin_instance = null;
    
    public static function init($main_plugin_instance = null) {
        self::$main_plugin_instance = $main_plugin_instance;
        
        // Initialize integrations on plugins_loaded (later priority to ensure all plugins are loaded)
        add_action('plugins_loaded', array(__CLASS__, 'load_integrations'), 999);
        
        // Add integration status to admin
        add_filter('auto_seo_system_info', array(__CLASS__, 'add_integration_info'));
    }
    
    public static function load_integrations() {
        // Only load integrations if main plugin instance is available
        if (!self::$main_plugin_instance) {
            return;
        }
        
        // WooCommerce Integration
        if (class_exists('WooCommerce') && get_option('auto_seo_integration_woocommerce', 1)) {
            self::init_woocommerce_integration();
        }
        
        // Advanced Custom Fields Integration
        if (class_exists('ACF') && get_option('auto_seo_integration_acf', 1)) {
            self::init_acf_integration();
        }
        
        // Elementor Integration
        if (class_exists('\Elementor\Plugin') && get_option('auto_seo_integration_elementor', 1)) {
            self::init_elementor_integration();
        }
        
        // Beaver Builder Integration
        if (class_exists('FLBuilder') && get_option('auto_seo_integration_beaver_builder', 1)) {
            self::init_beaver_builder_integration();
        }
        
        // The Events Calendar Integration
        if (class_exists('Tribe__Events__Main') && get_option('auto_seo_integration_events_calendar', 1)) {
            self::init_events_calendar_integration();
        }
        
        // WPML/Polylang Integration
        if ((function_exists('pll_current_language') || class_exists('SitePress')) && get_option('auto_seo_integration_multilingual', 1)) {
            self::init_multilingual_integration();
        }
        
        // Gutenberg/Block Editor Integration
        if (function_exists('register_block_type') && get_option('auto_seo_integration_gutenberg', 1)) {
            self::init_gutenberg_integration();
        }
        
        // Custom Post Type UI Integration
        if (function_exists('cptui_get_post_type_data') && get_option('auto_seo_integration_cptui', 1)) {
            self::init_cptui_integration();
        }

        // WP Travel (wptravel.io, by WEN Solutions) — NOT the separate WP Travel
        // Engine plugin. They are different products with different meta schemas;
        // the keys used below are WP Travel's, verified against this site.
        // Its trip post type registers on `init`, after plugins_loaded, so the
        // availability check has to wait until then.
        if (get_option('auto_seo_integration_wp_travel', 1)) {
            add_action('init', array(__CLASS__, 'maybe_init_wp_travel_integration'), 20);
        }
    }

    /**
     * Post type WP Travel stores trips in. Filterable so a site on a different
     * trip post type (or on WP Travel Engine) can point this at its own.
     */
    public static function wp_travel_post_type() {
        return apply_filters('auto_seo_wp_travel_post_type', 'itineraries');
    }

    public static function wp_travel_available() {
        return post_type_exists(self::wp_travel_post_type());
    }

    public static function maybe_init_wp_travel_integration() {
        if (!self::wp_travel_available()) {
            return;
        }

        self::init_wp_travel_integration();
    }

    /**
     * WP Travel Integration (wptravel.io)
     * Trip-aware titles, descriptions and keywords for the trip post type.
     */
    private static function init_wp_travel_integration() {
        self::log_integration_activity('WP Travel', 'initialized', 'Trip SEO enhancement active');

        add_filter('auto_seo_title_generation', array(__CLASS__, 'wp_travel_title'), 10, 2);
        add_filter('auto_seo_content_extraction', array(__CLASS__, 'wp_travel_content_extraction'), 10, 2);
        add_filter('auto_seo_meta_description', array(__CLASS__, 'wp_travel_meta_description'), 5, 2);
        add_filter('auto_seo_focus_keywords', array(__CLASS__, 'wp_travel_focus_keywords'), 5, 2);
        add_filter('auto_seo_og_data', array(__CLASS__, 'wp_travel_og_data'), 10, 2);
    }

    /**
     * Trip meta, normalised. WP Travel keeps duration in two scalars and again
     * inside wp_travel_trip_duration_formating; the scalars are the writable
     * pair, and the array is what the front end renders.
     */
    private static function wp_travel_trip_data($post_id) {
        return array(
            'price'       => get_post_meta($post_id, 'wp_travel_trip_price', true),
            'days'        => get_post_meta($post_id, 'wp_travel_trip_duration', true),
            'nights'      => get_post_meta($post_id, 'wp_travel_trip_duration_night', true),
            'group_size'  => get_post_meta($post_id, 'wp_travel_group_size', true),
            'code'        => get_post_meta($post_id, 'wp_travel_trip_code', true),
            'location'    => get_post_meta($post_id, 'wp_travel_location', true),
            'overview'    => get_post_meta($post_id, 'wp_travel_overview', true),
            'outline'     => get_post_meta($post_id, 'wp_travel_outline', true),
            'includes'    => get_post_meta($post_id, 'wp_travel_trip_include', true),
        );
    }

    /**
     * Terms attached to a trip, flattened to names.
     */
    private static function wp_travel_term_names($post_id, $taxonomy) {
        $terms = get_the_terms($post_id, $taxonomy);

        if (!$terms || is_wp_error($terms)) {
            return array();
        }

        return wp_list_pluck($terms, 'name');
    }

    /**
     * Adds trip placeholders to the title template:
     * %%trip_duration%%, %%trip_price%%, %%trip_destination%%, %%trip_activity%%.
     */
    public static function wp_travel_title($title, $post) {
        if (!$post || self::wp_travel_post_type() !== $post->post_type) {
            return $title;
        }

        $trip = self::wp_travel_trip_data($post->ID);

        $destinations = self::wp_travel_term_names($post->ID, 'travel_locations');
        $activities   = self::wp_travel_term_names($post->ID, 'activity');

        $duration = '';
        if ($trip['days']) {
            $duration = $trip['nights']
                ? sprintf('%d Days %d Nights', (int) $trip['days'], (int) $trip['nights'])
                : sprintf('%d Days', (int) $trip['days']);
        }

        $replacements = array(
            '%%trip_duration%%'    => $duration,
            '%%trip_price%%'       => $trip['price'] ? (string) $trip['price'] : '',
            '%%trip_destination%%' => $destinations ? $destinations[0] : (string) $trip['location'],
            '%%trip_activity%%'    => $activities ? $activities[0] : '',
        );

        $title = str_replace(array_keys($replacements), array_values($replacements), $title);

        // Collapse the separators left behind by placeholders that had no value.
        $title = preg_replace('/\s*([|\-–])\s*(?=\1|$)/u', '', $title);

        return trim(preg_replace('/\s{2,}/', ' ', $title), " \t|-–");
    }

    public static function wp_travel_content_extraction($content, $post) {
        if (!$post || self::wp_travel_post_type() !== $post->post_type) {
            return $content;
        }

        $trip  = self::wp_travel_trip_data($post->ID);
        $parts = array($post->post_title);

        foreach (array('overview', 'outline', 'includes') as $key) {
            if (!empty($trip[$key])) {
                $parts[] = wp_strip_all_tags($trip[$key]);
            }
        }

        // The day-by-day itinerary is a serialised array of day blocks.
        $days = get_post_meta($post->ID, 'wp_travel_trip_itinerary_data', true);
        if (is_array($days)) {
            foreach ($days as $day) {
                if (!empty($day['title'])) {
                    $parts[] = wp_strip_all_tags($day['title']);
                }
                if (!empty($day['desc'])) {
                    $parts[] = wp_strip_all_tags($day['desc']);
                }
            }
        }

        $combined = trim(implode(' ', array_filter($parts)));

        return $combined ?: $content;
    }

    public static function wp_travel_meta_description($description, $post) {
        if (!$post || self::wp_travel_post_type() !== $post->post_type) {
            return $description;
        }

        $trip = self::wp_travel_trip_data($post->ID);

        if (empty($trip['overview'])) {
            return $description;
        }

        $max     = (int) get_option('auto_seo_max_description_length', 155);
        $summary = trim(wp_strip_all_tags($trip['overview']));

        // Lead with duration and destination — the two things a traveller scans for.
        $lead = array();
        if ($trip['days']) {
            $lead[] = sprintf('%d-day', (int) $trip['days']);
        }

        $destinations = self::wp_travel_term_names($post->ID, 'travel_locations');
        if ($destinations) {
            $lead[] = $destinations[0];
        }

        if ($lead) {
            $summary = implode(' ', $lead) . ' trip. ' . $summary;
        }

        return wp_trim_words($summary, 40, '');
    }

    public static function wp_travel_focus_keywords($keywords, $post) {
        if (!$post || self::wp_travel_post_type() !== $post->post_type) {
            return $keywords;
        }

        $terms = array_merge(
            self::wp_travel_term_names($post->ID, 'travel_locations'),
            self::wp_travel_term_names($post->ID, 'activity'),
            self::wp_travel_term_names($post->ID, 'itinerary_types'),
            self::wp_travel_term_names($post->ID, 'travel_keywords')
        );

        if (!$terms) {
            return $keywords;
        }

        // The taxonomy terms are editorially chosen, so they beat anything we
        // could infer from the body copy.
        return implode(', ', array_slice(array_unique($terms), 0, 5));
    }

    public static function wp_travel_og_data($og_data, $post) {
        if (!$post || self::wp_travel_post_type() !== $post->post_type) {
            return $og_data;
        }

        $trip = self::wp_travel_trip_data($post->ID);

        $og_data['type'] = 'product';

        if ($trip['price']) {
            $og_data['product:price:amount'] = $trip['price'];
        }

        return $og_data;
    }
    
    /**
     * WooCommerce Integration
     * Enhanced SEO for products, categories, and shop pages
     */
    private static function init_woocommerce_integration() {
        // Log integration initialization
        self::log_integration_activity('WooCommerce', 'initialized', 'Product SEO enhancement active');
        
        // Product-specific title templates
        add_filter('auto_seo_title_generation', array(__CLASS__, 'woocommerce_product_titles'), 10, 2);
        
        // Product descriptions with pricing and availability
        add_filter('auto_seo_meta_description', array(__CLASS__, 'woocommerce_product_descriptions'), 10, 2);
        
        // Product keywords with categories and attributes
        add_filter('auto_seo_focus_keywords', array(__CLASS__, 'woocommerce_product_keywords'), 10, 2);
        
        // Product Open Graph data
        add_filter('auto_seo_og_data', array(__CLASS__, 'woocommerce_og_data'), 10, 2);
        
        // Schema markup for products
        add_action('wp_head', array(__CLASS__, 'woocommerce_schema_markup'));
    }
    
    public static function woocommerce_product_titles($title, $post) {
        if ($post->post_type !== 'product' || !function_exists('wc_get_product')) {
            return $title;
        }
        
        $product = wc_get_product($post->ID);
        if (!$product) {
            return $title;
        }
        
        // Replace WooCommerce-specific placeholders
        $title = str_replace('%%price%%', wc_price($product->get_price()), $title);
        $title = str_replace('%%currency%%', get_woocommerce_currency_symbol(), $title);
        $title = str_replace('%%sale%%', $product->is_on_sale() ? 'On Sale' : '', $title);
        $title = str_replace('%%stock%%', $product->is_in_stock() ? 'In Stock' : 'Out of Stock', $title);
        
        // Add product categories
        $categories = wp_get_post_terms($post->ID, 'product_cat', array('fields' => 'names'));
        if (!empty($categories)) {
            $title = str_replace('%%product_category%%', $categories[0], $title);
        }
        
        return $title;
    }
    
    public static function woocommerce_product_descriptions($description, $post) {
        if ($post->post_type !== 'product' || !function_exists('wc_get_product')) {
            return $description;
        }
        
        $product = wc_get_product($post->ID);
        if (!$product) {
            return $description;
        }
        
        // Use short description if available
        $short_desc = $product->get_short_description();
        if ($short_desc && strlen($short_desc) > 50) {
            $description = wp_strip_all_tags($short_desc);
        }
        
        // Add pricing and availability info
        $price_text = '';
        if ($product->is_on_sale()) {
            $price_text = sprintf(' On sale from %s', wc_price($product->get_sale_price()));
        } else {
            $price_text = sprintf(' Starting at %s', wc_price($product->get_price()));
        }
        
        $stock_text = $product->is_in_stock() ? ' In stock and ready to ship.' : ' Currently out of stock.';
        
        // Combine description with commerce info
        $max_length = get_option('auto_seo_max_description_length', 155);
        $base_length = strlen($description . $price_text . $stock_text);
        
        if ($base_length <= $max_length) {
            $description .= $price_text . $stock_text;
        } else {
            // Truncate base description to fit commerce info
            $available_length = $max_length - strlen($price_text . $stock_text) - 3;
            if ($available_length > 0) {
                $description = substr($description, 0, $available_length) . '...' . $price_text . $stock_text;
            }
        }
        
        return $description;
    }
    
    public static function woocommerce_product_keywords($keywords, $post) {
        if ($post->post_type !== 'product') {
            return $keywords;
        }
        
        $product_keywords = array();
        
        // Add product categories
        $categories = wp_get_post_terms($post->ID, 'product_cat', array('fields' => 'names'));
        if (!is_wp_error($categories)) {
            $product_keywords = array_merge($product_keywords, $categories);
        }
        
        // Add product tags
        $tags = wp_get_post_terms($post->ID, 'product_tag', array('fields' => 'names'));
        if (!is_wp_error($tags)) {
            $product_keywords = array_merge($product_keywords, $tags);
        }
        
        // Add product attributes
        if (function_exists('wc_get_product')) {
            $product = wc_get_product($post->ID);
            if ($product) {
                $attributes = $product->get_attributes();
                foreach ($attributes as $attribute) {
                    if ($attribute->is_taxonomy()) {
                        $terms = wp_get_post_terms($post->ID, $attribute->get_name(), array('fields' => 'names'));
                        if (!is_wp_error($terms)) {
                            $product_keywords = array_merge($product_keywords, $terms);
                        }
                    }
                }
            }
        }
        
        // Combine with existing keywords
        if (is_array($keywords)) {
            return array_unique(array_merge($keywords, $product_keywords));
        } else {
            $existing = !empty($keywords) ? explode(',', $keywords) : array();
            $combined = array_unique(array_merge($existing, $product_keywords));
            return implode(', ', array_map('trim', $combined));
        }
    }
    
    public static function woocommerce_og_data($og_data, $post) {
        if ($post->post_type !== 'product' || !function_exists('wc_get_product')) {
            return $og_data;
        }
        
        $product = wc_get_product($post->ID);
        if (!$product) {
            return $og_data;
        }
        
        // Set product-specific OG data
        $og_data['type'] = 'product';
        
        // Add price to title if not already there
        if (!isset($og_data['title'])) {
            $og_data['title'] = $post->post_title . ' - ' . wc_price($product->get_price());
        }
        
        return $og_data;
    }
    
    /**
     * Advanced Custom Fields Integration
     * Use ACF fields for enhanced SEO data
     */
    private static function init_acf_integration() {
        self::log_integration_activity('ACF', 'initialized', 'Custom field SEO integration active');
        
        add_filter('auto_seo_meta_description', array(__CLASS__, 'acf_meta_description'), 5, 2); // Higher priority
        add_filter('auto_seo_focus_keywords', array(__CLASS__, 'acf_focus_keywords'), 5, 2);
        add_filter('auto_seo_og_data', array(__CLASS__, 'acf_og_data'), 10, 2);
    }
    
    public static function acf_meta_description($description, $post) {
        if (!function_exists('get_field')) {
            return $description;
        }
        
        // Check for ACF SEO description field (highest priority)
        $acf_description = get_field('seo_description', $post->ID);
        if ($acf_description) {
            return $acf_description;
        }
        
        // Check for ACF summary/excerpt field
        $acf_summary = get_field('summary', $post->ID) ?: get_field('excerpt', $post->ID);
        if ($acf_summary) {
            return $acf_summary;
        }
        
        return $description;
    }
    
    public static function acf_focus_keywords($keywords, $post) {
        if (!function_exists('get_field')) {
            return $keywords;
        }
        
        $acf_keywords = get_field('seo_keywords', $post->ID) ?: get_field('focus_keywords', $post->ID);
        
        if ($acf_keywords) {
            if (is_array($acf_keywords)) {
                return implode(', ', $acf_keywords);
            }
            return $acf_keywords;
        }
        
        return $keywords;
    }
    
    public static function acf_og_data($og_data, $post) {
        if (!function_exists('get_field')) {
            return $og_data;
        }
        
        // Check for ACF OG-specific fields
        $og_title = get_field('og_title', $post->ID);
        if ($og_title) {
            $og_data['title'] = $og_title;
        }
        
        $og_description = get_field('og_description', $post->ID);
        if ($og_description) {
            $og_data['description'] = $og_description;
        }
        
        $og_image = get_field('og_image', $post->ID);
        if ($og_image) {
            if (is_array($og_image)) {
                $og_data['image'] = $og_image['url'];
            } else {
                $og_data['image'] = $og_image;
            }
        }
        
        return $og_data;
    }
    
    /**
     * Elementor Integration
     * Extract content from Elementor pages
     */
    private static function init_elementor_integration() {
        self::log_integration_activity('Elementor', 'initialized', 'Page builder content extraction active');
        add_filter('auto_seo_content_extraction', array(__CLASS__, 'elementor_content_extraction'), 10, 2);
    }
    
    public static function elementor_content_extraction($content, $post) {
        if (!class_exists('\Elementor\Plugin')) {
            return $content;
        }
        
        $elementor_data = get_post_meta($post->ID, '_elementor_data', true);
        if ($elementor_data) {
            $elementor_content = self::extract_elementor_text($elementor_data);
            if ($elementor_content) {
                return $post->post_title . ' ' . $elementor_content;
            }
        }
        
        return $content;
    }
    
    private static function extract_elementor_text($elementor_data) {
        if (is_string($elementor_data)) {
            $elementor_data = json_decode($elementor_data, true);
        }
        
        $text_content = '';
        
        if (is_array($elementor_data)) {
            array_walk_recursive($elementor_data, function($value, $key) use (&$text_content) {
                if (in_array($key, array('text', 'title', 'description', 'content', 'editor')) && is_string($value)) {
                    $text_content .= ' ' . wp_strip_all_tags($value);
                }
            });
        }
        
        return trim($text_content);
    }
    
    /**
     * Beaver Builder Integration
     * Extract content from Beaver Builder layouts
     */
    private static function init_beaver_builder_integration() {
        self::log_integration_activity('Beaver Builder', 'initialized', 'Page builder content extraction active');
        add_filter('auto_seo_content_extraction', array(__CLASS__, 'beaver_builder_content_extraction'), 10, 2);
    }
    
    public static function beaver_builder_content_extraction($content, $post) {
        if (!class_exists('FLBuilder')) {
            return $content;
        }
        
        $bb_data = get_post_meta($post->ID, '_fl_builder_data', true);
        if ($bb_data && is_array($bb_data)) {
            $bb_content = self::extract_beaver_builder_text($bb_data);
            if ($bb_content) {
                return $post->post_title . ' ' . $bb_content;
            }
        }
        
        return $content;
    }
    
    private static function extract_beaver_builder_text($bb_data) {
        $text_content = '';
        
        if (is_array($bb_data)) {
            foreach ($bb_data as $item) {
                if (isset($item->settings)) {
                    foreach ($item->settings as $key => $value) {
                        if (in_array($key, array('text', 'title', 'content', 'heading')) && is_string($value)) {
                            $text_content .= ' ' . wp_strip_all_tags($value);
                        }
                    }
                }
            }
        }
        
        return trim($text_content);
    }
    
    /**
     * The Events Calendar Integration
     * Enhanced SEO for events
     */
    private static function init_events_calendar_integration() {
        self::log_integration_activity('Events Calendar', 'initialized', 'Event-specific SEO active');
        add_filter('auto_seo_title_generation', array(__CLASS__, 'events_calendar_titles'), 10, 2);
        add_filter('auto_seo_meta_description', array(__CLASS__, 'events_calendar_descriptions'), 10, 2);
    }
    
    public static function events_calendar_titles($title, $post) {
        if ($post->post_type !== 'tribe_events') {
            return $title;
        }
        
        // Add event-specific placeholders
        $event_start = get_post_meta($post->ID, '_EventStartDate', true);
        $event_venue = get_post_meta($post->ID, '_EventVenueID', true);
        
        if ($event_start) {
            $event_date = date('F j, Y', strtotime($event_start));
            $title = str_replace('%%event_date%%', $event_date, $title);
        }
        
        if ($event_venue) {
            $venue_name = get_the_title($event_venue);
            $title = str_replace('%%event_venue%%', $venue_name, $title);
        }
        
        return $title;
    }
    
    public static function events_calendar_descriptions($description, $post) {
        if ($post->post_type !== 'tribe_events') {
            return $description;
        }
        
        $event_start = get_post_meta($post->ID, '_EventStartDate', true);
        $event_venue = get_post_meta($post->ID, '_EventVenueID', true);
        
        $event_info = array();
        
        if ($event_start) {
            $event_info[] = 'Event date: ' . date('F j, Y \a\t g:i A', strtotime($event_start));
        }
        
        if ($event_venue) {
            $venue_name = get_the_title($event_venue);
            $event_info[] = 'Venue: ' . $venue_name;
        }
        
        if (!empty($event_info)) {
            $description .= ' ' . implode('. ', $event_info) . '.';
        }
        
        return $description;
    }
    
    /**
     * Multilingual Integration (WPML/Polylang)
     * Handle multiple languages properly
     */
    private static function init_multilingual_integration() {
        self::log_integration_activity('Multilingual', 'initialized', 'Multi-language SEO support active');
        add_filter('auto_seo_title_generation', array(__CLASS__, 'multilingual_titles'), 10, 2);
        add_filter('auto_seo_meta_description', array(__CLASS__, 'multilingual_descriptions'), 10, 2);
    }
    
    public static function multilingual_titles($title, $post) {
        $current_lang = '';
        
        // Get current language
        if (function_exists('pll_current_language')) {
            $current_lang = pll_current_language();
        } elseif (function_exists('icl_get_current_language')) {
            $current_lang = icl_get_current_language();
        }
        
        if ($current_lang) {
            // Add language-specific templates if available
            $lang_templates = get_option('auto_seo_lang_templates', array());
            if (isset($lang_templates[$current_lang][$post->post_type])) {
                return $lang_templates[$current_lang][$post->post_type];
            }
        }
        
        return $title;
    }
    
    public static function multilingual_descriptions($description, $post) {
        // Could add language-specific description logic here
        return $description;
    }
    
    /**
     * Gutenberg/Block Editor Integration
     * Extract content from blocks
     */
    private static function init_gutenberg_integration() {
        self::log_integration_activity('Gutenberg', 'initialized', 'Block editor content extraction active');
        add_filter('auto_seo_content_extraction', array(__CLASS__, 'gutenberg_content_extraction'), 10, 2);
    }
    
    public static function gutenberg_content_extraction($content, $post) {
        if (!function_exists('has_blocks') || !has_blocks($post->post_content)) {
            return $content;
        }
        
        $blocks = parse_blocks($post->post_content);
        $block_content = '';
        
        foreach ($blocks as $block) {
            $block_content .= self::extract_block_text($block);
        }
        
        if ($block_content) {
            return $post->post_title . ' ' . $block_content;
        }
        
        return $content;
    }
    
    private static function extract_block_text($block) {
        $text = '';
        
        // Extract text from common blocks
        if (isset($block['attrs']['content'])) {
            $text .= wp_strip_all_tags($block['attrs']['content']) . ' ';
        }
        
        if (isset($block['innerHTML']) && !empty($block['innerHTML'])) {
            $text .= wp_strip_all_tags($block['innerHTML']) . ' ';
        }
        
        // Extract from block attributes that commonly contain text
        if (isset($block['attrs'])) {
            foreach ($block['attrs'] as $key => $value) {
                if (in_array($key, array('content', 'text', 'title', 'caption', 'alt')) && is_string($value)) {
                    $text .= wp_strip_all_tags($value) . ' ';
                }
            }
        }
        
        // Recursively process inner blocks
        if (!empty($block['innerBlocks'])) {
            foreach ($block['innerBlocks'] as $inner_block) {
                $text .= self::extract_block_text($inner_block);
            }
        }
        
        return $text;
    }
    
    /**
     * Custom Post Type UI Integration
     * Support for custom post types and taxonomies
     */
    private static function init_cptui_integration() {
        self::log_integration_activity('Custom Post Type UI', 'initialized', 'Custom post type SEO support active');
        // This integration mainly provides support detection
        // The main functionality is handled by the core plugin's post type detection
    }
    
    /**
     * Add integration information to system info
     */
    public static function add_integration_info($info) {
        $integrations = array(
            'WooCommerce' => array(
                'available' => class_exists('WooCommerce'),
                'enabled' => get_option('auto_seo_integration_woocommerce', 1)
            ),
            'Advanced Custom Fields' => array(
                'available' => class_exists('ACF'),
                'enabled' => get_option('auto_seo_integration_acf', 1)
            ),
            'Elementor' => array(
                'available' => class_exists('\Elementor\Plugin'),
                'enabled' => get_option('auto_seo_integration_elementor', 1)
            ),
            'Beaver Builder' => array(
                'available' => class_exists('FLBuilder'),
                'enabled' => get_option('auto_seo_integration_beaver_builder', 1)
            ),
            'The Events Calendar' => array(
                'available' => class_exists('Tribe__Events__Main'),
                'enabled' => get_option('auto_seo_integration_events_calendar', 1)
            ),
            'WPML' => array(
                'available' => class_exists('SitePress'),
                'enabled' => get_option('auto_seo_integration_multilingual', 1)
            ),
            'Polylang' => array(
                'available' => function_exists('pll_current_language'),
                'enabled' => get_option('auto_seo_integration_multilingual', 1)
            ),
            'Gutenberg' => array(
                'available' => function_exists('register_block_type'),
                'enabled' => get_option('auto_seo_integration_gutenberg', 1)
            ),
            'Custom Post Type UI' => array(
                'available' => function_exists('cptui_get_post_type_data'),
                'enabled' => get_option('auto_seo_integration_cptui', 1)
            )
        );
        
        $info['integrations'] = $integrations;
        return $info;
    }
    
    /**
     * Log integration activity
     */
    /**
     * Integration bookkeeping.
     *
     * load_integrations() runs on every request, so these calls used to write
     * ~4 rows per page load and had grown wp_auto_seo_log to 1.9M rows / 295MB.
     * They are now classified as verbose by AutoSEOManager::entry_level(), which
     * means they are dropped at the default 'actions' level and only stored when
     * someone deliberately turns verbosity up to debug an integration.
     */
    public static function log_integration_activity($integration, $action, $details = '') {
        if (self::$main_plugin_instance && method_exists(self::$main_plugin_instance, 'log_seo_action')) {
            self::$main_plugin_instance->log_seo_action(0, 'integration_' . $action, 'info', $integration . ': ' . $details);
        }
    }
    
    /**
     * Custom integration hooks for developers
     */
    public static function register_custom_integration($plugin_name, $callback) {
        add_action('auto_seo_custom_integration_' . sanitize_key($plugin_name), $callback);
        do_action('auto_seo_custom_integration_' . sanitize_key($plugin_name));
    }
    
    /**
     * Schema markup for enhanced SEO
     */
    public static function woocommerce_schema_markup() {
        if (!is_product() || !function_exists('wc_get_product')) {
            return;
        }
        
        global $product;
        if (!$product) {
            $product = wc_get_product(get_the_ID());
        }
        
        if (!$product) {
            return;
        }
        
        $schema = array(
            '@context' => 'https://schema.org/',
            '@type' => 'Product',
            'name' => $product->get_name(),
            'description' => wp_strip_all_tags($product->get_description()),
            'sku' => $product->get_sku()
        );
        
        // Add offers
        $schema['offers'] = array(
            '@type' => 'Offer',
            'price' => $product->get_price(),
            'priceCurrency' => get_woocommerce_currency(),
            'availability' => $product->is_in_stock() ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
            'url' => get_permalink($product->get_id())
        );
        
        // Add product image
        $image_id = $product->get_image_id();
        if ($image_id) {
            $schema['image'] = wp_get_attachment_url($image_id);
        }
        
        // Add ratings if available
        $rating_count = $product->get_rating_count();
        $average_rating = $product->get_average_rating();
        
        if ($rating_count > 0 && $average_rating > 0) {
            $schema['aggregateRating'] = array(
                '@type' => 'AggregateRating',
                'ratingValue' => $average_rating,
                'reviewCount' => $rating_count
            );
        }
        
        // Add brand if available
        $brand_terms = wp_get_post_terms($product->get_id(), 'product_brand');
        if (!is_wp_error($brand_terms) && !empty($brand_terms)) {
            $schema['brand'] = array(
                '@type' => 'Brand',
                'name' => $brand_terms[0]->name
            );
        }
        
        // Add category
        $categories = wp_get_post_terms($product->get_id(), 'product_cat', array('fields' => 'names'));
        if (!is_wp_error($categories) && !empty($categories)) {
            $schema['category'] = $categories[0];
        }
        
        // Output the schema
        echo '<script type="application/ld+json">' . wp_json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>' . "\n";
    }
    
    /**
     * Get main plugin instance for use by integrations
     */
    public static function get_main_plugin_instance() {
        return self::$main_plugin_instance;
    }
    
    /**
     * Check if a specific integration is active and available
     */
    public static function is_integration_active($integration_key) {
        $option_key = 'auto_seo_integration_' . $integration_key;
        $is_enabled = get_option($option_key, 1);
        
        if (!$is_enabled) {
            return false;
        }
        
        // Check if the plugin is actually available
        switch ($integration_key) {
            case 'woocommerce':
                return class_exists('WooCommerce');
            case 'acf':
                return class_exists('ACF');
            case 'elementor':
                return class_exists('\Elementor\Plugin');
            case 'beaver_builder':
                return class_exists('FLBuilder');
            case 'events_calendar':
                return class_exists('Tribe__Events__Main');
            case 'multilingual':
                return function_exists('pll_current_language') || class_exists('SitePress');
            case 'gutenberg':
                return function_exists('register_block_type');
            case 'cptui':
                return function_exists('cptui_get_post_type_data');
            default:
                return false;
        }
    }
}

/**
 * Developer API for custom integrations
 */

/**
 * Register a custom integration
 * 
 * @param string $plugin_name Name of the plugin to integrate with
 * @param callable $callback Function to run when plugin is detected
 */
function auto_seo_register_integration($plugin_name, $callback) {
    AutoSEOIntegrations::register_custom_integration($plugin_name, $callback);
}

/**
 * Check if an integration is active
 * 
 * @param string $integration_key Integration key to check
 * @return bool Whether the integration is active and available
 */
function auto_seo_is_integration_active($integration_key) {
    return AutoSEOIntegrations::is_integration_active($integration_key);
}

/**
 * Get the main plugin instance
 * 
 * @return AutoSEOManager|null Main plugin instance
 */
function auto_seo_get_main_instance() {
    return AutoSEOIntegrations::get_main_plugin_instance();
}

/**
 * Example custom integration usage:
 * 
 * auto_seo_register_integration('my_plugin', function() {
 *     if (class_exists('MyPlugin')) {
 *         add_filter('auto_seo_title_generation', 'my_custom_title_function', 10, 2);
 *         add_filter('auto_seo_meta_description', 'my_custom_description_function', 10, 2);
 *     }
 * });
 */