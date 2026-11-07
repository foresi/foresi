#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-only
#
# Backs up Foresi into a restic repository: a pg_dump (custom format) of the
# database and the shared data directories (firm images, sent documents, raw
# bank mail, import files), as one snapshot. Then applies the retention and
# writes a status file. See docs/operations.md#backups.
#
#   ops/backup.sh                    # tag "daily"
#   ops/backup.sh --tag pre-migrate  # what deploy.sh runs before migrations
#
# Continuous WAL archiving for point-in-time recovery is configured in
# PostgreSQL by the operator (pgBackRest, see the guide); this is the logical,
# long-term copy next to it.
#
# Connection: the standard libpq variables (PGHOST, PGPORT, PGUSER, PGDATABASE)
# and ~/.pgpass for the password. restic: RESTIC_REPOSITORY and
# RESTIC_PASSWORD_FILE, plus the backend's own variables (for S3:
# AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY), set in $BACKUP_ENV_FILE, which is
# readable by the backup user only. No secret belongs in this script.
set -euo pipefail

BACKUP_ENV_FILE=${BACKUP_ENV_FILE:-/etc/foresi/backup.env}
if [[ -f "$BACKUP_ENV_FILE" ]]; then
    set -a
    # shellcheck source=/dev/null
    source "$BACKUP_ENV_FILE"
    set +a
fi

export PGDATABASE=${PGDATABASE:-foresi}
SHARED_DATA_DIRS=${SHARED_DATA_DIRS:-/srv/foresi/shared/var/share /srv/foresi/shared/var/imports}
# A fixed path, so every snapshot holds the dump at the same place.
DUMP_DIR=${DUMP_DIR:-/var/backups/foresi}
BACKUP_STATUS_FILE=${BACKUP_STATUS_FILE:-/var/lib/foresi/backup.status}
RESTIC=${RESTIC:-restic}
PG_DUMP=${PG_DUMP:-pg_dump}
RESTIC_HOST=${RESTIC_HOST:-foresi}
KEEP_DAILY=${KEEP_DAILY:-30}
KEEP_MONTHLY=${KEEP_MONTHLY:-120}
# 1: also prune the repository after forget (slow; once a week is enough).
PRUNE=${PRUNE:-0}

TAG=daily
while [[ $# -gt 0 ]]; do
    case "$1" in
        --tag) TAG=$2; shift 2 ;;
        --prune) PRUNE=1; shift ;;
        *) echo "usage: $0 [--tag name] [--prune]" >&2; exit 2 ;;
    esac
done

: "${RESTIC_REPOSITORY:?set RESTIC_REPOSITORY (in $BACKUP_ENV_FILE)}"
: "${RESTIC_PASSWORD_FILE:?set RESTIC_PASSWORD_FILE (in $BACKUP_ENV_FILE)}"
export RESTIC_REPOSITORY RESTIC_PASSWORD_FILE

log() { printf '[backup %s] %s\n' "$(date '+%H:%M:%S')" "$*"; }

STARTED=$(date -u '+%Y-%m-%dT%H:%M:%SZ')
SNAPSHOT=""
STEP="start"
write_status() {
    local result=$1
    mkdir -p "$(dirname "$BACKUP_STATUS_FILE")"
    {
        printf 'time=%s\n' "$(date -u '+%Y-%m-%dT%H:%M:%SZ')"
        printf 'started=%s\n' "$STARTED"
        printf 'result=%s\n' "$result"
        printf 'tag=%s\n' "$TAG"
        printf 'snapshot=%s\n' "$SNAPSHOT"
        printf 'step=%s\n' "$STEP"
    } > "$BACKUP_STATUS_FILE.tmp"
    mv -f "$BACKUP_STATUS_FILE.tmp" "$BACKUP_STATUS_FILE"
}
finish() {
    local status=$?
    # The dump lives on in the snapshot only, never as a plain file on this disk.
    rm -f "$DUMP_DIR/foresi.dump.tmp" "$DUMP_DIR/foresi.dump"
    if [[ $status -eq 0 ]]; then
        write_status ok
    else
        write_status failed
        log "FAILED at step '$STEP' (exit $status)" >&2
    fi
    exit "$status"
}
trap finish EXIT

umask 077
mkdir -p "$DUMP_DIR"

STEP="dump"
log "dumping database $PGDATABASE"
"$PG_DUMP" --format=custom --compress=0 --file="$DUMP_DIR/foresi.dump.tmp" "$PGDATABASE"
mv -f "$DUMP_DIR/foresi.dump.tmp" "$DUMP_DIR/foresi.dump"

STEP="init"
if ! "$RESTIC" cat config >/dev/null 2>&1; then
    log "initialising the repository"
    "$RESTIC" init
fi

STEP="backup"
paths=("$DUMP_DIR/foresi.dump")
for dir in $SHARED_DATA_DIRS; do
    if [[ -d "$dir" ]]; then
        paths+=("$dir")
    else
        log "warning: $dir does not exist; skipped"
    fi
done
log "backing up ${paths[*]}"
SNAPSHOT=$("$RESTIC" backup --host "$RESTIC_HOST" --tag foresi --tag "$TAG" --json "${paths[@]}" \
    | sed -n 's/.*"message_type":"summary".*"snapshot_id":"\([0-9a-f]*\)".*/\1/p' | tail -n 1)
[[ -n "$SNAPSHOT" ]] || { log "restic reported no snapshot" >&2; exit 1; }
log "snapshot $SNAPSHOT"

STEP="retention"
forget=(forget --host "$RESTIC_HOST" --tag foresi --keep-daily "$KEEP_DAILY" --keep-monthly "$KEEP_MONTHLY")
[[ "$PRUNE" == 1 ]] && forget+=(--prune)
"$RESTIC" "${forget[@]}"

STEP="done"
log "done"
