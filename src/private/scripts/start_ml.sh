#!/usr/bin/env bash
#create & track your working branch
set -euo pipefail
cd "$(git rev-parse --show-toplevel)"
base="${1:-master}"
topic="${2:?Usage: start_ml <base> <topic>; e.g. start_ml master index-links}"
branch="ml/${topic}"
git fetch --prune
git switch "$base"
git pull --rebase origin "$base"
git switch -c "$branch"
git push -u origin "$branch"
echo "✓ Branch: $branch  (tracking origin/$branch)"
echo "✓ Done"