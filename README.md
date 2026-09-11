# Auto SEO Manager for WordPress

**Automated SEO optimization with Yoast integration, comprehensive meta tags, and social media optimization.**

[![WordPress](https://img.shields.io/badge/WordPress-5.0%2B-blue.svg)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-purple.svg)](https://php.net/)
[![License](https://img.shields.io/badge/License-GPL%20v2-green.svg)](https://www.gnu.org/licenses/gpl-2.0.html)
[![Version](https://img.shields.io/badge/Version-1.0.0-orange.svg)](https://github.com/nyuchitech/auto-seo-manager)
[![Tested](https://img.shields.io/badge/Tested%20up%20to-WordPress%206.4-brightgreen.svg)](https://wordpress.org/)

## 🚀 Overview

Auto SEO Manager is a powerful WordPress plugin that automatically generates and manages comprehensive SEO data for your entire website. It works seamlessly with Yoast SEO while adding advanced meta tags, Open Graph data, Twitter Cards, WooCommerce product optimization, and intelligent automation features.

**Perfect for agencies, developers, and site owners** who want comprehensive SEO automation without manual intervention.

**Developed by:** [Nyuchi Web Services](https://nyuchi.com)  
**Lead Developer:** Bryan Fawcett ([@bryanfawcett](https://github.com/bryanfawcett))  
**Repository:** [GitHub - Auto SEO Manager](https://github.com/nyuchitech/auto-seo-manager)

---

## ✨ Key Features

### 🔧 **Core Automation**

- ✅ **Automated SEO Generation** - Titles, descriptions, and keywords from content
- ✅ **Yoast SEO Integration** - Works alongside existing Yoast data without conflicts
- ✅ **Scheduled Processing** - Daily automation with external cron support
- ✅ **Batch Processing** - Efficient handling of large sites (1000+ posts)
- ✅ **Smart Detection** - Only processes content missing SEO data

### 🏷️ **Comprehensive Meta Tags**

- ✅ **Basic SEO Tags** - Description, keywords, author, robots
- ✅ **Open Graph Tags** - Complete social media optimization
- ✅ **Twitter Cards** - Enhanced Twitter sharing with large images
- ✅ **Schema Markup** - Structured data for better search results
- ✅ **Google Verification** - Search Console integration

### 🔌 **Plugin Integrations**

- ✅ **WooCommerce** - Product SEO with pricing, inventory, categories
- ✅ **Advanced Custom Fields** - Use ACF fields for SEO data
- ✅ **Elementor** - Extract content from page builder elements
- ✅ **Beaver Builder** - Page builder content analysis
- ✅ **The Events Calendar** - Event-specific SEO with dates/venues
- ✅ **WPML/Polylang** - Multi-language SEO support
- ✅ **Gutenberg Blocks** - Modern block editor content extraction
- ✅ **Custom Post Types** - Support for any post type

### 🎛️ **Management Interface**

- ✅ **Visual Integration Management** - Easy enable/disable for each plugin
- ✅ **Activity Monitoring** - Comprehensive logging and reporting
- ✅ **Manual Tools** - Bulk updates and meta tag preview
- ✅ **Testing Utilities** - Built-in validation and debugging
- ✅ **Performance Monitoring** - Track processing times and success rates

---

## 📋 Requirements

| Component     | Version      | Status      |
| ------------- | ------------ | ----------- |
| **WordPress** | 5.0+         | Required    |
| **PHP**       | 7.4+         | Required    |
| **Yoast SEO** | Any version  | Required    |
| **Memory**    | 128MB+       | Recommended |
| **Server**    | Apache/Nginx | Required    |

---

## 🔧 Installation

### Method 1: GitHub Release (Recommended)

1. **Download** the latest release ZIP from [GitHub Releases](https://github.com/nyuchitech/auto-seo-manager/releases)
2. **Upload** via WordPress Admin → Plugins → Add New → Upload Plugin
3. **Activate** the plugin
4. **Configure** at Settings → Auto SEO Manager

### Method 2: Manual Installation

1. **Clone** or download this repository

   ```bash
   git clone https://github.com/nyuchitech/auto-seo-manager.git
   ```

2. **Upload** the folder to `/wp-content/plugins/auto-seo-manager/`
3. **Activate** through WordPress Admin → Plugins
4. **Configure** settings

### Required Files Structure

```
/wp-content/plugins/auto-seo-manager/
├── auto-seo-manager.php          # Main plugin file
├── admin-page.php                # Admin interface
├── integrations.php              # Plugin integrations
├── meta-tags-test.php           # Testing utility (optional)
├── README.md                    # This documentation
├── LICENSE                      # GPL v2 License
└── installation-guide.md        # Detailed setup guide
```

---

## ⚙️ Quick Setup Guide

### 1. **Basic Configuration**

1. Go to **Settings → Auto SEO Manager**
2. ✅ Check **"Enable Auto SEO"**
3. Select **post types** to process (posts, pages, products, etc.)
4. Set **meta description length** (155 characters recommended)
5. Add your **email** for weekly audit reports

### 2. **Meta Tags Setup**

1. ✅ Enable **"Additional Meta Tags"**
2. Set **site author** name
3. Add **Google site verification** token
4. Configure **default robots** setting (index, follow)

### 3. **Social Media Setup**

1. ✅ Enable **"Open Graph Tags"**
2. Set **default OG image** URL (1200x630px recommended)
3. ✅ Enable **"Twitter Cards"**
4. Add your **Twitter username** (without @)

### 4. **Integration Setup**

1. Go to **Integrations tab**
2. **Review available integrations** - automatically detects installed plugins
3. **Toggle integrations** on/off as needed
4. **Save integration settings**

---

## 🔌 Plugin Integrations

### 🛒 **WooCommerce Integration**

Transform your product SEO with automated enhancements:

**Features:**

- Product-specific title templates with pricing placeholders
- Enhanced descriptions with inventory status and pricing
- Category and attribute keyword extraction
- Schema markup for rich product snippets
- Sale status integration in meta descriptions

**Title Placeholders:**

```php
%%price%%           // Product price: $29.99
%%sale%%            // "On Sale" if applicable
%%stock%%           // "In Stock" or "Out of Stock"
%%product_category%% // Primary product category
```

**Example Templates:**

```php
// Product page
'%%title%% - %%price%% | %%product_category%% | %%sitename%%'
// Result: "Gaming Laptop - $899.99 | Electronics | Your Store"
```

### 🔧 **Advanced Custom Fields Integration**

Leverage your custom fields for SEO automation:

**Supported Field Names:**

- `seo_description` - Override auto-generated descriptions
- `seo_keywords` - Custom focus keywords
- `focus_keywords` - Alternative keyword field
- `summary` - Page summary content
- `excerpt` - Custom excerpt field
- `og_title` - Custom Open Graph title
- `og_description` - Custom OG description
- `og_image` - Custom OG image

**Priority System:**

1. ACF custom fields (highest priority)
2. Yoast SEO existing data
3. Auto-generated content (fallback)

### 🎨 **Page Builder Integrations**

**Elementor Integration:**

- Extracts text from all Elementor widgets and sections
- Parses page builder data for comprehensive content analysis
- Works with any Elementor layout or template

**Beaver Builder Integration:**

- Processes Beaver Builder modules and content
- Extracts text from all builder elements
- Maintains layout-aware SEO generation

### 📅 **Events Calendar Integration**

Perfect for event websites and venues:

**Event Placeholders:**

```php
%%event_date%%    // Event start date: "March 15, 2024"
%%event_venue%%   // Venue name: "Madison Square Garden"
%%event_time%%    // Event time: "7:00 PM"
```

**Auto-Generated Content:**

- Event dates in titles and descriptions
- Venue information integration
- Calendar-specific meta optimization

### 🌐 **Multi-Language Support**

**WPML Integration:**

- Language-specific title templates
- Localized meta descriptions
- Regional SEO optimization

**Polylang Integration:**

- Multi-language keyword extraction
- Language-aware content processing
- Localized social media tags

---

## 🎯 Usage Examples

### **Automated Processing**

The plugin runs automatically and:

1. **Scans daily** for content missing SEO data
2. **Generates titles** using your custom templates
3. **Creates descriptions** from content or excerpts
4. **Extracts keywords** from content, categories, tags
5. **Logs activities** for monitoring and debugging
6. **Sends weekly reports** with SEO audit findings

### **Manual Tools**

Access powerful manual tools for immediate results:

- **🔄 Bulk Update**: Process all content immediately
- **👁️ Meta Preview**: See generated tags before publishing
- **🧪 Test Utility**: Comprehensive validation and debugging
- **📊 Activity Monitor**: Track all plugin operations
- **⚙️ System Info**: Integration status and scheduling details

### **Generated Meta Tags Example**

```html
<!-- Basic SEO Tags -->
<meta name="description" content="Complete guide to WordPress SEO automation with step-by-step instructions and expert tips.">
<meta name="keywords" content="wordpress, seo, automation, guide, optimization, meta tags">
<meta name="author" content="Your Name">
<meta name="robots" content="index, follow">
<meta name="google-site-verification" content="your_verification_token">

<!-- Open Graph Tags -->
<meta property="og:title" content="WordPress SEO Automation Guide | Your Site">
<meta property="og:description" content="Complete guide to WordPress SEO automation with expert tips.">
<meta property="og:image" content="https://yoursite.com/images/seo-guide.jpg">
<meta property="og:url" content="https://yoursite.com/wordpress-seo-guide">
<meta property="og:type" content="article">
<meta property="og:site_name" content="Your Site Name">

<!-- Twitter Card Tags -->
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:site" content="@yourusername">
<meta name="twitter:title" content="WordPress SEO Automation Guide">
<meta name="twitter:description" content="Complete guide to WordPress SEO automation.">
<meta name="twitter:image" content="https://yoursite.com/images/seo-guide.jpg">
```

---

## 🛠️ Advanced Configuration

### **Custom Title Templates**

Create dynamic, SEO-optimized titles for each post type:

```php
// Available placeholders
%%title%%        // Post/page title
%%sitename%%     // Your site name
%%currentdate%%  // Current year
%%category%%     // Primary category
%%author%%       // Post author name

// Example templates
'post' => '%%title%% | Expert Guide | %%sitename%%'
'page' => '%%title%% | %%sitename%% | %%currentdate%%'
'product' => '%%title%% - Best Price | %%sitename%%'
'event' => '%%title%% | %%event_date%% | %%sitename%%'
```

### **External Cron Setup** (Recommended)

For reliable automation on high-traffic sites:

```bash
# Add to your server's crontab
# Daily execution at 2 AM
0 2 * * * curl -s "https://yoursite.com/auto-seo-cron/YOUR-SECRET-KEY" > /dev/null

# Alternative: cPanel Cron Jobs
# Command: curl -s "https://yoursite.com/auto-seo-cron/YOUR-SECRET-KEY"
# Schedule: Daily at 02:00
```

### **Performance Optimization**

```php
// Recommended settings for different site sizes

// Small sites (<500 posts)
Batch Size: 50-100 posts
Memory Limit: 128MB+
Execution Time: 60 seconds

// Medium sites (500-2000 posts)
Batch Size: 25-50 posts
Memory Limit: 256MB+
Execution Time: 120 seconds

// Large sites (2000+ posts)
Batch Size: 10-25 posts
Memory Limit: 512MB+
Execution Time: 300 seconds
```

---

## 🔍 Testing & Debugging

### **Built-in Test Utility**

Access comprehensive testing at:

```
yoursite.com/wp-admin/admin.php?page=auto-seo-manager&test_meta=1
```

**Test Features:**

- ✅ Plugin status verification
- ✅ Sample meta tag generation
- ✅ Performance benchmarking
- ✅ Configuration validation
- ✅ Integration testing
- ✅ Conflict detection

### **Integration Testing**

1. Go to **Settings → Auto SEO Manager → Tools tab**
2. Click **"Run Manual Update"** to test processing
3. Check **Activity Log tab** for integration activity
4. Verify **System Information** shows active integrations
5. Use **Meta Preview** to see generated tags

### **Debug Mode**

Enable detailed logging in `wp-config.php`:

```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);

// Check logs
tail -f /wp-content/debug.log | grep "Auto SEO"
```

---

## 📊 Performance & Optimization

### **Efficiency Features**

- **Smart Batch Processing**: Handles large sites without timeouts
- **Memory Management**: Dynamic batch sizing based on available resources
- **Caching Integration**: Compatible with popular caching plugins
- **Database Optimization**: Indexed logging table for fast queries
- **Resource Monitoring**: Tracks memory usage and execution times

### **Site Impact**

- **Minimal Resource Usage**: Processes during low-traffic periods
- **Non-Blocking Operation**: Doesn't affect frontend performance
- **Graceful Fallbacks**: Continues working if integrations fail
- **Error Recovery**: Automatically retries failed operations

---

## 🛡️ Security Features

### **Built-in Security**

- ✅ **Nonce verification** for all admin actions
- ✅ **Capability checks** for user permissions
- ✅ **Input sanitization** for all settings
- ✅ **Secret cron keys** for external automation
- ✅ **SQL injection prevention** with prepared statements

### **Best Practices**

- Keep WordPress and plugins updated regularly
- Use strong admin passwords and 2FA
- Limit admin access to trusted users only
- Monitor activity logs for unusual behavior
- Use HTTPS for all admin operations

---

## 🔧 Troubleshooting

### **Common Issues & Solutions**

| Issue                               | Symptoms                            | Solution                                                                                              |
| ----------------------------------- | ----------------------------------- | ----------------------------------------------------------------------------------------------------- |
| **Plugin not generating meta tags** | No meta tags in source              | ✅ Verify Yoast SEO is active<br>✅ Enable "Auto Additional Meta"<br>✅ Check post types are selected |
| **Settings being erased**           | Options reset after saving          | ✅ Update to latest version<br>✅ Check file permissions<br>✅ Disable conflicting plugins            |
| **Integration not working**         | No enhanced SEO for products/events | ✅ Verify plugin is active<br>✅ Enable integration in settings<br>✅ Check integration logs          |
| **Cron not running**                | No automatic updates                | ✅ Set up external cron<br>✅ Check cron URL format<br>✅ Verify secret key                           |
| **Performance issues**              | Slow admin/timeouts                 | ✅ Reduce batch size<br>✅ Increase PHP memory limit<br>✅ Use external cron                          |

### **Debug Checklist**

1. ✅ Check WordPress and PHP versions meet requirements
2. ✅ Verify Yoast SEO is installed and active
3. ✅ Review activity logs for error messages
4. ✅ Test with default theme and minimal plugins
5. ✅ Check file permissions on plugin directory
6. ✅ Verify database table was created properly

---

## 🤝 Contributing

We welcome contributions from the community! Here's how to get involved:

### **Ways to Contribute**

- 🐛 **Bug Reports**: Help us find and fix issues
- ✨ **Feature Requests**: Suggest new functionality
- 📖 **Documentation**: Improve guides and examples
- 🌐 **Translations**: Add support for new languages
- 💻 **Code**: Submit pull requests with improvements

### **Development Setup**

```bash
# Clone the repository
git clone https://github.com/nyuchitech/auto-seo-manager.git
cd auto-seo-manager

# Install development dependencies
composer install
npm install

# Set up local WordPress environment
wp server --host=localhost --port=8080
```

### **Contribution Guidelines**

1. **Fork** the repository on GitHub
2. **Create** a feature branch: `git checkout -b feature/amazing-feature`
3. **Follow** WordPress coding standards
4. **Add** tests for new functionality
5. **Update** documentation as needed
6. **Submit** a pull request with detailed description

**Detailed Contributing Guide:** [CONTRIBUTING.md](CONTRIBUTING.md)

---

## 📝 Changelog

### **Version 1.0.0** (2025-01-01)

🎉 **Initial Release**

**🆕 New Features:**

- ✅ Core SEO automation engine with Yoast integration
- ✅ Comprehensive meta tags generation (description, keywords, author, robots)
- ✅ Open Graph and Twitter Cards for social media optimization
- ✅ Visual plugin integration system with 8+ supported plugins
- ✅ Professional admin interface with 4-tab navigation
- ✅ Advanced integration management with toggle controls
- ✅ Activity logging and monitoring system
- ✅ Built-in testing and debugging utilities
- ✅ Performance optimization with batch processing

**🔌 Integrations:**

- ✅ WooCommerce - Product SEO with pricing and inventory
- ✅ Advanced Custom Fields - Custom field SEO data
- ✅ Elementor - Page builder content extraction
- ✅ Beaver Builder - Layout-aware SEO generation
- ✅ The Events Calendar - Event-specific optimization
- ✅ WPML/Polylang - Multi-language support
- ✅ Gutenberg Blocks - Modern editor content extraction
- ✅ Custom Post Type UI - Extended post type support

**🛠️ Technical:**

- ✅ WordPress 5.0+ compatibility
- ✅ PHP 7.4+ requirement
- ✅ Database schema version 1.0
- ✅ Comprehensive security implementations
- ✅ Performance-optimized batch processing

### **Upcoming Features (v1.1.0)**

**🚀 Planned Additions:**

- 📊 Advanced analytics dashboard with SEO metrics
- 🔍 Google Search Console API integration
- 🧪 A/B testing framework for meta tag optimization
- 🌍 Enhanced multilingual features with RTL support
- 🔗 REST API endpoints for headless WordPress
- ⚡ WP-CLI commands for developers
- 📚 Additional plugin integrations (LearnDash, GiveWP, EDD)

---

## 📄 License

This plugin is licensed under the **GNU General Public License v2 or later**.

```
Auto SEO Manager WordPress Plugin
Copyright (C) 2025 Nyuchi Web Services
Developed by Bryan Fawcett (@bryanfawcett)

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.
```

**Full License:** [LICENSE](LICENSE)

---

## 👥 Credits & About

### **Development Team**

**🏢 Company:** [Nyuchi Web Services](https://nyuchi.com)  
**👨‍💻 Lead Developer:** Bryan Fawcett ([@bryanfawcett](https://github.com/bryanfawcett))  
**🤝 Contributors:** [View all contributors](https://github.com/nyuchitech/auto-seo-manager/contributors)

### **About Nyuchi Web Services**

Nyuchi Web Services specializes in WordPress development, SEO optimization, and web automation solutions. We create tools that help businesses improve their online presence through intelligent automation and best-practice implementations.

**🌐 Services:**

- Custom WordPress development
- SEO automation solutions
- Plugin and theme development
- Website optimization consulting
- Technical SEO audits

**📧 Contact:**

- **Website**: [https://nyuchi.com](https://nyuchi.com)
- **GitHub**: [https://github.com/nyuchitech](https://github.com/nyuchitech)
- **Email**: [hello@nyuchi.com](mailto:hello@nyuchi.com)

---

## 📞 Support & Resources

### **Getting Help**

| Type                        | Resource                                                                         | Response Time |
| --------------------------- | -------------------------------------------------------------------------------- | ------------- |
| 📚 **Documentation**        | [GitHub Wiki](https://github.com/nyuchitech/auto-seo-manager/wiki)               | -             |
| 🐛 **Bug Reports**          | [GitHub Issues](https://github.com/nyuchitech/auto-seo-manager/issues)           | 48 hours      |
| 💡 **Feature Requests**     | [GitHub Discussions](https://github.com/nyuchitech/auto-seo-manager/discussions) | 1 week        |
| 🏢 **Professional Support** | [Nyuchi Web Services](https://nyuchi.com)                                        | 24 hours      |
| 👨‍💻 **Developer Contact**    | [@bryanfawcett](https://github.com/bryanfawcett)                                 | Best effort   |

### **Community Resources**

- 📖 **Installation Guide**: [installation-guide.md](installation-guide.md)
- 🤝 **Contributing Guide**: [CONTRIBUTING.md](CONTRIBUTING.md)
- 🔧 **Developer API**: [Wiki - Developer Documentation](https://github.com/nyuchitech/auto-seo-manager/wiki/Developer-API)
- 📊 **Performance Tips**: [Wiki - Performance Optimization](https://github.com/nyuchitech/auto-seo-manager/wiki/Performance)

---

## 🌟 Show Your Support

If Auto SEO Manager has helped improve your website's SEO, please consider:

- ⭐ **Star this repository** on GitHub
- 📝 **Leave a review** on WordPress.org (coming soon)
- 🐛 **Report bugs** and suggest improvements
- 💡 **Contribute code** or documentation
- 📢 **Share** with the WordPress community

### **Sponsor Development**

Support ongoing development and new features:

- 💖 [GitHub Sponsors](https://github.com/sponsors/bryanfawcett)
- ☕ [Buy me a coffee](https://buymeacoffee.com/bryany)
- 🏢 [Professional services](https://nyuchi.com)

---

## 🏷️ Tags

`wordpress` `seo` `automation` `yoast` `meta-tags` `open-graph` `twitter-cards` `woocommerce` `elementor` `acf` `schema` `social-media` `performance` `optimization` `plugin-integration`

---

**Made with ❤️ by [Nyuchi Web Services](https://nyuchi.com) for the WordPress community**

_Specializing in WordPress SEO automation and web development solutions since 2020._
