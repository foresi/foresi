#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-only
#
# Proves the latest backup can be restored: restores the newest restic snapshot
# into a temporary directory and a scratch database, compares row counts of key
# tables with the live database, checks the SHA-256 of a sample of sent
# documents against invoice_send.pdf_sha256, then drops the scratch database.
# Writes a status file and exits non-zero on any failure, so cron mails the
# output (MAILTO) or systemd marks the unit failed (OnFailure=).
# See docs/operations.md#restore-check.
#
# Uses the same environment as ops/backup.sh. The database role needs CREATEDB
# and read access to the live database (see the guide).
set -euo pipefail

BACKUP_ENV_FILE=${BACKUP_ENV_FILE:-/etc/foresi/backup.env}
if [[ -f "$BACKUP_ENV_FILE" ]]; then
    set -a
    # shellcheck source=/dev/null
    source "$BACKUP_ENV_FILE"
    set +a
fi

export PGDATABASE=${PGDATABASE:-foresi}
LIVE_DB=$PGDATABASE
DUMP_DIR=${DUMP_DIR:-/var/backups/foresi}
# The share directory as it is backed up (APP_SHARE_DIR, absolute) and the environment below it.
SHARE_DIR=${SHARE_DIR:-/srv/foresi/shared/var/share}
APP_ENV=${APP_ENV:-prod}
CHECK_STATUS_FILE=${CHECK_STATUS_FILE:-/var/lib/foresi/restore-check.status}
RESTIC=${RESTIC:-restic}
PSQL=${PSQL:-psql}
PG_RESTORE=${PG_RESTORE:-pg_restore}
CREATEDB=${CREATEDB:-createdb}
DROPDB=${DROPDB:-dropdb}
RESTIC_HOST=${RESTIC_HOST:-foresi}
TMP_PARENT=${TMP_PARENT:-${TMPDIR:-/tmp}}
CHECK_TABLES=${CHECK_TABLES:-invoice payment party recurring_invoice}
# The restored count may be lower than the live one by this many rows plus
# this percentage: rows written since the backup ran.
TOLERANCE_ROWS=${TOLERANCE_ROWS:-50}
TOLERANCE_PERCENT=${TOLERANCE_PERCENT:-5}
SAMPLE_DOCUMENTS=${SAMPLE_DOCUMENTS:-20}
# Fail when the newest snapshot is older than this many hours.
MAX_AGE_HOURS=${MAX_AGE_HOURS:-26}

: "${RESTIC_REPOSITORY:?set RESTIC_REPOSITORY (in $BACKUP_ENV_FILE)}"
: "${RESTIC_PASSWORD_FILE:?set RESTIC_PASSWORD_FILE (in $BACKUP_ENV_FILE)}"
export RESTIC_REPOSITORY RESTIC_PASSWORD_FILE

log() { printf '[restore-check %s] %s\n' "$(date '+%H:%M:%S')" "$*"; }

SCRATCH_DB="foresi_restore_check_$(date -u '+%Y%m%d%H%M%S')"
WORK=$(mktemp -d "$TMP_PARENT/foresi-restore-check.XXXXXX")
PROBLEMS=()
SNAPSHOT=""
STEP="start"
SCRATCH_CREATED=0

write_status() {
    local result=$1
    mkdir -p "$(dirname "$CHECK_STATUS_FILE")"
    {
        printf 'time=%s\n' "$(date -u '+%Y-%m-%dT%H:%M:%SZ')"
        printf 'result=%s\n' "$result"
        printf 'snapshot=%s\n' "$SNAPSHOT"
        printf 'step=%s\n' "$STEP"
        printf 'problems=%s\n' "${#PROBLEMS[@]}"
        local problem
        for problem in "${PROBLEMS[@]}"; do
            printf 'problem=%s\n' "$problem"
        done
    } > "$CHECK_STATUS_FILE.tmp"
    mv -f "$CHECK_STATUS_FILE.tmp" "$CHECK_STATUS_FILE"
}
finish() {
    local status=$?
    if [[ $SCRATCH_CREATED -eq 1 ]]; then
        "$DROPDB" --if-exists "$SCRATCH_DB" || log "warning: could not drop $SCRATCH_DB" >&2
    fi
    rm -rf "$WORK"
    if [[ $status -eq 0 && ${#PROBLEMS[@]} -eq 0 ]]; then
        write_status ok
        log "ok"
        exit 0
    fi
    [[ $status -eq 0 ]] && status=1
    write_status failed
    log "FAILED at step '$STEP'" >&2
    local problem
    for problem in "${PROBLEMS[@]}"; do
        log "  $problem" >&2
    done
    exit "$status"
}
trap finish EXIT

problem() { PROBLEMS+=("$*"); log "problem: $*" >&2; }
query() { "$PSQL" --no-psqlrc --quiet --tuples-only --no-align --set ON_ERROR_STOP=1 --dbname "$1" --command "$2"; }
sha256() {
    if command -v sha256sum >/dev/null 2>&1; then sha256sum "$1" | cut -d' ' -f1; else shasum -a 256 "$1" | cut -d' ' -f1; fi
}

STEP="snapshot"
latest=$("$RESTIC" snapshots --host "$RESTIC_HOST" --tag foresi --latest 1 --json)
SNAPSHOT=$(printf '%s' "$latest" | sed -n 's/.*"short_id":"\([0-9a-f]*\)".*/\1/p' | tail -n 1)
taken=$(printf '%s' "$latest" | sed -n 's/.*"time":"\([^"]*\)".*/\1/p' | head -n 1)
[[ -n "$SNAPSHOT" ]] || { problem "no snapshot in $RESTIC_REPOSITORY"; exit 1; }
log "latest snapshot $SNAPSHOT taken $taken"
taken_epoch=$("$PSQL" --no-psqlrc --tuples-only --no-align --dbname "$LIVE_DB" --command "SELECT floor(extract(epoch FROM timestamptz '$taken'))::bigint")
age_hours=$(( ($(date -u '+%s') - taken_epoch) / 3600 ))
if [[ $age_hours -gt $MAX_AGE_HOURS ]]; then
    problem "the latest snapshot is $age_hours hours old (more than $MAX_AGE_HOURS)"
fi

STEP="restore files"
"$RESTIC" restore "$SNAPSHOT" --target "$WORK/restore"
DUMP="$WORK/restore$DUMP_DIR/foresi.dump"
[[ -s "$DUMP" ]] || { problem "the snapshot holds no database dump at $DUMP_DIR/foresi.dump"; exit 1; }

STEP="restore database"
"$CREATEDB" "$SCRATCH_DB"
SCRATCH_CREATED=1
"$PG_RESTORE" --no-owner --no-acl --exit-on-error --dbname "$SCRATCH_DB" "$DUMP"
query "$SCRATCH_DB" "SELECT 1" >/dev/null

STEP="row counts"
for table in $CHECK_TABLES; do
    live=$(query "$LIVE_DB" "SELECT count(*) FROM \"$table\"")
    restored=$(query "$SCRATCH_DB" "SELECT count(*) FROM \"$table\"")
    allowed=$(( TOLERANCE_ROWS + live * TOLERANCE_PERCENT / 100 ))
    log "$table: live $live, restored $restored (may differ by $allowed)"
    if (( restored > live + allowed || restored < live - allowed )); then
        problem "$table has $restored rows restored and $live live, more than $allowed apart"
    fi
done

STEP="sent documents"
root="$WORK/restore$SHARE_DIR/$APP_ENV/sent-documents"
checked=0
while IFS='|' read -r key expected; do
    [[ -n "$key" ]] || continue
    checked=$((checked + 1))
    file="$root/$key"
    if [[ ! -f "$file" ]]; then
        problem "sent document $key is missing from the snapshot"
    elif [[ "$(sha256 "$file")" != "$expected" ]]; then
        problem "sent document $key does not match its SHA-256"
    fi
done < <(query "$SCRATCH_DB" "SELECT pdf_key, pdf_sha256 FROM invoice_send WHERE status = 'sent' ORDER BY random() LIMIT $SAMPLE_DOCUMENTS")
log "checked $checked sent documents"

STEP="done"
