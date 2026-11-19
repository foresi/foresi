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
# Needs a full clone with tags. Env: GH_TOKEN, GITHUB_REPOSITORY.
set -euo pipefail
# shellcheck source=.github/scripts/release-lib.sh
. "$(dirname "$0")/release-lib.sh"

notes_file=$(mktemp)
trap 'rm -f "$notes_file"' EXIT

last=$(git tag -l 'v[0-9]*.[0-9]*.[0-9]*' --sort=-v:refname | head -n 1)
[ -n "$last" ] || { echo "No release tag to start from."; exit 1; }
mapfile -t commits < <(git rev-list --reverse --first-parent "$last..HEAD")

# The version a commit cuts in CHANGELOG.md, if any.
changelog_cut() {
	git diff "$1^" "$1" -- CHANGELOG.md | sed -nE 's/^\+## \[([0-9]+\.[0-9]+\.[0-9]+)\].*/\1/p' | head -n 1
}

# Start after the newest changelog cut in range, which covers everything before it.
start=0
for i in "${!commits[@]}"; do
	[ -n "$(changelog_cut "${commits[$i]}")" ] && start=$i
done

version=${last#v}
published=0
for sha in "${commits[@]:$start}"; do
	subject=$(git log -1 --format=%s "$sha")
	cut=$(changelog_cut "$sha")
	if [ -n "$cut" ]; then
		next=$cut
		title="v$next"
		git show "$sha:CHANGELOG.md" | awk -v version="$next" '
			index($0, "## [" version "]") == 1 { found = 1; next }
			found && /^## \[/ { exit }
			found { print }
		' >"$notes_file"
	elif is_releasable "$subject"; then
		next=$(next_version "$version" "$subject")
		summary=$(printf '%s\n' "$subject" | sed -E 's/^[a-z]+(\([a-z0-9._-]+\))?!?: //; s/ \(#[0-9]+\)$//')
		title="v$next: $summary"
		pr=$(printf '%s\n' "$subject" | sed -nE 's/.*\(#([0-9]+)\)$/\1/p')
		body=""
		[ -n "$pr" ] && body=$(gh pr view "$pr" --repo "$GITHUB_REPOSITORY" --json body --jq .body)
		notes=$(release_notes "$body")
		[ -n "$notes" ] || notes="- $summary"
		{
			printf '%s\n' "$notes"
			[ -n "$pr" ] && printf '\nPull request: #%s\n' "$pr"
		} >"$notes_file"
	else
		continue
	fi

	if git rev-parse -q --verify "refs/tags/v$next" >/dev/null; then
		echo "v$next exists already; skipping $sha."
	else
		gh release create "v$next" --repo "$GITHUB_REPOSITORY" --target "$sha" --title "$title" --notes-file "$notes_file"
		git tag "v$next" "$sha"
		published=$((published + 1))
		echo "Published v$next from $sha: $subject"
	fi
	version=$next
done

echo "Published $published release(s)."
[ -n "${GITHUB_OUTPUT:-}" ] && echo "published=$published" >>"$GITHUB_OUTPUT"
exit 0
