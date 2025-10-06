<?php
/**
 * Simple .htaccess / .htpasswd manager (PHP 7.3+ compatible)
 *
 * Features:
 *   - Edit / generate minimal Basic Auth .htaccess snippet
 *   - Manage a single‑user .htpasswd file (bcrypt via htpasswd if available,
 *     otherwise PHP's password_hash())
 *   - CSRF protection
 *   - No external dependencies – uses helpers from utils.php
 *
 * Author: CodeWalker
 */

require_once __DIR__ . '/utils.php';
if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

/* -------------------------------------------------------------------------- */
/* Configuration & constants                                                 */
/* -------------------------------------------------------------------------- */
define('HTPASSWD_DIR',  __DIR__ . '/private/passwords');
define('HTPASSWD_FILE', HTPASSWD_DIR . '/.htpasswd');
define('HTACCESS_FILE', __DIR__ . '/test_htaccess/.htaccess'); // editable target
define('DEFAULT_USER',   'admin');
define('DEFAULT_REALM',  'Restricted Area');

@mkdir(HTPASSWD_DIR, 0770, true);

/* -------------------------------------------------------------------------- */
/* CSRF handling                                                             */
/* -------------------------------------------------------------------------- */
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$csrfToken = $_SESSION['csrf'];

/* -------------------------------------------------------------------------- */
/* Helper functions                                                          */
/* -------------------------------------------------------------------------- */

/**
 * Detect server type.
 *
 * @return string 'nginx', 'apache' or 'unknown'
 */
function getServerType(): string
{
    $software = $_SERVER['SERVER_SOFTWARE'] ?? '';
    if (stripos($software, 'nginx') !== false) {
        return 'nginx';
    }
    if (stripos($software, 'apache') !== false) {
        return 'apache';
    }
    return 'unknown';
}

/**
 * Atomically write content to a file.
 *
 * @param string $path
 * @param string $content
 * @param int    $perms  File permissions (default 0640)
 *
 * @return bool Success status
 */
function atomicWrite(string $path, string $content, int $perms = 0640): bool
{
    $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, $content, LOCK_EX) === false) {
        return false;
    }
    @chmod($tmp, $perms);
    return @rename($tmp, $path);
}

/**
 * Locate the htpasswd binary.
 *
 * @return string|null Path or null if not found
 */
function findHtpasswdBinary(): ?string
{
    $bin = trim((string)@shell_exec('command -v htpasswd 2>/dev/null'));
    return $bin !== '' ? $bin : null;
}

/**
 * Build a single line for .htpasswd.
 *
 * @param string $user   Username
 * @param string $plain  Plain‑text password
 *
 * @return string|false Line or false on failure
 */
function buildHtpasswdLine(string $user, string $plain)
{
    if ($user === '' || $plain === '') {
        return false;
    }

    // Prefer system htpasswd binary (bcrypt)
    if ($bin = findHtpasswdBinary()) {
        $cmd  = escapeshellcmd($bin) . ' -nbB ';
        $cmd .= escapeshellarg($user) . ' ' . escapeshellarg($plain);
        $out  = @shell_exec($cmd . ' 2>/dev/null');
        if ($out && strpos($out, ':') !== false) {
            return trim($out);
        }
    }

    // Fallback to PHP's password_hash (bcrypt)
    if (function_exists('password_hash')) {
        $hash = password_hash($plain, PASSWORD_BCRYPT);
        return $user . ':' . $hash;
    }

    // Last resort: crypt
    $salt = '$2y$10$' . substr(bin2hex(random_bytes(16)), 0, 22);
    return $user . ':' . crypt($plain, $salt);
}

/**
 * Load the current .htaccess content.
 *
 * @param string $path
 *
 * @return string File contents or empty string if not found
 */
function loadHtaccess(string $path): string
{
    return is_file($path) ? file_get_contents($path) : '';
}

/**
 * Default .htaccess snippet for Apache.
 *
 * @param string $realm        AuthName
 * @param string $passwdFile   Path to .htpasswd
 *
 * @return string Snippet
 */
function defaultApacheSnippet(string $realm, string $passwdFile): string
{
    return <<<APACHE
AuthType Basic
AuthName "$realm"
AuthUserFile $passwdFile
Require valid-user
APACHE;
}

/**
 * Default .htaccess snippet for Nginx.
 *
 * @param string $realm        AuthName
 * @param string $passwdFile   Path to .htpasswd
 *
 * @return string Snippet
 */
function defaultNginxSnippet(string $realm, string $passwdFile): string
{
    return <<<NGINX
location /secure-area {
    auth_basic "$realm";
    auth_basic_user_file $passwdFile;
}
NGINX;
}

/**
 * Build the appropriate snippet based on server type.
 *
 * @param string $realm      AuthName
 * @param string $passwdFile Path to .htpasswd
 *
 * @return string Snippet or comment if unknown server
 */
function buildAuthSnippet(string $realm, string $passwdFile): string
{
    switch (getServerType()) {
        case 'nginx':
            return defaultNginxSnippet($realm, $passwdFile);
        case 'apache':
            return defaultApacheSnippet($realm, $passwdFile);
        default:
            return "# Unknown server type. Please configure manually.";
    }
}

/* -------------------------------------------------------------------------- */
/* POST handling                                                             */
/* -------------------------------------------------------------------------- */
$message = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF validation
    if (empty($_POST['csrf']) || !hash_equals($csrfToken, $_POST['csrf'])) {
        $error = 'Invalid CSRF token';
    } else {
        $action   = $_POST['action'] ?? '';
        $realm    = trim((string)($_POST['realm'] ?? DEFAULT_REALM));
        $username = trim((string)($_POST['username'] ?? DEFAULT_USER));
        $password = (string)($_POST['password'] ?? '');
        $customHt = (string)($_POST['htaccess_content'] ?? '');

        switch ($action) {
            case 'update_pass':
                if ($username === '' || $password === '') {
                    $error = 'Username and password required';
                } else {
                    $line = buildHtpasswdLine($username, $password);
                    if ($line && atomicWrite(HTPASSWD_FILE, $line . PHP_EOL)) {
                        $message = '.htpasswd updated';
                    } else {
                        $error = 'Failed to write .htpasswd';
                    }
                }
                break;

            case 'update_htaccess':
                // Use custom content if provided; otherwise generate default snippet
                $content = $customHt !== '' ? $customHt : buildAuthSnippet($realm, HTPASSWD_FILE);
                if (atomicWrite(HTACCESS_FILE, $content)) {
                    $message = '.htaccess updated';
                } else {
                    $error = 'Failed to write .htaccess';
                }
                break;

            default:
                $error = 'Unknown action';
        }
    }
}

/* -------------------------------------------------------------------------- */
/* Prepare data for rendering                                               */
/* -------------------------------------------------------------------------- */
$currentHtaccess = loadHtaccess(HTACCESS_FILE);
if ($currentHtaccess === '') {
    // Fallback to a minimal Apache snippet
    $currentHtaccess = defaultApacheSnippet(DEFAULT_REALM, HTPASSWD_FILE);
}
$currentUser = DEFAULT_USER;

/* -------------------------------------------------------------------------- */
/* Output HTML                                                              */
/* -------------------------------------------------------------------------- */
header('Cache-Control: no-store');
?>
<!doctype html>
<meta charset="utf-8">
<title>.htaccess Manager</title>
<meta name="robots" content="noindex,nofollow">
<style>
body{font:14px/1.4 system-ui,Arial,sans-serif;background:#111;color:#eee;margin:1.2rem}
h1{font-size:1.4rem;margin:0 0 1rem}
form{background:#1e1e1e;padding:1rem;border:1px solid #333;border-radius:8px;margin-bottom:1.2rem}
input[type=text],input[type=password],textarea{width:100%;background:#000;color:#eee;border:1px solid #444;border-radius:6px;padding:.55rem;font:13px monospace}
textarea{min-height:160px;resize:vertical}
button{background:#2d5fd3;color:#fff;border:1px solid #1b4aa8;border-radius:6px;padding:.55rem 1rem;cursor:pointer}
button:hover{background:#3970f0}
.msg{padding:.6rem;border-radius:6px;margin-bottom:1rem}
.ok{background:#103a18;color:#9febb5;border:1px solid #1e6a31}
.err{background:#3a1010;color:#f5b3b3;border:1px solid #6a1e1e}
code{background:#222;padding:2px 4px;border-radius:4px}
.grid{display:grid;grid-template-columns:1fr 1fr;grid-gap:1.2rem}
@media(max-width:900px){.grid{grid-template-columns:1fr}}
</style>
<h1>.htaccess / .htpasswd Manager / <a href="/admin/php_mc/src/test_htaccess/">Test Area</a></h1>

<?php if ($message): ?>
<div class="msg ok"><?php echo h($message); ?></div>
<?php endif; ?>

<?php if ($error): ?>
<div class="msg err"><?php echo h($error); ?></div>
<?php endif; ?>

<p style="font-size:.8rem;color:#ccc;margin-top:.8rem">
  Server: <code><?php echo h(getServerType()); ?></code><br>
  File: <code><?php echo h(HTACCESS_FILE); ?></code>
</p>

<div class="grid">

    <!-- Password form -->
    <form method="post" autocomplete="off">
        <h2 style="margin-top:0;font-size:1.1rem">Update Password</h2>
        <input type="hidden" name="csrf" value="<?php echo h($csrfToken); ?>">
        <input type="hidden" name="action" value="update_pass">
        <label>Username
            <input type="text" name="username" value="<?php echo h($currentUser); ?>" required>
        </label>
        <label style="margin-top:.6rem">New Password
            <input type="password" name="password" value="" required>
        </label>
        <div style="margin-top:.8rem"><button type="submit">Write .htpasswd</button></div>
        <p style="font-size:.8rem;color:#ccc;margin-top:.8rem">
            File: <code><?php echo h(HTPASSWD_FILE); ?></code>
        </p>
    </form>

    <!-- .htaccess form -->
    <form method="post" autocomplete="off">
        <h2 style="margin-top:0;font-size:1.1rem">Edit .htaccess</h2>
        <input type="hidden" name="csrf" value="<?php echo h($csrfToken); ?>">
        <input type="hidden" name="action" value="update_htaccess">
        <label>Realm (AuthName)
            <input type="text" name="realm" value="<?php echo h(DEFAULT_REALM); ?>">
        </label>
        <label style="margin-top:.6rem">Content
            <textarea name="htaccess_content"><?php echo h($currentHtaccess); ?></textarea>
        </label>
        <div style="margin-top:.8rem"><button type="submit">Write .htaccess</button></div>
        <p style="font-size:.8rem;color:#ccc;margin-top:.8rem">
            File: <code><?php echo h(HTACCESS_FILE); ?></code>
        </p>
    </form>

</div>

<section style="margin-top:2rem;font-size:.85rem;line-height:1.5">
    <h2 style="font-size:1rem;margin:0 0 .5rem">Notes</h2>
    <ul style="padding-left:1.2rem;list-style:disc">
        <li>Bcrypt preferred via <code>htpasswd -nbB</code>. If not available, uses PHP's password_hash().</li>
        <li>Single‑user focus; rewrites entire <code>.htpasswd</code> each update.</li>
        <li>Disable by removing or renaming <code>.htaccess</code>.</li>
        <li>Ensure Apache/Nginx is configured to honor this directory's <code>.htaccess</code>.</li>
    </ul>
</section>

<?php /* EOF */ ?>