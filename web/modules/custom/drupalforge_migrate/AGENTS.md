# AI Contributor Instructions

This document defines how AI agents should contribute to the `drupalforge_migrate` module.

## Audience

This document is for **AI contributors/agents** only.

For other audiences:
- Users/site administrators: [README.md](README.md)
- Developers/maintainers: [TODO.md](TODO.md), [DESIGN.md](DESIGN.md), [CHANGELOG.md](CHANGELOG.md)

## Objective

Deliver the source-site half of the DrupalForge → DevPanel migration flow:
- Preflight-check the customer site (docroot/PHP/DB driver/CLI tooling) and refuse to guess.
- Archive the site's code and export its database without any Backup and Migrate or AWS SDK dependency.
- Upload both artifacts straight to DrupalForge via presigned URLs (single PUT or resumable multipart).
- Work identically whether invoked over Drush or the web/Batch UI, through one shared service layer.

## Non-Negotiable Constraints

- No dependency on `drupal/backup_migrate_aws_s3` or any AWS SDK.
- No Composer runtime dependency beyond `php: >=8.1` (Guzzle comes from Drupal core's `http_client` service).
- The DrupalForge Migration ID/Secret pair is never exported as config; the secret lives exclusively in `\Drupal\Core\State\StateInterface`.
- Any failed preflight/archive/export/upload step must leave DrupalForge with **no** `ready` backup — abort the reservation rather than silently continuing.
- Business logic (archiving, exporting, uploading) lives once in `src/Service/*`; the Drush commands and the Batch API operations are thin adapters over the same `BackupOrchestrator` — never duplicate logic between the two.

## Required Development Process

For each feature slice, always follow this order:
1. Define a short implementation plan and explicit definition of done for the requested slice.
2. Align documentation intent, behavior, and edge cases with the requested task before test work begins.
3. If current docs are already aligned with the requested task, record that no doc content update is required and proceed.
4. Add or update automated tests and run them before changing production code.
5. Implement production code.

Track only in-flight work in `TODO.md`. Move completed work to `CHANGELOG.md` before commit. Every TODO item must include an explicit definition of done.

Test-first confirmation is required for every code-changing slice:
- Add or update tests first.
- Run affected tests before production code changes and confirm they fail for the intended behavior gap (red).
- After implementation, rerun affected tests and confirm they pass (green).

## Expected Module Structure

Agents should preserve and build around these files/components:
- `drupalforge_migrate.info.yml`
- `drupalforge_migrate.routing.yml`
- `drupalforge_migrate.permissions.yml`
- `drupalforge_migrate.services.yml`
- `drupalforge_migrate.module` (minimal hooks)

Primary services (see `DESIGN.md` for the full breakdown):
- `PreflightService`
- `CodeArchiver`
- `DatabaseExporter`
- `DrupalForgeClient`
- `Uploader`
- `BackupOrchestrator` (the single shared entry point for Drush + Batch)
- `ProgressStateStore`

Primary UI routes:
- Settings/connect page: `/admin/config/development/drupalforge-migrate`
- Status page: `/admin/config/development/drupalforge-migrate/status`

## Drupal Coding and Architecture Standards

- Follow Drupal coding standards and best practices.
- Prefer dependency injection and service classes.
- Keep hooks minimal and only where necessary.
- Use config APIs, form APIs, plugin systems, and core services.
- Keep code changes focused and minimal.

## Security and Validation Requirements

- Permission-gate all admin routes with `administer drupalforge migrate`.
- Sanitize and validate all dynamic values (especially anything shelled out to `tar`/`mysqldump`/`gzip`).
- Log operational failures via the `drupalforge_migrate` logger channel.
- The Migration Secret must never appear in exported config, in logs, or in rendered form values after submission.
- All requests to DrupalForge must use HTTPS/TLS transport only.

## Testing Requirements

Minimum expectations for each feature:
- Unit tests for service logic (archiver exclusions, exporter table filtering, client request signing, preflight checks).
- Kernel tests for service wiring and Batch/State-backed resume behavior.
- Functional tests for admin page behavior and permissions, using the `drupalforge_migrate_test_support` module to fake DrupalForge/shell responses instead of touching real network or binaries.

## Contribution Guardrails for Agents

- Do not introduce unrelated refactors.
- Do not add speculative features outside the current slice's scope.
- Do not remove existing extension points needed for later slices (e.g. the `BackupOrchestrator` seam that later slices hook into).
- Keep commit-sized changes reviewable, coherent, and test-backed.

## Completion Definitions (Stop Conditions)

### Ready-to-push
An implementation slice is **ready-to-push** when:
- Scope requested for the slice is fully implemented (no known missing acceptance criteria in that slice).
- Required docs for the slice are updated (`README.md`, `DESIGN.md`, and this file if process rules changed).
- Tests for the slice were written first, confirmed failing, then confirmed passing after implementation.
- `TODO.md` reflects only remaining in-flight work with explicit definitions of done, and `CHANGELOG.md` records completed work for the slice.

### Done
A slice is **done** when it is ready-to-push and local checks/tests for it pass.

### When to stop and hand off
Stop and hand off when the slice is done, or when remaining work depends on external systems/decisions not available in the workspace (e.g. the live DrupalForge API contract, DevPanel credentials).
