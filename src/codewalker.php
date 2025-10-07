<?php
// CodeWalker Admin — lightweight viewer/apply tool for codewalker.db
// Requirements: PHP 7.4+ with SQLite (pdo_sqlite)
// Default DB path assumes server has /web mounted; override via CODEWALKER_DB env.

declare(strict_types=1);

session_start();

$path = __DIR__ . '/private/codewalker.json';

if (file_exists($path)) {
    $json = file_get_contents($path);
    $data = json_decode($json, true);

    if (json_last_error() === JSON_ERROR_NONE) {
        //echo "<pre>";
        //print_r($data); // or var_dump($data) for more detail
        //echo "</pre>";
    } else {
        echo "Error decoding JSON: " . json_last_error_msg();
    }
} else {
    echo "File not found: $path";
}

// ---- Config ----
//$DB_PATH = getenv('CODEWALKER_DB');
$DB_PATH = $data['db_path'] ?? null;
if (!$DB_PATH || !is_string($DB_PATH)) {
    printf("Warning: CODEWALKER_DB env not set, using default path.\n");
    exit;
}

// Limit file writes to this root for safety
$WRITE_ROOT = $data['write_root'] ?? null;
if (!$WRITE_ROOT || !is_string($WRITE_ROOT)) {
    printf("Warning: WRITE_ROOT not set, using default path.\n");
    exit;
}

// Simple access guard: set CODEWALKER_ADMIN_PASS in server env to require login
$ADMIN_PASS = getenv('CODEWALKER_ADMIN_PASS') ?: '';

// ---- Helpers ----
function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function getp(string $key, $default = null) {
    return $_GET[$key] ?? $default;
}
function postp(string $key, $default = null) {
    return $_POST[$key] ?? $default;
}
function now_iso(): string { return date('Y-m-d\TH:i:s'); }
function sha256_file_s(string $path): ?string {
    if (!is_file($path)) return null;
    $ctx = hash_init('sha256');
    $fh = @fopen($path, 'rb');
    if (!$fh) return null;
    while (!feof($fh)) {
        $buf = fread($fh, 8192);
        if ($buf === false) { fclose($fh); return null; }
        hash_update($ctx, $buf);
    }
    fclose($fh);
    return hash_final($ctx);
}
function require_csrf(): void {
    if (empty($_POST['csrf']) || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], (string)$_POST['csrf'])) {
        http_response_code(400);
        echo 'CSRF token invalid.'; exit;
    }
}
function ensure_csrf(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}
function login_required(string $ADMIN_PASS): void {
    if ($ADMIN_PASS === '') return; // disabled
    if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_pass'])) {
            if (hash_equals($ADMIN_PASS, (string)$_POST['admin_pass'])) {
                $_SESSION['logged_in'] = true; header('Location: '.$_SERVER['PHP_SELF']); exit;
            }
            $err = 'Invalid password';
        }
        $csrf = ensure_csrf();
        echo '<!doctype html><meta charset="utf-8"><title>CodeWalker Admin Login</title>';
        echo '<style>body{font-family:system-ui,Segoe UI,Arial;margin:2rem;background:#0b1020;color:#eef} .card{max-width:420px;margin:10vh auto;padding:1.5rem;background:#141a33;border:1px solid #263056;border-radius:10px} input{width:100%;padding:.6rem;border-radius:8px;border:1px solid #344;color:#eef;background:#0e1330} button{padding:.6rem 1rem;border-radius:8px;border:1px solid #37f;background:#1a2246;color:#fff;cursor:pointer} .err{color:#f77;margin:.5rem 0}</style>';
        echo '<div class="card"><h2>CodeWalker Admin</h2>';
        if (!empty($err)) echo '<div class="err">'.h($err).'</div>';
        echo '<form method="post"><input type="password" name="admin_pass" placeholder="Admin password" autofocus><input type="hidden" name="csrf" value="'.h($csrf).'"><div style="margin-top:1rem"><button type="submit">Sign in</button></div></form></div>';
        exit;
    }
}

// Polyfills / helpers for older PHP
if (!function_exists('cw_starts_with')) {
    function cw_starts_with(string $haystack, string $needle): bool {
        return substr($haystack, 0, strlen($needle)) === $needle;
    }
}

// ---- DB ----
try {
    $pdo = new PDO('sqlite:' . $DB_PATH, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA journal_mode=WAL');
} catch (Throwable $e) {
    http_response_code(500);
    echo '<h1>Cannot open SQLite DB</h1><p>Path: '.h($DB_PATH).'</p><pre>'.h($e->getMessage()).'</pre>';
    echo '<p>Run script <source>/src/private/scripts/codewalker.py</source> to create and populate the DB.</p>';
    exit;
}


// Ensure applied_rewrites table exists
$pdo->exec("CREATE TABLE IF NOT EXISTS applied_rewrites (
  action_id INTEGER PRIMARY KEY,
  applied_at TEXT,
  applied_by TEXT,
  backup_path TEXT,
  result TEXT,
  notes TEXT
)");

// Queue of files to prioritize in CodeWalker cron
$pdo->exec("CREATE TABLE IF NOT EXISTS queued_files (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    path TEXT UNIQUE,
    requested_at TEXT,
    requested_by TEXT,
    notes TEXT,
    status TEXT DEFAULT 'pending'
)");

// ---- Auth (optional) ----
login_required($ADMIN_PASS);

// ---- Handle POST actions ----
$flash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $op = postp('op');
    if ($op === 'apply_rewrite') {
        require_csrf();
        $action_id = (int)postp('action_id', 0);
        $notes = (string)postp('notes', '');
        $force = (int)postp('force', 0) === 1;
        $content = (string)postp('new_content', '');
        $user = isset($_SERVER['PHP_AUTH_USER']) ? $_SERVER['PHP_AUTH_USER'] : 'admin';
        // Load action + file + rewrite
        $stmt = $pdo->prepare("SELECT a.*, f.path AS file_path, r.rewrite, r.diff
          FROM actions a JOIN files f ON f.id=a.file_id LEFT JOIN rewrites r ON r.action_id=a.id
          WHERE a.id=?");
        $stmt->execute([$action_id]);
        $row = $stmt->fetch();
        if (!$row) { $flash = 'Action not found'; }
        else if ($row['action'] !== 'rewrite' || ($row['status'] ?? '') !== 'ok') { $flash = 'Only successful rewrite actions can be applied.'; }
        else {
            $path = (string)$row['file_path'];
            $orig_hash = (string)$row['file_hash'];
            $rewrite_text = ($content !== '' ? $content : (string)($row['rewrite'] ?? ''));
            if ($rewrite_text === '') { $flash = 'No rewrite content available.'; }
            else if (!cw_starts_with($path, rtrim($WRITE_ROOT, '/').'/')) { $flash = 'Blocked path outside allowed root.'; }
            else {
                $current_hash = sha256_file_s($path);
                if ($current_hash !== $orig_hash && !$force) {
                    $flash = 'File changed since action. Enable Force to apply anyway.';
                } else {
                    // Backup then write
                    $ts = date('Ymd_His');
                    $backup = $path . '.bak.' . $ts;
                    $ok = @copy($path, $backup);
                    if (!$ok) { $flash = 'Failed to create backup: '.h($backup); }
                    else {
                        $bytes = @file_put_contents($path, $rewrite_text, LOCK_EX);
                        if ($bytes === false) {
                            $flash = 'Failed to write new content to file.';
                        } else {
                            $stmt2 = $pdo->prepare('INSERT OR REPLACE INTO applied_rewrites(action_id,applied_at,applied_by,backup_path,result,notes) VALUES (?,?,?,?,?,?)');
                            $stmt2->execute([$action_id, now_iso(), $user, $backup, 'ok', $notes]);
                            $flash = 'Applied rewrite to '.h($path).' ('.(string)$bytes.' bytes). Backup: '.h($backup);
                        }
                    }
                }
            }
        }
    }
    elseif ($op === 'queue_add') {
        require_csrf();
        $path = (string)postp('path','');
        $notes = (string)postp('notes','');
        if ($path === '') { $flash = 'Missing path.'; }
        elseif (!cw_starts_with($path, rtrim($WRITE_ROOT,'/').'/')) { $flash = 'Path outside allowed root.'; }
        else {
            $stmt = $pdo->prepare('INSERT OR IGNORE INTO queued_files(path,requested_at,requested_by,notes,status) VALUES (?,?,?,?,\'pending\')');
            $stmt->execute([$path, now_iso(), 'admin', $notes]);
            $flash = 'Queued: '.h($path);
        }
    }
    elseif ($op === 'queue_delete') {
        require_csrf();
        $id = (int)postp('id',0);
        $pdo->prepare('DELETE FROM queued_files WHERE id=?')->execute([$id]);
        $flash = 'Deleted queue entry #'.$id;
    }
    elseif ($op === 'queue_mark') {
        require_csrf();
        $id = (int)postp('id',0);
        $status = (string)postp('status','done');
        $pdo->prepare("UPDATE queued_files SET status=?, requested_at=requested_at WHERE id=?")->execute([$status,$id]);
        $flash = 'Updated queue entry #'.$id.' to '.h($status);
    }
    elseif ($op === 'delete_action') {
        require_csrf();
        $id = (int)postp('id',0);
        // Remove child rows then action
        $pdo->prepare('DELETE FROM summaries WHERE action_id=?')->execute([$id]);
        $pdo->prepare('DELETE FROM rewrites WHERE action_id=?')->execute([$id]);
        $pdo->prepare('DELETE FROM applied_rewrites WHERE action_id=?')->execute([$id]);
        $pdo->prepare('DELETE FROM actions WHERE id=?')->execute([$id]);
        $flash = 'Deleted action #'.$id;
    }
}

// ---- Routing ----
$view = (string)getp('view', 'dashboard');
$csrf = ensure_csrf();

// Logout handler (if enabled)
if (isset($_GET['logout'])) {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'], $params['secure'], $params['httponly']
        );
    }
    session_destroy();
    header('Location: '. strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

// ---- Fetch quick stats ----
$stats = [
    'files' => (int)$pdo->query('SELECT COUNT(*) FROM files')->fetchColumn(),
    'actions' => (int)$pdo->query('SELECT COUNT(*) FROM actions')->fetchColumn(),
    'summaries' => (int)$pdo->query('SELECT COUNT(*) FROM summaries')->fetchColumn(),
    'rewrites' => (int)$pdo->query('SELECT COUNT(*) FROM rewrites')->fetchColumn(),
    'applied' => (int)$pdo->query('SELECT COUNT(*) FROM applied_rewrites')->fetchColumn(),
    'pending_rewrites' => (int)$pdo->query('SELECT COUNT(*) FROM rewrites r LEFT JOIN applied_rewrites a ON a.action_id=r.action_id WHERE a.action_id IS NULL')->fetchColumn(),
];

// ---- UI ----
echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
echo '<title>CodeWalker Admin</title>';
echo '<style>
:root{--bg:#0b1020;--card:#141a33;--ink:#eef;--mut:#9fb0d0;--pri:#37f;--good:#2ecc71;--warn:#f4b942;--bad:#ff6b6b}
*{box-sizing:border-box} body{margin:0;font-family:system-ui,Segoe UI,Arial;background:var(--bg);color:var(--ink)}
.nav{display:flex;gap:.75rem;align-items:center;padding:.75rem 1rem;background:#0e1530;border-bottom:1px solid #1e2a55;position:sticky;top:0}
.nav a{color:#cfe; text-decoration:none;padding:.4rem .6rem;border-radius:8px;border:1px solid transparent}
.nav a.active{border-color:#2c3f78;background:#111a44}
.container{max-width:1280px;margin:1rem auto;padding:0 1rem}
.cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1rem}
.card{background:var(--card);border:1px solid #263056;border-radius:12px;padding:1rem}
.kv{display:flex;justify-content:space-between;color:var(--mut)}
.table{width:100%;border-collapse:collapse}
.table th,.table td{padding:.5rem;border-bottom:1px solid #263056;vertical-align:top}
.badge{display:inline-block;padding:.1rem .4rem;border-radius:6px;border:1px solid #345;color:#ccf;background:#0f1740}
.btn{display:inline-block;padding:.4rem .6rem;border-radius:8px;border:1px solid #456;background:#101a44;color:#fff;text-decoration:none}
.btn:hover{filter:brightness(1.1)}
.btn.good{border-color:#2ecc71;background:#0e3a22}
.btn.warn{border-color:#f4b942;background:#3a2d0e}
.btn.bad{border-color:#ff6b6b;background:#3a1010}
input,select,textarea{width:100%;padding:.5rem;border-radius:8px;border:1px solid #344;color:#eef;background:#0e1330}
pre{white-space:pre-wrap;word-wrap:break-word;background:#0f1433;border:1px solid #263056;border-radius:8px;padding:.75rem}
.flash{margin:1rem 0;padding:.6rem;border-radius:8px;background:#112045;border:1px solid #2e67ff}
.diff del{background:#4a1a1a;text-decoration:none}
.diff ins{background:#1a4a1a;text-decoration:none}
</style>';
echo '</head><body>';

echo '<div class="nav">';
echo '<strong>CodeWalker Admin</strong>';
$tabs = [
    'dashboard' => 'Dashboard',
    'actions' => 'Actions',
    'rewrites' => 'Rewrites',
    'files' => 'Files',
    'queue' => 'Queue',
    'mc' => 'MC Browser',  //index.php
    'config' => 'Config', //codew_config.php
];   
   

foreach ($tabs as $k=>$label) {    
    $cls = $view===$k? 'active':'';
    if ($k === 'mc') {
        $url = 'index.php'; // Link to MC browser
    } elseif ($k === 'config') {
        $url = 'codew_config.php'; // Link to CodeWalker config
    }else{
        $url = '?view='.$k;
    }
    echo '<a class="'.$cls.'" href="'.$url.'">'.h($label).'</a>';
}
if ($ADMIN_PASS !== '') {
    echo '<span style="margin-left:auto"></span><form method="post" action="?view='.$view.'" style="margin:0"><input type="hidden" name="op" value="logout"><button class="btn" formaction="?view='.$view.'&logout=1" onclick="return true">Logout</button></form>';
}

echo '</div><div class="container">';

if (!empty($flash)) {
    echo '<div class="flash">'.h($flash).'</div>';
}

// ---- Views ----
if ($view === 'dashboard') {
    echo '<div class="cards">';
    foreach ([
        ['Files',$stats['files']],
        ['Actions',$stats['actions']],
        ['Summaries',$stats['summaries']],
        ['Rewrites',$stats['rewrites']],
        ['Applied',$stats['applied']],
        ['Pending applies',$stats['pending_rewrites']],
    ] as $c) {
        echo '<div class="card"><div class="kv"><div>'.h($c[0]).'</div><div style="font-size:1.8rem">'.h((string)$c[1]).'</div></div></div>';
    }
    echo '</div>';

    // Recent activity
    $recent = $pdo->query("SELECT a.id, a.action, a.status, a.model, a.backend, a.created_at, f.path FROM actions a JOIN files f ON f.id=a.file_id ORDER BY a.id DESC LIMIT 25")->fetchAll();
    echo '<div class="card" style="margin-top:1rem"><h3>Recent Actions</h3><table class="table"><tr><th>ID</th><th>File</th><th>Action</th><th>Status</th><th>Model</th><th>When</th><th></th></tr>';
    foreach ($recent as $r) {
        echo '<tr><td>'.(int)$r['id'].'</td><td style="max-width:540px">'.h($r['path']).'</td><td><span class="badge">'.h($r['action']).'</span></td><td>'.h($r['status']).'</td><td>'.h(($r['backend']?:'').'/'.($r['model']?:'')) . '</td><td>'.h($r['created_at']).'</td><td><a class="btn" href="?view=action&id='.(int)$r['id'].'">Open</a></td></tr>';
    }
    echo '</table></div>';

    // Recently applied rewrites
    $applied = $pdo->query("SELECT a.id AS action_id, f.path, ar.applied_at, ar.backup_path FROM applied_rewrites ar JOIN actions a ON a.id=ar.action_id JOIN files f ON f.id=a.file_id ORDER BY ar.applied_at DESC LIMIT 15")->fetchAll();
    echo '<div class="card" style="margin-top:1rem"><h3>Recently Applied</h3><table class="table"><tr><th>Action</th><th>File</th><th>Applied at</th><th>Backup</th><th></th></tr>';
    foreach ($applied as $ap) {
        $mc_link = 'index.php?dir='.urlencode(dirname($ap['path'])).'&view=true&tpage='.urlencode($ap['path']).'&filename='.urlencode(basename($ap['path']));
        echo '<tr><td>#'.(int)$ap['action_id'].'</td><td style="max-width:640px">'.h($ap['path']).' <a class="badge" href="'.$mc_link.'">MC</a></td><td>'.h($ap['applied_at']).'</td><td>'.h($ap['backup_path']).'</td><td><a class="btn" href="?view=action&id='.(int)$ap['action_id'].'">Open</a></td></tr>';
    }
    echo '</table></div>';
}
elseif ($view === 'actions') {
    $kind = (string)getp('kind','all');
    $status = (string)getp('status','all');
    $q = trim((string)getp('q',''));
    $limit = max(1, min(500, (int)getp('limit', 100)));

    $sql = 'SELECT a.*, f.path FROM actions a JOIN files f ON f.id=a.file_id WHERE 1=1';
    $params = [];
    if ($kind !== 'all') { $sql .= ' AND a.action=?'; $params[] = $kind; }
    if ($status !== 'all') { $sql .= ' AND a.status=?'; $params[] = $status; }
    if ($q !== '') { $sql .= ' AND f.path LIKE ?'; $params[] = '%'.$q.'%'; }
    $sql .= ' ORDER BY a.id DESC LIMIT '.(int)$limit;

    $stmt = $pdo->prepare($sql); $stmt->execute($params); $rows = $stmt->fetchAll();

    echo '<div class="card"><form method="get" class="grid" style="display:grid;grid-template-columns:repeat(6,1fr);gap:.5rem">';
    echo '<input type="hidden" name="view" value="actions">';
    echo '<select name="kind"><option value="all"'.($kind==='all'?' selected':'').'>all</option><option value="summarize"'.($kind==='summarize'?' selected':'').'>summarize</option><option value="rewrite"'.($kind==='rewrite'?' selected':'').'>rewrite</option></select>';
    echo '<select name="status"><option value="all"'.($status==='all'?' selected':'').'>all</option><option value="ok"'.($status==='ok'?' selected':'').'>ok</option><option value="error"'.($status==='error'?' selected':'').'>error</option></select>';
    echo '<input type="text" name="q" placeholder="path contains…" value="'.h($q).'">';
    echo '<input type="number" name="limit" min="1" max="500" value="'.(int)$limit.'">';
    echo '<button class="btn" type="submit">Filter</button>';
    echo '</form></div>';

    echo '<div class="card" style="margin-top:1rem"><table class="table"><tr><th>ID</th><th>File</th><th>Action</th><th>Status</th><th>Tokens</th><th>When</th><th colspan="2"></th></tr>';
    foreach ($rows as $r) {
        $tok = (($r['tokens_in']??'')!==''? (int)$r['tokens_in']:0) . '/' . (($r['tokens_out']??'')!==''? (int)$r['tokens_out']:0);
        $mc_link = 'index.php?dir='.urlencode(dirname($r['path'])).'&view=true&tpage='.urlencode($r['path']).'&filename='.urlencode(basename($r['path']));
        echo '<tr><td>'.(int)$r['id'].'</td><td style="max-width:540px">'.h($r['path']).' <a class="badge" href="'.$mc_link.'">MC</a></td><td>'.h($r['action']).'</td><td>'.h($r['status']).'</td><td>'.h($tok).'</td><td>'.h($r['created_at']).'</td><td><a class="btn" href="?view=action&id='.(int)$r['id'].'">Open</a></td>';
        echo '<td><form method="post" onsubmit="return confirm(\'Delete this action record?\')"><input type="hidden" name="csrf" value="'.h($csrf).'"><input type="hidden" name="op" value="delete_action"><input type="hidden" name="id" value="'.(int)$r['id'].'"><button class="btn bad" type="submit">Delete</button></form></td></tr>';
    }
    echo '</table></div>';
}
elseif ($view === 'files') {
    $q = trim((string)getp('q',''));
    $limit = max(1, min(500, (int)getp('limit', 100)));
    $sql = 'SELECT * FROM files WHERE 1=1'; $params=[];
    if ($q!==''){ $sql.=' AND path LIKE ?'; $params[]='%'.$q.'%'; }
    $sql.=' ORDER BY last_seen DESC LIMIT '.(int)$limit; $stmt=$pdo->prepare($sql); $stmt->execute($params); $rows=$stmt->fetchAll();
    echo '<div class="card"><form method="get" class="grid" style="display:grid;grid-template-columns:1fr 100px auto;gap:.5rem">';
    echo '<input type="hidden" name="view" value="files">';
    echo '<input type="text" name="q" placeholder="/web/path contains…" value="'.h($q).'">';
    echo '<input type="number" name="limit" min="1" max="500" value="'.(int)$limit.'">';
    echo '<button class="btn" type="submit">Filter</button></form></div>';
    echo '<div class="card" style="margin-top:1rem"><table class="table"><tr><th>ID</th><th>Path</th><th>Ext</th><th>First</th><th>Last</th></tr>';
    foreach ($rows as $r) {
        echo '<tr><td>'.(int)$r['id'].'</td><td style="max-width:700px">'.h($r['path']).'</td><td>'.h($r['ext']).'</td><td>'.h($r['first_seen']).'</td><td>'.h($r['last_seen']).'</td></tr>';
    }
    echo '</table></div>';
}
elseif ($view === 'rewrites') {
    $rows = $pdo->query("SELECT a.id, a.created_at, f.path, r.action_id, ar.action_id AS applied, a.file_hash
        FROM rewrites r
        JOIN actions a ON a.id=r.action_id
        JOIN files f ON f.id=a.file_id
        LEFT JOIN applied_rewrites ar ON ar.action_id=r.action_id
        WHERE a.status='ok'
        ORDER BY a.id DESC LIMIT 200")->fetchAll();
    echo '<div class="card"><h3>Rewrites</h3><table class="table"><tr><th>ID</th><th>File</th><th>When</th><th>Hash match?</th><th>Status</th><th></th></tr>';
    foreach ($rows as $r) {
        $path = (string)$r['path'];
        $cur = sha256_file_s($path);
        $match = ($cur && $cur === (string)$r['file_hash']) ? '<span class="badge" style="border-color:#2ecc71">ok</span>' : '<span class="badge" style="border-color:#f4b942">changed</span>';
        echo '<tr><td>'.(int)$r['id'].'</td><td style="max-width:640px">'.h($path).'</td><td>'.h($r['created_at']).'</td><td>'.$match.'</td><td>'.($r['applied']?'<span class="badge" style="border-color:#2ecc71">applied</span>':'<span class="badge">pending</span>').'</td>';
        echo '<td><a class="btn" href="?view=action&id='.(int)$r['id'].'">Open</a></td></tr>';
    }
    echo '</table></div>';
}
elseif ($view === 'action') {
    $id = (int)getp('id', 0);
    $stmt = $pdo->prepare("SELECT a.*, f.path FROM actions a JOIN files f ON f.id=a.file_id WHERE a.id=?");
    $stmt->execute([$id]); $a = $stmt->fetch();
    if (!$a) { echo '<div class="card">Not found</div>'; }
    else {
        echo '<div class="card"><h3>Action #'.(int)$a['id'].' — '.h($a['action']).' ('.h($a['status']).')</h3>';
    $mc_link = 'index.php?dir='.urlencode(dirname($a['path'])).'&view=true&tpage='.urlencode($a['path']).'&filename='.urlencode(basename($a['path']));
    echo '<div class="kv"><div>File</div><div style="max-width:900px">'.h($a['path']).' <a class="badge" href="'.$mc_link.'">Open in MC</a></div></div>';
        echo '<div class="kv"><div>Model</div><div>'.h(($a['backend']?:'').'/'.($a['model']?:'')).'</div></div>';
        echo '<div class="kv"><div>When</div><div>'.h($a['created_at']).'</div></div>';
        echo '<details style="margin-top:.5rem"><summary>Prompt</summary><pre>'.h($a['prompt']).'</pre></details>';
        if ($a['action']==='summarize') {
            $s = $pdo->prepare('SELECT summary FROM summaries WHERE action_id=?'); $s->execute([$id]); $row=$s->fetch();
            //echo '<h4>Summary</h4><pre>'.h($row['summary'] ?? '(none)').'</pre>';


//echo '<h4>Summary</h4><pre>' . print_r($inner, true) . '</pre>';
$outer = json_decode($row['summary'] ?? '', true);
$inner = json_decode($outer['raw'] ?? '', true);
echo '<h4>Summary</h4><pre>' . print_r($inner, true) . '</pre>';
echo '<pre>' . json_encode($outer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . '</pre>';

echo '<hr>';
var_dump($outer);
echo '<hr>';
var_dump($inner);
echo '<hr>';
var_dump($row);
            
        } else {
            $r = $pdo->prepare('SELECT rewrite,diff FROM rewrites WHERE action_id=?'); $r->execute([$id]); $rw=$r->fetch();
            if (!$rw) { echo '<p>No rewrite stored.</p>'; }
            else {
                $path = (string)$a['path'];
                $cur_hash = sha256_file_s($path);
                $hash_ok = ($cur_hash && $cur_hash === (string)$a['file_hash']);
                $applied = (int)$pdo->query('SELECT COUNT(1) FROM applied_rewrites WHERE action_id='.(int)$a['id'])->fetchColumn() > 0;
                echo '<div class="kv"><div>Hash match</div><div>'.($hash_ok?'<span class="badge" style="border-color:#2ecc71">ok</span>':'<span class="badge" style="border-color:#f4b942">changed</span>').'</div></div>';
                echo '<details open style="margin-top:1rem"><summary>Diff (stored)</summary><pre class="diff">'.h($rw['diff']).'</pre></details>';
                // live diff vs current (optional best-effort)
                echo '<details style="margin-top:.5rem"><summary>Rewrite content</summary><pre>'.h($rw['rewrite']).'</pre></details>';
                // Apply form
                echo '<h4>Apply rewrite</h4>';
                if ($applied) { echo '<p><span class="badge" style="border-color:#2ecc71">Already applied</span></p>'; }
                echo '<form method="post" onsubmit="return confirm(\'Apply rewrite to file?\')">';
                echo '<input type="hidden" name="op" value="apply_rewrite">';
                echo '<input type="hidden" name="action_id" value="'.(int)$a['id'].'">';
                echo '<input type="hidden" name="csrf" value="'.h($csrf).'">';
                echo '<label>Notes (optional)</label><textarea name="notes" rows="2" placeholder="Why applying / context"></textarea>';
                echo '<label>Edit rewrite before apply (optional)</label><textarea name="new_content" rows="16" spellcheck="false">'.h((string)$rw['rewrite']).'</textarea>';
                echo '<label><input type="checkbox" name="force" value="1"> Force apply even if hash changed</label><br>';
                echo '<div style="margin-top:.5rem"><button class="btn good" type="submit" '.($applied?'disabled':'').'>Apply now</button></div>';
                echo '</form>';
                echo '<form method="post" onsubmit="return confirm(\'Delete this action record?\')" style="margin-top:.5rem">';
                echo '<input type="hidden" name="csrf" value="'.h($csrf).'">';
                echo '<input type="hidden" name="op" value="delete_action">';
                echo '<input type="hidden" name="id" value="'.(int)$a['id'].'">';
                echo '<button class="btn bad" type="submit">Delete action</button>';
                echo '</form>';
            }
        }
        echo '</div>';
    }
}
elseif ($view === 'file') {
    $path = (string)getp('path','');
    if ($path==='') { echo '<div class="card">No file path provided.</div>'; }
    else {
        $stmt = $pdo->prepare('SELECT * FROM files WHERE path=?'); $stmt->execute([$path]); $f=$stmt->fetch();
        $mc_link = 'index.php?dir='.urlencode(dirname($path)).'&view=true&tpage='.urlencode($path).'&filename='.urlencode(basename($path));
        echo '<div class="card"><h3>File</h3>';
        echo '<div class="kv"><div>Path</div><div style="max-width:900px">'.h($path).' <a class="badge" href="'.$mc_link.'">Open in MC</a></div></div>';
        if ($f) {
            echo '<div class="kv"><div>Ext</div><div>'.h($f['ext']).'</div></div>';
            echo '<div class="kv"><div>First seen</div><div>'.h($f['first_seen']).'</div></div>';
            echo '<div class="kv"><div>Last seen</div><div>'.h($f['last_seen']).'</div></div>';
        } else {
            echo '<p>Not found in DB.</p>';
        }
        // Actions for this file
        $rows = $pdo->prepare('SELECT * FROM actions WHERE file_id=(SELECT id FROM files WHERE path=?) ORDER BY id DESC LIMIT 100');
        $rows->execute([$path]); $rows=$rows->fetchAll();
        echo '<h4 style="margin-top:1rem">Recent actions</h4><table class="table"><tr><th>ID</th><th>Action</th><th>Status</th><th>When</th><th></th></tr>';
        foreach ($rows as $r) {
            echo '<tr><td>'.(int)$r['id'].'</td><td>'.h($r['action']).'</td><td>'.h($r['status']).'</td><td>'.h($r['created_at']).'</td><td><a class="btn" href="?view=action&id='.(int)$r['id'].'">Open</a></td></tr>';
        }
        echo '</table>';
        echo '</div>';
    }
}
elseif ($view === 'queue') {
    $pre_path = (string)getp('path','');
    $pre_add = (string)getp('pre_add','') !== '';
    echo '<div class="card"><h3>Queue for CodeWalker</h3>';
    if ($pre_add && $pre_path!=='') {
        echo '<form method="post" style="margin-bottom:1rem">';
        echo '<input type="hidden" name="csrf" value="'.h($csrf).'">';
    echo '<input type="hidden" name="op" value="queue_add">';
    echo '<label>Path</label><input type="text" name="path" value="'.h($pre_path).'">';
    echo '<label>Prompt override (optional)</label><textarea name="notes" rows="3" placeholder="Give the AI specific instructions for this file" spellcheck="false"></textarea>';
    echo '<div style="margin-top:.25rem;color:var(--mut);font-size:.85rem">When present, this text is sent as the first prompt to the AI for this queued item.</div>';
    echo '<div style="margin-top:.5rem"><button class="btn good" type="submit">Add to queue</button></div>';
        echo '</form>';
    }
    $rows = $pdo->query('SELECT * FROM queued_files ORDER BY id DESC LIMIT 300')->fetchAll();
    echo '<table class="table"><tr><th>ID</th><th>Path</th><th>Status</th><th>Requested</th><th>Notes</th><th colspan="3"></th></tr>';
    foreach ($rows as $r) {
        $mc_link = 'index.php?dir='.urlencode(dirname($r['path'])).'&view=true&tpage='.urlencode($r['path']).'&filename='.urlencode(basename($r['path']));
    $note_cell = $r['notes'] !== '' ? '<pre style="margin:0;white-space:pre-wrap">'.h($r['notes']).'</pre>' : '';
    echo '<tr><td>'.(int)$r['id'].'</td><td style="max-width:700px">'.h($r['path']).' <a class="badge" href="'.$mc_link.'">MC</a></td><td>'.h($r['status']).'</td><td>'.h($r['requested_at']).'</td><td>'.$note_cell.'</td>';
        echo '<td><form method="post"><input type="hidden" name="csrf" value="'.h($csrf).'"><input type="hidden" name="op" value="queue_mark"><input type="hidden" name="id" value="'.(int)$r['id'].'"><input type="hidden" name="status" value="done"><button class="btn" type="submit">Mark done</button></form></td>';
        echo '<td><a class="btn" href="?view=file&path='.urlencode($r['path']).'">File</a></td>';
        echo '<td><form method="post" onsubmit="return confirm(\'Delete this queue entry?\')"><input type="hidden" name="csrf" value="'.h($csrf).'"><input type="hidden" name="op" value="queue_delete"><input type="hidden" name="id" value="'.(int)$r['id'].'"><button class="btn bad" type="submit">Delete</button></form></td></tr>';
    }
    echo '</table></div>';
}
else {
    echo '<div class="card">Unknown view.</div>';
}

echo '</div></body></html>';
