#!/usr/bin/env bash
# Checks the "## Release notes" section of a pull request.
# Env: PR_TITLE, PR_BODY.
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
[ "$status" -eq 0 ] && echo "Release notes are fine; merging publishes a release."
exit $status
