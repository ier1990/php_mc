#!/usr/bin/env bash
set -euo pipefail
cd "$(git rev-parse --show-toplevel 2>/dev/null || pwd)"

branch="$(git branch --show-current || true)"
[ "$branch" = "master" ] || { echo "✗ Not on master (on '$branch')"; exit 1; }

git fetch --prune
git status -sb

# Stage everything (your .gitignore protects logs/db)
git add -A
# Belt & suspenders: unstage runtime junk if it slipped
git restore --staged src/private/logs/* src/private/db/* 2>/dev/null || true

# Lint staged PHP (fast)
STAGED="$(git diff --cached --name-only --diff-filter=ACM | grep -E '\.php$' || true)"
if [ -n "$STAGED" ]; then
  echo "Linting staged PHP files..."
  ok=1; while IFS= read -r f; do php -l "$f" || ok=0; done <<<"$STAGED"
  [ "$ok" -eq 1 ] || { echo "✗ Lint failed"; exit 1; }
fi

msg="${1:-"chore: update from .152"}"
git commit -m "$msg" || echo "Nothing to commit."
git pull --rebase origin master
git push origin master
echo "✓ Pushed to origin/master"
echo "✓ Done"