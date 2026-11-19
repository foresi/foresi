# shellcheck shell=bash
# Shared by check-release-notes.sh and release.sh. Source it; it defines functions only.

# The Conventional Commit type of a subject, such as feat; empty when none.
commit_type() {
	printf '%s\n' "$1" | sed -nE 's/^([a-z]+)(\([a-z0-9._-]+\))?!?: .*/\1/p'
}

# Succeeds when the subject marks a breaking change with "!".
is_breaking() {
	printf '%s\n' "$1" | grep -Eq '^[a-z]+(\([a-z0-9._-]+\))?!: '
}

# Succeeds when merging this subject publishes a release.
is_releasable() {
	case "$(commit_type "$1")" in
		feat | fix | perf) return 0 ;;
	esac
	is_breaking "$1"
}

# Prints the "## Release notes" section of a pull request body, without its
# heading and HTML comments, trimmed of blank lines at both ends.
release_notes() {
	printf '%s\n' "$1" | tr -d '\r' | perl -0pe 's/<!--.*?-->//gs' | awk '
		/^## / { if (found) exit; if ($0 ~ /^## Release notes[[:space:]]*$/) { found = 1; next } }
		found { lines[++n] = $0 }
		END {
			first = 1; while (first <= n && lines[first] ~ /^[[:space:]]*$/) first++
			last = n; while (last >= first && lines[last] ~ /^[[:space:]]*$/) last--
			for (i = first; i <= last; i++) print lines[i]
		}
	'
}

# The next version after $1 (x.y.z) for a subject, following Semantic Versioning.
# Before 1.0.0 a breaking change raises the minor version and anything else the patch.
next_version() {
	local major minor patch
	IFS=. read -r major minor patch <<<"$1"
	if is_breaking "$2"; then
		if [ "$major" -eq 0 ]; then echo "0.$((minor + 1)).0"; else echo "$((major + 1)).0.0"; fi
	elif [ "$major" -gt 0 ] && [ "$(commit_type "$2")" = feat ]; then
		echo "$major.$((minor + 1)).0"
	else
		echo "$major.$minor.$((patch + 1))"
	fi
}
