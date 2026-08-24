=== Nyuchi SEO Addons - Automation for Yoast SEO ===
Contributors: nyuchi
Tags: yoast, seo, meta description, automation, rest api
Requires at least: 5.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.1.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Fills empty Yoast SEO fields automatically, drawing content from page builders and custom fields, with a REST API for automation clients.

== Description ==

Nyuchi SEO Addons writes into the Yoast SEO title, meta description and focus keyword
fields when they have been left empty. It is aimed at sites where the content volume has
outgrown the effort available to hand-write every field, and where a consistent template is
preferable to a blank.

It is not connected to, endorsed by, or affiliated with Team Yoast. Yoast SEO must be
installed and active; this plugin writes into Yoast's own fields rather than replacing them.

= Content extraction that understands page builders =

Generating a description from `post_content` produces nothing useful on a site built with a
page builder, because the readable text is stored inside builder data rather than in the
post body. This plugin extracts text from Elementor and Beaver Builder layouts, from
Gutenberg blocks, and from Advanced Custom Fields, so the generated description reflects
what the page actually says.

Each integration is a separate switch. Turning one off leaves its filters unregistered,
which is also the quickest way to isolate one when checking generated output.

= Title templates =

Per post type, with placeholders for the post title, the site name and the current year.
On sites running WP Travel, trip templates additionally accept the trip duration, price,
destination and primary activity, and separators around empty placeholders are collapsed so
a trip with no price does not render a stray divider.

= Logging you can actually leave switched on =

Activity logging has four levels. **Off** writes nothing. **Errors** records failures only.
**Actions** records real SEO changes and is the default. **Verbose** additionally records
integration start-up, which fires on every request and is intended for short debugging
sessions rather than permanent use.

A retention window and a hard row cap are applied by a daily cron, and both can be run on
demand. This exists because logging that grows without limit eventually becomes the
performance problem it was meant to help diagnose.

= REST API =

Routes under `auto-seo/v1` expose status, settings, log entries and a manual run, so the
plugin can be driven by automation and AI clients rather than only through wp-admin. Every
route requires an authenticated user with the `manage_options` capability.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`, or install it through the Plugins
   screen in WordPress.
2. Activate the plugin through the Plugins screen.
3. Open **SEO Addons** in the admin menu, choose the post types to process, and review the
   title templates.

Yoast SEO must be installed and active.

== Frequently Asked Questions ==

= Will it overwrite SEO fields I have written myself? =

No. Generation applies to fields that are empty. Existing titles, descriptions and focus
keywords are left alone.

= Does it replace Yoast SEO? =

No. It writes into Yoast's fields and depends on Yoast being active. Yoast remains the
source of truth for output, sitemaps and analysis.

= Why is the activity log empty? =

Either nothing has run yet, or the log level is set to Off or Errors. The default level,
Actions, records generated titles, descriptions and keywords. Run a manual update from the
Tools tab to confirm.

= The log table grew very large. What should I do? =

Set a retention window and a row cap on the Activity Log tab and leave daily pruning
enabled. Use Purge to clear existing entries. If the log grew while verbose logging was on,
lower the level to Actions.

= Can I trigger runs from outside WordPress? =

Yes. The Tools tab shows a secret cron URL for external schedulers, and the REST API exposes
a run endpoint for authenticated clients. Treat the cron URL as a credential.

== Changelog ==

= 1.1.0 =
* Logging levels, retention window, row cap, daily pruning, manual prune and purge.
* Integration start-up logging reclassified as verbose. It previously wrote one row per
  active integration on every request.
* REST API under `auto-seo/v1` for status, settings, logs and manual runs.
* WP Travel integration adding trip duration, price, destination and activity placeholders,
  trip-aware descriptions, and keywords drawn from trip taxonomies.
* Admin menu moved out of Settings to its own top-level menu; admin screen rebuilt.
* Fixed: log table identifier widened from `mediumint` to `bigint`, with a migration for
  existing installations.
* Fixed: the main automation switch was written under two different option names and read
  from the wrong one. Existing values are folded onto the correct name on upgrade.
* Fixed: scheduling moved off the front-end request path, and each scheduled task now
  follows its own switch.
* Security: nonce presence is checked before verification in the AJAX handler, and the
  external cron key is compared with `hash_equals()`.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.1.0 =
Adds logging controls and fixes a defect that could grow the activity log without limit.
Review the Activity Log tab after upgrading and set a retention window.
