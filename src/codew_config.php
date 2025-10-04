<?php
// Minimal landing page for the PHP MC tools.
// Provides quick links into the file browser (mc.php) and the main Admin dashboard.

require_once __DIR__ . '/utils.php';

$docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? $_SERVER['DOCUMENT_ROOT'] : '';
$candidates = array_unique(array_values(array_filter([
    $docRoot,
    '/var/www',
    '/var/www/html',
    '/home',
    '/web',
    dirname(__DIR__),
])));

// Filter to only those that exist on this system
$candidates = array_values(array_filter($candidates, function ($p) { return is_dir($p); }));

$startDir = getp('dir', $docRoot ?: (isset($candidates[0]) ? $candidates[0] : getcwd()));
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>MC Tools</title>
  <style>
    :root{color-scheme:dark light}
    body{font-family:system-ui,Segoe UI,Arial; background:#0b1020; color:#e8eefb; margin:0}
    .wrap{max-width:980px; margin:6vh auto; padding:0 16px}
    .card{background:#141a33; border:1px solid #263056; border-radius:12px; padding:18px; margin-bottom:18px}
    h1{font-weight:600; font-size:22px; margin:0 0 8px}
    label{display:block; font-size:13px; opacity:.9; margin-bottom:6px}
    input[type=text]{width:100%; padding:10px 12px; border-radius:10px; border:1px solid #334; background:#0e1330; color:#eef}
    .row{display:flex; gap:10px; flex-wrap:wrap; align-items:center}
    .btn{display:inline-block; padding:10px 14px; border-radius:10px; border:1px solid #3af; background:#1a2246; color:#fff; text-decoration:none}
    .btn.secondary{border-color:#666; background:#222a4a}
    .chips{display:flex; flex-wrap:wrap; gap:8px; margin-top:8px}
    .chip{padding:6px 10px; border-radius:999px; border:1px solid #334; background:#11183a}
    .footer{opacity:.7; font-size:12px; margin-top:14px}
  </style>
</head>
<body>
  <div class="wrap">
    <div class="card">
      <h1>PHP MC Tools</h1>
      <div class="row" style="margin-top:8px">
        <a class="btn" href="index.php?dir=<?php echo urlencode($startDir); ?>">↩ Back to MC Browser</a>
        <a class="btn secondary" href="codewalker.php">Back to CodeWalker</a>
        <a class="btn secondary" href="custom.php">Run custom.php</a>
        <a class="btn secondary" href="config.php">Edit codewalker.json</a>
      </div>
    </div>

    <div class="card">
      <label for="dir">Start directory</label>
      <form method="get" action="index.php" class="row">
        <input type="text" id="dir" name="dir" value="<?php echo h($startDir); ?>" placeholder="/var/www/html">
        <button class="btn" type="submit">Browse…</button>
      </form>
      <?php if (!empty($candidates)) { ?>
      <div class="chips">
        <?php foreach ($candidates as $p) { ?>
          <a class="chip" href="index.php?dir=<?php echo urlencode($p); ?>"><?php echo h($p); ?></a>
        <?php } ?>
      </div>
      <?php } ?>
      <div class="footer">DocRoot: <?php echo h($docRoot ?: '(unknown)'); ?> · Script: <?php echo h(__FILE__); ?></div>
    </div>
  </div>
</body>
</html>
