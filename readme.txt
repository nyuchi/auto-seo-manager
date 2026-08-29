=== Nyuchi WordPress Optimization ===
Contributors: nyuchi
Tags: seo, database, cleanup, metadata, automation
Requires at least: 5.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.6.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

SEO, metadata, and database cleaning and editing. Fills empty Yoast SEO fields, reports what is costing the database, and exposes both to the REST API.

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

= 1.6.0 =
* Drop tables left behind by plugins that are no longer installed, so the
  third-party database cleaner can be retired. Dropping is irreversible, so it
  is a dry run unless told otherwise, refuses core tables outright, refuses
  tables outside this install's prefix unless forced, and re-derives whether a
  table is really an orphan rather than trusting the caller.
* Table attribution is now a map first and a name heuristic only as a fallback.
  The heuristic on its own reported Yoast's, Elementor's, WP Travel's and this
  plugin's own tables as orphans, and acting on that would have deleted a live
  plugin's data.
* Report options left behind by uninstalled plugins - the wp_options equivalent
  of an orphaned table. Read-only; deletion goes through the existing tools.
* Site metrics as a readable surface: PHP and memory limits, OPcache, object
  cache, database size and overhead, content counts by type, term and user
  counts, and the cron queue including how far behind it is running.
* Health checks return a plain pass or fail with a sentence of explanation each,
  rather than numbers a reader has to interpret.
* A daily recorded history, so growth is visible rather than only the current
  moment. Stored without autoload, because an ever-growing autoloaded option is
  precisely the fault this plugin exists to find.
* List installed plugins with their update status, check for updates on demand,
  apply updates, and activate or deactivate a plugin. Updating defaults to a dry
  run, reports whether each plugin is still active afterwards, and refuses to
  update this plugin from inside itself.
* Read and build Elementor pages, including a page's element tree, a widget's
  available controls, and creating or patching a layout from a simplified
  description. Elementor is not a dependency: its absence is reported as a fact
  about the site rather than failing the call.

= 1.5.0 =
* Image sizes work again on sites offloading to Cloudflare Images. Offloading
  removes the local sub-sizes and, on some sites, the recorded width and height
  with them. WordPress is then left holding one image of unknown dimensions, so
  every request for medium, large or a theme's own size resolves to the same
  file, and it is delivered width-constrained only. Nothing crops, so the
  aspect ratio is always whatever the source photograph happened to be.
* The requested shape now goes into the delivery URL, where Cloudflare can
  honour it. A size that crops becomes a width, a height and fit=cover; one
  that does not becomes a width and fit=scale-down, which is the same promise
  WordPress made. Nothing is regenerated and nothing is re-uploaded.
* Crops use gravity=auto, which picks the focal point by detecting the most
  visually interesting part of the image rather than taking the middle.
* The srcset is rewritten to match the src, so a browser choosing a wider
  candidate gets the same shape rather than swapping a cropped image for an
  uncropped one while the page is still loading.
* Width and height go back onto the tag, so there is an aspect ratio to
  reserve before the image arrives and the page stops shifting as it loads.
* Only flexible variants on imagedelivery.net are rewritten. A named variant
  takes no parameters and is left alone, as is any URL the plugin does not
  recognise, and parameters already present are preserved.

= 1.4.0 =
* Read and write individual database values. The database module reported and
  deleted in bulk but could not read or change one thing, which is the half
  the name "editor" was promising.
* db-read returns a single option or meta value with its type, size, whether
  it is serialised and whether it is autoloaded.
* db-find reports which rows hold a string, across options, meta and post
  content. Read-only, and the sensible step before a replace - it says how
  many rows a change would touch before anything is touched.
* db-write sets one value, defaults to a dry run, and returns the previous
  value so a change can be undone without having read it first.
* db-replace does find-and-replace across the database, unserialising values
  before replacing and re-serialising after. A plain SQL replace changes the
  text of a serialised array without changing the byte-length prefixes PHP
  wrote into it, and the result no longer unserialises - WordPress then reads
  the option as empty and settings quietly disappear, usually noticed long
  after the replace that caused it.
* Options that can make the site or the admin unreachable - siteurl, home,
  active_plugins and similar - are refused unless asked for by name.

= 1.3.2 =
* The Nyuchi wordmark is capitalised. It was being lowercased in CSS, which
  rendered the company name as a styling choice rather than a name.

= 1.3.1 =
* A Database tab. The database features shipped in 1.2.x as abilities only,
  reachable by an MCP client but invisible in the admin - so a plugin renamed
  for database work showed no sign of doing any. The tab reports database size,
  reclaimable overhead, the weight of the autoloaded options read on every
  request, and every category of row nothing reads, with the counts and the
  buttons to clear them.
* The active tab rendered square while every other tab and button was a pill.
  After a click the active tab is also the focused one, and the focus ring was
  drawn with outline - which has only followed border-radius since Safari 16.4
  and not at all in some older engines, so it painted a rectangle around a
  pill. The ring now uses box-shadow, which has always followed the radius.
* A transparent outline is kept alongside it on purpose: Windows High Contrast
  Mode drops box-shadow entirely and forces transparent outlines to a visible
  colour, so removing it would make focus invisible to the people who most
  depend on seeing it.

= 1.3.0 =
* Updates now arrive through GitHub Releases, so this plugin gets an update
  notice and a one-click update like any directory plugin. Until now every
  change had to be copied onto the server by hand, which meant the installed
  copy matched no released artifact and there was no way to tell what a site
  was actually running.
* Attachment repair. An optimiser that converts a file in place and renames it
  without updating the attachment record leaves WordPress describing a file
  that is not there: the library reports the wrong type, offload plugins send
  bytes the destination rejects, and nothing errors because as far as
  WordPress is concerned nothing failed. media-mismatches lists them;
  media-repair repoints the record and rebuilds the attachment metadata, and
  restores the original pointer if that rebuild fails.
* Continuous integration on PHP 7.4 and 8.3, with a guard against the plugin
  header, AUTO_SEO_VERSION and the readme stable tag drifting apart - a
  mismatch there means the updater offers a version that is not the one people
  receive.

= 1.2.3 =
* Abilities moved from the nyuchi-seo namespace to nyuchi-optimization, so the
  names match what the plugin now is. This renames every ability: anything
  calling nyuchi-seo/get-status needs updating to nyuchi-optimization/get-status.
  Done now, while the only consumer is a single site, rather than later.

= 1.2.2 =
* Fixed settings not saving. Moving the save to admin_init in 1.1.0 introduced
  a guard that tested the submit field with empty(). The save buttons carry a
  name but no value, so a browser submits an empty string, which empty() reads
  as absent - every save from Settings, Integrations and Logs was discarded
  before anything was written. Tools was unaffected because it posts a hidden
  submit=1, which is why the failure looked partial rather than total.
* Selected post types are now checked against the registered types before
  being stored. The submitted array went straight into the option, and every
  later read hands it to WP_Query, where an unregistered type returns nothing
  at all rather than raising an error.

= 1.2.1 =
* Three more database abilities, which together are what a dedicated cleaner
  plugin is usually installed for: tables that no plugin on disk appears to
  claim, scheduled events whose hook has no listener, and OPTIMIZE TABLE to
  reclaim the overhead the size report shows.
* Both detections are reported as candidates rather than verdicts. Table
  attribution is a name heuristic and some plugins name tables nothing like
  their slug; cron hooks are frequently registered conditionally, so a hook
  with no listener in one request may have a perfectly good one in another.
  Neither removes anything on its own.

= 1.2.0 =
* Renamed to Nyuchi WordPress Optimization. The plugin had grown past
  "SEO addons": it now covers metadata and the database as well, and the old
  name described about a third of it.
* Database module. Four new abilities: a per-table size and overhead report,
  a count of every category of row nothing reads any more, a breakdown of the
  autoloaded options that are read on every single request, and a cleanup
  operation for named categories.
* Cleanup defaults to a dry run. It reports what would be removed and changes
  nothing unless dry_run is explicitly false, so a malformed call fails safe.
  Deletion is capped per call, because a single statement across millions of
  rows gets killed part-way through on managed hosting.
* Built on $wpdb rather than on another cleanup plugin's internals, so nothing
  here stops working when that plugin is deactivated.
* Admin sections switch without reloading the page, and the active tab now
  matches the shape of the buttons beside it.
* Settings save before output begins, fixing the blank screen on save.

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

= 1.6.0 =
Adds the ability to drop database tables and apply plugin updates. Both are
irreversible and both default to a dry run - read what a call reports before
running it again with the dry run turned off.

= 1.5.0 =
Images start cropping to the size that was requested instead of keeping the
source photograph's shape. This is the intended behaviour, but it will change
how existing pages look. Check a page with a card grid after upgrading.

= 1.1.0 =
Adds logging controls and fixes a defect that could grow the activity log without limit.
Review the Activity Log tab after upgrading and set a retention window.
