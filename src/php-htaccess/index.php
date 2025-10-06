<?php
/*
 * Simple .htaccess / .htpasswd manager (PHP 7.3+ compatible)
 * Apache or Nginx Basic Auth
 * Location: protected admin area (assumed already authenticated at higher layer).
 * Features:
 *   - Edit / generate minimal Basic Auth .htaccess snippet
 *   - Manage single-user .htpasswd (bcrypt via htpasswd if available; otherwise crypt APR1 fallback TBD)
 *   - CSRF protection
 *   - No external deps; uses utils.php helpers
 */
// Development mode
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

define('PROJECT_ROOT', dirname(__DIR__, 2)); // Goes up 2 levels from php-htaccess/

// Optional legacy APR1 (Apache MD5) support: if you truly need older style hashes you
// can drop in Md5Crypt.php (with a class exposing ->apache($plain)). For now we rely
// on system htpasswd (bcrypt) or PHP password_hash().
require_once PROJECT_ROOT.'/src/utils.php';


if (session_status() !== PHP_SESSION_ACTIVE) @session_start();


// ---------------- Config ----------------
$HTPASSWD_DIR   = PROJECT_ROOT . '/src/private/passwords';
$HTPASSWD_FILE  = $HTPASSWD_DIR . '/.htpasswd';
$TEST_DIR       = __DIR__ . '/test_htaccess';
@mkdir($TEST_DIR, 0775, true);
$HTACCESS_FILE  = $TEST_DIR . '/.htaccess'; // Allow editing target (could be another path via ?file=)
$DEFAULT_USER   = 'admin';
$DEFAULT_REALM  = 'Restricted Area';
// Hash strategy: 'auto' (prefer htpasswd bcrypt, then password_hash), 'bcrypt', 'apr1'
$DEFAULT_HASH_MODE = 'auto';

@mkdir($HTPASSWD_DIR, 0770, true);

// CSRF token
if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(16)); }
$csrf = $_SESSION['csrf'];

function htp_server_type() {
    $serverSoftware = $_SERVER['SERVER_SOFTWARE'] ?? '';
    if (stripos($serverSoftware, 'nginx') !== false) return 'nginx';
    if (stripos($serverSoftware, 'apache') !== false) return 'apache';
    return 'unknown';
}

function htp_atomic_write($path, $content, $perms = 0640) {
    $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
    if (file_put_contents($tmp, $content, LOCK_EX) === false) return false;
    @chmod($tmp, $perms);
    return @rename($tmp, $path);
}

function htp_htpasswd_bin() {
    $p = trim((string)@shell_exec('command -v htpasswd 2>/dev/null'));
    return $p !== '' ? $p : null;
}

function htp_apr1($plain) {
    // Minimal APR1 implementation (hashed format $apr1$) for legacy Apache if needed.
    // NOTE: Bcrypt is recommended. This APR1 routine is a simplified adaptation.
    $salt = substr(str_shuffle('abcdefghijklmnopqrstuvwxyz0123456789'), 0, 8);
    $len = strlen($plain);
    $text = $plain . '$apr1$' . $salt;
    $bin = pack('H32', md5($plain . $salt . $plain));
    for ($i = $len; $i > 0; $i -= 16) {
        $text .= substr($bin, 0, min(16, $i));
    }
    for ($i = $len; $i > 0; $i >>= 1) {
        $text .= ($i & 1) ? chr(0) : $plain[0];
    }
    $bin = pack('H32', md5($text));
    for ($i = 0; $i < 1000; $i++) {
        $new = ($i & 1) ? $plain : $bin;
        if ($i % 3) $new .= $salt;
        if ($i % 7) $new .= $plain;
        $new .= ($i & 1) ? $bin : $plain;
        $bin = pack('H32', md5($new));
    }
    $tmp = '';
    for ($i = 0; $i < 5; $i++) {
        $k = $i+6; $j = $i+12; if ($j == 16) $j = 5;
        $tmp = $bin[$i].$bin[$k].$bin[$j].$tmp;
    }
    $tmp = chr(0).chr(0).$bin[11].$tmp;
    $b64 = strtr(rtrim(base64_encode($tmp), '='), 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/', './0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz');
    $b64 = substr($b64, 2, 22);
    return '$apr1$'.$salt.'$'.$b64;
}

function htp_build_line($user, $plain, $mode = 'auto') {
    if ($user === '' || $plain === '') return '';
    $mode = strtolower($mode);
    // auto -> try htpasswd bcrypt first
    if ($mode === 'auto' || $mode === 'bcrypt') {
        if ($bin = htp_htpasswd_bin()) {
            // -B enables bcrypt
            $cmd = escapeshellcmd($bin) . ' -nbB ' . escapeshellarg($user) . ' ' . escapeshellarg($plain) . ' 2>/dev/null';
            $out = @shell_exec($cmd);
            if ($out && strpos($out, ':') !== false) return trim($out);
        }
        if ($mode === 'bcrypt' || $mode === 'auto') {
            if (function_exists('password_hash')) {
                $hash = password_hash($plain, PASSWORD_BCRYPT);
                return $user . ':' . $hash;
            }
        }
        if ($mode === 'bcrypt') {
            // bcrypt forced but unavailable fall through to crypt
            return $user . ':' . crypt($plain, '$2y$10$'.substr(bin2hex(random_bytes(16)),0,22));
        }
    }
    if ($mode === 'apr1') {
        return $user . ':' . htp_apr1($plain);
    }
    // final fallback
    return $user . ':' . crypt($plain, '$2y$10$'.substr(bin2hex(random_bytes(16)),0,22));
}

function htp_load_htaccess($path) {
    if (is_file($path)) return file_get_contents($path);
    return '';
}

function htp_default_htaccess($realm, $htpasswdFile) {
    return "AuthType Basic\nAuthName \"" . addslashes($realm) . "\"\nAuthUserFile " . $htpasswdFile . "\nRequire valid-user\n";
}
function htp_default_auth_snippet($realm, $htpasswdFile) {
    $type = htp_server_type();
    if ($type === 'nginx') {
        return <<<NGINX
location /secure-area {
    auth_basic "$realm";
    auth_basic_user_file $htpasswdFile;
}
NGINX;
    } elseif ($type === 'apache') {
        return <<<APACHE
AuthType Basic
AuthName "$realm"
AuthUserFile $htpasswdFile
Require valid-user
APACHE;
    } else {
        return "# Unknown server type. Please configure manually.";
    }
}


// ---------------- Handle POST ----------------
$message = '';$error='';
// Derive active username from first line of .htpasswd if present
$activeUser = null;
if (is_file($HTPASSWD_FILE)) {
    $first = fgets(@fopen($HTPASSWD_FILE, 'r'));
    if ($first && strpos($first, ':') !== false) {
        $activeUser = substr($first, 0, strpos($first, ':'));
    }
}
if ($activeUser === null) $activeUser = $DEFAULT_USER;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['csrf']) || !hash_equals($csrf, (string)$_POST['csrf'])) {
        $error = 'Invalid CSRF token';
    } else {
    $action   = isset($_POST['action']) ? $_POST['action'] : '';
    $realmRaw = trim((string)($_POST['realm'] ?? $DEFAULT_REALM));
    // Basic sanitization: strip control chars
    $realm    = preg_replace('~[\r\n\t]+~', ' ', $realmRaw);
    $hashMode = isset($_POST['hash_mode']) ? strtolower($_POST['hash_mode']) : $DEFAULT_HASH_MODE;
        $username = trim((string)($_POST['username'] ?? $DEFAULT_USER));
        $password = (string)($_POST['password'] ?? '');
        $customHt = (string)($_POST['htaccess_content'] ?? '');

        if ($action === 'update_pass') {
            if ($username === '' || $password === '') {
                $error = 'Username and password required';
            } else {
                $line = htp_build_line($username, $password, $hashMode);
                if ($line && htp_atomic_write($HTPASSWD_FILE, $line . "\n")) {
                    $message = '.htpasswd updated';
                    $activeUser = $username;
                } else {
                    $error = 'Failed to write .htpasswd';
                }
            }
        } elseif ($action === 'update_htaccess') {
            $content = $customHt !== '' ? $customHt : htp_default_auth_snippet($realm, $HTPASSWD_FILE);
            if (htp_atomic_write($HTACCESS_FILE, $content)) {
                $message = '.htaccess updated';
            } else {
                $error = 'Failed to write .htaccess';
            }
        }
    }
}

$currentHtaccess = htp_load_htaccess($HTACCESS_FILE);
if ($currentHtaccess === '') {
    $currentHtaccess = htp_default_htaccess($DEFAULT_REALM, $HTPASSWD_FILE);
}
$currentUser = $activeUser; // Single-user focus.

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
<h1><a href="../">← MC Browser</a> /.htaccess / .htpasswd Manager / <a href="test_htaccess/">Test Area</a></h1>
<?php if($message): ?><div class="msg ok"><?php echo h($message); ?></div><?php endif; ?>
<?php if($error): ?><div class="msg err"><?php echo h($error); ?></div><?php endif; ?>

<p style="font-size:.8rem;color:#ccc;margin-top:.8rem">
    Server: <code><?php echo h(htp_server_type()); ?></code><br>
    .htaccess: <code><?php echo h($HTACCESS_FILE); ?></code> (<?php echo is_writable($HTACCESS_FILE) || (!file_exists($HTACCESS_FILE) && is_writable(dirname($HTACCESS_FILE))) ? 'writable' : 'read-only'; ?>)<br>
    .htpasswd: <code><?php echo h($HTPASSWD_FILE); ?></code> (<?php echo is_writable($HTPASSWD_FILE) || (!file_exists($HTPASSWD_FILE) && is_writable(dirname($HTPASSWD_FILE))) ? 'writable' : 'read-only'; ?>)
</p>


<div class="grid">
  <form method="post" autocomplete="off">
    <h2 style="margin-top:0;font-size:1.1rem">Update Password</h2>
    <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
    <input type="hidden" name="action" value="update_pass">
        <label>Username (active: <code><?php echo h($activeUser); ?></code>)
      <input type="text" name="username" value="<?php echo h($currentUser); ?>" required>
    </label>
    <label style="margin-top:.6rem">New Password
      <input type="password" name="password" value="" required>
    </label>
        <fieldset style="margin-top:.6rem;border:1px solid #333;padding:.6rem;border-radius:6px">
            <legend style="font-size:.8rem;padding:0 .4rem">Hash Mode</legend>
            <?php $modes = ['auto'=>'Auto (prefer bcrypt)','bcrypt'=>'Bcrypt only','apr1'=>'Legacy APR1 (MD5)'];
            $sel = h($_POST['hash_mode'] ?? $DEFAULT_HASH_MODE); foreach($modes as $k=>$label): ?>
                <label style="display:block;font-weight:normal"><input type="radio" name="hash_mode" value="<?php echo h($k); ?>" <?php if($sel===$k) echo 'checked'; ?>> <?php echo h($label); ?></label>
            <?php endforeach; ?>
        </fieldset>
    <div style="margin-top:.8rem"><button type="submit">Write .htpasswd</button></div>
    <p style="font-size:.8rem;color:#ccc;margin-top:.8rem">File: <code><?php echo h($HTPASSWD_FILE); ?></code></p>
  </form>

  <form method="post" autocomplete="off">
    <h2 style="margin-top:0;font-size:1.1rem">Edit .htaccess</h2>
    <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
    <input type="hidden" name="action" value="update_htaccess">
    <label>Realm (AuthName)
      <input type="text" name="realm" value="<?php echo h($DEFAULT_REALM); ?>">
    </label>
    <label style="margin-top:.6rem">Content
      <textarea name="htaccess_content"><?php echo h($currentHtaccess); ?></textarea>
    </label>
    <div style="margin-top:.8rem"><button type="submit">Write .htaccess</button></div>
    <p style="font-size:.8rem;color:#ccc;margin-top:.8rem">File: <code><?php echo h($HTACCESS_FILE); ?></code></p>
  </form>
</div>

<section style="margin-top:2rem;font-size:.85rem;line-height:1.5">
  <h2 style="font-size:1rem;margin:0 0 .5rem">Notes</h2>
  <ul style="padding-left:1.2rem;list-style:disc">
    <li>Bcrypt preferred via <code>htpasswd -nbB</code>. If not available, uses PHP's password_hash().</li>
    <li>Single-user focus; rewrites entire <code>.htpasswd</code> each update.</li>
    <li>Disable by removing or renaming <code>.htaccess</code>.</li>
        <li>Ensure Apache/Nginx is configured to honor this directory's <code>.htaccess</code>.</li>
        <li>Nginx usage: place the generated <code>location</code> block inside a relevant <code>server {}</code>. Adjust the <code>/secure-area</code> path to the directory you want protected, and ensure <code>auth_basic_user_file</code> points to this .htpasswd path (Nginx must have read permission).</li>
        <li>Hash modes: <code>Auto</code> tries system <code>htpasswd</code> (bcrypt) → PHP bcrypt → fallback crypt. <code>APR1</code> only for legacy Apache needing MD5; avoid unless required.</li>
  </ul>
</section>
<?php /* EOF */ ?>
