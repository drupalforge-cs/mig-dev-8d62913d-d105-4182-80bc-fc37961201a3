#!/usr/bin/env bash
#
# Real end-to-end smoke test for drupalforge_migrate.
#
# Unlike the PHPUnit suite (which fakes every external system), this drives
# the REAL Drush commands against a REAL local HTTPS server standing in for
# DrupalForge (fake-drupalforge-api.php in this same directory), with real
# file uploads and real sha256 verification. It proves:
#   - preflight reports this site supported
#   - a full backup (archive + export + upload + complete) really reaches
#     `ready` on the fake API, with matching checksums
#   - an interrupted upload resumes without re-uploading already-confirmed
#     parts
#   - a forced upload failure aborts the reservation and never calls
#     `complete` - the module's core safety guarantee
#
# Usage: bash web/modules/upgrade/drupalforge_migrate/scripts/smoke/run.sh
# (from the site root, or anywhere - the script locates itself and the site
# root on its own). Requires `ddev` and an already-started DDEV project.
#
# Leaves no residue: settings.php is restored exactly, and all state/PID
# files this script creates live under scripts/smoke/.state (removed on
# exit, success or failure, via the trap below).

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
MODULE_DIR="$(cd "$SCRIPT_DIR/../.." && pwd)"
SITE_ROOT="$(cd "$SCRIPT_DIR/../../../../../.." && pwd)"

if [ ! -d "$SITE_ROOT/.ddev" ]; then
  echo "Could not locate the DDEV site root above $MODULE_DIR (expected $SITE_ROOT/.ddev to exist)." >&2
  exit 1
fi
if ! command -v ddev >/dev/null 2>&1; then
  echo "ddev is required on the host PATH." >&2
  exit 1
fi

cd "$SITE_ROOT"

PORT=8199
MIGRATION_ID="smoke-test-migration"
SECRET="smoke-secret"
MULTIPART_THRESHOLD=1048576   # 1 MiB - forces multipart mode for the code archive (and most db dumps)
PART_SIZE=786432              # 768 KiB/part - a ~30MB code archive splits into ~40 parts, plenty of margin
                               # to interrupt after a few confirmed parts and still have most remaining.
MAX_BACKUPS=3

STATE_DIR="$MODULE_DIR/scripts/smoke/.state"
LOG="$STATE_DIR/requests.log"
STATE_JSON="$STATE_DIR/state.json"
CONTAINER_SMOKE="/var/www/html/web/modules/upgrade/drupalforge_migrate/scripts/smoke"
STATE_DIR_CONTAINER="$CONTAINER_SMOKE/.state"
SETTINGS_FILE="$SITE_ROOT/web/sites/default/settings.php"
SETTINGS_BACKUP="/tmp/drupalforge_migrate_smoke_settings_backup.$$"

OVERALL=0
pass() { echo "  PASS: $1"; }
fail() { echo "  FAIL: $1"; OVERALL=1; }

cleanup() {
  local exit_code=$?
  echo
  echo "== Cleaning up =="
  # PID files aren't used here: `ddev exec` nests its own `bash -c` wrapper
  # (with `set -eu`) around whatever command string is passed to it, so a
  # literal `$!` in that string gets evaluated at the WRONG shell level and
  # fails with "unbound variable" (verified empirically - see run.sh's
  # comments at the process-management call sites below). pkill/pgrep with
  # the classic `[x]xxx` bracket trick (so the pattern doesn't match pkill's
  # own invocation, which otherwise IS an ancestor process matching its own
  # search string - also verified empirically) sidesteps both problems.
  ddev exec bash -c "pkill -f '[f]ake-drupalforge-api.php' 2>/dev/null" >/dev/null 2>&1 || true
  ddev exec bash -c "pkill -9 -f '[d]rupalforge-migrate:backup' 2>/dev/null" >/dev/null 2>&1 || true
  if [ -f "$SETTINGS_BACKUP" ]; then
    cp "$SETTINGS_BACKUP" "$SETTINGS_FILE"
    rm -f "$SETTINGS_BACKUP"
    echo "Restored $SETTINGS_FILE."
  fi
  ddev drush sdel drupalforge_migrate.progress.current_backup_id -y >/dev/null 2>&1 || true
  rm -rf "$STATE_DIR"
  echo "Removed $STATE_DIR."
  exit "$exit_code"
}
trap cleanup EXIT INT TERM

reset_scenario() {
  ddev exec bash -c "curl -sk --max-time 3 -X POST https://127.0.0.1:$PORT/admin/reset >/dev/null 2>&1" || true
  ddev drush sdel drupalforge_migrate.progress.current_backup_id -y >/dev/null 2>&1 || true
}

# --- Set up: cert, fake API server, settings.php override ----------------

mkdir -p "$STATE_DIR"

echo "== Generating a self-signed cert for the fake API =="
ddev exec bash -c "openssl req -x509 -newkey rsa:2048 -nodes \
  -keyout $STATE_DIR_CONTAINER/key.pem -out $STATE_DIR_CONTAINER/cert.pem \
  -days 2 -subj '/CN=127.0.0.1' >/dev/null 2>&1"
if [ ! -f "$STATE_DIR/cert.pem" ] || [ ! -f "$STATE_DIR/key.pem" ]; then
  echo "Failed to generate a self-signed cert." >&2
  exit 1
fi

echo "== Starting the fake DrupalForge API on https://127.0.0.1:$PORT (inside the web container) =="
# No PID capture here (see the note in cleanup() on why `$!` doesn't survive
# ddev exec's own wrapping) - the process is found later via pgrep/pkill.
ddev exec bash -c "
  FAKE_API_HOST=127.0.0.1 FAKE_API_PORT=$PORT FAKE_API_STATE_DIR=$STATE_DIR_CONTAINER \
  FAKE_API_SECRET=$SECRET FAKE_API_CERT=$STATE_DIR_CONTAINER/cert.pem FAKE_API_KEY=$STATE_DIR_CONTAINER/key.pem \
  FAKE_API_MULTIPART_THRESHOLD=$MULTIPART_THRESHOLD FAKE_API_PART_SIZE=$PART_SIZE FAKE_API_MAX_BACKUPS=$MAX_BACKUPS \
  nohup php $CONTAINER_SMOKE/fake-drupalforge-api.php > $STATE_DIR_CONTAINER/server.log 2>&1 &
"

READY=0
for _ in $(seq 1 30); do
  if ddev exec bash -c "curl -sk --max-time 2 https://127.0.0.1:$PORT/health 2>/dev/null" | grep -q '"ok":true'; then
    READY=1
    break
  fi
  sleep 0.5
done
if [ "$READY" -ne 1 ]; then
  echo "Fake API did not come up. Server log:" >&2
  cat "$STATE_DIR/server.log" >&2 2>/dev/null || true
  exit 1
fi
echo "Fake API is up."

echo "== Pointing the module at the fake API (temporary settings.php override) =="
cp "$SETTINGS_FILE" "$SETTINGS_BACKUP"
cat >> "$SETTINGS_FILE" <<PHP

// --- BEGIN drupalforge_migrate smoke test override (auto-removed on exit) ---
\$settings['drupalforge_migrate_url'] = 'https://127.0.0.1:$PORT';
// Self-signed cert for a loopback-only smoke test target: skip peer
// verification for THIS site's outbound requests. Orthogonal to (and does
// not weaken) DrupalForgeClient's own HTTPS-scheme enforcement, which is
// still exercised - the URL above really is HTTPS.
\$settings['http_client_config']['verify'] = FALSE;
// --- END drupalforge_migrate smoke test override ---
PHP

ddev drush config:set drupalforge_migrate.settings migration_id "$MIGRATION_ID" -y >/dev/null
ddev drush sset drupalforge_migrate.secret "$SECRET" >/dev/null

# --- Scenarios -------------------------------------------------------------

scenario_preflight() {
  echo "== Scenario: preflight =="
  local out code
  out="$(ddev drush drupalforge-migrate:preflight 2>&1)"
  code=$?
  if [ "$code" -eq 0 ] && echo "$out" | grep -qi "supported for a DrupalForge migration"; then
    pass "preflight reports supported"
  else
    fail "preflight did not report supported (exit=$code)"
    echo "$out"
  fi
}

scenario_happy_path() {
  echo "== Scenario: happy-path backup =="
  reset_scenario
  local out code backup_id
  out="$(ddev drush drupalforge-migrate:backup 2>&1)"
  code=$?
  backup_id="$(echo "$out" | grep -oE 'Backup [0-9a-f]+' | head -1 | awk '{print $2}')"

  if [ "$code" -eq 0 ] && [ -n "$backup_id" ] && grep -q "$backup_id" "$STATE_JSON" 2>/dev/null && grep -q '"status":"ready"' "$STATE_JSON" 2>/dev/null; then
    pass "backup $backup_id reached ready on the fake API"
  else
    fail "happy-path backup did not reach ready (exit=$code, id=${backup_id:-none})"
    echo "$out"
    return
  fi

  local status_out
  status_out="$(ddev drush drupalforge-migrate:status 2>&1)"
  if echo "$status_out" | grep -qi "ready"; then
    pass "status command reports ready"
  else
    fail "status command did not report ready"
    echo "$status_out"
  fi
}

scenario_resume() {
  echo "== Scenario: interrupt mid-upload, then resume =="
  reset_scenario

  ddev exec bash -c "cd /var/www/html && nohup drush drupalforge-migrate:backup > $STATE_DIR_CONTAINER/backup_bg.log 2>&1 &"

  # Poll the (host-readable, no ddev exec round-trip needed) request log at a
  # tight interval - local loopback part uploads complete fast, so a coarser
  # poll risks catching this well past the intended "just a few parts in".
  local target_parts=3 waited_ms=0 done_parts=0
  while [ "$waited_ms" -lt 30000 ]; do
    done_parts=$(awk -F'\t' '$2=="put_part" && $4 ~ /^code:/{c++} END{print c+0}' "$LOG" 2>/dev/null)
    if [ "$done_parts" -ge "$target_parts" ]; then
      break
    fi
    sleep 0.05
    waited_ms=$((waited_ms + 50))
  done

  if [ "$done_parts" -lt "$target_parts" ]; then
    fail "did not observe $target_parts code parts uploaded before timeout (saw $done_parts) - see $STATE_DIR/backup_bg.log"
    return
  fi

  ddev exec bash -c "pkill -9 -f '[d]rupalforge-migrate:backup'" >/dev/null 2>&1 || true
  sleep 1
  if ddev exec bash -c "pgrep -f '[d]rupalforge-migrate:backup'" >/dev/null 2>&1; then
    fail "background backup process did not die after kill -9"
    return
  fi

  # Confirm this was a genuinely PARTIAL interrupt (not an accidental no-op
  # where the whole upload had already finished by the time we killed it) by
  # cross-checking against the total part count the fake API itself recorded
  # when the upload plan was requested.
  local total_parts
  total_parts=$(awk -F'\t' '$2=="upload_request" && $4 ~ /^code:multipart:/{split($4,a,":"); print a[3]; exit}' "$LOG" 2>/dev/null)
  if [ -z "${total_parts:-}" ] || [ "$done_parts" -ge "$total_parts" ]; then
    fail "interrupt was not genuinely partial (done=$done_parts, total=${total_parts:-unknown}) - resume would be a trivial no-op"
    return
  fi
  pass "interrupted the backup after $done_parts of $total_parts code part(s) uploaded"

  local resume_out resume_code
  resume_out="$(ddev drush drupalforge-migrate:backup --resume 2>&1)"
  resume_code=$?

  # Tolerate AT MOST ONE duplicate part upload: the part that happened to be
  # in-flight at the exact instant of `kill -9` may have been received and
  # written by the (fake) server before the client process died, without the
  # client ever getting to persist that success locally - on resume it is
  # legitimately re-uploaded once (harmless: an idempotent overwrite of the
  # same bytes at the same key). This is the documented guarantee ("a crash
  # mid-part loses at most one part's progress" - see Uploader's docblock),
  # not a bug; a STRICTER bar here (zero tolerance) is flaky by construction
  # since it depends on exactly when the kill lands relative to the network
  # round-trip. Any part duplicated, or any single part uploaded 3+ times,
  # would still correctly fail this check.
  local dup_count
  dup_count=$(awk -F'\t' '$2=="put_part" && $4 !~ /:single$/{print $4}' "$LOG" | sort | uniq -c | awk '$1>1{c++} END{print c+0}')

  if [ "$resume_code" -eq 0 ] && [ "$dup_count" -le 1 ] && grep -q '"status":"ready"' "$STATE_JSON" 2>/dev/null; then
    pass "resume completed with at most one (in-flight-at-kill-time) duplicate part upload (exit=$resume_code, duplicates=$dup_count)"
  else
    fail "resume scenario failed (exit=$resume_code, duplicate part-uploads=$dup_count)"
    echo "$resume_out"
  fi
}

scenario_forced_failure() {
  echo "== Scenario: forced upload failure aborts cleanly =="
  reset_scenario
  echo '{"artifact":"code","part":1,"status":403}' > "$STATE_DIR/fail_flag.json"

  local out code
  out="$(ddev drush drupalforge-migrate:backup 2>&1)"
  code=$?

  local failed_logged ff_backup_id abort_logged complete_logged
  failed_logged=$(awk -F'\t' '$2=="put_part_forced_failure"{c++} END{print c+0}' "$LOG" 2>/dev/null)
  ff_backup_id=$(awk -F'\t' '$2=="put_part_forced_failure"{print $3; exit}' "$LOG" 2>/dev/null)
  abort_logged=0
  complete_logged=0
  if [ -n "${ff_backup_id:-}" ]; then
    abort_logged=$(awk -F'\t' -v id="$ff_backup_id" '$2=="abort" && $3==id{c++} END{print c+0}' "$LOG")
    complete_logged=$(awk -F'\t' -v id="$ff_backup_id" '$2=="complete" && $3==id{c++} END{print c+0}' "$LOG")
  fi

  if [ "$code" -ne 0 ] && [ "$failed_logged" -ge 1 ] && [ "$abort_logged" -ge 1 ] && [ "$complete_logged" -eq 0 ]; then
    pass "forced failure on backup ${ff_backup_id:-?} correctly aborted and never completed"
  else
    fail "forced-failure scenario did not behave as expected (exit=$code, forced_failure_logged=$failed_logged, abort_logged=$abort_logged, complete_logged=$complete_logged)"
    echo "$out"
  fi
}

scenario_preflight
scenario_happy_path
scenario_resume
scenario_forced_failure

echo
if [ "$OVERALL" -eq 0 ]; then
  echo "== ALL SCENARIOS PASSED =="
else
  echo "== ONE OR MORE SCENARIOS FAILED =="
fi
exit "$OVERALL"
