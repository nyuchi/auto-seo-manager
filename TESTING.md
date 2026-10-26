# Testing

The PHPUnit suite runs against a real WordPress, on SQLite, so it needs no
MySQL server: the WordPress test library (`wp-phpunit/wp-phpunit`), WordPress
core and the [SQLite Database Integration](https://wordpress.org/plugins/sqlite-database-integration/)
drop-in. The plugin is loaded the way a site loads it, from
`wp-content/plugins/auto-seo-manager` (a symlink to the working tree) via
`active_plugins`.

## Running it

Requirements: PHP 8.2 or newer with `pdo_sqlite`, Composer, `curl`.

```bash
composer install
bash tests/bin/install-wp.sh   # once: WordPress + SQLite into $WP_CORE_DIR
vendor/bin/phpunit
```

`WP_CORE_DIR` defaults to `${TMPDIR:-/tmp}/wordpress-tests`. Set it to keep
WordPress somewhere else (outside any git checkout: WordPress will not
auto-update a plugin that sits under version control, and one test checks
that it would). `WP_VERSION` picks the WordPress release, e.g.
`WP_VERSION=6.8 bash tests/bin/install-wp.sh` for the newest 6.8.x; use a
fresh `WP_CORE_DIR` per version, and the matching test library:

```bash
composer update wp-phpunit/wp-phpunit --with "wp-phpunit/wp-phpunit:~6.8.0"
```

A run takes about ten seconds.

## What is covered

- `tests/integration/UpdaterTest.php`: the GitHub updater inside WordPress,
  with GitHub mocked on `pre_http_request`. Registration outside wp-admin,
  `wp_update_plugins()`, the automatic updater's decision, failure caching,
  version comparison, asset selection, the "View details" modal, and where the
  token is sent.
- `tests/integration/UpdaterContextTest.php`: each test boots a fresh
  WordPress in a child process (`tests/request.php`) as a wp-cron, REST,
  WP-CLI, front-end or wp-admin request, and one lets the wp-cron background
  updater really install a 9.9.9 release zip into a scratch plugins
  directory.
- `tests/integration/TokenRedirectTest.php`: real HTTP through WordPress's
  transport against PHP's built-in server: the token does not follow a
  redirect to another host.
- `tests/integration/AbilitiesTest.php`: every ability refuses logged-out
  visitors, subscribers, contributors, authors and editors and allows an
  administrator; every ability that takes `dry_run` changes nothing unless it
  is sent `dry_run: false`. On WordPress without the Abilities API (before
  6.9) it checks that the plugin loads and registers nothing.

No test reaches the network: `tests/includes/class-asm-test-http.php` answers
every request or refuses it.

## CI

`.github/workflows/tests.yml` runs the suite on PHP 8.2, 8.3, 8.4 and 8.5
against the latest WordPress, and on PHP 8.2 against WordPress 6.8. The
`PHPUnit` job is the single check to require.

PHPUnit stays on 9.6: the WordPress test library still calls PHPUnit APIs
that were removed in PHPUnit 10.

None of `composer.*`, `phpunit.xml.dist`, `tests/`, `vendor/` or this file is
shipped in the release zip.
