# AI CodeWalker — MC Tools

A lightweight companion UI to browse files, edit local CodeWalker config, and jump to the main Admin.

## URLs

- MC landing page: `/admin/php_mc/src/index.php`
- File browser: `/admin/php_mc/src/mc.php?dir=/var/www/html`
- Config editor: `/admin/php_mc/src/config.php`
- Main Admin: `/admin/index.php`

## What lives here

- `mc.php` — simple file browser with a tools dropdown per file:
  - Run `custom.php` on the file
  - Open DB file info in Admin
  - Add file to CodeWalker queue
- `index.php` — mini landing page with quick links (Admin, mc.php, config editor)
- `config.php` — editor for `src/private/codewalker.json` with JSON validation and backups
- `utils.php` — PHP 7.4-safe helpers shared by pages
- `private/codewalker.json` — config for the local MC CodeWalker instance

## Editing the CodeWalker config

Open `/admin/php_mc/src/config.php` to view or edit `private/codewalker.json`.

- Validate/format JSON before save
- Backups are created as `codewalker.json.bak.YYYYMMDD_HHMMSS`
- If the file/folder doesn’t exist, saving will create them

Example config (edit to match your environment):

```
{
  "db_path": "/var/www/html/admin/php_mc/src/private/codewalker.db",
  "write_root": "/var/www/html/admin/php_mc",
  "mode": "cron",                   // or: "que" to process queued files only
  "exclude_dirs": ["vendor", ".git", "node_modules"],
  "exclude_files": ["*.min.js", "*.map"],
  "llm": {
    "provider": "local",
    "base_url": "http://127.0.0.1:11434"
  }
}
```

## Queue integration

From `mc.php`:
- For any file, use the wrench dropdown → “Add to CodeWalker queue” to queue it in Admin.
- “Open DB file info” jumps to the Admin page for that file.

In Admin (`/admin/index.php`):
- Use the Queue tab to see pending/done entries.
- The CodeWalker (mc-local) walker can prioritize or only process these queued paths depending on `mode`.

## Modes

- `cron` — normal scan of directories, but prioritize queued paths first
- `que` / `queue` / `queue-only` / `queued` — only process queued paths from DB

These modes are read by the mc-local CodeWalker script (in `src/private/scripts/codewalker.py`).

## Excluding files/dirs

- `exclude_dirs` — prunes traversal (e.g., vendor, .git)
- `exclude_files` — fnmatch patterns tested on both basename and relative path (e.g., `*.min.js`, `*/cache/*`)

## Tips

- Use the Admin dashboard to apply rewrites and see history.
- Always keep a backup of important files; Admin applies a backup when applying a rewrite.
- Keep PHP at 7.4+; this UI avoids PHP 8-only features.

---

If you want a multi-DB selector or richer diffs/revert from backup, we can extend this UI. 
