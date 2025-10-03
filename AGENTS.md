# Codex Agent Instructions

## Mission

Provide precise, actionable help for developers working in this codebase.

Goals:

1. Respect existing project conventions; do not overwrite unrelated work.
2. Communicate assumptions, limitations, and next steps clearly.
3. Prefer minimal, reversible changes with clear rationale.

## Environment Facts (Read First)

**Target PHP:** 7.4+ (must remain 7.4-safe; only use 8.x features behind guards).

**Sensitive areas (hands off unless explicitly approved):**

- `private/db/`, any `*.sqlite` / `*.db` – never modify directly.
- `vendor/` – do not edit; only modify via Composer with approval.
- `logs/`, `cache/` – writable runtime output; do not commit artifacts.

**UI preference:** dark fields (black inputs, bold white text, light grey backgrounds) when adding forms.

## Allowed / Approval Matrix

| Operation                                              | Status         |
|--------------------------------------------------------|----------------|
| Read/grep repo, propose diffs, local `php -l`          | Allowed        |
| Create / edit docs (README, CHANGELOG, etc.)           | Allowed        |
| Add tests or dev scripts under `tests/` or `dev/`      | Allowed        |
| Network access / package installs / Composer changes   | Needs approval |
| File writes outside repo root                          | Needs approval |
| DB schema changes or writes to production data         | Needs approval |
| Deleting unrelated existing files                      | Denied         |
| Editing `private/db/`, `vendor/`, `.env*`, OS services | Denied         |

## Workflow

1. **Recon** – Inspect structure first.
   - Map structure:
     ```bash
     rg -n --hidden --glob '!vendor' --glob '!private/db' . | head -n 100
     ```
   - Identify PHP entrypoints, includes, helpers (e.g., [utils.php](http://_vscodecontentref_/2)).
2. **Plan** – Draft concise steps; flag anything needing approval.
3. **Execute** – Small, reversible, logically isolated edits.
4. **Validate** – Lint / sanity-test affected paths.
5. **Summarize** – List touched files + rollback instructions.

## Editing Guidelines

- Keep edits ASCII-only unless non-ASCII already exists or is required.
- Preserve existing formatting; if none, follow PSR-12.
- Avoid reverting user edits unless explicitly requested.
- Use prepared / parameterized queries for SQL.
- Add comments only for non-obvious logic.

## Security Rules

- Never dump entire `$_ENV` or `$_SERVER`.
- Escape any echoed user input (`h()` helper, `htmlspecialchars`).
- Validate / sanitize all `$_GET`, `$_POST`, `$_COOKIE` inputs.
- All state-changing forms require CSRF tokens.
- Report unexpected world-writable directories or permissions issues.

## Sandbox & Approvals

- Default mode: workspace-only operations.
- Always ask before: network calls, Composer, writing outside repo, long-running jobs.
- If blocked: offer an offline alternative (e.g., regex transform instead of fetch).

## Testing & Verification (Minimal Set)

- Lint changed PHP files:
  ```bash
  git diff --cached --name-only | grep -E '\.php$' | xargs -r -n1 php -l
  ```
- Ad hoc checks:
  - Static HTML/PHP includes resolve paths.
  - Forms: verify CSRF + server-side validation exists or add it.
- If adding tests, place in tests/ with a simple runner; skip heavy frameworks unless present.

## Git Hygiene

- Always work on a topic branch.
- Make a single cohesive commit unless the task is large (then logical commits).
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
  where `<type>` ∈ fix, feat, docs, refactor, chore, test, security.

## Definition of Done

- Changes lint clean (php -l).
- No writes outside repo; no DB/schema mutations.
- Docs updated if behavior, config, or UI changed.
- Clear summary + rollback instructions included.

## Quick Command Palette (agent helpers)

# Grep with context (read-only)
rg -n --glob '!vendor' --glob '!private/db' 'pattern'

# Lint only files staged for commit
git diff --cached --name-only | grep -E '\.php$' | xargs -r -n1 php -l

# Create a patch without committing
git diff > /tmp/patch.diff

# Grep with context (read-only)
rg -n --glob '!vendor' --glob '!private/db' 'pattern'

# Lint only files staged for commit
git diff --cached --name-only | grep -E '\.php$' | xargs -r -n1 php -l

# Create a patch without committing
git diff > /tmp/patch.diff

## Common Pitfalls (avoid)

- Using PHP 8.1+ features (enums, readonly props) in shared code.
- Echoing user input without escaping.
- Editing .sqlite files or anything in private/db/.
- Writing assets or logs that end up in version control.

## Safety

- Do not execute destructive commands without explicit user approval.
- Report unexpected repository changes immediately.
- Stay sandbox-aware: request elevation for operations outside the workspace or requiring network access.

## Communication

- Use a friendly, concise tone.
- Highlight blockers or open questions early.
- Suggest logical follow-up actions when appropriate.
