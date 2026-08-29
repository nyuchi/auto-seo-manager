<?php
/**
 * Plugin Name: Nyuchi WordPress Optimization
 * Plugin URI: https://github.com/nyuchi/auto-seo-manager
 * Description: SEO, metadata, and database cleaning and editing. Automates Yoast SEO fields, reports what is costing the database, and exposes the lot to the REST API and to MCP clients. By Nyuchi Web Services.
 * Version: 1.4.0
 * Author: Nyuchi Web Services
 * Author URI: https://nyuchi.com
 * Developer: Bryan Fawcett (@bryanfawcett)
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: nyuchi-wp-optimization
 * Requires at least: 5.0
 * Tested up to: 7.0
 * Requires PHP: 7.4
 * Network: false
 * 
 * @package AutoSEOManager
 * @author Nyuchi Web Services
 * @since 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

define('AUTO_SEO_VERSION', '1.4.0');
define('AUTO_SEO_DB_VERSION', 2);

class AutoSEOManager {

    private $plugin_name = 'auto-seo-manager';
    private $product_name = 'Nyuchi WordPress Optimization';
    private $version = AUTO_SEO_VERSION;
    private $table_name;

    /**
     * Log verbosity ladder. A message is written only when its own level is
     * less than or equal to the configured level, so 'off' silences everything
     * and 'verbose' keeps per-request integration chatter.
     */
    const LOG_LEVELS = array(
        'off'     => 0,
        'errors'  => 1,
        'actions' => 2,
        'verbose' => 3,
    );

    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'auto_seo_log';

        add_action('init', array($this, 'init'));
        add_action('admin_init', array($this, 'schedule_seo_updates'));
        add_action('daily_seo_update', array($this, 'execute_seo_updates'));
        add_action('weekly_seo_audit', array($this, 'execute_seo_audit'));
        add_action('auto_seo_prune_log', array($this, 'prune_log'));
        add_action('template_redirect', array($this, 'handle_seo_cron_request'));
        add_action('wp_head', array($this, 'output_additional_meta_tags'));

        // Admin hooks
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('wp_ajax_manual_seo_update', array($this, 'manual_seo_update'));
        add_action('admin_init', array($this, 'maybe_upgrade_db'));

        // Saves must run before the admin header is emitted. Handling them
        // inside the page callback means wp_safe_redirect() is called after
        // output has already started, so the Location header is dropped and the
        // bare exit truncates the page — which renders as a blank screen.
        add_action('admin_init', array($this, 'maybe_save_settings'));

        // REST API, so the plugin can be driven by MCP clients and other automation
        add_action('rest_api_init', array($this, 'register_rest_routes'));

        // Activation/Deactivation hooks
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));

        // Load integrations after plugins are loaded
        add_action('plugins_loaded', array($this, 'load_integrations'));
    }
    
    public function init() {
        // Add custom rewrite rule for external cron
        add_rewrite_rule(
            'auto-seo-cron/([^/]+)/?$',
            'index.php?auto_seo_cron=1&cron_key=$matches[1]',
            'top'
        );
        add_rewrite_tag('%auto_seo_cron%', '([^&]+)');
        add_rewrite_tag('%cron_key%', '([^&]+)');
    }
    
    public function load_integrations() {
        // Load integrations file if it exists
        $integrations_file = plugin_dir_path(__FILE__) . 'integrations.php';
        if (file_exists($integrations_file)) {
            require_once $integrations_file;
            
            // Initialize integrations with reference to this main class
            if (class_exists('AutoSEOIntegrations')) {
                AutoSEOIntegrations::init($this);
            }
        }
    }
    
    public function activate() {
        $this->create_log_table();
        $this->set_default_options();
        $this->schedule_seo_updates();
        update_option('auto_seo_db_version', AUTO_SEO_DB_VERSION);
        flush_rewrite_rules();
    }

    public function deactivate() {
        wp_clear_scheduled_hook('daily_seo_update');
        wp_clear_scheduled_hook('weekly_seo_audit');
        wp_clear_scheduled_hook('auto_seo_prune_log');
    }

    private function create_log_table() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        // id is bigint: the original mediumint(9) topped out at 8,388,607 rows,
        // which a chatty log reaches faster than you would expect.
        $sql = "CREATE TABLE {$this->table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            post_id bigint(20) NOT NULL,
            action varchar(50) NOT NULL,
            status varchar(20) NOT NULL,
            details text,
            timestamp datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY post_id (post_id),
            KEY timestamp (timestamp)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /**
     * Run schema changes for sites that installed before the current DB version.
     * dbDelta only runs on activation, so an already-installed site never picks
     * up column changes without this.
     */
    public function maybe_upgrade_db() {
        $installed = (int) get_option('auto_seo_db_version', 1);

        if ($installed >= AUTO_SEO_DB_VERSION) {
            return;
        }

        global $wpdb;

        if ($installed < 2) {
            // Widen the id column away from mediumint's 8.4M ceiling.
            $wpdb->query("ALTER TABLE {$this->table_name} MODIFY id bigint(20) unsigned NOT NULL AUTO_INCREMENT");

            // The master switch was written two different ways: set_default_options()
            // stored 'auto_seo_enabled' while save_basic_settings() stored
            // 'auto_seo_auto_seo_enabled', and the reader used the double-prefixed
            // one. Fold the double-prefixed value back onto the canonical name so
            // whatever the site actually had configured survives.
            $legacy = get_option('auto_seo_auto_seo_enabled', null);
            if (null !== $legacy) {
                update_option('auto_seo_enabled', $legacy);
                delete_option('auto_seo_auto_seo_enabled');
            }
        }

        update_option('auto_seo_db_version', AUTO_SEO_DB_VERSION);
    }
    
    private function set_default_options() {
        $default_options = array(
            'auto_seo_enabled' => 1,
            'cron_secret_key' => wp_generate_password(32, false),
            'post_types' => array('post', 'page'),
            'title_templates' => array(
                'post' => '%%title%% | Expert Guide | %%sitename%%',
                'page' => '%%title%% | %%sitename%%',
                'product' => '%%title%% - Buy Online | %%sitename%%'
            ),
            'auto_meta_description' => 1,
            'auto_focus_keywords' => 1,
            'audit_email' => get_option('admin_email'),
            'max_description_length' => 155,
            // Logging. 'actions' keeps the meaningful record (title/description
            // updates, bulk runs, audits) without the per-request integration
            // chatter that lives at 'verbose'.
            'log_level' => 'actions',
            'log_retention_days' => 30,
            'log_max_rows' => 20000,
            'log_pruning_enabled' => 1,
            // Scheduled work
            'daily_updates_enabled' => 1,
            'weekly_audit_enabled' => 1,
            'audit_email_enabled' => 1,
            // Meta tag options
            'site_author' => get_bloginfo('name'),
            'google_site_verification' => '',
            'twitter_username' => '',
            'default_og_image' => '',
            'robots_default' => 'index, follow',
            'auto_generate_keywords' => 1,
            'auto_og_tags' => 1,
            'auto_twitter_cards' => 1,
            'auto_additional_meta' => 1,
            // Integration settings - default enabled
            'integration_woocommerce' => 1,
            'integration_acf' => 1,
            'integration_elementor' => 1,
            'integration_beaver_builder' => 1,
            'integration_events_calendar' => 1,
            'integration_multilingual' => 1,
            'integration_gutenberg' => 1,
            'integration_cptui' => 1
        );
        
        foreach ($default_options as $key => $value) {
            $option_name = strpos($key, 'auto_seo_') === 0 ? $key : 'auto_seo_' . $key;
            if (!get_option($option_name)) {
                update_option($option_name, $value);
            }
        }
    }
    
    /**
     * Keep the cron schedule in step with the toggles.
     *
     * Previously hooked to `wp`, which meant every front-end request re-checked
     * the schedule. admin_init plus activation is enough, and each event is only
     * registered while its own toggle is on.
     */
    public function schedule_seo_updates() {
        $wanted = array(
            'daily_seo_update'  => array('daily',  'auto_seo_daily_updates_enabled'),
            'weekly_seo_audit'  => array('weekly', 'auto_seo_weekly_audit_enabled'),
            'auto_seo_prune_log' => array('daily', 'auto_seo_log_pruning_enabled'),
        );

        foreach ($wanted as $hook => $config) {
            list($recurrence, $option) = $config;

            $enabled   = (bool) get_option($option, 1);
            $scheduled = (bool) wp_next_scheduled($hook);

            if ($enabled && !$scheduled) {
                wp_schedule_event(time(), $recurrence, $hook);
            } elseif (!$enabled && $scheduled) {
                wp_clear_scheduled_hook($hook);
            }
        }
    }
    
    public function execute_seo_updates() {
        if (!get_option('auto_seo_enabled', 1)) {
            return;
        }
        
        $post_types = get_option('auto_seo_post_types', array('post', 'page'));
        $batch_size = 50;
        $offset = 0;
        $total_updated = 0;
        
        do {
            $posts = get_posts(array(
                'post_type' => $post_types,
                'numberposts' => $batch_size,
                'offset' => $offset,
                'post_status' => 'publish'
            ));
            
            foreach ($posts as $post) {
                $updated = $this->update_post_seo_data($post->ID);
                if ($updated) {
                    $total_updated++;
                }
            }
            
            $offset += $batch_size;
            
        } while (count($posts) === $batch_size);
        
        $this->log_seo_action(0, 'bulk_update', 'completed', "Updated {$total_updated} posts");
    }
    
    public function update_post_seo_data($post_id) {
        if (!$this->is_yoast_active()) {
            return false;
        }
        
        $post = get_post($post_id);
        if (!$post) {
            return false;
        }
        
        $updated = false;
        
        // Update title if empty
        $current_title = get_post_meta($post_id, '_yoast_wpseo_title', true);
        if (empty($current_title)) {
            $new_title = $this->generate_seo_title($post);
            update_post_meta($post_id, '_yoast_wpseo_title', $new_title);
            $this->log_seo_action($post_id, 'title_update', 'success', 'Generated SEO title');
            $updated = true;
        }
        
        // Update meta description if enabled and empty
        if (get_option('auto_seo_auto_meta_description')) {
            $current_desc = get_post_meta($post_id, '_yoast_wpseo_metadesc', true);
            if (empty($current_desc)) {
                $new_desc = $this->generate_meta_description($post);
                update_post_meta($post_id, '_yoast_wpseo_metadesc', $new_desc);
                $this->log_seo_action($post_id, 'description_update', 'success', 'Generated meta description');
                $updated = true;
            }
        }
        
        // Update focus keywords if enabled
        if (get_option('auto_seo_auto_focus_keywords')) {
            $current_keyword = get_post_meta($post_id, '_yoast_wpseo_focuskw', true);
            if (empty($current_keyword)) {
                $keyword = $this->extract_focus_keyword($post);
                if ($keyword) {
                    update_post_meta($post_id, '_yoast_wpseo_focuskw', $keyword);
                    $this->log_seo_action($post_id, 'keyword_update', 'success', 'Set focus keyword: ' . $keyword);
                    $updated = true;
                }
            }
        }
        
        // Update additional meta tags
        if (get_option('auto_seo_auto_additional_meta')) {
            $this->update_additional_meta_tags($post_id);
            $updated = true;
        }
        
        return $updated;
    }
    
    public function generate_seo_title($post) {
        $templates = get_option('auto_seo_title_templates', array());
        $template = isset($templates[$post->post_type]) ? 
                   $templates[$post->post_type] : 
                   '%%title%% | %%sitename%%';
        
        // Allow integrations to modify title generation
        $template = apply_filters('auto_seo_title_generation', $template, $post);
        
        // Replace placeholders
        $title = str_replace('%%title%%', $post->post_title, $template);
        $title = str_replace('%%sitename%%', get_bloginfo('name'), $title);
        $title = str_replace('%%currentdate%%', date('Y'), $title);
        
        return $title;
    }
    
    public function generate_meta_description($post) {
        $max_length = get_option('auto_seo_max_description_length', 155);
        
        // Try excerpt first
        if (has_excerpt($post->ID)) {
            $description = get_the_excerpt($post->ID);
        } else {
            // Get content for integrations to process
            $content = wp_strip_all_tags($post->post_content);
            
            // Allow integrations to modify content extraction
            $content = apply_filters('auto_seo_content_extraction', $content, $post);
            
            $content = preg_replace('/\s+/', ' ', $content);
            
            // Find first meaningful sentence
            $sentences = explode('.', $content);
            $description = '';
            
            foreach ($sentences as $sentence) {
                $sentence = trim($sentence);
                if (strlen($sentence) > 20) {
                    $description = $sentence . '.';
                    break;
                }
            }
            
            // Fallback to first N characters
            if (empty($description)) {
                $description = substr($content, 0, $max_length - 3) . '...';
            }
        }
        
        // Allow integrations to modify the final description
        $description = apply_filters('auto_seo_meta_description', $description, $post);
        
        // Ensure proper length
        if (strlen($description) > $max_length) {
            $description = substr($description, 0, $max_length - 3) . '...';
        }
        
        return trim($description);
    }
    
    public function extract_focus_keyword($post) {
        $content = strtolower($post->post_title . ' ' . wp_strip_all_tags($post->post_content));
        $words = str_word_count($content, 1);
        
        // Remove common stop words
        $stop_words = array('the', 'a', 'an', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for', 'of', 'with', 'by', 'is', 'are', 'was', 'were', 'be', 'been', 'have', 'has', 'had', 'do', 'does', 'did', 'will', 'would', 'could', 'should');
        $words = array_diff($words, $stop_words);
        
        // Count word frequency
        $word_count = array_count_values($words);
        arsort($word_count);
        
        // Allow integrations to modify keyword extraction
        $keywords = apply_filters('auto_seo_focus_keywords', array_keys($word_count), $post);
        
        // Return most frequent word that's longer than 3 characters
        if (is_array($keywords)) {
            foreach ($keywords as $word) {
                if (is_string($word) && strlen($word) > 3) {
                    return $word;
                }
            }
        } else if (is_string($keywords)) {
            return $keywords;
        }
        
        return '';
    }
    
    private function update_additional_meta_tags($post_id) {
        $post = get_post($post_id);
        if (!$post) return;
        
        // Generate and store keywords if enabled
        if (get_option('auto_seo_auto_generate_keywords')) {
            $existing_keywords = get_post_meta($post_id, '_auto_seo_keywords', true);
            if (empty($existing_keywords)) {
                $keywords = $this->generate_meta_keywords($post);
                update_post_meta($post_id, '_auto_seo_keywords', $keywords);
            }
        }
        
        // Store robots directive
        $existing_robots = get_post_meta($post_id, '_auto_seo_robots', true);
        if (empty($existing_robots)) {
            $robots = get_option('auto_seo_robots_default', 'index, follow');
            update_post_meta($post_id, '_auto_seo_robots', $robots);
        }
        
        // Generate Open Graph data
        if (get_option('auto_seo_auto_og_tags')) {
            $this->update_og_meta_tags($post_id);
        }
        
        // Generate Twitter Card data
        if (get_option('auto_seo_auto_twitter_cards')) {
            $this->update_twitter_meta_tags($post_id);
        }
    }
    
    private function generate_meta_keywords($post) {
        $content = strtolower($post->post_title . ' ' . wp_strip_all_tags($post->post_content));
        $words = str_word_count($content, 1);
        
        // Enhanced stop words
        $stop_words = array('the', 'a', 'an', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for', 'of', 'with', 'by', 'is', 'are', 'was', 'were', 'be', 'been', 'have', 'has', 'had', 'do', 'does', 'did', 'will', 'would', 'could', 'should', 'this', 'that', 'these', 'those');
        
        // Filter words
        $filtered_words = array_filter($words, function($word) use ($stop_words) {
            return strlen($word) >= 3 && !in_array($word, $stop_words) && !is_numeric($word);
        });
        
        // Count frequencies
        $word_count = array_count_values($filtered_words);
        arsort($word_count);
        
        // Get top keywords
        $keywords = array_slice(array_keys($word_count), 0, 10);
        
        // Add categories and tags as keywords
        $categories = wp_get_post_categories($post->ID, array('fields' => 'names'));
        $tags = wp_get_post_tags($post->ID, array('fields' => 'names'));
        
        $keywords = array_merge($keywords, array_map('strtolower', $categories), array_map('strtolower', $tags));
        $keywords = array_unique($keywords);
        
        return implode(', ', array_slice($keywords, 0, 15));
    }
    
    private function update_og_meta_tags($post_id) {
        $post = get_post($post_id);
        
        // Allow integrations to modify OG data
        $og_data = apply_filters('auto_seo_og_data', array(), $post);
        
        // OG Title
        $og_title = get_post_meta($post_id, '_auto_seo_og_title', true);
        if (empty($og_title)) {
            $title = isset($og_data['title']) ? $og_data['title'] : get_post_meta($post_id, '_yoast_wpseo_title', true);
            if (empty($title)) {
                $title = $post->post_title;
            }
            update_post_meta($post_id, '_auto_seo_og_title', $title);
        }
        
        // OG Description
        $og_description = get_post_meta($post_id, '_auto_seo_og_description', true);
        if (empty($og_description)) {
            $description = isset($og_data['description']) ? $og_data['description'] : get_post_meta($post_id, '_yoast_wpseo_metadesc', true);
            if (empty($description)) {
                $description = $this->generate_meta_description($post);
            }
            update_post_meta($post_id, '_auto_seo_og_description', $description);
        }
        
        // OG Image
        $og_image = get_post_meta($post_id, '_auto_seo_og_image', true);
        if (empty($og_image)) {
            $image = isset($og_data['image']) ? $og_data['image'] : $this->get_post_featured_image($post_id);
            if ($image) {
                update_post_meta($post_id, '_auto_seo_og_image', $image);
            }
        }
        
        // OG URL
        $og_url = get_post_meta($post_id, '_auto_seo_og_url', true);
        if (empty($og_url)) {
            update_post_meta($post_id, '_auto_seo_og_url', get_permalink($post_id));
        }
        
        // OG Type
        $og_type = get_post_meta($post_id, '_auto_seo_og_type', true);
        if (empty($og_type)) {
            $type = isset($og_data['type']) ? $og_data['type'] : (($post->post_type === 'post') ? 'article' : 'website');
            update_post_meta($post_id, '_auto_seo_og_type', $type);
        }
    }
    
    private function update_twitter_meta_tags($post_id) {
        $post = get_post($post_id);
        
        // Twitter Card Type
        $twitter_card = get_post_meta($post_id, '_auto_seo_twitter_card', true);
        if (empty($twitter_card)) {
            $card_type = $this->get_post_featured_image($post_id) ? 'summary_large_image' : 'summary';
            update_post_meta($post_id, '_auto_seo_twitter_card', $card_type);
        }
        
        // Twitter Title
        $twitter_title = get_post_meta($post_id, '_auto_seo_twitter_title', true);
        if (empty($twitter_title)) {
            $title = get_post_meta($post_id, '_yoast_wpseo_title', true);
            if (empty($title)) {
                $title = $post->post_title;
            }
            update_post_meta($post_id, '_auto_seo_twitter_title', $title);
        }
        
        // Twitter Description
        $twitter_description = get_post_meta($post_id, '_auto_seo_twitter_description', true);
        if (empty($twitter_description)) {
            $description = get_post_meta($post_id, '_yoast_wpseo_metadesc', true);
            if (empty($description)) {
                $description = $this->generate_meta_description($post);
            }
            update_post_meta($post_id, '_auto_seo_twitter_description', $description);
        }
        
        // Twitter Image
        $twitter_image = get_post_meta($post_id, '_auto_seo_twitter_image', true);
        if (empty($twitter_image)) {
            $image = $this->get_post_featured_image($post_id);
            if ($image) {
                update_post_meta($post_id, '_auto_seo_twitter_image', $image);
            }
        }
    }
    
    private function get_post_featured_image($post_id) {
        if (has_post_thumbnail($post_id)) {
            return get_the_post_thumbnail_url($post_id, 'large');
        }
        
        // Fallback to default OG image
        $default_image = get_option('auto_seo_default_og_image');
        if ($default_image) {
            return $default_image;
        }
        
        // Extract first image from content
        $post = get_post($post_id);
        $content = $post->post_content;
        preg_match('/<img[^>]+src="([^">]+)"/', $content, $matches);
        
        return isset($matches[1]) ? $matches[1] : '';
    }
    
    public function output_additional_meta_tags() {
        if (!is_singular()) {
            $this->output_global_meta_tags();
            return;
        }
        
        global $post;
        if (!$post) return;
        
        $post_id = $post->ID;
        
        // Basic meta tags
        $this->output_basic_meta_tags($post_id);
        
        // Open Graph tags
        if (get_option('auto_seo_auto_og_tags')) {
            $this->output_og_meta_tags($post_id);
        }
        
        // Twitter Card tags
        if (get_option('auto_seo_auto_twitter_cards')) {
            $this->output_twitter_meta_tags($post_id);
        }
    }
    
    private function output_global_meta_tags() {
        // Global meta tags for non-singular pages
        $author = get_option('auto_seo_site_author');
        $google_verification = get_option('auto_seo_google_site_verification');
        $twitter_site = get_option('auto_seo_twitter_username');
        
        if ($author) {
            echo '<meta name="author" content="' . esc_attr($author) . '">' . "\n";
        }
        
        if ($google_verification) {
            echo '<meta name="google-site-verification" content="' . esc_attr($google_verification) . '">' . "\n";
        }
        
        echo '<meta name="robots" content="index, follow">' . "\n";
        
        if ($twitter_site) {
            echo '<meta name="twitter:site" content="@' . esc_attr(ltrim($twitter_site, '@')) . '">' . "\n";
        }
    }
    
    private function output_basic_meta_tags($post_id) {
        // Keywords
        $keywords = get_post_meta($post_id, '_auto_seo_keywords', true);
        if ($keywords) {
            echo '<meta name="keywords" content="' . esc_attr($keywords) . '">' . "\n";
        }
        
        // Author
        $author = get_option('auto_seo_site_author');
        if ($author) {
            echo '<meta name="author" content="' . esc_attr($author) . '">' . "\n";
        }
        
        // Robots
        $robots = get_post_meta($post_id, '_auto_seo_robots', true);
        if ($robots) {
            echo '<meta name="robots" content="' . esc_attr($robots) . '">' . "\n";
        }
        
        // Google Site Verification (only on homepage)
        if (is_home() || is_front_page()) {
            $google_verification = get_option('auto_seo_google_site_verification');
            if ($google_verification) {
                echo '<meta name="google-site-verification" content="' . esc_attr($google_verification) . '">' . "\n";
            }
        }
    }
    
    private function output_og_meta_tags($post_id) {
        $og_title = get_post_meta($post_id, '_auto_seo_og_title', true);
        $og_description = get_post_meta($post_id, '_auto_seo_og_description', true);
        $og_image = get_post_meta($post_id, '_auto_seo_og_image', true);
        $og_url = get_post_meta($post_id, '_auto_seo_og_url', true);
        $og_type = get_post_meta($post_id, '_auto_seo_og_type', true);
        
        if ($og_title) {
            echo '<meta property="og:title" content="' . esc_attr($og_title) . '">' . "\n";
        }
        
        if ($og_description) {
            echo '<meta property="og:description" content="' . esc_attr($og_description) . '">' . "\n";
        }
        
        if ($og_image) {
            echo '<meta property="og:image" content="' . esc_url($og_image) . '">' . "\n";
        }
        
        if ($og_url) {
            echo '<meta property="og:url" content="' . esc_url($og_url) . '">' . "\n";
        }
        
        if ($og_type) {
            echo '<meta property="og:type" content="' . esc_attr($og_type) . '">' . "\n";
        }
        
        echo '<meta property="og:site_name" content="' . esc_attr(get_bloginfo('name')) . '">' . "\n";
    }
    
    private function output_twitter_meta_tags($post_id) {
        $twitter_card = get_post_meta($post_id, '_auto_seo_twitter_card', true);
        $twitter_title = get_post_meta($post_id, '_auto_seo_twitter_title', true);
        $twitter_description = get_post_meta($post_id, '_auto_seo_twitter_description', true);
        $twitter_image = get_post_meta($post_id, '_auto_seo_twitter_image', true);
        $twitter_site = get_option('auto_seo_twitter_username');
        
        if ($twitter_card) {
            echo '<meta name="twitter:card" content="' . esc_attr($twitter_card) . '">' . "\n";
        }
        
        if ($twitter_title) {
            echo '<meta name="twitter:title" content="' . esc_attr($twitter_title) . '">' . "\n";
        }
        
        if ($twitter_description) {
            echo '<meta name="twitter:description" content="' . esc_attr($twitter_description) . '">' . "\n";
        }
        
        if ($twitter_image) {
            echo '<meta name="twitter:image" content="' . esc_url($twitter_image) . '">' . "\n";
        }
        
        if ($twitter_site) {
            echo '<meta name="twitter:site" content="@' . esc_attr(ltrim($twitter_site, '@')) . '">' . "\n";
        }
    }
    
    public function execute_seo_audit() {
        $issues = array();
        $post_types = get_option('auto_seo_post_types', array('post', 'page'));
        
        // Check for missing titles
        $posts_no_title = get_posts(array(
            'post_type' => $post_types,
            'meta_query' => array(
                array(
                    'key' => '_yoast_wpseo_title',
                    'compare' => 'NOT EXISTS'
                )
            ),
            'fields' => 'ids',
            'numberposts' => -1
        ));
        
        if (!empty($posts_no_title)) {
            $issues[] = count($posts_no_title) . ' posts missing SEO titles';
        }
        
        // Check for missing descriptions
        $posts_no_desc = get_posts(array(
            'post_type' => $post_types,
            'meta_query' => array(
                array(
                    'key' => '_yoast_wpseo_metadesc',
                    'compare' => 'NOT EXISTS'
                )
            ),
            'fields' => 'ids',
            'numberposts' => -1
        ));
        
        if (!empty($posts_no_desc)) {
            $issues[] = count($posts_no_desc) . ' posts missing meta descriptions';
        }
        
        // Check for missing focus keywords
        $posts_no_keywords = get_posts(array(
            'post_type' => $post_types,
            'meta_query' => array(
                array(
                    'key' => '_yoast_wpseo_focuskw',
                    'compare' => 'NOT EXISTS'
                )
            ),
            'fields' => 'ids',
            'numberposts' => -1
        ));
        
        if (!empty($posts_no_keywords)) {
            $issues[] = count($posts_no_keywords) . ' posts missing focus keywords';
        }
        
        // Send audit report
        if (!empty($issues)) {
            $email = get_option('auto_seo_audit_email');
            if ($email) {
                $subject = 'Auto SEO Manager - Weekly Audit Report';
                $message = "SEO Audit Issues Found:\n\n" . implode("\n", $issues);
                $message .= "\n\nRun the Auto SEO Manager to fix these issues automatically.";
                
                wp_mail($email, $subject, $message);
            }
        }
        
        $this->log_seo_action(0, 'audit', 'completed', count($issues) . ' issues found');
    }
    
    public function handle_seo_cron_request() {
        $expected_key = (string) get_option('auto_seo_cron_secret_key');

        if (get_query_var('auto_seo_cron') && '' !== $expected_key &&
            hash_equals($expected_key, (string) get_query_var('cron_key'))) {
            
            $this->execute_seo_updates();
            
            wp_send_json(array(
                'status' => 'success',
                'message' => 'SEO updates completed',
                'timestamp' => current_time('mysql')
            ));
        }
    }
    
    public function manual_seo_update() {
        // Verify nonce. isset() first: a missing key was a notice on every
        // malformed request, and wp_verify_nonce(null) is not a rejection path
        // worth relying on.
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field($_POST['nonce']), 'auto_seo_nonce')) {
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Access denied');
            return;
        }
        
        $this->execute_seo_updates();
        
        wp_send_json_success('SEO update completed successfully');
    }
    
    /**
     * Current log verbosity as an integer from the LOG_LEVELS ladder.
     */
    public function get_log_level() {
        $level = get_option('auto_seo_log_level', 'actions');

        return isset(self::LOG_LEVELS[$level]) ? self::LOG_LEVELS[$level] : self::LOG_LEVELS['actions'];
    }

    /**
     * Verbosity an individual entry needs before it is worth storing.
     *
     * Anything that failed is an error. Per-request integration bookkeeping is
     * verbose-only — it fires on every page load, so at any lower level it is
     * pure write amplification against the log table.
     */
    private function entry_level($action, $status) {
        if (in_array($status, array('error', 'failed', 'failure'), true)) {
            return self::LOG_LEVELS['errors'];
        }

        if (strpos($action, 'integration_') === 0 || 'info' === $status) {
            return self::LOG_LEVELS['verbose'];
        }

        return self::LOG_LEVELS['actions'];
    }

    public function log_seo_action($post_id, $action, $status, $details = '') {
        if ($this->entry_level($action, $status) > $this->get_log_level()) {
            return;
        }

        global $wpdb;

        $wpdb->insert(
            $this->table_name,
            array(
                'post_id' => $post_id,
                'action' => $action,
                'status' => $status,
                'details' => $details,
                'timestamp' => current_time('mysql')
            ),
            array('%d', '%s', '%s', '%s', '%s')
        );
    }

    /**
     * Trim the log to the configured retention window and row cap.
     *
     * Deletes run in bounded batches so a log that has been left to grow for
     * years cannot hold a write lock on the table for the whole cleanup.
     */
    public function prune_log($batch_limit = 5000, $max_batches = 20) {
        global $wpdb;

        $deleted = 0;
        $days    = (int) get_option('auto_seo_log_retention_days', 30);
        $max     = (int) get_option('auto_seo_log_max_rows', 20000);

        if ($days > 0) {
            for ($i = 0; $i < $max_batches; $i++) {
                $rows = $wpdb->query($wpdb->prepare(
                    "DELETE FROM {$this->table_name}
                     WHERE timestamp < DATE_SUB(NOW(), INTERVAL %d DAY)
                     LIMIT %d",
                    $days,
                    $batch_limit
                ));

                $deleted += (int) $rows;

                if ($rows < $batch_limit) {
                    break;
                }
            }
        }

        if ($max > 0) {
            $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name}");

            if ($total > $max) {
                // Find the id of the newest row we intend to keep, then delete
                // everything older in one indexed range scan.
                $cutoff_id = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$this->table_name} ORDER BY id DESC LIMIT 1 OFFSET %d",
                    $max - 1
                ));

                if ($cutoff_id > 0) {
                    for ($i = 0; $i < $max_batches; $i++) {
                        $rows = $wpdb->query($wpdb->prepare(
                            "DELETE FROM {$this->table_name} WHERE id < %d LIMIT %d",
                            $cutoff_id,
                            $batch_limit
                        ));

                        $deleted += (int) $rows;

                        if ($rows < $batch_limit) {
                            break;
                        }
                    }
                }
            }
        }

        return $deleted;
    }

    /**
     * Drop every log row and reset the auto-increment counter.
     */
    public function purge_log() {
        global $wpdb;

        $wpdb->query("TRUNCATE TABLE {$this->table_name}");
    }

    /**
     * Row count and on-disk size, for the admin screen.
     */
    public function get_log_stats() {
        global $wpdb;

        $rows = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name}");

        $size = (float) $wpdb->get_var($wpdb->prepare(
            "SELECT ROUND((data_length + index_length) / 1024 / 1024, 1)
             FROM information_schema.TABLES
             WHERE table_schema = DATABASE() AND table_name = %s",
            $this->table_name
        ));

        $oldest = $wpdb->get_var("SELECT MIN(timestamp) FROM {$this->table_name}");

        return array(
            'rows'    => $rows,
            'size_mb' => $size,
            'oldest'  => $oldest,
            'level'   => get_option('auto_seo_log_level', 'actions'),
        );
    }
    
    public function is_yoast_active() {
        return class_exists('WPSEO_Options');
    }
    
    /**
     * Top-level menu rather than a Settings sub-item — this is a daily-driver
     * screen, not a one-time configuration page.
     */
    public function add_admin_menu() {
        add_menu_page(
            'Nyuchi WordPress Optimization',
            'Optimization',
            'manage_options',
            'auto-seo-manager',
            array($this, 'admin_page'),
            'dashicons-chart-line',
            58
        );

        $subpages = array(
            'settings'     => 'Settings',
            'integrations' => 'Integrations',
            'logs'         => 'Activity Log',
            'tools'        => 'Tools',
        );

        foreach ($subpages as $tab => $label) {
            add_submenu_page(
                'auto-seo-manager',
                'Optimization — ' . $label,
                $label,
                'manage_options',
                'auto-seo-manager' . ('settings' === $tab ? '' : '&tab=' . $tab),
                array($this, 'admin_page')
            );
        }

        // The auto-added first submenu repeats the parent title; relabel it.
        global $submenu;
        if (isset($submenu['auto-seo-manager'][0][0])) {
            $submenu['auto-seo-manager'][0][0] = 'Settings';
        }
    }

    /**
     * Admin screen URL for a given tab. Centralised because the menu moved out
     * of options-general.php and every link had to follow.
     */
    public static function admin_url_for($tab = 'settings') {
        $url = admin_url('admin.php?page=auto-seo-manager');

        return 'settings' === $tab ? $url : $url . '&tab=' . $tab;
    }

    public function enqueue_admin_scripts($hook) {
        if (strpos($hook, 'auto-seo-manager') === false) {
            return;
        }

        wp_enqueue_script('jquery');
    }
    
    /**
     * Handle a settings POST on admin_init, before anything is rendered.
     */
    public function maybe_save_settings() {
        // isset(), not empty(). The save buttons carry a name but no value, so
        // a browser submits submit="" - an empty string, which empty() treats
        // as absent. Testing with empty() here silently discarded every save
        // from the Settings, Integrations and Logs tabs while Tools, which
        // posts a hidden submit=1, carried on working.
        if (!isset($_POST['submit']) || !isset($_POST['tab'])) {
            return;
        }

        // Only our own screen. Other admin pages post 'submit' constantly.
        $page = isset($_REQUEST['page']) ? sanitize_key($_REQUEST['page']) : '';

        if ('auto-seo-manager' !== $page) {
            return;
        }

        if (!current_user_can('manage_options')) {
            return;
        }

        $this->save_settings();
    }

    public function admin_page() {
        include plugin_dir_path(__FILE__) . 'admin-page.php';
    }
    
    private function save_settings() {
        if (!wp_verify_nonce($_POST['auto_seo_nonce'], 'save_auto_seo_settings')) {
            wp_die('Security check failed');
        }
        
        if (!current_user_can('manage_options')) {
            wp_die('You do not have sufficient permissions to access this page.');
        }
        
        $tab = sanitize_key($_POST['tab']);
        
        // Determine which settings to save based on tab
        if ($tab === 'settings') {
            $this->save_basic_settings();
        } elseif ($tab === 'integrations') {
            $this->save_integration_settings();
        } elseif ($tab === 'logs') {
            $this->save_logging_settings();
        } elseif ($tab === 'database') {
            $this->run_database_action();
        }
        
        // Set success flag and redirect
        set_transient('auto_seo_settings_saved', true, 30);
        $redirect_url = self::admin_url_for($tab);
        wp_safe_redirect($redirect_url);
        exit;
    }
    
    /**
     * Reduce submitted post types to ones that actually exist.
     *
     * The raw array was previously written straight to the option. Every later
     * read feeds it to WP_Query as a post_type, so a value that is not a
     * registered type produces an empty result set rather than an error, and
     * the cause is invisible from the admin.
     *
     * @param mixed $raw Submitted value.
     * @return string[]
     */
    private function sanitize_post_types($raw) {
        if (!is_array($raw)) {
            return array();
        }

        $registered = get_post_types(array(), 'names');
        $clean      = array();

        foreach ($raw as $type) {
            if (!is_string($type)) {
                continue;
            }

            $type = sanitize_key($type);

            if ($type !== '' && isset($registered[$type])) {
                $clean[] = $type;
            }
        }

        return array_values(array_unique($clean));
    }

    /**
     * Act on the Database tab.
     *
     * Nothing here is a setting - each button performs work immediately - so
     * this reports what it did through a transient rather than saving state.
     *
     * @return void
     */
    private function run_database_action() {
        if (!class_exists('AutoSEODatabase')) {
            return;
        }

        $db = new AutoSEODatabase();

        if (!empty($_POST['db_optimize'])) {
            $result = $db->optimize(array());
            set_transient(
                'auto_seo_db_notice',
                sprintf(
                    'Reclaimed %s MB across %d table%s.',
                    number_format((float) $result['freed_mb_total'], 1),
                    count($result['tables']),
                    1 === count($result['tables']) ? '' : 's'
                ),
                60
            );

            return;
        }

        $targets = isset($_POST['db_targets']) ? (array) $_POST['db_targets'] : array();
        $targets = array_map('sanitize_key', $targets);
        $targets = array_values(array_filter($targets));

        if (empty($targets)) {
            set_transient('auto_seo_db_notice', 'Nothing was selected, so nothing was removed.', 60);

            return;
        }

        // dry_run false: the operator ticked boxes and pressed a button labelled
        // Delete, which is as explicit as consent gets on an admin screen.
        $result = $db->cleanup($targets, false);

        $remaining = 0;

        foreach ($result['results'] as $r) {
            $remaining += isset($r['remaining']) ? (int) $r['remaining'] : 0;
        }

        set_transient(
            'auto_seo_db_notice',
            $remaining
                ? sprintf(
                    'Removed %s rows. %s still to go - deletion is capped per run, so press it again.',
                    number_format((int) $result['removed_total']),
                    number_format($remaining)
                )
                : sprintf('Removed %s rows. Nothing left in the selected categories.', number_format((int) $result['removed_total'])),
            60
        );
    }

    private function save_basic_settings() {
        // Basic settings
        $basic_settings = array(
            'enabled' => isset($_POST['auto_seo_enabled']) ? 1 : 0,
            'post_types' => $this->sanitize_post_types(isset($_POST['post_types']) ? $_POST['post_types'] : array()),
            'auto_meta_description' => isset($_POST['auto_meta_description']) ? 1 : 0,
            'auto_focus_keywords' => isset($_POST['auto_focus_keywords']) ? 1 : 0,
            'audit_email' => isset($_POST['audit_email']) ? sanitize_email($_POST['audit_email']) : '',
            'max_description_length' => isset($_POST['max_description_length']) ? intval($_POST['max_description_length']) : 155,
            'google_site_verification' => isset($_POST['google_site_verification']) ? sanitize_text_field($_POST['google_site_verification']) : '',
            'daily_updates_enabled' => isset($_POST['daily_updates_enabled']) ? 1 : 0,
            'weekly_audit_enabled' => isset($_POST['weekly_audit_enabled']) ? 1 : 0,
            'audit_email_enabled' => isset($_POST['audit_email_enabled']) ? 1 : 0
        );
        
        foreach ($basic_settings as $key => $value) {
            update_option($this->option_name($key), $value);
        }
        
        // Save title templates
        if (isset($_POST['title_templates'])) {
            $templates = array();
            foreach ($_POST['title_templates'] as $post_type => $template) {
                $templates[sanitize_key($post_type)] = sanitize_text_field($template);
            }
            update_option('auto_seo_title_templates', $templates);
        }
        
        // Save additional meta settings
        $meta_settings = array(
            'auto_additional_meta' => isset($_POST['auto_additional_meta']) ? 1 : 0,
            'site_author' => isset($_POST['site_author']) ? sanitize_text_field($_POST['site_author']) : '',
            'robots_default' => isset($_POST['robots_default']) ? sanitize_text_field($_POST['robots_default']) : 'index, follow',
            'auto_generate_keywords' => isset($_POST['auto_generate_keywords']) ? 1 : 0,
            'auto_og_tags' => isset($_POST['auto_og_tags']) ? 1 : 0,
            'default_og_image' => isset($_POST['default_og_image']) ? esc_url_raw($_POST['default_og_image']) : '',
            'auto_twitter_cards' => isset($_POST['auto_twitter_cards']) ? 1 : 0,
            'twitter_username' => isset($_POST['twitter_username']) ? sanitize_text_field($_POST['twitter_username']) : ''
        );
        
        foreach ($meta_settings as $key => $value) {
            update_option('auto_seo_' . $key, $value);
        }

        // Cron events must follow the toggles that were just saved.
        $this->schedule_seo_updates();
    }
    
    private function save_integration_settings() {
        // Save integration settings only (preserve basic settings)
        $integrations = $this->get_available_integrations();
        foreach ($integrations as $integration_key => $integration) {
            // Skip required integrations (they can't be disabled)
            if (isset($integration['required']) && $integration['required']) {
                continue;
            }
            
            $option_key = 'auto_seo_integration_' . $integration_key;
            $is_enabled = isset($_POST['integration_' . $integration_key]) ? 1 : 0;
            update_option($option_key, $is_enabled);
        }
    }
    
    private function save_logging_settings() {
        $level = isset($_POST['log_level']) ? sanitize_key($_POST['log_level']) : 'actions';
        if (!isset(self::LOG_LEVELS[$level])) {
            $level = 'actions';
        }
        update_option('auto_seo_log_level', $level);

        // 0 in either field means "no limit on this axis".
        update_option('auto_seo_log_retention_days', isset($_POST['log_retention_days']) ? max(0, intval($_POST['log_retention_days'])) : 30);
        update_option('auto_seo_log_max_rows', isset($_POST['log_max_rows']) ? max(0, intval($_POST['log_max_rows'])) : 20000);
        update_option('auto_seo_log_pruning_enabled', isset($_POST['log_pruning_enabled']) ? 1 : 0);

        // Toggling pruning on or off has to move the cron event with it.
        $this->schedule_seo_updates();

        if (isset($_POST['purge_log_now'])) {
            $this->purge_log();
        } elseif (isset($_POST['prune_log_now'])) {
            $this->prune_log();
        }
    }

    /**
     * REST routes under auto-seo/v1.
     *
     * These exist so MCP clients and other automation can read status, flip
     * settings and trigger a run without screen-scraping wp-admin.
     */
    public function register_rest_routes() {
        $can_manage = array($this, 'rest_can_manage');

        register_rest_route('auto-seo/v1', '/status', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array($this, 'rest_get_status'),
            'permission_callback' => $can_manage,
        ));

        register_rest_route('auto-seo/v1', '/settings', array(
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array($this, 'rest_get_settings'),
                'permission_callback' => $can_manage,
            ),
            array(
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => array($this, 'rest_update_settings'),
                'permission_callback' => $can_manage,
            ),
        ));

        register_rest_route('auto-seo/v1', '/logs', array(
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array($this, 'rest_get_logs'),
                'permission_callback' => $can_manage,
                'args'                => array(
                    'limit' => array(
                        'type'              => 'integer',
                        'default'           => 50,
                        'sanitize_callback' => 'absint',
                    ),
                ),
            ),
            array(
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => array($this, 'rest_purge_logs'),
                'permission_callback' => $can_manage,
            ),
        ));

        register_rest_route('auto-seo/v1', '/run', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array($this, 'rest_run_update'),
            'permission_callback' => $can_manage,
            'args'                => array(
                'post_id' => array(
                    'type'              => 'integer',
                    'required'          => false,
                    'sanitize_callback' => 'absint',
                ),
            ),
        ));
    }

    public function rest_can_manage() {
        return current_user_can('manage_options');
    }

    /**
     * Settings exposed over REST, with the type each one is coerced to.
     * Anything not listed here cannot be written through the API.
     */
    private function rest_writable_settings() {
        return array(
            'auto_seo_enabled'              => 'bool',
            'auto_meta_description'         => 'bool',
            'auto_focus_keywords'           => 'bool',
            'auto_generate_keywords'        => 'bool',
            'auto_og_tags'                  => 'bool',
            'auto_twitter_cards'            => 'bool',
            'auto_additional_meta'          => 'bool',
            'daily_updates_enabled'         => 'bool',
            'weekly_audit_enabled'          => 'bool',
            'audit_email_enabled'           => 'bool',
            'log_pruning_enabled'           => 'bool',
            'log_level'                     => 'level',
            'log_retention_days'            => 'int',
            'log_max_rows'                  => 'int',
            'max_description_length'        => 'int',
            'audit_email'                   => 'email',
            'site_author'                   => 'text',
            'robots_default'                => 'text',
            'twitter_username'              => 'text',
            'google_site_verification'      => 'text',
            'default_og_image'              => 'url',
        );
    }

    /**
     * Option name for a settings key.
     *
     * Most keys are stored with an 'auto_seo_' prefix, but a few already carry
     * it — 'auto_seo_enabled' among them. Blindly prefixing turns that into
     * 'auto_seo_auto_seo_enabled', which is a different option from the one the
     * rest of the plugin reads, so the setting silently stops taking effect.
     */
    private function option_name($key) {
        return strpos($key, 'auto_seo_') === 0 ? $key : 'auto_seo_' . $key;
    }

    public function rest_get_settings() {
        $out = array();

        foreach ($this->rest_writable_settings() as $key => $type) {
            $value = get_option($this->option_name($key));
            $out[$key] = ('bool' === $type) ? (bool) $value : $value;
        }

        $out['post_types']      = get_option('auto_seo_post_types', array());
        $out['title_templates'] = get_option('auto_seo_title_templates', array());

        return rest_ensure_response($out);
    }

    public function rest_update_settings(WP_REST_Request $request) {
        $allowed = $this->rest_writable_settings();
        $params  = $request->get_json_params();

        if (!is_array($params)) {
            $params = $request->get_params();
        }

        $updated = array();
        $ignored = array();

        foreach ($params as $key => $value) {
            if (!isset($allowed[$key])) {
                $ignored[] = $key;
                continue;
            }

            switch ($allowed[$key]) {
                case 'bool':
                    $value = rest_sanitize_boolean($value) ? 1 : 0;
                    break;
                case 'int':
                    $value = max(0, intval($value));
                    break;
                case 'email':
                    $value = sanitize_email($value);
                    break;
                case 'url':
                    $value = esc_url_raw($value);
                    break;
                case 'level':
                    $value = sanitize_key($value);
                    if (!isset(self::LOG_LEVELS[$value])) {
                        return new WP_Error(
                            'auto_seo_bad_log_level',
                            'log_level must be one of: ' . implode(', ', array_keys(self::LOG_LEVELS)),
                            array('status' => 400)
                        );
                    }
                    break;
                default:
                    $value = sanitize_text_field($value);
            }

            update_option('auto_seo_' . $key, $value);
            $updated[$key] = $value;
        }

        // Any of the schedule toggles may have moved.
        $this->schedule_seo_updates();

        return rest_ensure_response(array(
            'updated' => $updated,
            'ignored' => $ignored,
        ));
    }

    public function rest_get_status() {
        return rest_ensure_response(array(
            'version'       => AUTO_SEO_VERSION,
            'db_version'    => (int) get_option('auto_seo_db_version', 1),
            'enabled'       => (bool) get_option('auto_seo_enabled', 1),
            'yoast_active'  => $this->is_yoast_active(),
            'log'           => $this->get_log_stats(),
            'integrations'  => $this->rest_integration_status(),
            'next_run'      => array(
                'daily_update' => wp_next_scheduled('daily_seo_update') ?: null,
                'weekly_audit' => wp_next_scheduled('weekly_seo_audit') ?: null,
                'log_prune'    => wp_next_scheduled('auto_seo_prune_log') ?: null,
            ),
        ));
    }

    private function rest_integration_status() {
        $out = array();

        foreach ($this->get_available_integrations() as $key => $integration) {
            $out[$key] = array(
                'name'      => $integration['name'],
                'available' => $this->is_integration_available($key),
                'enabled'   => (bool) $this->is_integration_enabled($key),
                'required'  => !empty($integration['required']),
            );
        }

        return $out;
    }

    public function rest_get_logs(WP_REST_Request $request) {
        $limit = min(500, max(1, (int) $request->get_param('limit')));

        return rest_ensure_response(array(
            'stats'   => $this->get_log_stats(),
            'entries' => $this->get_log_entries($limit),
        ));
    }

    public function rest_purge_logs() {
        $before = $this->get_log_stats();
        $this->purge_log();

        return rest_ensure_response(array(
            'purged_rows' => $before['rows'],
            'stats'       => $this->get_log_stats(),
        ));
    }

    public function rest_run_update(WP_REST_Request $request) {
        if (!$this->is_yoast_active()) {
            return new WP_Error(
                'auto_seo_yoast_missing',
                'Yoast SEO is required for Auto SEO Manager to write SEO fields.',
                array('status' => 409)
            );
        }

        $post_id = (int) $request->get_param('post_id');

        if ($post_id > 0) {
            if (!get_post($post_id)) {
                return new WP_Error('auto_seo_no_post', 'No post with that ID.', array('status' => 404));
            }

            if (!current_user_can('edit_post', $post_id)) {
                return new WP_Error('auto_seo_forbidden', 'You cannot edit that post.', array('status' => 403));
            }

            return rest_ensure_response(array(
                'scope'   => 'post',
                'post_id' => $post_id,
                'updated' => (bool) $this->update_post_seo_data($post_id),
            ));
        }

        $this->execute_seo_updates();

        return rest_ensure_response(array(
            'scope' => 'site',
            'log'   => $this->get_log_stats(),
        ));
    }

    public function get_available_integrations() {
        return array(
            'yoast' => array(
                'name' => 'Yoast SEO',
                'description' => 'Core SEO plugin integration - Required for Auto SEO Manager to function',
                'class_check' => 'WPSEO_Options',
                'required' => true,
                'features' => array(
                    'SEO title field integration',
                    'Meta description field integration', 
                    'Focus keyword field integration',
                    'Sitemap generation compatibility',
                    'Schema markup coordination'
                )
            ),
            'woocommerce' => array(
                'name' => 'WooCommerce',
                'description' => 'Enhanced product SEO with pricing, inventory, and category data',
                'class_check' => 'WooCommerce',
                'features' => array(
                    'Product-specific title templates with price placeholders',
                    'Auto-generated descriptions with pricing info',
                    'Product category and attribute keywords',
                    'Schema markup for products'
                )
            ),
            'acf' => array(
                'name' => 'Advanced Custom Fields',
                'description' => 'Use ACF fields for SEO data and enhanced content extraction',
                'class_check' => 'ACF',
                'features' => array(
                    'Custom SEO description fields',
                    'Focus keywords from ACF fields',
                    'Summary and excerpt field integration',
                    'Custom meta data support'
                )
            ),
            'elementor' => array(
                'name' => 'Elementor',
                'description' => 'Extract content from Elementor page builder elements',
                'class_check' => '\Elementor\Plugin',
                'features' => array(
                    'Text extraction from Elementor widgets',
                    'Content parsing from page builder data',
                    'Enhanced SEO for builder-created pages'
                )
            ),
            'beaver_builder' => array(
                'name' => 'Beaver Builder',
                'description' => 'Content extraction from Beaver Builder layouts',
                'class_check' => 'FLBuilder',
                'features' => array(
                    'Page builder content analysis',
                    'Widget text extraction',
                    'Layout-aware SEO generation'
                )
            ),
            'events_calendar' => array(
                'name' => 'The Events Calendar',
                'description' => 'Event-specific SEO optimization with dates and venues',
                'class_check' => 'Tribe__Events__Main',
                'features' => array(
                    'Event date placeholders in titles',
                    'Venue information in descriptions',
                    'Event-specific meta data',
                    'Calendar SEO optimization'
                )
            ),
            'multilingual' => array(
                'name' => 'WPML/Polylang',
                'description' => 'Multi-language SEO support and localization',
                'function_check' => array('pll_current_language', 'icl_get_current_language'),
                'features' => array(
                    'Language-specific title templates',
                    'Localized meta descriptions',
                    'Multi-language keyword extraction',
                    'Regional SEO optimization'
                )
            ),
            'gutenberg' => array(
                'name' => 'Gutenberg Blocks',
                'description' => 'Enhanced content extraction from block editor',
                'function_check' => 'register_block_type',
                'features' => array(
                    'Block content analysis',
                    'Advanced text extraction',
                    'Block-specific SEO data',
                    'Modern editor support'
                )
            ),
            'wp_travel' => array(
                'name' => 'WP Travel',
                'description' => 'Trip-aware SEO for WP Travel (wptravel.io) using duration, price, destinations and the day-by-day itinerary',
                'callable_check' => array('AutoSEOIntegrations', 'wp_travel_available'),
                'features' => array(
                    'Trip placeholders: %%trip_duration%%, %%trip_price%%, %%trip_destination%%, %%trip_activity%%',
                    'Meta descriptions built from the trip overview',
                    'Focus keywords from Destinations, Activities and Trip Types',
                    'Day-by-day itinerary text included in content extraction'
                )
            ),
            'cptui' => array(
                'name' => 'Custom Post Type UI',
                'description' => 'Support for custom post types and taxonomies',
                'function_check' => 'cptui_get_post_type_data',
                'features' => array(
                    'Custom post type SEO templates',
                    'Taxonomy integration',
                    'Custom field support',
                    'Extended content type handling'
                )
            )
        );
    }
    
    public function is_integration_available($integration_key) {
        $integrations = $this->get_available_integrations();
        
        if (!isset($integrations[$integration_key])) {
            return false;
        }
        
        $integration = $integrations[$integration_key];
        
        // Deferred check: some integrations can only be resolved once the
        // relevant plugin has registered its post types on `init`.
        if (isset($integration['callable_check']) && is_callable($integration['callable_check'])) {
            return (bool) call_user_func($integration['callable_check']);
        }

        // Check class existence
        if (isset($integration['class_check'])) {
            return class_exists($integration['class_check']);
        }
        
        // Check function existence
        if (isset($integration['function_check'])) {
            if (is_array($integration['function_check'])) {
                foreach ($integration['function_check'] as $function) {
                    if (function_exists($function)) {
                        return true;
                    }
                }
                return false;
            } else {
                return function_exists($integration['function_check']);
            }
        }
        
        return false;
    }
    
    public function is_integration_enabled($integration_key) {
        // Yoast is always "enabled" since it's required
        if ($integration_key === 'yoast') {
            return true;
        }
        
        return get_option('auto_seo_integration_' . $integration_key, 1);
    }
    
    public function get_log_entries($limit = 50) {
        global $wpdb;
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table_name} ORDER BY timestamp DESC LIMIT %d",
            $limit
        ));
    }
}

// Initialize the plugin
$auto_seo_manager = new AutoSEOManager();

// Abilities API. Registers this plugin's operations so MCP clients and other
// AI tooling can discover and call them. Inert when the API is not present.
$auto_seo_abilities_file = plugin_dir_path(__FILE__) . 'abilities.php';
if (file_exists($auto_seo_abilities_file)) {
    require_once $auto_seo_abilities_file;

    if (class_exists('AutoSEOAbilities')) {
        new AutoSEOAbilities($auto_seo_manager);
    }
}

// Database diagnostics and cleanup. Counting is read-only; deletion defaults to
// a dry run and has to be asked for explicitly. Loaded alongside the abilities
// above so the whole surface appears together, or not at all.
$auto_seo_database_file = plugin_dir_path(__FILE__) . 'database.php';
if (file_exists($auto_seo_database_file)) {
    require_once $auto_seo_database_file;

    if (class_exists('AutoSEODatabase')) {
        new AutoSEODatabase();
    }
}

// Attachment repair. Finds records whose stored file contradicts their recorded
// type - what an in-place image conversion leaves behind - and repoints them at
// a replacement once one exists on disk.
$auto_seo_media_file = plugin_dir_path(__FILE__) . 'media-repair.php';
if (file_exists($auto_seo_media_file)) {
    require_once $auto_seo_media_file;

    if (class_exists('AutoSEOMediaRepair')) {
        new AutoSEOMediaRepair();
    }
}

// Reading and writing individual values. Separate from the database module
// above, which reports and deletes in bulk - this is the part that changes one
// thing, and every write here defaults to a dry run.
$auto_seo_dbedit_file = plugin_dir_path(__FILE__) . 'database-editor.php';
if (file_exists($auto_seo_dbedit_file)) {
    require_once $auto_seo_dbedit_file;

    if (class_exists('AutoSEODatabaseEditor')) {
        new AutoSEODatabaseEditor();
    }
}

// Image sizing at the delivery layer. Offloading to Cloudflare Images leaves
// WordPress with no sub-sizes and no recorded dimensions, so every size request
// resolves to the same uncropped image. This puts the requested shape back into
// the delivery URL, where Cloudflare can honour it.
$auto_seo_imgsize_file = plugin_dir_path(__FILE__) . 'image-sizes.php';
if (file_exists($auto_seo_imgsize_file)) {
    require_once $auto_seo_imgsize_file;

    if (class_exists('AutoSEOImageSizes')) {
        new AutoSEOImageSizes();
    }
}

// Updates from GitHub Releases. A plugin outside wordpress.org gets no update
// notice, so without this a site sits on whatever version was installed.
$auto_seo_updater_file = plugin_dir_path(__FILE__) . 'updater.php';
if (is_admin() && file_exists($auto_seo_updater_file)) {
    require_once $auto_seo_updater_file;

    if (class_exists('AutoSEOUpdater')) {
        new AutoSEOUpdater(__FILE__);
    }
}