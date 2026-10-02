# Design

`drupalforge_migrate` runs on a customer's Drupal 10/11 site and produces a DrupalForge-hosted
backup (code archive + database dump) that DevPanel can boot from, without depending on Backup
and Migrate or any AWS SDK.

## Architecture

```
Drush command  ──┐
                  ├──> BackupOrchestrator ──> PreflightService
Batch API steps ─┘                       ├──> CodeArchiver
                                          ├──> DatabaseExporter
                                          ├──> Uploader ──> DrupalForgeClient ──> DrupalForge API
                                          └──> ProgressStateStore (State-backed, resumable)
```

`BackupOrchestrator` is the single service both the CLI and the web/Batch path call, so archiving,
exporting, and uploading logic exists exactly once.

## Phased delivery

- **Slice 1:** module skeleton, `ShellRunner`, `PreflightService`, `DrupalForgeClient`
  (connect/listBackups/reserve/abort, upload request stubbed), settings form with "Test connection",
  a `preflight` Drush command.
- **Slice 2:** `CodeArchiver`, `DatabaseExporter` (CLI + web/batch modes), `ProgressStateStore`,
  `BackupOrchestrator`, `:backup`/`:status`/`:list` Drush commands. `:backup` produced artifacts
  locally but did not yet upload them or call `complete`.
- **Slice 3 (this slice, module complete):** `Uploader` (single PUT + resumable multipart),
  `DrupalForgeClientInterface::complete()`, `BackupBatch` Batch API operations, the status page, and
  wiring `BackupOrchestrator`'s artifact steps (`runFullBackup()`/`resumeBackup()`) into an actual
  upload + `complete` call. A backup now genuinely reaches `ready` on DrupalForge, on both the
  Drush/CLI path and the web/Batch path, with resumable multipart uploads on both.

## Interfaces over final classes

`ShellRunner`, `DrupalForgeClient`, `CodeArchiver`, `DatabaseExporter`, and `Uploader` are `final`
classes with corresponding `*Interface` interfaces (`ShellRunnerInterface`,
`DrupalForgeClientInterface`, `CodeArchiverInterface`, `DatabaseExporterInterface`,
`UploaderInterface`). Every other service depends on the
interface, not the concrete class. This is what lets PHPUnit mock `ShellRunner` in
`PreflightServiceTest`, what lets `BackupOrchestratorTest` fake a `CodeArchiver` that throws (to
test the abort-on-failure contract) without needing a real filesystem/tar, and what lets
`drupalforge_migrate_test_support` swap in a fake `DrupalForgeClientOverride` via Symfony service
decoration for functional tests - PHPUnit/Symfony cannot mock or decorate `final` classes
directly. `ProgressStateStore` stays a plain `final` class with no interface: nothing needs to
fake it in tests, since constructing a real one against Drupal's in-memory `State`
(`KeyValueMemoryFactory` + `NullBackend` + `NullLockBackend`) is just as easy and exercises the
real key-namespacing logic.

## Web/batch database export: why plain SQL, not a live gzip stream

`DatabaseExporter::exportStepWeb()` cannot keep a single gzip stream open across separate bounded
calls (a Batch API operation, or a resumed Drush invocation, is a fresh PHP process each time) -
gzip's format doesn't support being safely reopened and appended to after a prior close. Instead,
each step appends plain SQL to an uncompressed sibling file
(`sys_get_temp_dir() . '/drupalforge_migrate_<backup_id>.sql'`), and only the final step (once
every table is done) streams that file through PHP's `gz*` functions into the actual
`db.sql.gz` destination. The intermediate `.sql` file is deleted once finalized.

## mysqldump credential handling

`DatabaseExporter::exportViaCli()` writes DB credentials to a temporary `--defaults-extra-file`
(permissions `0600`, deleted in a `finally` block) rather than passing `-p<password>` as a bare
CLI argument. A CLI argument is visible to any other process on the host via `ps`/`/proc`; a
defaults-extra-file is not. This is a small amount of extra complexity (a temp file to create and
clean up) that was judged worth it given these are real production database credentials, not test
fixtures.

## Security

- `migration_id` is ordinary exported config (`drupalforge_migrate.settings`). The DrupalForge base
  URL is hardcoded to production (`DrupalForgeClient::DEFAULT_URL`) rather than a form field, to
  rule out accidental misconfiguration; a developer overrides it per-environment only via
  `$settings['drupalforge_migrate_url']` in settings.php (dev/stage testing), never through the UI
  or exported config.
- The Migration Secret is written and read exclusively through `\Drupal\Core\State\StateInterface`
  (key `drupalforge_migrate.secret`) — the config schema has no `secret` key, so it cannot be
  exported via `drush cex` even by mistake.
- Every DrupalForge API call authenticates with `Authorization: Bearer <migration_id>.<secret>`
  over HTTPS.

## Failure contract

Any failed preflight/archive/export/upload step must never leave a `ready` backup on DrupalForge:
the orchestrator explicitly `DELETE`s the reservation on failure (or lets it expire if the abort
call itself cannot reach the server) and records the specific failing step/check so it can be
surfaced to the user on both the Drush and web paths.
