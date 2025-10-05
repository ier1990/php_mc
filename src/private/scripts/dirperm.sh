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


# if not root, exit
if [[ $EUID -ne 0 ]]; then
   echo "This script must be run as root"
   exit 1
fi

# Backup last 200 lines of nginx error log to ai summary log
# detects if apache or nginx is used
if [ -f /var/log/apache2/error.log ]; then
    tail -n 200 /var/log/apache2/error.log > /var/www/html/admin/php_mc/src/private/logs/error.log
elif [ -f /var/log/httpd/error_log ]; then
    tail -n 200 /var/log/httpd/error_log > /var/www/html/admin/php_mc/src/private/logs/error.log
elif [ -f /var/log/nginx/error.log ]; then
    tail -n 200 /var/log/nginx/error.log > /var/www/html/admin/php_mc/src/private/logs/error.log
else
    echo "No web server error log found."
    #exit 1
fi

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

    # 4. Ensure files are executable    
    run_cmd chmod 770 "$SCRIPTS_DIR/*"
    run_cmd chmod 0755 "$PROJECT_ROOT/.githooks/*"

    # 5. Git sentinels (keep dirs tracked, ignore contents)
    printf '*\n!.gitignore\n' > "$PROJECT_ROOT/src/private/db/.gitignore"
    printf '*.log\n*.log.*\n!.gitignore\n!README.md\n' > "$PROJECT_ROOT/src/private/logs/.gitignore"    

    # 6. Delete cron‑hourly wrapper if it doesn't exist
    if [[ -f "$CRON_HOURLY" ]]; then
        log "Deleting hourly cron wrapper at $CRON_HOURLY"
        rm -f "$CRON_HOURLY"
    fi

    # 7. Create cron‑hourly wrapper if it doesn't exist
    if [[ ! -f "$CRON_HOURLY" ]]; then
        log "Creating hourly cron wrapper at $CRON_HOURLY"
        cp "$0" "$CRON_HOURLY"
        run_cmd chmod 770 "$CRON_HOURLY"
        run_cmd chown -R -c "${OWNER_USER}:${OWNER_GROUP}" "$CRON_HOURLY"
    else
        log "Cron wrapper already present: $CRON_HOURLY"
    fi

    log "Permission normalisation complete."
}

# --------------------------------------------------------------------------- #
# Entry point
# --------------------------------------------------------------------------- #

main "$@"
