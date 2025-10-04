#!/bin/bash
#===============================================================================
# dirperm.sh – Directory and file permission normaliser
#
# This script ensures consistent ownership and permissions across the PHP‑MC
# project tree.  It is safe to run from cron (hourly) or manually by an admin.
#
# Author: CodeWalker lmstudio/openai/gpt-oss-20b
# Date:   2025-09-28
#===============================================================================

set -euo pipefail

# Backup last 200 lines of nginx error log to ai summary log
tail -n 200 /var/log/nginx/error.log > /var/www/html/admin/php_mc/src/private/logs/error.log

# --------------------------------------------------------------------------- #
# Configuration
# --------------------------------------------------------------------------- #

# Base directory of the PHP‑MC project
PROJECT_ROOT="/var/www/html/admin/php_mc"

# User and group that should own all files
OWNER_USER="samekhi"
OWNER_GROUP="www-data"

# Permissions to apply
DIR_PERM=2770   # sticky bit + rwx for owner/group
FILE_PERM=660   # rw for owner/group, no execute

# Scripts directory (needs exec permissions)
SCRIPTS_DIR="$PROJECT_ROOT/src/private/scripts"

# Cron hourly wrapper location
CRON_HOURLY="/etc/cron.hourly/dirperm"

# --------------------------------------------------------------------------- #
# Helper functions
# --------------------------------------------------------------------------- #

log() {
    printf '%s\n' "$*"
}

run_cmd() {
    # Execute a command and log it; exit on failure.
    log ">>> $*"
    eval "$@"
}

# --------------------------------------------------------------------------- #
# Core logic
# --------------------------------------------------------------------------- #

main() {
    # 1. Ensure project ownership
    run_cmd chown -R -c "${OWNER_USER}:${OWNER_GROUP}" "$PROJECT_ROOT"

    # 2. Set directory permissions recursively
    find "$PROJECT_ROOT" -type d -exec chmod "$DIR_PERM" {} +

    # 3. Set file permissions recursively
    find "$PROJECT_ROOT" -type f -exec chmod "$FILE_PERM" {} +

    # 4. Ensure script files are executable
    run_cmd chmod 770 "$SCRIPTS_DIR/codewalker.py"
    run_cmd chmod 770 "$SCRIPTS_DIR/dirperm.sh"

    # 5. Create cron‑hourly wrapper if it doesn't exist
    #rm -f "$CRON_HOURLY"
    if [[ ! -f "$CRON_HOURLY" ]]; then
        log "Creating hourly cron wrapper at $CRON_HOURLY"
        cp "$0" "$CRON_HOURLY"
        run_cmd chmod 770 "$CRON_HOURLY"
    else
        log "Cron wrapper already present: $CRON_HOURLY"
    fi

    log "Permission normalisation complete."
}

# --------------------------------------------------------------------------- #
# Entry point
# --------------------------------------------------------------------------- #

main "$@"
