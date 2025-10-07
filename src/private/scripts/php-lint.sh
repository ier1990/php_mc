#!/usr/bin/env bash
set -euo pipefail
cd "$(git rev-parse --show-toplevel)"

MODE="${1:-all}"          # staged | changed | all
PHPBIN="${PHPBIN:-php}"   # override with PHPBIN=/path/php
DOCKER="${DOCKER:-}"      # set DOCKER=7.4 to lint in php:7.4-cli

# Build the file list (NUL-delimited, robust)
list_files() {
  case "$MODE" in
    staged)
      git diff --cached --name-only -z --diff-filter=ACM -- '*.php' ;;
    changed)
      git diff --name-only -z --diff-filter=ACM -- '*.php' ;;
    all)
      git ls-files -z '*.php' \
        ':(exclude)vendor/**' ':(exclude)old/**' \
        ':(exclude)src/private/db/**' ':(exclude)src/private/logs/**' ;;
    *)
      echo "Usage: $0 [staged|changed|all]"; exit 2 ;;
  esac
}

# Choose linter command
if [[ "$DOCKER" == "7.4" ]]; then
  run_lint='docker run --rm -v "$PWD":/app -w /app php:7.4-cli php -n -l'
else
  run_lint="$PHPBIN -n -l"   # -n = no php.ini (faster, pure syntax)
fi

ok=1
# shellcheck disable=SC2046
if [[ -z "$(list_files | tr -d '\0')" ]]; then
  echo "No PHP files to lint ($MODE)."; exit 0
fi

# Read NUL-delimited paths safely
while IFS= read -r -d '' f; do
  case "$f" in
    vendor/*|old/*|src/private/db/*|src/private/logs/*) continue ;;
  esac
  eval $run_lint "\"$f\"" || ok=0
done < <(list_files)

exit $ok
