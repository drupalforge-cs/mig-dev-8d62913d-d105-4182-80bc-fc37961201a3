# DrupalForge Migrate

Preflight-checks this Drupal site, archives its code, exports its database, and uploads both
artifacts to DrupalForge so DevPanel can migrate this site — without depending on Backup and
Migrate or any AWS SDK.

## Requirements

- Drupal 10.2+ or 11
- PHP 8.1+
- MySQL/MariaDB
- No Composer dependencies beyond core (this module's own runtime `require` is `php: >=8.1` only)

## Setup

1. Enable the module: `drush en drupalforge_migrate`.
2. Visit `/admin/config/development/drupalforge-migrate` and enter:
   - **Migration ID** — the UUID DrupalForge issued for this migration.
   - **Secret** — the matching migration secret (write-only; it is never redisplayed and is stored
     outside exported configuration).

   The DrupalForge instance itself is not a form field — it's hardcoded to production
   (`https://www.drupalforge.org`) in `DrupalForgeClient::DEFAULT_URL`. To test against a dev/stage
   DrupalForge instead, a developer sets this in `settings.php` (never in exported config):

   ```php
   $settings['drupalforge_migrate_url'] = 'https://stage.drupalforge.example';
   ```
3. Click **Test connection** to confirm the site can reach DrupalForge and see your current
   backup limits and existing backups.

## Running a backup

Either from the command line or the web UI - both share the same underlying service layer, so the
result is identical either way.

### Web UI

Visit `/admin/config/development/drupalforge-migrate/status` (the **Status** tab next to
**Settings**). Click **Start backup** to archive the code, export the database, upload both to
DrupalForge, and mark the backup ready - tracked as a Batch API run with a progress bar. If a
backup is interrupted partway through an upload (browser closed, network dropped), reloading this
page shows a **Resume backup** action instead, which continues uploading only the parts that
haven't been recorded as done yet - already-uploaded parts are never re-sent.

### Drush

- `drush drupalforge-migrate:preflight` — reports whether this site is supported (docroot/PHP/DB
  driver detection, CLI tool availability, size estimates) and names the specific failing check
  if not.
- `drush drupalforge-migrate:backup [--db-only] [--code-only]` — archives the code and/or exports
  the database, uploads both to DrupalForge (single PUT or resumable multipart, depending on
  artifact size), and marks the backup `ready`. Runs to completion in one blocking call (no
  per-request time limit, unlike the web/Batch path).
- `drush drupalforge-migrate:backup --resume` — resumes a previously interrupted backup: finishes
  any in-progress database export cursor (web/PHP-fallback mode only), then resumes/finishes
  uploading whichever local artifacts already exist on disk and completes the backup.
- `drush drupalforge-migrate:status` — shows the last recorded backup status and any backups
  DrupalForge already knows about.
- `drush drupalforge-migrate:list` — lists backups known to DrupalForge for this migration.

Any failure at any step (preflight, archiving, exporting, or uploading) aborts the backup - the
DrupalForge reservation is deleted immediately rather than left dangling until it expires - and the
specific failing step is named in the error message, in both the Drush output and the status page.
A backup can never be marked `ready` on DrupalForge unless every requested artifact uploaded
successfully.

## Testing

This module's tests currently run against the host site's own installed Drupal core (rather than
a separate standalone runtime like `drupalforge_deploy` uses) - see `TODO.md` for the open
question of whether to switch to a standalone runtime later. From the site root:

```bash
ddev composer require --dev phpunit/phpunit:^9.6 phpspec/prophecy-phpunit:^2.4 drupal/core-dev:^10.6
```

Then, from *inside this module's directory* (PHPUnit's `<directory>` paths in `phpunit.xml.dist`
resolve most reliably against the current working directory):

```bash
ddev exec bash -c "cd web/modules/upgrade/drupalforge_migrate && /var/www/html/vendor/bin/phpunit"
```

Or a single suite: `... phpunit --testsuite unit` / `... phpunit --testsuite kernel` /
`... phpunit --testsuite functional`.

Note: `vendor/bin/phpunit` only accepts one file/directory path argument at a time - to run more
than one specific test file in one invocation, pass a directory (or a `--testsuite`) instead of
listing multiple file paths.

### Smoke testing

The PHPUnit suite above fakes every external system (DrupalForge, shell tools). `scripts/smoke/`
is a separate, real-HTTP end-to-end smoke test: it runs the actual Drush commands against a real
local HTTPS server standing in for DrupalForge (`scripts/smoke/fake-drupalforge-api.php`), with
real file uploads and real sha256 verification. It proves:

- preflight reports this site supported;
- a full backup (archive + export + upload + complete) really reaches `ready`, with matching
  checksums;
- an interrupted upload resumes without re-uploading already-confirmed parts;
- a forced upload failure aborts the reservation and never calls `complete` - the module's core
  safety guarantee.

Run it from the site root (or anywhere - it locates itself):

```bash
bash web/modules/upgrade/drupalforge_migrate/scripts/smoke/run.sh
```

It requires `ddev` and an already-started project, temporarily points the module at the fake API
(a `settings.php` override, restored exactly on exit - success, failure, or Ctrl-C), and prints a
PASS/FAIL summary per scenario. It leaves no residue: `scripts/smoke/.state/` (certs, logs, PID
tracking) is removed on exit either way.

## Status

The module's planned scope (preflight → connect → backup → upload → complete, over both Drush and
the web/Batch UI, with resumable multipart uploads and an abort-on-any-failure contract) is
complete as of Slice 3. See `DESIGN.md` for the architecture, `CHANGELOG.md` for what shipped in
each slice, and `TODO.md` for remaining polish items (linting/static-analysis tooling, and a few
documented simplifications that weren't required by the current acceptance criteria).
