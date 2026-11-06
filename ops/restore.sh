#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-only
#
# Manual full restore of Foresi from a restic snapshot: the database from its
# pg_dump and the shared data directories. Run it by hand, during an outage
# window, after reading docs/operations.md#restore. For a point in time between
# two snapshots, restore the database with pgBackRest instead and use --files-only.
#
#   ops/restore.sh --snapshot latest --yes
#   ops/restore.sh --snapshot 1a2b3c4d --files-only --yes
#   ops/restore.sh --before "2026-11-06 10:00" --yes   # newest snapshot before that time
#
# What it does:
#   1. stops the workers and the web application (configurable commands);
#   2. restores the snapshot into a temporary directory;
#   3. restores the dump into a new database <db>_restored_<time>, then renames
#      the live database to <db>_before_<time> and the restored one to <db>;
#   4. moves each live data directory aside to <dir>.before-<time> and puts the
#      restored one in its place;
#   5. starts the application and the workers again.
# Nothing is deleted: the previous database and directories stay for comparison
# until the operator removes them.
#
# Uses the same environment as ops/backup.sh, with RESTORE_PGUSER for a role
# allowed to create and rename databases (the PostgreSQL superuser, for example).
set -euo pipefail

BACKUP_ENV_FILE=${BACKUP_ENV_FILE:-/etc/foresi/backup.env}
if [[ -f "$BACKUP_ENV_FILE" ]]; then
    set -a
    # shellcheck source=/dev/null
    source "$BACKUP_ENV_FILE"
    set +a
fi

# The role of the restore, which must create and rename databases; the backup's
# role from the env file cannot. Its password belongs in PGPASSFILE.
if [[ -n "${RESTORE_PGUSER:-}" ]]; then
    export PGUSER=$RESTORE_PGUSER
fi
DB=${PGDATABASE:-foresi}
# The database the restore connects to while it renames the Foresi one.
MAINTENANCE_DB=${MAINTENANCE_DB:-postgres}
DB_OWNER=${DB_OWNER:-foresi}
DUMP_DIR=${DUMP_DIR:-/var/backups/foresi}
SHARED_DATA_DIRS=${SHARED_DATA_DIRS:-/srv/foresi/shared/var/share /srv/foresi/shared/var/imports}
RESTIC=${RESTIC:-restic}
RESTIC_HOST=${RESTIC_HOST:-foresi}
PSQL=${PSQL:-psql}
PG_RESTORE=${PG_RESTORE:-pg_restore}
CREATEDB=${CREATEDB:-createdb}
TMP_PARENT=${TMP_PARENT:-/var/tmp}
STOP_COMMAND=${STOP_COMMAND:-sudo systemctl stop 'foresi-worker@*' php8.4-fpm}
START_COMMAND=${START_COMMAND:-sudo systemctl start php8.4-fpm foresi-worker@1}
# The owner of the restored files (the user PHP-FPM and the workers run as).
FILE_OWNER=${FILE_OWNER:-www-data:www-data}

SNAPSHOT=latest
BEFORE=""
FILES_ONLY=0
YES=0
while [[ $# -gt 0 ]]; do
    case "$1" in
        --snapshot) SNAPSHOT=$2; shift 2 ;;
        --before) BEFORE=$2; shift 2 ;;
        --files-only) FILES_ONLY=1; shift ;;
        --yes) YES=1; shift ;;
        *) echo "usage: $0 [--snapshot id|latest] [--before 'YYYY-MM-DD HH:MM'] [--files-only] --yes" >&2; exit 2 ;;
    esac
done

: "${RESTIC_REPOSITORY:?set RESTIC_REPOSITORY (in $BACKUP_ENV_FILE)}"
: "${RESTIC_PASSWORD_FILE:?set RESTIC_PASSWORD_FILE (in $BACKUP_ENV_FILE)}"
export RESTIC_REPOSITORY RESTIC_PASSWORD_FILE

log() { printf '[restore %s] %s\n' "$(date '+%H:%M:%S')" "$*"; }
fail() { log "ERROR: $*" >&2; exit 1; }

if [[ -n "$BEFORE" ]]; then
    # restic lists snapshots oldest first, with their time in the local zone.
    limit=$(date -d "$BEFORE" '+%s' 2>/dev/null || date -j -f '%Y-%m-%d %H:%M' "$BEFORE" '+%s') \
        || fail "cannot read the time $BEFORE"
    SNAPSHOT=""
    while read -r id time; do
        when=$(date -d "$time" '+%s' 2>/dev/null || date -j -f '%Y-%m-%d %H:%M:%S' "$time" '+%s')
        [[ $when -le $limit ]] && SNAPSHOT=$id
    done < <("$RESTIC" snapshots --host "$RESTIC_HOST" --tag foresi --compact | awk '$1 ~ /^[0-9a-f]{8}$/ {print $1, $2" "$3}')
    [[ -n "$SNAPSHOT" ]] || fail "no snapshot before $BEFORE"
fi

log "snapshot to restore: $SNAPSHOT"
"$RESTIC" snapshots --host "$RESTIC_HOST" --tag foresi "$SNAPSHOT"
if [[ $YES -ne 1 ]]; then
    fail "this replaces the live data; run again with --yes after reading the plan at the top of this script"
fi

STAMP=$(date -u '+%Y%m%d%H%M%S')
WORK=$(mktemp -d "$TMP_PARENT/foresi-restore.XXXXXX")
trap 'log "the restored files are kept in $WORK until you remove them"' EXIT

log "restoring the snapshot into $WORK"
"$RESTIC" restore "$SNAPSHOT" --host "$RESTIC_HOST" --tag foresi --target "$WORK"

log "stopping the application: $STOP_COMMAND"
bash -c "$STOP_COMMAND"

if [[ $FILES_ONLY -eq 0 ]]; then
    DUMP="$WORK$DUMP_DIR/foresi.dump"
    [[ -s "$DUMP" ]] || fail "the snapshot holds no dump at $DUMP_DIR/foresi.dump"
    RESTORED="${DB}_restored_$STAMP"
    log "restoring the database into $RESTORED"
    "$CREATEDB" --owner "$DB_OWNER" "$RESTORED"
    "$PG_RESTORE" --no-owner --role "$DB_OWNER" --exit-on-error --dbname "$RESTORED" "$DUMP"
    log "swapping $DB for $RESTORED (the old one becomes ${DB}_before_$STAMP)"
    "$PSQL" --no-psqlrc --set ON_ERROR_STOP=1 --dbname "$MAINTENANCE_DB" <<SQL
SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '$DB' AND pid <> pg_backend_pid();
ALTER DATABASE "$DB" RENAME TO "${DB}_before_$STAMP";
ALTER DATABASE "$RESTORED" RENAME TO "$DB";
SQL
fi

for dir in $SHARED_DATA_DIRS; do
    source_dir="$WORK$dir"
    if [[ ! -d "$source_dir" ]]; then
        log "warning: the snapshot holds no $dir; left as it is"
        continue
    fi
    if [[ -d "$dir" ]]; then
        log "moving $dir aside to $dir.before-$STAMP"
        mv "$dir" "$dir.before-$STAMP"
    fi
    log "restoring $dir"
    mv "$source_dir" "$dir"
    chown -R "$FILE_OWNER" "$dir"
done

log "starting the application: $START_COMMAND"
bash -c "$START_COMMAND"

log "done. Check /health and sign in, then remove ${DB}_before_$STAMP and the .before-$STAMP directories when you no longer need them."
