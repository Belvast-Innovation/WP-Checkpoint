#!/usr/bin/env bash
#
# A worktree of this repository for work alongside the main working copy:
#
#   bin/worktree.sh <path> <new branch> [<start point>]
#
# What it sets up, beyond git worktree add:
#   - vendor/ copied from the main working copy, never linked: Composer's autoloader takes its base directory from
#     where its own files really are, so a linked vendor/ loads src/ and tests/ of the main working copy (the
#     worktree's tests would run the other copy's code), and a test's child process confined by open_basedir to the
#     worktree cannot read it at all.
#   - docs and CLAUDE.md: the same links to the docs repository the main working copy has (when it has them).
#   - each of vendor, docs and CLAUDE.md ignored in the worktree (info/exclude is shared by every worktree; an entry
#     is added only when one is missing), and the worktree's status checked clean at the end.
#
# Integration tests still run only in the main working copy (wp-env mounts it). Refuses an existing path or branch.
# Exits non-zero, with the reason, at the first step that fails.

set -euo pipefail

if [ "$#" -lt 2 ] || [ "$#" -gt 3 ]; then
	echo "Usage: bin/worktree.sh <path> <new branch> [<start point>]" >&2
	exit 2
fi
path=$1
branch=$2
start=${3:-HEAD}

repo=$(git rev-parse --show-toplevel)
if [ -e "$path" ] || [ -L "$path" ]; then
	echo "Refused: $path is there already." >&2
	exit 1
fi
if git -C "$repo" show-ref --verify --quiet "refs/heads/$branch"; then
	echo "Refused: the branch $branch is there already." >&2
	exit 1
fi

git -C "$repo" worktree add -q -b "$branch" "$path" "$start"
path=$(cd "$path" && pwd -P)

if [ -d "$repo/vendor" ] && [ ! -L "$repo/vendor" ]; then
	cp -a "$repo/vendor" "$path/vendor"
else
	echo "Note: the main working copy has no vendor/ directory; run composer install in $path." >&2
fi
for name in docs CLAUDE.md; do
	if [ -L "$repo/$name" ]; then
		ln -s "$(readlink "$repo/$name")" "$path/$name"
	fi
done

exclude="$(git -C "$path" rev-parse --git-common-dir)/info/exclude"
case "$exclude" in
	/*) ;;
	*) exclude="$path/$exclude" ;;
esac
mkdir -p "$(dirname "$exclude")"
for name in vendor docs CLAUDE.md; do
	if [ -e "$path/$name" ] || [ -L "$path/$name" ]; then
		if ! git -C "$path" check-ignore -q "$name"; then
			printf '/%s\n' "$name" >>"$exclude"
		fi
	fi
done

left=$(git -C "$path" status --porcelain)
if [ -n "$left" ]; then
	echo "The new worktree's status is not clean:" >&2
	echo "$left" >&2
	exit 1
fi
if [ -L "$path/vendor" ]; then
	echo "vendor/ in the worktree is a link." >&2
	exit 1
fi
echo "Worktree $path on $branch (from $start), vendor/ copied."
