<?php
/**
 * Meta Tags Testing Utility
 * Save as: meta-tags-test.php in the plugin directory
 * Access via: yoursite.com/wp-admin/admin.php?page=auto-seo-manager&test_meta=1
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class AutoSEOMetaTagsTester {
    
    public static function init() {
        if (isset($_GET['test_meta']) && current_user_can('manage_options')) {
            add_action('admin_init', array(__CLASS__, 'run_meta_test'));
        }
    }
    
    public static function run_meta_test() {
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <title>Auto SEO Manager - Meta Tags Test</title>
            <style>
                body { font-family: Arial, sans-serif; margin: 20px; }
                .test-section { margin-bottom: 30px; }
                .meta-output { background: #f5f5f5; padding: 15px; border-left: 4px solid #0073aa; }
                .success { color: #46b450; }
                .warning { color: #ffb900; }
                .error { color: #dc3232; }
                pre { background: #fff; padding: 10px; border: 1px solid #ddd; overflow: auto; }
            </style>
        </head>
        <body>
            <h1>Auto SEO Manager - Meta Tags Test</h1>
            
            <?php self::test_plugin_status(); ?>
            <?php self::test_sample_post(); ?>
            <?php self::test_global_settings(); ?>
            <?php self::validate_meta_tags(); ?>
            
            <p><a href="<?php echo admin_url('options-general.php?page=auto-seo-manager'); ?>">&larr; Back to Settings</a></p>
        </body>
        </html>
        <?php
        exit;
    }
    
    private static function test_plugin_status() {
        ?>
        <div class="test-section">
            <h2>Plugin Status Check</h2>
            <div class="meta-output">
                <?php
                $auto_seo = new AutoSEOManager();
                
                echo "<p><strong>Yoast SEO:</strong> ";
                if (class_exists('WPSEO_Options')) {
                    echo '<span class="success">✅ Active</span>';
                } else {
                    echo '<span class="error">❌ Not Active</span>';
                }
                echo "</p>";
                
                echo "<p><strong>Auto Additional Meta:</strong> ";
                echo get_option('auto_seo_auto_additional_meta') ? '<span class="success">✅ Enabled</span>' : '<span class="warning">⚠️ Disabled</span>';
                echo "</p>";
                
                echo "<p><strong>Open Graph Tags:</strong> ";
                echo get_option('auto_seo_auto_og_tags') ? '<span class="success">✅ Enabled</span>' : '<span class="warning">⚠️ Disabled</span>';
                echo "</p>";
                
                echo "<p><strong>Twitter Cards:</strong> ";
                echo get_option('auto_seo_auto_twitter_cards') ? '<span class="success">✅ Enabled</span>' : '<span class="warning">⚠️ Disabled</span>';
                echo "</p>";
                ?>
            </div>
        </div>
        <?php
    }
    
    private static function test_sample_post() {
        $sample_post = get_posts(array(
            'numberposts' => 1,
            'post_status' => 'publish'
        ));
        
        if (empty($sample_post)) {
            echo '<div class="test-section"><h2>Sample Post Test</h2><p class="error">No published posts found to test.</p></div>';
            return;
        }
        
        $post = $sample_post[0];
        ?>
        <div class="test-section">
            <h2>Sample Post Meta Tags Test</h2>
            <p><strong>Testing Post:</strong> <a href="<?php echo get_permalink($post->ID); ?>" target="_blank"><?php echo esc_html($post->post_title); ?></a></p>
            
            <div class="meta-output">
                <h3>Generated Meta Tags:</h3>
                <pre><?php echo esc_html(self::generate_sample_meta_tags($post->ID)); ?></pre>
            </div>
        </div>
        <?php
    }
    
    private static function test_global_settings() {
        ?>
        <div class="test-section">
            <h2>Global Settings Test</h2>
            <div class="meta-output">
                <?php
                $settings = array(
                    'Site Author' => get_option('auto_seo_site_author'),
                    'Google Site Verification' => get_option('auto_seo_google_site_verification'),
                    'Twitter Username' => get_option('auto_seo_twitter_username'),
                    'Default OG Image' => get_option('auto_seo_default_og_image'),
                    'Default Robots' => get_option('auto_seo_robots_default')
                );
                
                foreach ($settings as $label => $value) {
                    echo "<p><strong>{$label}:</strong> ";
                    if (!empty($value)) {
                        echo '<span class="success">✅ Set</span> - ' . esc_html($value);
                    } else {
                        echo '<span class="warning">⚠️ Not Set</span>';
                    }
                    echo "</p>";
                }
                ?>
            </div>
        </div>
        <?php
    }
    
    private static function validate_meta_tags() {
        ?>
        <div class="test-section">
            <h2>Meta Tags Validation</h2>
            <div class="meta-output">
                <h3>Validation Results:</h3>
                <?php
                $issues = array();
                
                // Check for required settings
                if (!get_option('auto_seo_site_author')) {
                    $issues[] = "Site Author is not set - meta author tag will be empty";
                }
                
                if (!get_option('auto_seo_default_og_image')) {
                    $issues[] = "Default OG Image is not set - posts without featured images won't have og:image";
                }
                
                if (!get_option('auto_seo_twitter_username')) {
                    $issues[] = "Twitter Username is not set - twitter:site tag will be empty";
                }
                
                if (get_option('auto_seo_auto_og_tags') && !get_option('auto_seo_auto_additional_meta')) {
                    $issues[] = "Open Graph is enabled but Additional Meta is disabled - some features may not work";
                }
                
                // Check for conflicts with other SEO plugins
                if (class_exists('RankMath')) {
                    $issues[] = "RankMath detected - may conflict with meta tag generation";
                }
                
                if (class_exists('AIOSEO\Plugin\AIOSEO')) {
                    $issues[] = "All in One SEO detected - may conflict with meta tag generation";
                }
                
                if (empty($issues)) {
                    echo '<p class="success">✅ All validations passed!</p>';
                } else {
                    echo '<p class="warning">⚠️ Issues found:</p><ul>';
                    foreach ($issues as $issue) {
                        echo '<li class="warning">' . esc_html($issue) . '</li>';
                    }
                    echo '</ul>';
                }
                ?>
                
                <h3>Performance Check:</h3>
                <?php
                $start_time = microtime(true);
                
                // Test meta generation performance
                $test_post = get_posts(array('numberposts' => 1, 'post_status' => 'publish'));
                if (!empty($test_post)) {
                    self::generate_sample_meta_tags($test_post[0]->ID);
                }
                
                $end_time = microtime(true);
                $execution_time = ($end_time - $start_time) * 1000;
                
                echo "<p><strong>Meta Generation Speed:</strong> " . number_format($execution_time, 2) . "ms";
                if ($execution_time < 100) {
                    echo ' <span class="success">✅ Fast</span>';
                } elseif ($execution_time < 500) {
                    echo ' <span class="warning">⚠️ Moderate</span>';
                } else {
                    echo ' <span class="error">❌ Slow</span>';
                }
                echo "</p>";
                ?>
            </div>
        </div>
        <?php
    }
    
    private static function generate_sample_meta_tags($post_id) {
        $auto_seo = new AutoSEOManager();
        
        // Get post data
        $post = get_post($post_id);
        $meta_tags = array();
        
        // Basic meta tags
        $keywords = get_post_meta($post_id, '_auto_seo_keywords', true);
        if (empty($keywords)) {
            // Generate keywords for testing
            $content = strtolower($post->post_title . ' ' . wp_strip_all_tags($post->post_content));
            $words = str_word_count($content, 1);
            $stop_words = array('the', 'a', 'an', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for', 'of', 'with', 'by');
            $filtered_words = array_filter($words, function($word) use ($stop_words) {
                return strlen($word) >= 3 && !in_array($word, $stop_words);
            });
            $word_count = array_count_values($filtered_words);
            arsort($word_count);
            $keywords = implode(', ', array_slice(array_keys($word_count), 0, 10));
        }
        
        $description = get_post_meta($post_id, '_yoast_wpseo_metadesc', true);
        if (empty($description)) {
            $description = has_excerpt($post_id) ? get_the_excerpt($post_id) : substr(wp_strip_all_tags($post->post_content), 0, 155) . '...';
        }
        
        $meta_tags[] = '<meta name="description" content="' . esc_attr($description) . '">';
        $meta_tags[] = '<meta name="keywords" content="' . esc_attr($keywords) . '">';
        $meta_tags[] = '<meta name="author" content="' . esc_attr(get_option('auto_seo_site_author', 'Your Name')) . '">';
        $meta_tags[] = '<meta name="robots" content="' . esc_attr(get_option('auto_seo_robots_default', 'index, follow')) . '">';
        
        // Google verification (only on homepage)
        if (is_home() || is_front_page()) {
            $verification = get_option('auto_seo_google_site_verification');
            if ($verification) {
                $meta_tags[] = '<meta name="google-site-verification" content="' . esc_attr($verification) . '">';
            }
        }
        
        // Open Graph tags
        if (get_option('auto_seo_auto_og_tags')) {
            $og_title = get_post_meta($post_id, '_yoast_wpseo_title', true) ?: $post->post_title;
            $og_image = has_post_thumbnail($post_id) ? get_the_post_thumbnail_url($post_id, 'large') : get_option('auto_seo_default_og_image');
            
            $meta_tags[] = '<meta property="og:title" content="' . esc_attr($og_title) . '">';
            $meta_tags[] = '<meta property="og:description" content="' . esc_attr($description) . '">';
            $meta_tags[] = '<meta property="og:url" content="' . esc_url(get_permalink($post_id)) . '">';
            $meta_tags[] = '<meta property="og:type" content="article">';
            $meta_tags[] = '<meta property="og:site_name" content="' . esc_attr(get_bloginfo('name')) . '">';
            
            if ($og_image) {
                $meta_tags[] = '<meta property="og:image" content="' . esc_url($og_image) . '">';
            }
        }
        
        // Twitter Card tags
        if (get_option('auto_seo_auto_twitter_cards')) {
            $twitter_image = has_post_thumbnail($post_id) ? get_the_post_thumbnail_url($post_id, 'large') : get_option('auto_seo_default_og_image');
            $card_type = $twitter_image ? 'summary_large_image' : 'summary';
            
            $meta_tags[] = '<meta name="twitter:card" content="' . esc_attr($card_type) . '">';
            $meta_tags[] = '<meta name="twitter:title" content="' . esc_attr($post->post_title) . '">';
            $meta_tags[] = '<meta name="twitter:description" content="' . esc_attr($description) . '">';
            
            $twitter_username = get_option('auto_seo_twitter_username');
            if ($twitter_username) {
                $meta_tags[] = '<meta name="twitter:site" content="@' . esc_attr(ltrim($twitter_username, '@')) . '">';
            }
            
            if ($twitter_image) {
                $meta_tags[] = '<meta name="twitter:image" content="' . esc_url($twitter_image) . '">';
            }
        }
        
        return implode("\n", $meta_tags);
    }
}

// Initialize the tester
AutoSEOMetaTagsTester::init();