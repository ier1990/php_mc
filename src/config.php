<?php
// Edit the CodeWalker config JSON (private/codewalker.json) with validation and backups.

require_once __DIR__ . '/utils.php';

$CONFIG_PATH = __DIR__ . '/private/codewalker.json';
$status = null; // ['type' => 'ok'|'err'|'info', 'msg' => string]
$loadedText = '';

// Load current config text (or default empty object)
if (is_file($CONFIG_PATH)) {
    $loadedText = (string)file_get_contents($CONFIG_PATH);
} else {
    $loadedText = "{\n}\n";
}

$action = postp('op');
$csrf = ensure_csrf();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $incoming = (string)($_POST['json'] ?? '');
    $decoded = json_decode($incoming, true);
    if (JSON_ERROR_NONE !== json_last_error()) {
        $status = ['type' => 'err', 'msg' => 'Invalid JSON: ' . json_last_error_msg()];
        $loadedText = $incoming; // keep user edits
    } else {
        // Reformat (pretty print) for both Format and Save
        $pretty = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        if ($action === 'format') {
            $status = ['type' => 'info', 'msg' => 'JSON formatted (not saved).'];
            $loadedText = $pretty;
        } else {
            // Save with backup
            $dir = dirname($CONFIG_PATH);
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            if (is_file($CONFIG_PATH)) {
                $backup = $CONFIG_PATH . '.bak.' . date('Ymd_His');
                @copy($CONFIG_PATH, $backup);
            }
            $ok = @file_put_contents($CONFIG_PATH, $pretty, LOCK_EX);
            if ($ok === false) {
                $status = ['type' => 'err', 'msg' => 'Failed to write file. Check permissions for: ' . h($CONFIG_PATH)];
                $loadedText = $incoming; // keep user edits
            } else {
                $status = ['type' => 'ok', 'msg' => 'Saved successfully.'];
                $loadedText = $pretty;
            }
        }
    }
}

$writable = is_writable(dirname($CONFIG_PATH)) && (!is_file($CONFIG_PATH) || is_writable($CONFIG_PATH));
$exists = is_file($CONFIG_PATH);
$meta = $exists ? ('size ' . number_format(filesize($CONFIG_PATH)) . ' bytes · modified ' . date('Y-m-d H:i', filemtime($CONFIG_PATH))) : 'file will be created';
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Edit codewalker.json</title>
  <style>
    :root{color-scheme:dark light}
    body{font-family:system-ui,Segoe UI,Arial; background:#0b1020; color:#e8eefb; margin:0}
    .wrap{max-width:980px; margin:6vh auto; padding:0 16px}
    .card{background:#141a33; border:1px solid #263056; border-radius:12px; padding:18px; margin-bottom:18px}
    h1{font-weight:600; font-size:22px; margin:0 0 8px}
    .row{display:flex; gap:10px; flex-wrap:wrap; align-items:center}
    .btn{display:inline-block; padding:10px 14px; border-radius:10px; border:1px solid #3af; background:#1a2246; color:#fff; text-decoration:none}
    .btn.secondary{border-color:#666; background:#222a4a}
    .btn.warn{border-color:#f90; background:#4a351a}
    textarea{width:100%; min-height:60vh; font-family:ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace; font-size:13px; line-height:1.4; color:#eef; background:#0e1330; border:1px solid #334; border-radius:10px; padding:12px}
    .meta{opacity:.8; font-size:12px; margin:6px 0}
    .flash{margin:10px 0; padding:10px 12px; border-radius:8px}
    .flash.ok{background:#12351a; border:1px solid #2e6b3a}
    .flash.err{background:#3a1820; border:1px solid #7b2d3a}
    .flash.info{background:#112a3a; border:1px solid #2e5a7b}
  </style>
  <script>
    function loadExample() {
      const example = {
        "db_path": "/var/www/html/admin/php_mc/src/private/codewalker.db",
        "write_root": "/var/www/html/admin/php_mc",
        "mode": "cron",
        "exclude_dirs": ["vendor", ".git", "node_modules"],
        "exclude_files": ["*.min.js", "*.map"],
        "llm": {"provider": "local", "base_url": "http://127.0.0.1:11434"}
      };
      const ta = document.getElementById('json');
      ta.value = JSON.stringify(example, null, 2) + "\n";
    }
  </script>
  </head>
<body>
  <div class="wrap">
    <div class="card">
      <h1>Edit codewalker.json</h1>
      <div class="row" style="margin-top:8px">
        <a class="btn" href="index.php">↩ Back to MC Tools</a>
        <a class="btn secondary" href="index.php?dir=<?php echo urlencode(dirname($CONFIG_PATH)); ?>" target="_blank">Open private/ in Browser</a>
        <button class="btn secondary" type="button" onclick="loadExample()">Load example</button>
      </div>
      <div class="meta">File: <?php echo h($CONFIG_PATH); ?> · <?php echo h($meta); ?> · Writable: <?php echo $writable ? 'yes' : 'no'; ?></div>
      <?php if ($status) { ?>
        <div class="flash <?php echo h($status['type']); ?>"><?php echo h($status['msg']); ?></div>
      <?php } ?>
      <form method="post">
        <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
        <textarea id="json" name="json" spellcheck="false"><?php echo h($loadedText); ?></textarea>
        <div class="row" style="margin-top:10px">
          <button class="btn secondary" name="op" value="format" type="submit">Format JSON</button>
          <button class="btn" name="op" value="save" type="submit" <?php echo $writable ? '' : 'disabled'; ?>>Save</button>
        </div>
      </form>
    </div>
  </div>
</body>
</html>
