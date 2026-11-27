#!/usr/bin/env bash
# Checks the "## Release notes" section of a pull request.
# Env: PR_TITLE, PR_BODY, and PR_NUMBER when the pull request has one (CI). Without it, as
# in bin/pr-check before the pull request exists, the docs/upgrading.md check below matches
# the section by its text alone.
set -euo pipefail
# shellcheck source=.github/scripts/release-lib.sh
. "$(dirname "$0")/release-lib.sh"

notes=$(release_notes "${PR_BODY:-}")

if ! is_releasable "$PR_TITLE"; then
	if [ -n "$notes" ]; then
		echo "A $(commit_type "$PR_TITLE") pull request publishes no release, so its Release notes would be lost."
		echo "Remove the section, or use feat, fix or perf if users notice the change."
		exit 1
	fi
	echo "No release: $(commit_type "$PR_TITLE") changes are not released."
	exit 0
fi

if [ -z "$notes" ]; then
	echo "Merging this pull request publishes a release. Add a '## Release notes' section to its description,"
	echo "with at least one of the headings ### Upgrading, ### Added, ### Changed, ### Deprecated, ### Removed,"
	echo "### Fixed or ### Security, as in .github/pull_request_template.md."
	exit 1
fi

status=0
headings=$(printf '%s\n' "$notes" | grep -E '^#' || true)
while IFS= read -r heading; do
	[ -z "$heading" ] && continue
	if ! printf '%s\n' "$heading" | grep -Eq '^### (Upgrading|Added|Changed|Deprecated|Removed|Fixed|Security)$'; then
		echo "Unknown heading in the Release notes: $heading"
		status=1
	fi
done <<<"$headings"
if [ -z "$headings" ]; then
	echo "The Release notes need a heading such as ### Added or ### Fixed."
	status=1
fi
if ! printf '%s\n' "$notes" | grep -Eq '^[^#[:space:]]'; then
	echo "The Release notes have headings but no text."
	status=1
fi
if is_breaking "$PR_TITLE" && ! printf '%s\n' "$notes" | grep -Eq '^### Upgrading$'; then
	echo "A breaking change needs a ### Upgrading section that says what an operator or integrator must do."
	status=1
fi

# Release notes with ### Upgrading also go into docs/upgrading.md: a section headed
# "#### ... (#<PR_NUMBER>)" under the line of the minor version this release gets
# ("### 0.11"), with the same text. Links to docs/ are written relative to that folder there.
upgrading=$(upgrading_notes "$notes" | sed 's#](docs/#](#g')
if [ -n "$upgrading" ]; then
	guide="$(dirname "$0")/../../docs/upgrading.md"
	last=$(git tag -l 'v[0-9]*.[0-9]*.[0-9]*' --sort=-v:refname 2>/dev/null | head -n 1 || true)
	minor=
	if [ -n "$last" ]; then
		next=$(next_version "${last#v}" "$PR_TITLE")
		minor=${next%.*}
	fi
	if ! guide_has_section "$guide" "$minor" "${PR_NUMBER:-}" "$upgrading"; then
		where="under the line ### ${minor:-<minor version>}"
		echo "The Release notes have ### Upgrading, so docs/upgrading.md needs the same text in a section"
		echo "headed '#### [<version>](<release URL>): <title> (#${PR_NUMBER:-<pull request>})' $where,"
		echo "at the top of that minor version. Links to docs/ drop the docs/ there. Its text must be:"
		printf '%s\n' "$upgrading"
		status=1
	fi
fi
[ "$status" -eq 0 ] && echo "Release notes are fine; merging publishes a release."
exit $status
