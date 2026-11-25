#!/usr/bin/env bash
# Installs bin/hooks/pre-push as the repository's pre-push hook: nothing is pushed that bin/gate.sh has not passed.
#
# Usage: bin/install-hooks.sh            install, or replace an older hook of this plugin
#        bin/install-hooks.sh --check    say nothing; status 0 when the installed hook is bin/hooks/pre-push as it is
#                                        now, 1 when it is not there or differs, 2 when this is not a working copy
#
# The hook goes into the hooks directory of the repository's common directory, so one installation serves the main
# working copy and every linked worktree. It is not part of a clone: run this once after cloning, and again when
# bin/hooks/pre-push changed (bin/gate.sh says so in one line when the installed hook is missing or differs).
#
# A hook that is there and is not this plugin's (it does not hold the line bin/hooks/pre-push is known by) is left
# as it is and the installation is refused: move it away first.
#
# No git command is run: the common directory is read from the working copy's .git (a directory, or the file a
# linked worktree has, which names its own directory, whose "commondir" names the common one). Not looked at: a
# core.hooksPath setting, with which git does not read this hooks directory at all.
set -u
REPO="$(cd "$(dirname "$0")/.." && pwd)" || exit 2
SOURCE="$REPO/bin/hooks/pre-push"
MARK='wp-checkpoint pre-push hook'

common=''
if [ -d "$REPO/.git" ]; then
	common="$REPO/.git"
elif [ -f "$REPO/.git" ]; then
	own="$(sed -n 's/^gitdir: //p' "$REPO/.git" | head -n 1)"
	if [ -n "$own" ]; then
		own="$(cd "$REPO" && cd "$own" 2>/dev/null && pwd)"
	fi
	if [ -n "$own" ] && [ -f "$own/commondir" ]; then
		common="$(cd "$own" && cd "$(head -n 1 "$own/commondir")" 2>/dev/null && pwd)"
	fi
fi
if [ -z "$common" ] || [ ! -d "$common" ] || [ ! -f "$common/HEAD" ]; then
	[ "${1:-}" = '--check' ] || echo "install-hooks: $REPO is not a working copy of a git repository (no repository found from its .git)." >&2
	exit 2
fi
target="$common/hooks/pre-push"

if [ "${1:-}" = '--check' ]; then
	[ -f "$target" ] && [ -x "$target" ] && cmp -s "$SOURCE" "$target" && exit 0
	exit 1
fi
if [ "$#" -ne 0 ]; then
	echo "Usage: bin/install-hooks.sh [--check]" >&2
	exit 64
fi
if [ ! -f "$SOURCE" ]; then
	echo "install-hooks: $SOURCE is not there." >&2
	exit 1
fi
if [ -e "$target" ] || [ -L "$target" ]; then
	if [ -f "$target" ] && [ ! -L "$target" ] && [ -x "$target" ] && cmp -s "$SOURCE" "$target"; then
		echo "install-hooks: the pre-push hook is installed and is bin/hooks/pre-push as it is now ($target)."
		exit 0
	fi
	if [ ! -f "$target" ] || [ -L "$target" ] || ! grep -qF "$MARK" "$target"; then
		echo "install-hooks: $target is there and is not this plugin's hook; it was left as it is. Move it away, then run this again." >&2
		exit 1
	fi
fi
mkdir -p "$common/hooks" || exit 1
# Written beside it and moved into place: a push never runs half a hook.
new="$target.new.$$"
cp "$SOURCE" "$new" && chmod 755 "$new" && mv -f "$new" "$target"
status=$?
if [ "$status" -ne 0 ]; then
	echo "install-hooks: the hook could not be written to $target." >&2
	exit 1
fi
echo "install-hooks: installed bin/hooks/pre-push as $target."
exit 0
