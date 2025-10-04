#!/usr/bin/env bash
set -euo pipefail
cd "$(git rev-parse --show-toplevel)"
cur="$(git branch --show-current)"
[ -n "$cur" ] || { echo "✗ No current branch"; exit 1; }
[ "$cur" != "master" ] || { echo "✗ On master; use scripts/push_master.sh"; exit 1; }

git add -A
git restore --staged src/private/logs/* src/private/db/* 2>/dev/null || true

msg="${1:-"WIP: $cur"}"
STAGED="$(git diff --cached --name-only --diff-filter=ACM | grep -E '\.php$' || true)"
if [ -n "$STAGED" ]; then
  ok=1; while IFS= read -r f; do php -l "$f" || ok=0; done <<<"$STAGED"
  [ "$ok" -eq 1 ] || { echo "✗ Lint failed"; exit 1; }
fi

git commit -m "$msg" || echo "Nothing to commit."
git pull --rebase origin "$cur" || true
git push -u origin "$cur"

if command -v gh >/dev/null; then
  gh pr create --base master --title "$msg" --fill --web || true
else
  echo "Open PR: https://github.com/ier1990/php_mc/compare/master...$cur"
fi
echo "✓ Pushed $cur"
