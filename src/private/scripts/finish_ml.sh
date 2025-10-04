#!/usr/bin/env bash
# finish & clean up your working branch
# after PR merge, sync master & clean local branch
set -euo pipefail
cd "$(git rev-parse --show-toplevel)"
merged_branch="${1:?Usage: finish_ml <branch>}"
git switch master
git fetch --prune
git pull --rebase origin master
git branch -d "$merged_branch" || true
git remote prune origin
echo "✓ master updated; local '$merged_branch' cleaned"
echo "✓ Done"