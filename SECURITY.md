
## Supported Versions
- **PHP 7.4+ required** (project must remain 7.4-safe)
- Verified in CI on **7.4** and **8.2**
- Newer PHP versions are best-effort unless otherwise noted

## Reporting a Vulnerability
- Preferred: open a **GitHub Security Advisory** (if enabled) or a private issue.
- Alternatively email: **sales@electronics-recycling.org** (include steps to reproduce; do **not** send secrets).
- Target response: acknowledgment in **72 hours**; remediation plan within **7 business days** when feasible.

## Security Expectations (Developers)
- **Escape at output boundaries**: `htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')`.
- **Validate input**: normalize and validate all `$_GET`/`$_POST`/`$_COOKIE` before use.
- **CSRF**: all state‑changing actions must carry a CSRF token (per-session, per-form) and verify server‑side.
- **File paths**: never concatenate user input into filesystem paths without strict allowlists.
- **Uploads** (if any): whitelist extensions/MIME, randomize filenames, store outside web root, verify post‑move.
- **SQL**: use parameterized queries only; never interpolate raw input.
- **Secrets**: never commit `.env` or credentials. Treat logs as sensitive (may contain request metadata).
- **Runtime artifacts**: `src/private/db/*` and `src/private/logs/*` are not versioned; keep `.gitignore` sentinels.
- **Dependencies**: Composer/package changes require review and must pin versions.

## Hardening Checklist
- Output escaping in templates and admin pages
- CSRF on all POST/DELETE actions
- Path traversal guards on any file read/write/include
- Session cookies: `HttpOnly` and `SameSite=Lax` or stricter
- Disable verbose error display in production (use logs)

## Responsible Disclosure
Thank you for reporting responsibly and giving us a chance to fix issues before public disclosure.
