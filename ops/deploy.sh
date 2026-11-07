#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-only
#
# Zero-downtime deploy of Foresi: builds a git ref into releases/<timestamp>,
# links the shared files, migrates the database and switches the `current`
# symlink atomically. See docs/operations.md.
#
#   ops/deploy.sh [git-ref]          # default: $DEPLOY_REF or main
#
# Everything below can be set in the environment (or in $DEPLOY_ROOT/deploy.env,
# which is read first). No secret belongs here: the application reads its
# secrets from shared/.env.local and the backup reads its own from files.
set -euo pipefail

DEPLOY_ROOT=${DEPLOY_ROOT:-/srv/foresi}
if [[ -f "$DEPLOY_ROOT/deploy.env" ]]; then
    # shellcheck source=/dev/null
    source "$DEPLOY_ROOT/deploy.env"
fi

REPO_URL=${REPO_URL:-https://github.com/foresi/foresi.git}
DEPLOY_REF=${1:-${DEPLOY_REF:-main}}
PHP=${PHP:-php}
COMPOSER=${COMPOSER:-composer}
KEEP_RELEASES=${KEEP_RELEASES:-5}
# Files and directories that live in shared/ and are symlinked into each release.
SHARED_FILES=${SHARED_FILES:-.env.local}
SHARED_DIRS=${SHARED_DIRS:-var/share var/imports}
# Run after the switch so the PHP-FPM workers drop their opcache and realpath cache.
RELOAD_FPM_COMMAND=${RELOAD_FPM_COMMAND:-sudo systemctl reload php8.4-fpm}
# 1: dump the database before pending migrations run (needs ops/backup.sh set up).
PRE_MIGRATE_BACKUP=${PRE_MIGRATE_BACKUP:-1}
# The command of that dump; the new release's backup script by default.
BACKUP_COMMAND=${BACKUP_COMMAND:-}
# 1: ask the workers to exit after their current message, so the supervisor
# starts them again on the new release.
STOP_WORKERS=${STOP_WORKERS:-1}
# 1: deploy even when the ref is the commit that `current` already runs.
FORCE=${FORCE:-0}

RELEASES="$DEPLOY_ROOT/releases"
SHARED="$DEPLOY_ROOT/shared"
CURRENT="$DEPLOY_ROOT/current"
MIRROR="$DEPLOY_ROOT/repo.git"
LOCK="$DEPLOY_ROOT/.deploy.lock"

export APP_ENV=prod APP_DEBUG=0 COMPOSER_NO_INTERACTION=1

log() { printf '[deploy %s] %s\n' "$(date '+%H:%M:%S')" "$*"; }
fail() { log "ERROR: $*" >&2; exit 1; }

mkdir -p "$RELEASES" "$SHARED"
mkdir "$LOCK" 2>/dev/null || fail "another deploy is running (remove $LOCK if it is stale)"

RELEASE=""
SWITCHED=0
cleanup() {
    local status=$?
    if [[ $status -ne 0 && $SWITCHED -eq 0 && -n "$RELEASE" && -d "$RELEASE" ]]; then
        log "removing the unfinished release $RELEASE"
        rm -rf "$RELEASE"
    fi
    rmdir "$LOCK" 2>/dev/null || true
    exit "$status"
}
trap cleanup EXIT

for file in $SHARED_FILES; do
    [[ -e "$SHARED/$file" ]] || fail "missing shared file $SHARED/$file (see docs/operations.md)"
done

log "fetching $REPO_URL"
if [[ -d "$MIRROR" ]]; then
    git --git-dir="$MIRROR" remote set-url origin "$REPO_URL"
    git --git-dir="$MIRROR" fetch --prune --tags origin '+refs/heads/*:refs/heads/*'
else
    git clone --mirror "$REPO_URL" "$MIRROR"
fi
REVISION=$(git --git-dir="$MIRROR" rev-parse --verify "$DEPLOY_REF^{commit}") || fail "unknown ref $DEPLOY_REF"

PREVIOUS=""
if [[ -L "$CURRENT" ]]; then
    PREVIOUS=$(cd -P "$CURRENT" && pwd)
    if [[ "$FORCE" != 1 && -f "$PREVIOUS/REVISION" && "$(cat "$PREVIOUS/REVISION")" == "$REVISION" ]]; then
        log "$DEPLOY_REF ($REVISION) is already live in $PREVIOUS; nothing to do (FORCE=1 deploys again)"
        exit 0
    fi
fi

RELEASE="$RELEASES/$(date -u '+%Y%m%d%H%M%S')"
[[ -e "$RELEASE" ]] && fail "$RELEASE exists already"
log "building $DEPLOY_REF ($REVISION) in $RELEASE"
mkdir -p "$RELEASE"
git --git-dir="$MIRROR" archive --format=tar "$REVISION" | tar -x -C "$RELEASE"
printf '%s\n' "$REVISION" > "$RELEASE/REVISION"

log "linking shared files"
for file in $SHARED_FILES; do
    mkdir -p "$(dirname "$RELEASE/$file")"
    rm -f "$RELEASE/$file"
    ln -s "$SHARED/$file" "$RELEASE/$file"
done
for dir in $SHARED_DIRS; do
    mkdir -p "$SHARED/$dir" "$(dirname "$RELEASE/$dir")"
    rm -rf "${RELEASE:?}/$dir"
    ln -s "$SHARED/$dir" "$RELEASE/$dir"
done
# The Tailwind binary is a download, not data: take the previous release's copy.
if [[ -n "$PREVIOUS" && -d "$PREVIOUS/var/tailwind" ]]; then
    cp -R "$PREVIOUS/var/tailwind" "$RELEASE/var/tailwind"
fi

cd "$RELEASE"
console() { "$PHP" bin/console --env=prod --no-interaction "$@"; }

log "installing dependencies"
"$COMPOSER" install --no-dev --optimize-autoloader --classmap-authoritative --no-progress

log "building assets"
console importmap:install
console tailwind:build --minify
console asset-map:compile

log "warming the cache"
console cache:clear
console cache:warmup

if ! console doctrine:migrations:up-to-date >/dev/null 2>&1; then
    if [[ "$PRE_MIGRATE_BACKUP" == 1 ]]; then
        log "pending migrations: dumping the database first"
        if [[ -n "$BACKUP_COMMAND" ]]; then
            bash -c "$BACKUP_COMMAND"
        else
            "$RELEASE/ops/backup.sh" --tag pre-migrate
        fi
    else
        log "pending migrations: no dump first (PRE_MIGRATE_BACKUP=$PRE_MIGRATE_BACKUP)"
    fi
    log "migrating"
    console doctrine:migrations:migrate --allow-no-migration
else
    log "no pending migrations"
fi

log "switching current to $RELEASE"
ln -sfn "$RELEASE" "$DEPLOY_ROOT/.current.tmp"
mv -fT "$DEPLOY_ROOT/.current.tmp" "$CURRENT" 2>/dev/null \
    || mv -fh "$DEPLOY_ROOT/.current.tmp" "$CURRENT"
SWITCHED=1

if [[ -n "$RELOAD_FPM_COMMAND" ]]; then
    log "reloading PHP-FPM: $RELOAD_FPM_COMMAND"
    bash -c "$RELOAD_FPM_COMMAND"
fi

if [[ "$STOP_WORKERS" == 1 ]]; then
    # The stop signal is kept in the cache of the release the workers run from,
    # so it is sent from the previous release; they restart on the new one.
    log "asking the workers to restart"
    if [[ -n "$PREVIOUS" && -d "$PREVIOUS" ]]; then
        (cd "$PREVIOUS" && "$PHP" bin/console --env=prod --no-interaction messenger:stop-workers) \
            || log "warning: the previous release could not signal its workers"
    fi
    console messenger:stop-workers
fi

log "keeping the last $KEEP_RELEASES releases"
LIVE=$(cd -P "$CURRENT" && pwd)
count=0
while IFS= read -r old; do
    count=$((count + 1))
    if [[ $count -gt $KEEP_RELEASES && "$RELEASES/$old" != "$LIVE" ]]; then
        log "removing $old"
        rm -rf "${RELEASES:?}/$old"
    fi
done < <(find "$RELEASES" -mindepth 1 -maxdepth 1 -type d -exec basename {} \; | sort -r)

log "done: $DEPLOY_REF ($REVISION) is live"
