# Nyuchi WordPress Optimization

> SEO, metadata and database maintenance for WordPress sites that have outgrown
> the effort available to do it by hand.

[![Lint](https://github.com/nyuchi/auto-seo-manager/actions/workflows/lint.yml/badge.svg)](https://github.com/nyuchi/auto-seo-manager/actions/workflows/lint.yml)
[![CI](https://github.com/nyuchi/auto-seo-manager/actions/workflows/ci.yml/badge.svg)](https://github.com/nyuchi/auto-seo-manager/actions/workflows/ci.yml)
[![License: GPL v2 or later](https://img.shields.io/badge/License-GPL_v2_or_later-blue.svg?style=flat-square)](https://www.gnu.org/licenses/gpl-2.0.html)
![WordPress](https://img.shields.io/badge/WordPress-5.0%2B-21759B?style=flat-square&logo=wordpress&logoColor=white)
![PHP](https://img.shields.io/badge/PHP-7.4%2B-777BB4?style=flat-square&logo=php&logoColor=white)

**Version:** 1.7.1 | **Requires:** WordPress 5.0+, PHP 7.4+ | **Tested up to:** WordPress 7.0 | **Releases:** [GitHub Releases](https://github.com/nyuchi/auto-seo-manager/releases)

> The repository is `auto-seo-manager` and the plugin directory, main file and
> `auto_seo_` option prefix still carry that name. The product is called
> **Nyuchi WordPress Optimization**; the two names refer to the same thing.

---

## What it is

A single WordPress plugin covering two jobs that usually need several: filling
in the SEO metadata nobody has time to write, and finding what has gone wrong
in the database and the media library underneath it.

**SEO.** The plugin writes into the Yoast SEO title, meta description and focus
keyword fields **only when they have been left empty**. Yoast SEO must be
installed and active — this writes into Yoast's own fields rather than
replacing them. It is not connected to, endorsed by, or affiliated with Team
Yoast.

Generating a description from `post_content` produces nothing useful on a site
built with a page builder, because the readable text lives inside builder data
rather than in the post body. Content is therefore extracted from Elementor and
Beaver Builder layouts, Gutenberg blocks and Advanced Custom Fields. Each
integration is a separate switch, which is also the quickest way to isolate one
when the generated output looks wrong.

Title templates are per post type, with placeholders for the post title, site
name and current year. On sites running WP Travel, trip templates additionally
accept duration, price, destination and primary activity, and separators around
empty placeholders collapse so a trip with no price does not render a stray
divider.

**Maintenance.** Every WordPress site accumulates rows nothing reads any more —
meta whose post was deleted, revisions nobody will roll back, transients that
expired years ago, and the debris left by plugins that have since been removed.
The database module reports what that is costing and cleans it up; a separate
editor reads and writes individual values; a third module drops orphaned tables
and finds options belonging to plugins that are gone.

The media modules exist because of a specific and recurring failure: an image
optimiser that rewrites files to AVIF or WebP in place without touching the
attachment record, leaving WordPress describing a file that is not there.
`media-repair.php` reconciles the record with the bytes on disk;
`media-convert.php` converts attachments back to a format the pipeline will
accept; `image-sizes.php` restores sizing at the Cloudflare Images delivery
layer after offloading has removed the local sub-sizes.

**Automation surfaces.** Everything above is reachable without wp-admin. The
REST API lives under the `auto-seo/v1` namespace and every route requires
`manage_options`. The same operations are also registered against the WordPress
Abilities API, so an MCP client can drive the plugin directly.

## Install

This plugin is **not** distributed through wordpress.org.

### From a GitHub release (recommended)

1. Download the latest ZIP from
   [Releases](https://github.com/nyuchi/auto-seo-manager/releases).
2. In wp-admin go to **Plugins → Add New → Upload Plugin** and upload it.
3. Activate the plugin.
4. Configure at **Settings → Auto SEO Manager**.

Once installed, the plugin wires GitHub Releases into WordPress's own update
machinery, so later versions appear as a normal "update available" notice.

### Manually

```bash
git clone https://github.com/nyuchi/auto-seo-manager.git \
  wp-content/plugins/auto-seo-manager
```

Then activate through **Plugins** in wp-admin.

Install and activate **Yoast SEO** first. The SEO modules stay inert without
it; the database and media modules do not need it.

See [installation-guide.md](https://github.com/nyuchi/auto-seo-manager/blob/main/installation-guide.md)
for the longer version.

## Usage

Read plugin status over REST:

```bash
curl -s "https://example.com/wp-json/auto-seo/v1/status" \
  -u "user:application-password"
```

Run an update for a single post:

```bash
curl -s -X POST "https://example.com/wp-json/auto-seo/v1/run" \
  -u "user:application-password" \
  -H "Content-Type: application/json" \
  -d '{"post_id": 123}'
```

Omit `post_id` for a site-wide run.

### REST routes

All routes require `manage_options`.

| Method   | Route                           | Purpose                                                                        |
| -------- | ------------------------------- | ------------------------------------------------------------------------------ |
| `GET`    | `/wp-json/auto-seo/v1/status`   | Version, DB version, master switch, Yoast state, log stats, next scheduled run |
| `GET`    | `/wp-json/auto-seo/v1/settings` | Writable settings, plus `post_types` and `title_templates`                     |
| `POST`   | `/wp-json/auto-seo/v1/settings` | Write settings; unrecognised keys are returned under `ignored`                 |
| `GET`    | `/wp-json/auto-seo/v1/logs`     | Log statistics and up to `limit` entries, capped at 500                        |
| `DELETE` | `/wp-json/auto-seo/v1/logs`     | Purge the log, returning the number of rows removed                            |
| `POST`   | `/wp-json/auto-seo/v1/run`      | Run an update, for one post or site-wide                                       |

`POST /run` returns HTTP 409 and `auto_seo_yoast_missing` when Yoast SEO is not
active.

### Logging

Activity logging has four levels, set through `auto_seo_log_level`:

| Level     | Records                                                         |
| --------- | --------------------------------------------------------------- |
| `off`     | Nothing                                                         |
| `errors`  | Failures only                                                   |
| `actions` | Real SEO changes — the default                                  |
| `verbose` | Integration start-up as well; for short debugging sessions only |

`verbose` fires on every request. Left on, it grows quickly: on one production
site the log table reached 1.94 million rows and 295 MB, and was measurably
slowing post saves. A daily prune applies a retention window
(`auto_seo_log_retention_days`, default 30) and a row cap
(`auto_seo_log_max_rows`, default 20000) in bounded batches.

## Architecture

One file per concern, loaded from `auto-seo-manager.php`.

| File                   | Concern                                                                      |
| ---------------------- | ---------------------------------------------------------------------------- |
| `auto-seo-manager.php` | Plugin header, bootstrap, SEO generation, scheduling                         |
| `admin-page.php`       | The tabbed admin screen                                                      |
| `integrations.php`     | Elementor, Beaver Builder, Gutenberg, ACF, WooCommerce, WP Travel extraction |
| `abilities.php`        | WordPress Abilities API surface, mirroring the REST routes                   |
| `database.php`         | Database diagnostics and bulk cleanup                                        |
| `database-editor.php`  | Reading, locating and writing individual values                              |
| `tables.php`           | Dropping orphaned tables; finding options left by removed plugins            |
| `metrics.php`          | Site health as numbers — autoload size, cron queue, growth over time         |
| `media-repair.php`     | Reconciling attachments whose file and metadata disagree                     |
| `media-convert.php`    | Converting mismatched attachments back to an ingestible format               |
| `image-sizes.php`      | Cloudflare Images sizing after offload removes local sub-sizes               |
| `plugin-manager.php`   | Plugin inventory, update checks and updates                                  |
| `elementor.php`        | Elementor page construction from `_elementor_data`                           |
| `updater.php`          | Wiring GitHub Releases into the WordPress update machinery                   |
| `meta-tags-test.php`   | Optional testing utility                                                     |

## Changelog

See [CHANGELOG.md](https://github.com/nyuchi/auto-seo-manager/blob/main/CHANGELOG.md).

## Contributing

See [CONTRIBUTING.md](https://github.com/nyuchi/auto-seo-manager/blob/main/CONTRIBUTING.md).
Bug reports and feature requests go to
[Issues](https://github.com/nyuchi/auto-seo-manager/issues).

## Licence

Licensed under the [GNU General Public License v2 or later](https://www.gnu.org/licenses/gpl-2.0.html);
see [LICENSE](https://github.com/nyuchi/auto-seo-manager/blob/main/LICENSE).

© Nyuchi Web Services. Developed by Bryan Fawcett
([@bryanfawcett](https://github.com/bryanfawcett)).
