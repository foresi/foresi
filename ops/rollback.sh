#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-only
#
# Points `current` back at the release before it (or at a named one), reloads
# PHP-FPM and restarts the workers. See docs/operations.md.
#
#   ops/rollback.sh                  # the release before the live one
#   ops/rollback.sh 20261106120000   # a named release
#
# DATABASE MIGRATIONS ARE NOT REVERTED. The older code runs against the newer
# schema. That is safe only when the migrations of the newer release were
# additive; otherwise restore the dump taken before them (ops/restore.sh).
set -euo pipefail

DEPLOY_ROOT=${DEPLOY_ROOT:-/srv/foresi}
if [[ -f "$DEPLOY_ROOT/deploy.env" ]]; then
    # shellcheck source=/dev/null
    source "$DEPLOY_ROOT/deploy.env"
fi
PHP=${PHP:-php}
RELOAD_FPM_COMMAND=${RELOAD_FPM_COMMAND:-sudo systemctl reload php8.4-fpm}
STOP_WORKERS=${STOP_WORKERS:-1}

RELEASES="$DEPLOY_ROOT/releases"
CURRENT="$DEPLOY_ROOT/current"

log() { printf '[rollback %s] %s\n' "$(date '+%H:%M:%S')" "$*"; }
fail() { log "ERROR: $*" >&2; exit 1; }

[[ -L "$CURRENT" ]] || fail "$CURRENT is not a symlink; nothing to roll back"
LIVE=$(cd -P "$CURRENT" && pwd)
LIVE_NAME=$(basename "$LIVE")

if [[ $# -ge 1 ]]; then
    TARGET="$RELEASES/$1"
else
    TARGET=""
    while IFS= read -r name; do
        if [[ "$name" < "$LIVE_NAME" ]]; then
            TARGET="$RELEASES/$name"
            break
        fi
    done < <(find "$RELEASES" -mindepth 1 -maxdepth 1 -type d -exec basename {} \; | sort -r)
fi
[[ -n "$TARGET" && -d "$TARGET" ]] || fail "no release to roll back to"
[[ -f "$TARGET/vendor/autoload.php" ]] || fail "$TARGET is not a complete release"
[[ "$TARGET" != "$LIVE" ]] || fail "$TARGET is already live"

log "switching current from $LIVE_NAME to $(basename "$TARGET")"
ln -sfn "$TARGET" "$DEPLOY_ROOT/.current.tmp"
mv -fT "$DEPLOY_ROOT/.current.tmp" "$CURRENT" 2>/dev/null \
    || mv -fh "$DEPLOY_ROOT/.current.tmp" "$CURRENT"

if [[ -n "$RELOAD_FPM_COMMAND" ]]; then
    log "reloading PHP-FPM: $RELOAD_FPM_COMMAND"
    bash -c "$RELOAD_FPM_COMMAND"
fi

if [[ "$STOP_WORKERS" == 1 ]]; then
    log "asking the workers to restart"
    (cd "$LIVE" && "$PHP" bin/console --env=prod --no-interaction messenger:stop-workers) \
        || log "warning: the rolled-back release could not signal its workers"
fi

log "done: $(basename "$TARGET") is live"
log "WARNING: database migrations were NOT reverted. Check that this release works with the current schema; see docs/operations.md#rollback."
