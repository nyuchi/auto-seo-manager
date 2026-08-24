# Changelog

All notable changes to this plugin are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.0] - 2026-08-19

The plugin is now called **Yoast SEO Addons**. The directory, the main file and
the `auto_seo_` option prefix are unchanged, so this is an in-place upgrade
rather than a migration.

### Added

- Log verbosity levels. `auto_seo_log_level` takes `off`, `errors`, `actions`
  or `verbose`, and an entry is written only when its own level is at or below
  the configured one. The default is `actions`, which keeps the meaningful
  record — title and description updates, bulk runs, audits — and drops
  per-request bookkeeping.
- A retention window (`auto_seo_log_retention_days`, default 30) and a hard row
  cap (`auto_seo_log_max_rows`, default 20000).
- A daily `auto_seo_prune_log` event that applies both, gated by
  `auto_seo_log_pruning_enabled`. Deletes run in bounded batches, so a log left
  to grow for years cannot hold a write lock on the table for the length of the
  whole cleanup.
- Manual prune and purge from the admin screen. Prune applies the retention
  window and row cap; purge truncates the table and resets the auto-increment
  counter.
- A REST API under the `auto-seo/v1` namespace, so the plugin can be driven by
  MCP clients and other automation without screen-scraping wp-admin. Every
  route requires `manage_options`.

  | Method | Route | Purpose |
  | --- | --- | --- |
  | `GET` | `/wp-json/auto-seo/v1/status` | Version, DB version, master switch, whether Yoast is active, log statistics, integration availability, next scheduled run for each event. |
  | `GET` | `/wp-json/auto-seo/v1/settings` | Current values of the writable settings, plus `post_types` and `title_templates`. |
  | `POST` | `/wp-json/auto-seo/v1/settings` | Write settings. Keys are coerced by declared type; unrecognised keys are returned under `ignored` rather than written. |
  | `GET` | `/wp-json/auto-seo/v1/logs` | Log statistics and up to `limit` entries, capped at 500. |
  | `DELETE` | `/wp-json/auto-seo/v1/logs` | Purge the log, returning the number of rows removed. |
  | `POST` | `/wp-json/auto-seo/v1/run` | Run an update. With `post_id`, one post; without it, a site-wide run. |

  `POST /settings` rejects an unknown `log_level` with `auto_seo_bad_log_level`
  and HTTP 400. `POST /run` returns `auto_seo_yoast_missing` and HTTP 409 when
  Yoast SEO is not active, and `auto_seo_no_post` or `auto_seo_forbidden` for a
  `post_id` that does not exist or that the caller cannot edit.

- A WP Travel integration, for **WP Travel** (wptravel.io, by WEN Solutions).
  It initialises on `init` at priority 20, only when the `itineraries` post
  type exists, and adds:
  - the title placeholders `%%trip_duration%%`, `%%trip_price%%`,
    `%%trip_destination%%` and `%%trip_activity%%`;
  - trip-aware meta descriptions built from the trip's own facts;
  - focus keywords drawn from `travel_locations`, `activity`,
    `itinerary_types` and `travel_keywords`;
  - the day-by-day itinerary text in content extraction, read from the
    serialised `wp_travel_trip_itinerary_data` array;
  - Open Graph data for trips.

  The post type is filterable through `auto_seo_wp_travel_post_type`.

### Changed

- Integration start-up logging is now classified as verbose, which means it is
  dropped at the default `actions` level and stored only when someone
  deliberately turns verbosity up to debug an integration. Previously
  `load_integrations()` ran on every request and wrote a row for each active
  integration, roughly four rows per page load. On one production site this had
  grown `wp_auto_seo_log` to 1.94 million rows and 295 MB, and was measurably
  slowing post saves.
- The admin menu moved from Settings to a top-level menu at position 58, with
  Settings, Integrations, Activity Log and Tools sub-items. This is a
  daily-driver screen rather than a one-time configuration page. Admin URLs are
  now built through a single helper, since every link had to follow the move
  out of `options-general.php`.
- The admin interface was rebuilt around those tabs, and the log screen now
  reports row count, on-disk size, the oldest retained entry and the current
  verbosity level.

### Fixed

- The log table's `id` column was `mediumint(9)`, which tops out at 8,388,607
  rows. It is now `bigint(20) unsigned`. `dbDelta()` only runs on activation, so
  an already-installed site would never have picked the change up; a database
  version check on `admin_init` runs the `ALTER TABLE` for existing installs.
- The master switch was written under two different names: the defaults stored
  `auto_seo_enabled` while the settings form stored
  `auto_seo_auto_seo_enabled`, and the reader used the double-prefixed one. The
  same upgrade routine folds any double-prefixed value onto the canonical
  `auto_seo_enabled` and deletes the stray option, so whatever the site
  actually had configured survives.
- Cron scheduling moved from the front-end `wp` hook to `admin_init`, so
  scheduling is no longer re-checked on every visitor request. Each event now
  follows its own toggle: `daily_seo_update`, `weekly_seo_audit` and
  `auto_seo_prune_log` are scheduled while their option is on and cleared when
  it is turned off.

### Security

- The AJAX handler for a manual run now checks `isset($_POST['nonce'])` before
  calling `wp_verify_nonce()`. A missing key previously raised a notice on every
  malformed request, and `wp_verify_nonce(null)` is not a rejection path worth
  relying on.
- The external cron endpoint compares the supplied key with the stored secret
  using `hash_equals()`, and refuses the request outright when no secret is
  configured.

## [1.0.0] - 2025-05-22

Initial release. Automated Yoast SEO title, meta description and focus keyword
generation for selected post types, driven by a daily update and a weekly audit,
with plugin-aware content extraction for WooCommerce, ACF, Elementor, Beaver
Builder, The Events Calendar, multilingual sites, Gutenberg and Custom Post Type
UI. Activity is recorded in a custom log table, and updates can be triggered
manually from wp-admin or through a secret-key cron URL.
