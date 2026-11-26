#!/usr/bin/env bash
# Publishes a GitHub release for every releasable commit on main since the
# newest release, oldest first. Running it again publishes only what is missing,
# so a cancelled or failed run is repaired by the next one.
#
# A commit is releasable when its Conventional Commit type is feat, fix or perf,
# or it is breaking. Its notes are the "## Release notes" section of its pull
# request. A commit that adds a version section to CHANGELOG.md (how releases
# were cut up to 0.5.0) is released under that version with that section, and
# it covers every commit before it.
#
# It runs in two steps, so that the workflow can attest the files in between:
#
#   release.sh plan DIR
#       Works out the missing releases and writes, for each one, DIR/VERSION/
#       with the notes, the source archive foresi-VERSION.tar.gz (git archive of
#       the commit) and the SBOM foresi-VERSION.cdx.json (bin/release-sbom), and
#       one line "VERSION SHA" in DIR/plan. The title is in DIR/VERSION/title.
#   release.sh publish DIR
#       Creates the planned releases with every file of DIR/VERSION/ named
#       foresi-* as an asset. gh uploads them before it publishes the release,
#       which immutable releases need.
#
# Needs a full clone with tags. Env: GH_TOKEN, GITHUB_REPOSITORY.
set -euo pipefail
# shellcheck source=.github/scripts/release-lib.sh
. "$(dirname "$0")/release-lib.sh"

mode=${1:-}
dir=${2:-}
[ -n "$dir" ] && { [ "$mode" = plan ] || [ "$mode" = publish ]; } || {
	echo "Usage: release.sh plan|publish DIR" >&2
	exit 2
}

# The version a commit cuts in CHANGELOG.md, if any.
changelog_cut() {
	git diff "$1^" "$1" -- CHANGELOG.md | sed -nE 's/^\+## \[([0-9]+\.[0-9]+\.[0-9]+)\].*/\1/p' | head -n 1
}

# The source archive and the SBOM of a release.
build_files() {
	local version=$1 sha=$2 out=$3 source
	git archive --format=tar.gz --prefix="foresi-$version/" -o "$out/foresi-$version.tar.gz" "$sha"
	source=$(mktemp -d)
	tar -xzf "$out/foresi-$version.tar.gz" -C "$source"
	bin/release-sbom "$source/foresi-$version" "$version" "$out/foresi-$version.cdx.json"
	rm -rf "$source"
}

plan() {
	mkdir -p "$dir"
	: >"$dir/plan"

	local last
	last=$(git tag -l 'v[0-9]*.[0-9]*.[0-9]*' --sort=-v:refname | head -n 1)
	[ -n "$last" ] || { echo "No release tag to start from."; exit 1; }
	mapfile -t commits < <(git rev-list --reverse --first-parent "$last..HEAD")

	# Start after the newest changelog cut in range, which covers everything before it.
	local start=0 i
	for i in "${!commits[@]}"; do
		[ -n "$(changelog_cut "${commits[$i]}")" ] && start=$i
	done

	local version=${last#v} planned=0 sha subject cut next title summary pr body notes out
	for sha in "${commits[@]:$start}"; do
		subject=$(git log -1 --format=%s "$sha")
		cut=$(changelog_cut "$sha")
		if [ -n "$cut" ]; then
			next=$cut
			title="v$next"
			out="$dir/$next"
			mkdir -p "$out"
			git show "$sha:CHANGELOG.md" | awk -v version="$next" '
				index($0, "## [" version "]") == 1 { found = 1; next }
				found && /^## \[/ { exit }
				found { print }
			' >"$out/notes.md"
		elif is_releasable "$subject"; then
			next=$(next_version "$version" "$subject")
			summary=$(printf '%s\n' "$subject" | sed -E 's/^[a-z]+(\([a-z0-9._-]+\))?!?: //; s/ \(#[0-9]+\)$//')
			title="v$next: $summary"
			out="$dir/$next"
			mkdir -p "$out"
			pr=$(printf '%s\n' "$subject" | sed -nE 's/.*\(#([0-9]+)\)$/\1/p')
			body=""
			[ -n "$pr" ] && body=$(gh pr view "$pr" --repo "$GITHUB_REPOSITORY" --json body --jq .body)
			notes=$(release_notes "$body")
			[ -n "$notes" ] || notes="- $summary"
			{
				printf '%s\n' "$notes"
				[ -n "$pr" ] && printf '\nPull request: #%s\n' "$pr"
			} >"$out/notes.md"
		else
			continue
		fi

		if git rev-parse -q --verify "refs/tags/v$next" >/dev/null; then
			echo "v$next exists already; skipping $sha."
			rm -rf "$out"
		else
			printf '%s\n' "$title" >"$out/title"
			build_files "$next" "$sha" "$out"
			printf '%s %s\n' "$next" "$sha" >>"$dir/plan"
			planned=$((planned + 1))
			echo "Planned v$next from $sha: $subject"
		fi
		version=$next
	done

	echo "Planned $planned release(s)."
	[ -n "${GITHUB_OUTPUT:-}" ] && echo "planned=$planned" >>"$GITHUB_OUTPUT"
	return 0
}

publish() {
	local published=0 version sha out
	while read -r version sha; do
		out="$dir/$version"
		if git rev-parse -q --verify "refs/tags/v$version" >/dev/null ||
			gh release view "v$version" --repo "$GITHUB_REPOSITORY" >/dev/null 2>&1; then
			echo "v$version exists already; skipping."
			continue
		fi
		gh release create "v$version" --repo "$GITHUB_REPOSITORY" --target "$sha" \
			--title "$(cat "$out/title")" --notes-file "$out/notes.md" "$out"/foresi-*
		git tag "v$version" "$sha"
		published=$((published + 1))
		echo "Published v$version from $sha."
	done <"$dir/plan"

	echo "Published $published release(s)."
	[ -n "${GITHUB_OUTPUT:-}" ] && echo "published=$published" >>"$GITHUB_OUTPUT"
	return 0
}

"$mode"
