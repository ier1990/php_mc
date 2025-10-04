<?php
require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/Parsedown.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

$csrf = ensure_csrf();
$errors = [];
$status = null;
$unsaved = $_SESSION['notes_unsaved'] ?? ['title' => '', 'body' => '', 'tags' => ''];
$dbPath = __DIR__ . '/private/db/notes.db';
$pdo = null;

try {
    $dir = dirname($dbPath);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('CREATE TABLE IF NOT EXISTS notes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT NOT NULL,
        body TEXT NOT NULL,
        tags TEXT DEFAULT "",
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )');
} catch (Throwable $e) {
    $errors[] = 'Unable to open notes database: ' . h($e->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        require_csrf();
    } catch (Throwable $e) {
        $errors[] = 'Security token error. Please reload and try again.';
    }

    $title = trim((string)($_POST['title'] ?? ''));
    $body = (string)($_POST['body'] ?? '');
    $tags = trim((string)($_POST['tags'] ?? ''));

    $unsaved = ['title' => $title, 'body' => $body, 'tags' => $tags];

    if ($title === '') {
        $errors[] = 'Title is required.';
    }
    if (trim($body) === '') {
        $errors[] = 'Note body cannot be empty.';
    }

    if (empty($errors) && $pdo instanceof PDO) {
        try {
            $stmt = $pdo->prepare('INSERT INTO notes (title, body, tags) VALUES (:title, :body, :tags)');
            $stmt->execute([
                ':title' => $title,
                ':body' => $body,
                ':tags' => $tags,
            ]);
            unset($_SESSION['notes_unsaved']);
            header('Location: ' . $_SERVER['PHP_SELF'] . '?saved=1');
            exit;
        } catch (Throwable $e) {
            $errors[] = 'Failed to save note: ' . h($e->getMessage());
            $_SESSION['notes_unsaved'] = $unsaved;
        }
    } else {
        $_SESSION['notes_unsaved'] = $unsaved;
    }
}

if (isset($_GET['saved'])) {
    $status = 'Note saved successfully.';
}

if (is_array($unsaved)) {
    $keepUnsaved = false;
    foreach (['title', 'body', 'tags'] as $field) {
        if (isset($unsaved[$field]) && trim((string)$unsaved[$field]) !== '') {
            $keepUnsaved = true;
            break;
        }
    }
    if ($keepUnsaved) {
        $_SESSION['notes_unsaved'] = $unsaved;
    } else {
        unset($_SESSION['notes_unsaved']);
    }
}

$search = trim((string)($_GET['q'] ?? ''));
$notes = [];

if ($pdo instanceof PDO) {
    try {
        if ($search !== '') {
            $stmt = $pdo->prepare('SELECT * FROM notes WHERE title LIKE :query OR body LIKE :query OR tags LIKE :query ORDER BY created_at DESC, id DESC');
            $stmt->execute([':query' => '%' . $search . '%']);
        } else {
            $stmt = $pdo->query('SELECT * FROM notes ORDER BY created_at DESC, id DESC');
        }
        $notes = $stmt ? $stmt->fetchAll() : [];
    } catch (Throwable $e) {
        $errors[] = 'Failed to load notes: ' . h($e->getMessage());
    }
}

$parser = new Parsedown();
$parser->setSafeMode(true);
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Notes</title>
    <style>
        :root { color-scheme: dark; }
        body { font-family: system-ui, -apple-system, "Segoe UI", sans-serif; background:#0b1020; color:#e8eefb; margin:0; padding:0; }
        .wrap { max-width: 980px; margin: 0 auto; padding: 24px 16px 48px; }
        h1 { margin: 0 0 16px; font-size: 28px; font-weight: 600; }
        form { margin-bottom: 24px; }
        .card { background:#141a33; border:1px solid #263056; border-radius:12px; padding:18px; margin-bottom:18px; }
        label { display:block; font-weight:600; margin-bottom:6px; }
        input[type="text"], textarea { width:100%; border-radius:10px; border:1px solid #334; background:#0e1330; color:#eef; padding:10px 12px; }
        textarea { min-height: 160px; resize: vertical; font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace; }
        .btn { background:#1a2246; color:#fff; border:1px solid #3af; border-radius:10px; padding:10px 16px; cursor:pointer; font-weight:600; }
        .btn:hover { background:#233067; }
        .search-bar { display:flex; gap:10px; margin-bottom:18px; flex-wrap:wrap; }
        .search-bar input[type="text"] { flex:1; min-width:200px; }
        .status { margin-bottom: 18px; padding: 10px 12px; border-radius: 8px; background:#12351a; border:1px solid #2e6b3a; }
        .errors { margin-bottom: 18px; padding: 10px 12px; border-radius: 8px; background:#3a1820; border:1px solid #7b2d3a; }
        .note-meta { font-size: 12px; color:#9fb3d6; margin-bottom: 8px; display:flex; gap:8px; flex-wrap:wrap; }
        .tags { display:flex; gap:6px; flex-wrap:wrap; }
        .tag { background:#1a2246; border:1px solid #3af; border-radius:6px; padding:2px 8px; font-size:12px; color:#d6e6ff; }
        .note-content { line-height:1.55; }
        .note-content pre { background:#0e1330; padding:12px; border-radius:10px; overflow:auto; }
        .note-content code { background:#1d274b; padding:2px 4px; border-radius:6px; }
        .notes-empty { text-align:center; padding:32px; border:1px dashed #2d3a5c; border-radius:12px; color:#7f8db3; }
        a { color:#74b7ff; }
    </style>
</head>
<body>
<div class="wrap">
  <div class="row" style="margin-top:8px;margin-bottom:8px">
    <div class="col-sm-12">
      <a class="btn btn-default" href="index.php" style="background:#222;color:#eee;border-color:#444">&larr; MC Explorer</a>
      <a class="btn btn-default" href="codewalker.php" style="background:#222;color:#eee;border-color:#444">&larr; CodeWalker</a>
      <a class="btn btn-default" href="codew_config.php" style="background:#222;color:#eee;border-color:#444">&larr; CodeWalker Configuration</a>
    </div>
  </div>    
    <h1>Personal Notes</h1>
    <div class="card">
        <form method="post">
            <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
            <label for="title">Title</label>
            <input type="text" id="title" name="title" value="<?php echo h($unsaved['title'] ?? ''); ?>" required>
            <label for="tags" style="margin-top:12px;">Tags (comma separated)</label>
            <input type="text" id="tags" name="tags" value="<?php echo h($unsaved['tags'] ?? ''); ?>" placeholder="e.g. ideas, php, backlog">
            <label for="body" style="margin-top:12px;">Note</label>
            <textarea id="body" name="body" required><?php echo h($unsaved['body'] ?? ''); ?></textarea>
            <div style="margin-top:14px; display:flex; gap:10px; flex-wrap:wrap;">
                <button type="submit" class="btn">Save Note</button>
                <a class="btn" href="<?php echo h($_SERVER['PHP_SELF']); ?>">Reset</a>
            </div>
        </form>
        <p style="margin:0; font-size:12px; color:#9fb3d6;">Markdown supported. Code blocks use triple backticks. Notes are stored in <code><?php echo h($dbPath); ?></code>.</p>
    </div>

    <?php if ($status): ?>
        <div class="status"><?php echo h($status); ?></div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div class="errors">
            <ul style="margin:0; padding-left:18px;">
                <?php foreach ($errors as $error): ?>
                    <li><?php echo $error; ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="card">
        <form method="get" class="search-bar">
            <input type="text" name="q" value="<?php echo h($search); ?>" placeholder="Search title, tags or content...">
            <button type="submit" class="btn">Search</button>
            <?php if ($search !== ''): ?>
                <a class="btn" href="<?php echo h($_SERVER['PHP_SELF']); ?>">Clear</a>
            <?php endif; ?>
        </form>

        <?php if (empty($notes)): ?>
            <div class="notes-empty">No notes yet. Create one above to get started.</div>
        <?php else: ?>
            <?php foreach ($notes as $note): ?>
                <div class="card" style="margin-bottom:14px;">
                    <h2 style="margin:0 0 8px; font-size:20px; font-weight:600; color:#f1f6ff;">
                        <?php echo h($note['title']); ?>
                    </h2>
                    <div class="note-meta">
                        <span>Created <?php echo h(date('Y-m-d H:i', strtotime((string)$note['created_at']))); ?></span>
                        <?php if (!empty($note['tags'])): ?>
                            <div class="tags">
                                <?php foreach (preg_split('/\s*,\s*/', $note['tags']) as $tag): ?>
                                    <?php if ($tag !== ''): ?>
                                        <span class="tag"><?php echo h($tag); ?></span>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="note-content">
                        <?php echo $parser->text($note['body']); ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
