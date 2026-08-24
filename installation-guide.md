# Auto SEO Manager Plugin - Installation & Setup Guide

## Prerequisites

1. **WordPress self-hosted site** (version 5.0 or higher)
2. **Yoast SEO plugin** installed and activated
3. **Administrative access** to your WordPress site
4. **FTP/cPanel access** (for external cron setup)

## Installation Steps

### Step 1: Install the Plugin

1. Create a new folder in your WordPress plugins directory:
   ```
   /wp-content/plugins/auto-seo-manager/
   ```

2. Upload these files to the folder:
   - `auto-seo-manager.php` (main plugin file)
   - `admin-page.php` (admin interface)
   - `integrations.php` (plugin integrations)
   - `meta-tags-test.php` (testing utility - optional)

3. **Activate the plugin** via WordPress Admin → Plugins

### Step 2: Configure Settings

1. Go to **Settings → Auto SEO Manager** in your WordPress admin
2. Configure the following options:

#### Basic Settings
- ✅ **Enable Auto SEO**: Check to activate automatic updates
- **Post Types**: Select which content types to process (posts, pages, products, etc.)
- ✅ **Auto Meta Descriptions**: Generate descriptions from content
- ✅ **Auto Focus Keywords**: Extract keywords automatically
- **Max Description Length**: Set to 155 characters (recommended)
- **Audit Email**: Enter your email for weekly reports

#### Additional Meta Tags Settings
- ✅ **Enable Additional Meta Tags**: Generate comprehensive meta tags
- ✅ **Auto Generate Keywords**: Extract keywords from content and categories
- **Site Author**: Default author name for all pages
- **Default Robots**: Choose indexing behavior (index, follow recommended)
- **Google Site Verification**: Your Search Console verification token

#### Open Graph Settings
- ✅ **Enable Open Graph Tags**: For social media sharing
- **Default OG Image**: Fallback image URL when no featured image exists

#### Twitter Card Settings  
- ✅ **Enable Twitter Cards**: For Twitter sharing optimization
- **Twitter Username**: Your Twitter handle (without @)

### Step 3: Configure Plugin Integrations

1. Go to **Integrations tab** in plugin settings
2. **Review available integrations** - plugin automatically detects installed plugins
3. **Enable/disable integrations** using toggle switches for each plugin:

#### Supported Integrations
- 🛒 **WooCommerce**: Enhanced product SEO with pricing, inventory, and categories
- 🔧 **Advanced Custom Fields**: Use ACF fields for SEO data and content
- 🎨 **Elementor**: Extract content from page builder elements
- 🏗️ **Beaver Builder**: Page builder content extraction and analysis
- 📅 **The Events Calendar**: Event-specific SEO with dates and venues
- 🌐 **WPML/Polylang**: Multi-language SEO support and localization
- 📝 **Gutenberg Blocks**: Enhanced block editor content extraction
- 📋 **Custom Post Type UI**: Support for custom post types and taxonomies

#### Integration Features
Each integration provides specific enhancements:

**WooCommerce Integration:**
- Product-specific title templates with price placeholders
- Auto-generated descriptions with pricing information
- Product category and attribute keywords
- Schema markup for products
- Inventory status in meta descriptions

**Advanced Custom Fields Integration:**
- Custom SEO description fields (`seo_description`)
- Focus keywords from ACF fields (`seo_keywords`, `focus_keywords`)
- Summary and excerpt field integration
- Custom meta data support

**Elementor Integration:**
- Text extraction from Elementor widgets
- Content parsing from page builder data
- Enhanced SEO for builder-created pages

**Events Calendar Integration:**
- Event date placeholders in titles (`%%event_date%%`)
- Venue information in descriptions (`%%event_venue%%`)
- Event-specific meta data optimization

#### SEO Title Templates
Configure templates for each post type using these placeholders:
- `%%title%%` - Post/page title
- `%%sitename%%` - Your site name
- `%%currentdate%%` - Current year

**Example templates:**
- **Posts**: `%%title%% | Expert Guide | %%sitename%%`
- **Pages**: `%%title%% | %%sitename%%`
- **Products**: `%%title%% - Buy Online | %%sitename%%`

### Step 3: Set Up External Cron (Recommended)

For reliable automation, set up an external cron job:

1. **Get your cron URL** from Tools tab in plugin settings
2. **Add to your server's crontab**:
   ```bash
   # Edit crontab
   crontab -e
   
   # Add this line for daily execution at 2 AM
   0 2 * * * curl -s "https://yoursite.com/auto-seo-cron/YOUR-SECRET-KEY" > /dev/null
   ```

3. **Alternative: cPanel Cron Jobs**
   - Command: `curl -s "https://yoursite.com/auto-seo-cron/YOUR-SECRET-KEY"`
   - Schedule: Daily at 02:00

## What Gets Generated

The plugin now automatically adds comprehensive meta tags to all pages:

### Basic Meta Tags
- `<meta name="description">` - Page descriptions for search engines
- `<meta name="keywords">` - Extracted from content, categories, and tags  
- `<meta name="author">` - Site author information
- `<meta name="robots">` - Search engine indexing instructions
- `<meta name="google-site-verification">` - Google Search Console verification

### Open Graph Tags (Social Media)
- `<meta property="og:title">` - Title for social sharing
- `<meta property="og:description">` - Description for social platforms
- `<meta property="og:image">` - Featured image or default fallback
- `<meta property="og:url">` - Canonical page URL
- `<meta property="og:type">` - Content type (article/website)
- `<meta property="og:site_name">` - Your site name

### Twitter Card Tags
- `<meta name="twitter:card">` - Card type (summary/summary_large_image)
- `<meta name="twitter:site">` - Your Twitter username
- `<meta name="twitter:title">` - Title for Twitter
- `<meta name="twitter:description">` - Description for Twitter
- `<meta name="twitter:image">` - Image for Twitter cards

1. **Scans your content** daily for missing SEO data
2. **Generates SEO titles** using your templates
3. **Creates meta descriptions** from excerpts or content
4. **Extracts focus keywords** from post content
5. **Logs all activities** for monitoring
6. **Sends weekly audit reports** via email

## How It Works

### Automatic Processing
The plugin automatically:
- Content **without meta descriptions**
- Posts **lacking focus keywords**
- Only **published content** is processed
- **Existing SEO data is never overwritten**

## Usage Examples

### Manual Updates
- Use **Tools → Run SEO Update Now** for immediate processing
- Monitor progress in **Activity Log** tab
- Check **System Information** for scheduling status

### Bulk Processing
The plugin processes content in batches of 50 to avoid server overload:
```php
// Automatically handles large sites
$posts = get_posts(array(
    'numberposts' => 50,  // Batch size
    'offset' => $current_batch * 50
));
```

### Template Examples
```php
// Blog posts
'%%title%% | Ultimate Guide | %%sitename%%'

// Product pages  
'%%title%% - Best Price | %%sitename%%'

// Service pages
'%%title%% Services | %%sitename%% | %%currentdate%%'
```

## Monitoring & Maintenance

### Activity Log
- **View recent actions** in the plugin dashboard
- **Track success/failure** of automated updates
- **Monitor processing times** and batch sizes

### Weekly Audits
Automatic reports include:
- Posts missing SEO titles
- Content without meta descriptions  
- Pages lacking focus keywords
- Recommendations for improvement

### Performance Monitoring
```sql
-- Check plugin activity
SELECT action, COUNT(*) as count, status 
FROM wp_auto_seo_log 
WHERE timestamp > DATE_SUB(NOW(), INTERVAL 7 DAY)
GROUP BY action, status;
```

## Troubleshooting

### Common Issues

**Plugin not updating content:**
- Verify Yoast SEO is active
- Check that "Enable Auto SEO" is checked
- Ensure selected post types have published content

**Cron not running:**
- Verify external cron URL is correct
- Check server cron job syntax
- Test URL manually in browser

**Memory issues on large sites:**
- Plugin processes in batches of 50
- Increase PHP memory limit if needed:
  ```php
  ini_set('memory_limit', '256M');
  ```

**Email reports not sending:**
- Verify audit email address
- Check WordPress mail configuration
- Test with wp_mail() function

### Debug Mode
Add to wp-config.php for detailed logging:
```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
```

## Security Considerations

- **Secret cron key** is automatically generated
- **Nonce verification** for admin actions
- **Capability checks** for user permissions
- **Input sanitization** for all settings

## Performance Impact

- **Minimal resource usage** with batch processing
- **Scheduled execution** during low-traffic periods
- **Database indexing** for efficient log queries
- **Memory-conscious** design for large sites

## Support & Updates

For issues or feature requests:
1. Check the **Activity Log** for error details
2. Review **System Information** for configuration
3. Test with **manual updates** first
4. Monitor **weekly audit reports** for patterns

The plugin is designed to be self-maintaining with comprehensive logging and error handling.