#!/usr/bin/env bash
set -euo pipefail
cd /var/www/html/admin/php_mc
DEFBR=$(git remote show origin | sed -n 's/.*HEAD branch: //p'); [ -z "$DEFBR" ] && DEFBR=master
git fetch --prune
git reset --hard "origin/$DEFBR"
git clean -fd -e src/private/db/ -e src/private/logs/ -e src/private/.env
