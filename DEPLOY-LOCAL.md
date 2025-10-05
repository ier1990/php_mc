
## Target Host
- **Host:** `192.168.1.152`
- **OS:** Debian GNU/Linux 12 (bookworm)
- **Stack:** nginx + php‑fpm
- **PHP CLI:** 8.2 (project remains 7.4‑safe)

## Update from GitHub (pull‑only)
Use the provided helper to refresh the working tree while preserving runtime dirs:
```bash
# One-time install (if not already present)
sudo tee /usr/local/bin/phpmc-update >/dev/null <<'SH'
#!/usr/bin/env bash
set -euo pipefail
cd /var/www/html/admin/php_mc
DEFBR=$(git remote show origin | sed -n 's/.*HEAD branch: //p'); [ -z "$DEFBR" ] && DEFBR=master
git fetch --prune
git reset --hard "origin/$DEFBR"
# keep runtime-only paths
git clean -fd -e src/private/db/ -e src/private/logs/ -e src/private/.env
SH
sudo chmod +x /usr/local/bin/phpmc-update

# Pull latest
phpmc-update && git log --oneline -n 3
```

## Permissions (nginx/php‑fpm friendly)
```bash
cd /var/www/html/admin/php_mc
sudo chown -R samekhi:www-data .
find . -type d -exec chmod 2775 {} \;
find . -type f -exec chmod 0664 {} \;
```

## Repo Hygiene (kept but untracked)
- `src/private/db/.gitignore`:
  ```gitignore
  *
  !.gitignore
  ```
- `src/private/logs/.gitignore`:
  ```gitignore
  *.log
  *.log.*
  !.gitignore
  !README.md
  ```

## Troubleshooting
- **Detached HEAD / rebase issues**: `git rebase --abort && phpmc-update`.
- **Unwanted tracked logs/db**: `git rm -r --cached src/private/logs/*.log src/private/db/*.{db,sqlite,sqlite3} src/private/db/*-wal src/private/db/*-shm` then commit.
- **Auth issues** (https remotes): configure a GitHub token or switch to SSH.

---

# .github/workflows/php-lint.yml (place under `.github/workflows/`)

```yaml
name: php-lint
on: [push, pull_request]
permissions:
  contents: read
jobs:
  lint:
    runs-on: ubuntu-latest
    strategy:
      matrix:
        php: ['7.4','8.2']
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ matrix.php }}
      - name: Lint PHP files
        run: |
          set -e
          find . -type f -name '*.php' -not -path './vendor/*' -print0 \
            | xargs -0 -n1 php -l
```

---

**Optional next files I can draft on request:**
- `CSRF.php` helper + example usage in a form
- `SECURITY.md` upgrade with disclosure policy boilerplate
- `CONTRIBUTING.md` section for “how to run a local PHP server for testing” (`php -S`)
- `Makefile` with `dev-lint`, `dev-serve`, `dev-test` targets (no external deps)

