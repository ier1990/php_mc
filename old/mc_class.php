<?php
/**
 * Classic PHP File Commander – Refactored
 *
 * This script implements a simple web‑based file manager.
 * It handles authentication, directory navigation,
 * source code display and environment diagnostics.
 *
 * The original behaviour is preserved while the code has been
 * reorganised for readability and maintainability.
 */

declare(strict_types=1);

session_start();

/* ----------------------------------------------------------------------
 * Configuration & Logging
 * ---------------------------------------------------------------------- */
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

/**
 * Prefix all log messages with a consistent tag.
 *
 * @param string $msg Message to log.
 */
function log_msg(string $msg): void
{
    error_log('[FileCommander] ' . $msg);
}

/* ----------------------------------------------------------------------
 * Authentication
 * ---------------------------------------------------------------------- */

const COOKIE_VALID_SECONDS = 3600; // 1 hour
const LOGIN_COOKIE_NAME    = 'MOS_LOGIN_CLASS_COOKIE';

$credentials = [
    'username' => 'admin',
    'password' => 'password',
];

function encryptDecrypt(string $action, string $string): false|string
{
    $method   = 'AES-256-CBC';
    $secretKey= 'This is my secret key';
    $secretIv = 'This is my secret iv';

    // 32‑byte key and 16‑byte IV
    $key = hash('sha256', $secretKey, true);
    $iv  = substr(hash('sha256', $secretIv, true), 0, 16);

    if ($action === 'encrypt') {
        return base64_encode(openssl_encrypt($string, $method, $key, OPENSSL_RAW_DATA, $iv));
    }

    if ($action === 'decrypt') {
        $decoded = base64_decode($string, true);
        if ($decoded === false) {
            return false;
        }
        return openssl_decrypt($decoded, $method, $key, OPENSSL_RAW_DATA, $iv);
    }

    return false;
}

/**
 * Log the user in and set session + cookie.
 *
 * @param string $username
 * @param string $password
 * @return bool True on success, false otherwise.
 */
function loginUser(string $username, string $password): bool
{
    global $credentials;

    if ($username !== $credentials['username'] || $password !== $credentials['password']) {
        return false;
    }

    $_SESSION['UserData']['Username'] = $username;

    // Create a cookie that expires after COOKIE_VALID_SECONDS seconds.
    $token = encryptDecrypt('encrypt', $username . '|' . $password);
    setcookie(LOGIN_COOKIE_NAME, $token, time() + COOKIE_VALID_SECONDS, '/', '', false, true);

    header('Location: mc_class.php');
    exit;
}

/**
 * Attempt to authenticate using the login cookie.
 *
 * @return bool True if authentication succeeded, false otherwise.
 */
function tryLoginFromCookie(): bool
{
    global $credentials;

    if (!isset($_COOKIE[LOGIN_COOKIE_NAME])) {
        return false;
    }

    $cookie = htmlspecialchars($_COOKIE[LOGIN_COOKIE_NAME], ENT_QUOTES, 'UTF-8');
    $decrypted = encryptDecrypt('decrypt', $cookie);
    if ($decrypted === false) {
        return false;
    }

    [$user, $pass] = explode('|', $decrypted, 2) + [null, null];
    if ($user !== $credentials['username'] || $pass !== $credentials['password']) {
        return false;
    }

    // Credentials match – establish session & cookie again.
    loginUser($user, $pass);
    return true; // unreachable due to redirect in loginUser()
}

/* ----------------------------------------------------------------------
 * Request handling
 * ---------------------------------------------------------------------- */

if (!isset($_SESSION['UserData']['Username'])) {
    if (tryLoginFromCookie()) {
        // Redirected by loginUser()
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';

        if (!loginUser($username, $password)) {
            // Failed login – fall through to show form again
        }
    }

    /* ------------------------------------------------------------------
     * Login form (unchanged from original)
     * ------------------------------------------------------------------ */
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Classic PHP File Commander</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css"
      integrity="sha384-rbsA2VBKQhggwzxH7pPCaAqO46MgnOM80zW1RWuH61DGLwZJEdK2Kadq2F9CUG65"
      crossorigin="anonymous">
<link rel="stylesheet" href="dark.css">
</head>
<body class="text-center">

<main class="form-signin w-100 m-auto">
    <form action="mc_class.php" method="post">
        <img class="mb-4" src="/admin/php_mc/logoskull.png" alt="" width="72" height="57">
        <h1 class="h3 mb-3 fw-normal">Please sign in</h1>

        <div class="form-floating input-field" data-theme="dark">
            <input type="text" name="username" class="form-control" id="floatingInput"
                   placeholder="admin">
            <label for="floatingInput">Username</label>
        </div>
        <div class="form-floating input-field" data-theme="dark">
            <input type="password" name="password" class="form-control" id="floatingPassword"
                   placeholder="Password">
            <label for="floatingPassword">Password</label>
        </div>

        <div class="checkbox mb-3">
            <label><input type="checkbox" value="remember-me"> Remember me</label>
        </div>
        <button class="w-100 btn btn-lg btn-primary" name="submit"
                value="submit" type="submit">Sign in</button>
        <p class="mt-5 mb-3 text-muted">&copy; 2016–2023</p>
    </form>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.min.js"
        integrity="sha384-cuYeSxntonz0PPNlHhBs68uyIAVpIIOZZ5JqeqvYYIcEL727kskC66kF92t6Xl2V"
        crossorigin="anonymous"></script>
</body>
</html>
<?php
    exit;
}

//if set logout=1, clear session and cookie
if (isset($_GET['logout']) && $_GET['logout'] == '1') {
    // Clear session
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'],
            $params['secure'], $params['httponly']
        );
    }
    session_destroy();

    // Clear login cookie
    setcookie(LOGIN_COOKIE_NAME, '', time() - 3600, '/', '', false, true);

    header('Location: mc_class.php');
    exit;
}

log_msg('Authenticated user: ' . $_SESSION['UserData']['Username']);

/* ----------------------------------------------------------------------
 * Core functionality – MC class
 * ---------------------------------------------------------------------- */
class MC
{
    /* Properties ----------------------------------------------------- */
    public array  $excludeList = ['.', '..'];
    public string $httpHost   = '';
    public string $docRoot    = '';
    public string $currentUrl = '';
    public string $dir        = '';
    public bool   $view       = false;
    public mixed  $tpage      = null; // directory or file path
    public string $dirPath    = '';
    public string $protocol   = 'http://';
    public string $self       = '';
    public string $iconFolder = '📁';
    public string $iconFile   = '📝';
    public string $iconRun    = '▶️';
    public string $iconUp     = '💾';

    /* Constructor ---------------------------------------------------- */
    public function __construct()
    {
        $this->initEnvironment();
        $this->setDirFromRequest();
        $this->setDirPath();
        $this->handleTPage();
    }

    /* Environment helpers ------------------------------------------- */
    private function initEnvironment(): void
    {
        $this->httpHost = $_SERVER['HTTP_HOST'] ?? '';
        $this->docRoot  = $_SERVER['DOCUMENT_ROOT'] ?? '';
        $this->protocol = $this->isHttps() ? 'https://' : 'http://';
        $this->self     = str_replace($this->docRoot, '', $_SERVER['PHP_SELF']);
    }

    private function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && ($_SERVER['HTTPS'] === 'on' || $_SERVER['HTTPS'] == 1))
            || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    }

    /* Directory helpers --------------------------------------------- */
    private function setDirFromRequest(): void
    {
        $this->dir = $_GET['dir'] ?? getcwd();
        if (!is_dir($this->dir)) {
            $this->dir = getcwd();
        }
    }

    private function setDirPath(): void
    {
        $path = rtrim(str_replace('\\', '/', $this->dir), '/') . '/';
        $this->dirPath = str_replace('//', '/', $path);
    }

    /* tpage handling ----------------------------------------------- */
    private function handleTPage(): void
    {
        $this->tpage = $_GET['tpage'] ?? null;
        if ($this->tpage && is_dir($this->tpage)) {
            // Treat as navigation request
            $this->dirPath = rtrim(str_replace('\\', '/', $this->tpage), '/') . '/';
            $this->view    = false;
        } else {
            $this->view = true; // file view or error fallback
        }
    }

    /* URL helpers --------------------------------------------------- */
    public function getCurrentUrl(): string
    {
        return $this->protocol . $this->httpHost . $this->self;
    }

    /* File metadata ----------------------------------------------- */
    public function getFilePerm(string $file): string
    {
        return substr(sprintf('%o', fileperms($file)), -4);
    }

    public function getFileSize(string $file): int
    {
        return filesize($file);
    }

    /* Permission formatter ---------------------------------------- */
    public function formatPerm(array $stat): string
    {
        $mode = $stat['mode'];
        $perm = ($mode & 0x1000) ? 'd' : '-';
        $perm .= ($mode & 0x0100) ? 'r' : '-';
        $perm .= ($mode & 0x0080) ? 'w' : '-';
        $perm .= ($mode & 0x0040)
            ? (($mode & 0x0800) ? 's' : 'x')
            : (($mode & 0x0800) ? 'S' : '-');
        $perm .= ($mode & 0x0020) ? 'r' : '-';
        $perm .= ($mode & 0x0010) ? 'w' : '-';
        $perm .= ($mode & 0x0008)
            ? (($mode & 0x0400) ? 's' : 'x')
            : (($mode & 0x0400) ? 'S' : '-');
        $perm .= ($mode & 0x0004) ? 'r' : '-';
        $perm .= ($mode & 0x0002) ? 'w' : '-';
        $perm .= ($mode & 0x0001)
            ? (($mode & 0x0200) ? 't' : 'x')
            : (($mode & 0x0200) ? 'T' : '-');
        return $perm;
    }

    /* Table rendering ---------------------------------------------- */
    public function renderTable(array $rows): void
    {
        echo '<table class="table table-hover table-striped" width="600" border="1" cellspacing="10" cellpadding="10">';
        // Header
        echo '<thead><tr>';
        foreach ($rows[0] ?? [] as $key => $_) {
            if ($key !== 'path') {
                echo "<th>{$key}</th>";
            }
        }
        echo '</tr></thead>';

        // Rows
        foreach ($rows as $row) {
            echo '<tr>';
            foreach ($row as $key => $value) {
                if ($key === 'path') {
                    continue;
                }
                if ($key === 'name' && isset($row['type']) && $row['type'] !== 'dir') {
                    // Link to file view
                    $url = $this->getCurrentUrl() . '?dir=' . rawurlencode($this->dirPath) .
                           '&tpage=' . rawurlencode($value);
                    echo "<td><a href=\"{$url}\">{$value}</a></td>";
                } elseif ($key === 'size') {
                    // Convert bytes to KB
                    $kb = round($value / 1024, 2);
                    echo "<td>{$kb} KB</td>";
                } else {
                    echo "<td>{$value}</td>";
                }
            }
            echo '</tr>';
        }

        echo '</table>';
    }
}

/* ----------------------------------------------------------------------
 * Helper functions
 * ---------------------------------------------------------------------- */

/**
 * Build the directory listing array.
 *
 * @param MC $mc Instance of the core class.
 * @return array Listing data for rendering.
 */
function buildListing(MC $mc): array
{
    $listing = [];
    $index   = 0;

    // "UP" navigation entry
    $parentPath = dirname($mc->dirPath) . '/';
    $listing[$index++] = [
        'path' => $parentPath,
        'name' => $mc->iconUp . ' UP',
        'type' => 'dir',
        'size' => 4096,
        'perm' => '..',
    ];

    // Directories
    foreach (glob($mc->dirPath . '*', GLOB_MARK | GLOB_ONLYDIR) as $entry) {
        $listing[$index++] = [
            'path'   => $entry,
            'name'   => $mc->iconFolder . basename($entry),
            'type'   => 'dir',
            'size'   => $mc->getFileSize($entry),
            'perm'   => $mc->getFilePerm($entry),
        ];
    }

    // Files
    foreach (array_filter(glob($mc->dirPath . '*'), 'is_file') as $file) {
        // Build a URL that points to the file for preview
        $relative = str_replace(
            rtrim(str_replace('\\', '/', $mc->docRoot), '/'),
            '',
            str_replace('\\', '/', $file)
        );
        $url = $mc->protocol . $mc->httpHost . $relative;

        $listing[$index++] = [
            'path' => $file,
            'name' => $mc->iconFile . basename($file),
            'type' => "<a href=\"{$url}\" target=\"_blank\">{$mc->iconRun} Play?file</a>",
            'size' => $mc->getFileSize($file),
            'perm' => $mc->getFilePerm($file),
        ];
    }

    return $listing;
}

/**
 * Display the source code of a file with line numbers.
 *
 * @param string $filepath Path to the file.
 */
function displaySource(string $filepath): void
{
    echo '<div class="medium" style="overflow:scroll; overflow-x:hidden; height:50%;">';
    echo 'Displaying file: ' . htmlspecialchars($filepath) . "<br>";

    ini_set('highlight.comment', '#800080; font-weight: bold;');
    $highlighted = highlight_file($filepath, true);
    $lines       = explode('<br />', $highlighted);

    echo '<table class="table table-striped table-hover">';
    foreach ($lines as $idx => $line) {
        printf(
            '<tr><td width="34">%d. </td><td class="syntax-highlight-line">%s</td></tr>',
            $idx + 1,
            $line
        );
    }
    echo '</table>';
    echo '</div>';
}

/**
 * Dump various server and script environment variables.
 */
function displayEnvironment(): void
{
    echo '<div class="medium" style="overflow:scroll; overflow-x:hidden; height:50%;">';
    echo 'Displaying Paths:<br>';

    $vars = [
        'getcwd()'                => getcwd(),
        'dirname(__FILE__)'       => dirname(__FILE__),
        '_SERVER["DOCUMENT_ROOT"]'=> $_SERVER['DOCUMENT_ROOT'] ?? '',
        '_SERVER["SERVER_ADDR"]'  => $_SERVER['SERVER_ADDR'] ?? '',
        '_SERVER["SERVER_PORT"]'  => $_SERVER['SERVER_PORT'] ?? '',
        '_SERVER["REQUEST_SCHEME"]'=> $_SERVER['REQUEST_SCHEME'] ?? '',
        '_SERVER["HTTP_HOST"]'    => $_SERVER['HTTP_HOST'],
        '_SERVER["REQUEST_URI"]'  => $_SERVER['REQUEST_URI'],
        '_SERVER["QUERY_STRING"]' => $_SERVER['QUERY_STRING'],
        '_SERVER["SCRIPT_NAME"]'  => $_SERVER['SCRIPT_NAME'],
        '_SERVER["PHP_SELF"]'     => $_SERVER['PHP_SELF'],
        '_SERVER["SCRIPT_FILENAME"]'=> $_SERVER['SCRIPT_FILENAME'],
        '__FILE__'                => __FILE__,
        '__DIR__'                 => __DIR__,
        '__LINE__'                => __LINE__,
    ];

    echo '<table class="table table-striped table-hover" style="width:100%;" border="1">';
    echo '<tr><th>Variable</th><th>Value</th></tr>';
    foreach ($vars as $name => $value) {
        printf(
            '<tr><td>%s</td><td>%s</td></tr>',
            htmlspecialchars($name),
            htmlspecialchars((string)$value)
        );
    }
    echo '</table>';

    // Current URL
    $url = $_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
    echo 'Current URL=' . htmlspecialchars($url) . '<br>';

    // Pathinfo and parse_url dumps
    echo '<pre>';
    var_dump(pathinfo($url));
    echo '</pre>';

    echo '<pre>';
    var_dump(parse_url($url));
    echo '</pre>';

    echo '</div>';
}

/**
 * Highlight the $_SERVER superglobal.
 */
function highlightServer(): void
{
    echo '<div class="medium">';
    highlight_string("<?php\n\$SERVER =\n" . var_export($_SERVER, true) . ";\n?>");
    echo '</div>';
}

/* ----------------------------------------------------------------------
 * Main execution flow
 * ---------------------------------------------------------------------- */

$mc       = new MC();
$listing  = buildListing($mc);
$mc->renderTable($listing);

// Decide whether to show source or environment dump
if ($mc->view && is_readable((string)$mc->tpage)) {
    displaySource((string)$mc->tpage);
} else {
    displayEnvironment();
}

highlightServer();
echo '<hr>';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Classic PHP File Commander</title>
<link rel="stylesheet"
      href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css"
      integrity="sha384-rbsA2VBKQhggwzxH7pPCaAqO46MgnOM80zW1RWuH61DGLwZJEdK2Kadq2F9CUG65"
      crossorigin="anonymous">
<link rel="stylesheet" href="dark.css">
</head>
<body>

<div class="container mt-3">
    <h1>Classic PHP Mugsy Commander - <a
            href="<?= $mc->getCurrentUrl(); ?>">
        <?= htmlspecialchars($mc->httpHost); ?>
    </a> - <a href="?logout=1">Logout?</a></h1>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.min.js"
        integrity="sha384-cuYeSxntonz0PPNlHhBs68uyIAVpIIOZZ5JqeqvYYIcEL727kskC66kF92t6Xl2V"
        crossorigin="anonymous"></script>
</body>
</html>
