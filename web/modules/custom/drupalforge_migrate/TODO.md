# TODO

In-flight and planned work only. Completed work moves to `CHANGELOG.md`.

Slice 3 closes out the module's planned scope (preflight → connect → backup → upload → complete,
CLI and web/Batch, resumable multipart, the error-abort contract). What's left is polish and known
simplifications, not missing acceptance-surface functionality.

## Tooling follow-ups

- [ ] Add `phpcs.xml.dist`/`phpstan.neon` (deferred in Slices 1 and 2 for the same reason: avoid a
      tooling rabbit hole while there was still a lot of production code to land). Now that the
      module's scope is complete, this should land before any drupal.org release.
- [ ] Evaluate whether to mirror `drupalforge_deploy`'s standalone-runtime CI bootstrap
      (`scripts/testing/bootstrap-runtime.sh`, its own `vendor/drupal/drupal` copy) or keep running
      this module's tests against the host site's Drupal core (current approach: site-level
      `require-dev` additions - see `README.md` for exact invocation).
- [ ] `DatabaseExporter::exportStepWeb()`'s row pagination has no explicit `ORDER BY`; fine for a
      bounded backup snapshot with no concurrent writes, not a hard guarantee.
- [ ] `DatabaseExporter::exportViaCli()`'s mysqldump credential handling uses a
      `--defaults-extra-file` rather than `-p<password>` so the password never appears in
      `ps`/process-list output - see `DESIGN.md`.

## Known simplifications (candidates for a future slice, not required by the current acceptance criteria)

- [ ] Multisite support: `BackupOrchestrator::SITE_DIRS` is hardcoded to `['default']`. A real
      multisite detector (scanning `sites/sites.php` or the `sites/` directory) is out of scope.
- [ ] `BackupManifest`'s `file_counts` is always empty. Populating it accurately would need a
      second full filesystem walk purely for informational manifest metadata, which isn't required
      by the `complete` contract or the error-abort guarantee.
- [ ] Resuming a backup whose upload plan has *expired* (rather than merely being interrupted)
      re-requests a fresh `UploadPlan` from DrupalForge. This client trusts that the server either
      returns the same `upload_id`/part URLs for an incomplete upload (idempotent - already-recorded
      ETags remain valid) or, if truly expired, a fresh multipart upload whose part URLs happen to
      be addressed consistently enough that already-uploaded bytes aren't lost. Full server-side
      multipart-upload-id reconciliation (abort-and-restart semantics) is a DrupalForge-side API
      contract detail this client cannot control and is out of scope here.
- [ ] `resumeBackup()`'s single-PUT artifacts have no partial-resume concept (per the plan: "single
      PUT has no ETag/part concept") - an interrupted single-PUT artifact is simply re-uploaded
      whole on resume. Acceptable since single-PUT mode implies a small artifact.
- [ ] The status page's per-artifact progress indicator reports parts-done/total from
      `ProgressStateStore`'s `upload.*` state, which is only populated once at least one multipart
      part has uploaded; a backup that failed before the first part has no progress detail beyond
      the recorded error message (this is already surfaced via `last_status.errors`).
