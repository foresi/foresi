#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-only
#
# Splits every package of this repository into its own read-only repository
# (ADR 0034, Distribution): src/<Module>/ and packages/core/, each one that has a
# composer.json, go to <target><name>.git, where <name> is the package name
# without "foresi/".
#
# The split is `git subtree split`: the package's own history, with the same
# commit ids on every run, so a push is always a fast-forward. The branch is the
# split of HEAD, pushed as main. Tags are in lockstep: every tag vX.Y.Z from
# --since on is split and pushed to every repository that does not have it yet.
# Without --since no tag is pushed. A package added later starts at the first
# tag that has it.
#
#   split.sh [--target PREFIX] [--since TAG] [--push]
#
#   --target PREFIX  where the repositories are (default https://github.com/foresi/);
#                    a local directory of bare repositories works for a dry run
#   --since TAG      the first tag to push (the first lockstep tag)
#   --push           push; without it the splits are only computed and printed
#
# Needs a full clone with tags. Env: SPLIT_TOKEN, a token that may push to the
# target repositories on github.com (not needed for a local target).
set -euo pipefail

target=https://github.com/foresi/
since=
push=
while [ $# -gt 0 ]; do
	case "$1" in
	--target) target=$2; shift 2 ;;
	--since) since=$2; shift 2 ;;
	--push) push=1; shift ;;
	*) echo "Unknown option: $1" >&2; exit 2 ;;
	esac
done

cd "$(git rev-parse --show-toplevel)"

# The token goes in an HTTP header from the environment, so it is never on a
# command line or in a remote URL that git might print.
if [ -n "${SPLIT_TOKEN:-}" ]; then
	export GIT_CONFIG_COUNT=1
	export GIT_CONFIG_KEY_0=http.https://github.com/.extraheader
	GIT_CONFIG_VALUE_0="AUTHORIZATION: basic $(printf 'x-access-token:%s' "$SPLIT_TOKEN" | base64 | tr -d '\n')"
	export GIT_CONFIG_VALUE_0
fi

# The tags to keep in lockstep, oldest first.
tags=()
if [ -n "$since" ]; then
	git rev-parse -q --verify "refs/tags/$since" >/dev/null || { echo "No tag $since." >&2; exit 2; }
	mapfile -t tags < <(git tag -l 'v[0-9]*.[0-9]*.[0-9]*' --sort=v:refname | sed -n "/^$(printf '%s' "$since" | sed 's/\./\\./g')\$/,\$p")
fi

packages=()
for dir in packages/core src/*; do
	[ -f "$dir/composer.json" ] && packages+=("$dir")
done

# Splits one package and pushes it. Prints one line per ref.
split_one() {
	local dir=$1 name url head sha remote_tags tag
	name=$(sed -nE 's/^    "name": "foresi\/([a-z0-9-]+)",$/\1/p' "$dir/composer.json")
	[ -n "$name" ] || { echo "$dir: no foresi/ package name" >&2; return 1; }
	url="$target$name.git"

	# A new module has no repository until someone creates it; say so plainly
	# instead of a bare "repository not found" from git push.
	if [ -n "$push" ] && ! git ls-remote -q "$url" >/dev/null 2>&1; then
		echo "$name: ${url%.git} does not exist or SPLIT_TOKEN may not push to it. Create it first: CONTRIBUTING.md, \"A new module\"." >&2
		return 1
	fi

	head=$(git subtree split -q -P "$dir" HEAD)
	echo "$name main $head"
	[ -n "$push" ] && git push -q "$url" "$head:refs/heads/main"

	[ ${#tags[@]} -gt 0 ] || return 0
	remote_tags=
	[ -n "$push" ] && remote_tags=$(git ls-remote --tags --refs "$url" | sed 's|.*refs/tags/||')
	for tag in "${tags[@]}"; do
		grep -qxF "$tag" <<<"$remote_tags" && continue
		# A package added after a tag has nothing to split at that tag.
		git cat-file -e "$tag:$dir/composer.json" 2>/dev/null || continue
		sha=$(git subtree split -q -P "$dir" "$tag")
		echo "$name $tag $sha"
		[ -n "$push" ] && git push -q "$url" "$sha:refs/tags/$tag"
	done
	return 0
}

# The packages split in parallel; each one's output is printed when it is done.
logs=$(mktemp -d)
trap 'rm -rf "$logs"' EXIT
jobs=$(getconf _NPROCESSORS_ONLN 2>/dev/null || echo 2)
running=0
for dir in "${packages[@]}"; do
	(split_one "$dir" >"$logs/$(basename "$dir").out" 2>&1; echo $? >"$logs/$(basename "$dir").status") &
	running=$((running + 1))
	if [ "$running" -ge "$jobs" ]; then
		wait -n || true
		running=$((running - 1))
	fi
done
wait

failed=0
for dir in "${packages[@]}"; do
	base=$(basename "$dir")
	cat "$logs/$base.out"
	if [ "$(cat "$logs/$base.status")" != 0 ]; then
		echo "::error title=Split::$dir failed: $(tail -n 1 "$logs/$base.out")"
		[ -z "${GITHUB_STEP_SUMMARY:-}" ] || echo "- $dir: $(tail -n 1 "$logs/$base.out")" >>"$GITHUB_STEP_SUMMARY"
		failed=1
	fi
done
echo "${#packages[@]} packages${push:+ pushed to $target}."
exit $failed
