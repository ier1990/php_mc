
## Welcome
Contributions are appreciated. This repo targets **PHP 7.4+** and runs on **Debian 12, nginx + php‑fpm**.

## Ground Rules
- Keep changes **small and scoped**. Avoid drive‑by refactors.
- Do **not** modify:
  - `src/private/db/*` (SQLite, WAL/SHM)
  - `src/private/logs/*`
  - `src/private/.env*`
- Security first: escape at output, validate inputs, add CSRF for state changes.

## Setup
```bash
# Requirements
php -v              # 7.4+ (8.2 used locally)
rg --version || true # ripgrep optional for searches

# Clone
git clone https://github.com/ier1990/php_mc
cd php_mc

# Optional: enable project git hooks (PHP lint on commit)
git config core.hooksPath .githooks
```

### Pre-commit Lint (optional but recommended)
Create `.githooks/pre-commit`:
```bash
#!/usr/bin/env bash
set -e
CHANGED=$(git diff --cached --name-only --diff-filter=ACM | grep -E '\\.php$' || true)
[ -z "$CHANGED" ] && exit 0
echo "PHP lint on staged files..."
ok=1
while IFS= read -r f; do
  php -l "$f" || ok=0
done <<< "$CHANGED"
[ "$ok" -eq 1 ] || { echo "✗ Lint failed"; exit 1; }
echo "✓ Lint passed"
```
Make it executable and point hooksPath:
```bash
chmod +x .githooks/pre-commit
git config core.hooksPath .githooks
```

## Coding Standards
- Follow **PSR‑12** where a style exists; otherwise mirror nearby code.
- Keep comments minimal and purposeful; document non‑obvious logic.
- Avoid features newer than PHP 7.4 in shared code paths.

## Branch & Commit
- Branch names: `feat/*`, `fix/*`, `chore/*`, `docs/*`, `security/*`.
- Commit message template:
```
<type>(area): short imperative summary

Why:
- rationale

What:
- key changes

Notes:
- risks / follow-ups
```

## Tests & CI
- Minimum: run `php -l` on changed files locally.
- CI runs basic lint on PHP **7.4** and **8.2** (see `.github/workflows/php-lint.yml`).

## Pull Requests
- Keep PRs small and focused; include a short checklist of what you verified.
- If the change introduces network access, external processes, or Composer deps, call it out explicitly.
