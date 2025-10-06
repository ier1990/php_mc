<?php
/**
 * Simple Adminer clone – refactored for clarity and testability.
 *
 * Features:
 *   • Basic authentication (single user)
 *   • List databases on the server
 *   • CSRF protection
 *
 * The file is intentionally minimal; it does not provide full Adminer functionality,
 * but serves as a starting point that can be extended safely.
 */

require_once __DIR__ . '/utils.php';

/**
 * --------------------------------------------------------------------
 * Configuration constants
 * --------------------------------------------------------------------
 */
const HTACCESS_FILE = __DIR__ . '/../.htaccess';
const HTPASSWD_FILE = __DIR__ . '/../.htpasswd';
const DEFAULT_REALM  = 'Restricted Area';
const DEFAULT_USER   = 'admin';

/**
 * --------------------------------------------------------------------
 * Session handling
 * --------------------------------------------------------------------
 */
function init_session(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }
}

/**
 * --------------------------------------------------------------------
 * Authentication helpers
 * --------------------------------------------------------------------
 */

/**
 * Load the .htpasswd file and return an associative array of users.
 *
 * @return array<string, string>  username => password_hash
 */
function load_htpasswd(): array
{
    if (!file_exists(HTPASSWD_FILE)) {
        return [];
    }

    $lines = file(HTPASSWD_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $users = [];

    foreach ($lines as $line) {
        [$user, $hash] = explode(':', trim($line), 2) + [null, null];
        if ($user && $hash) {
            $users[$user] = $hash;
        }
    }

    return $users;
}

/**
 * Verify the provided credentials against .htpasswd.
 *
 * @param string $username
 * @param string $password
 * @return bool
 */
function verify_credentials(string $username, string $password): bool
{
    $users = load_htpasswd();

    if (!isset($users[$username])) {
        return false;
    }

    // The stored hash is expected to be a bcrypt hash.
    return password_verify($password, $users[$username]);
}

/**
 * Enforce HTTP Basic authentication using .htpasswd data.
 *
 * @return void
 */
function enforce_http_auth(): void
{
    if (!isset($_SERVER['PHP_AUTH_USER'])) {
        header('WWW-Authenticate: Basic realm="' . DEFAULT_REALM . '"');
        header('HTTP/1.0 401 Unauthorized');
        exit;
    }

    $user = $_SERVER['PHP_AUTH_USER'];
    $pass = $_SERVER['PHP_AUTH_PW'] ?? '';

    if (!verify_credentials($user, $pass)) {
        header('WWW-Authenticate: Basic realm="' . DEFAULT_REALM . '"');
        header('HTTP/1.0 401 Unauthorized');
        exit;
    }
}

/**
 * --------------------------------------------------------------------
 * CSRF helpers
 * --------------------------------------------------------------------
 */

/**
 * Generate a CSRF token and store it in the session.
 *
 * @return string
 */
function generate_csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verify a CSRF token from POST data.
 *
 * @param string|null $token
 * @return bool
 */
function verify_csrf_token(?string $token): bool
{
    return hash_equals($_SESSION['csrf_token'] ?? '', $token ?? '');
}

/**
 * --------------------------------------------------------------------
 * Database helpers
 * --------------------------------------------------------------------
 */

/**
 * Establish a MySQLi connection using credentials from .htpasswd.
 *
 * @return mysqli|null
 */
function connect_database(): ?mysqli
{
    // For simplicity, we use the authenticated username as DB user.
    $dbUser = $_SERVER['PHP_AUTH_USER'] ?? DEFAULT_USER;
    $dbPass = ''; // In a real setup you would store DB passwords separately.

    $conn = new mysqli('localhost', $dbUser, $dbPass);

    if ($conn->connect_error) {
        return null;
    }

    return $conn;
}

/**
 * Retrieve all database names from the server.
 *
 * @param mysqli $conn
 * @return array<string>
 */
function list_databases(mysqli $conn): array
{
    $result = $conn->query('SHOW DATABASES');
    if (!$result) {
        return [];
    }

    $databases = [];
    while ($row = $result->fetch_assoc()) {
        $databases[] = $row['Database'];
    }
    $result->free();

    return $databases;
}

/**
 * --------------------------------------------------------------------
 * Main execution flow
 * --------------------------------------------------------------------
 */
init_session();
enforce_http_auth();

// Generate CSRF token for the session.
$csrfToken = generate_csrf_token();

// Connect to database and fetch list of databases.
$conn = connect_database();
$databases = $conn ? list_databases($conn) : [];
if ($conn) {
    $conn->close();
}

/**
 * Render a minimal HTML page listing databases.
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Simple Adminer Clone</title>
<style>
    body { font-family: Arial, sans-serif; }
    table { border-collapse: collapse; width: 100%; }
    th, td { text-align: left; padding: 8px; }
    tr:nth-child(even) { background-color: #f2f2f2; }
</style>
</head>
<body>

<h1>Databases</h1>
<table border="1">
<tr><th>Database Name</th></tr>
<?php foreach ($databases as $db): ?>
    <tr><td><?= htmlspecialchars($db) ?></td></tr>
<?php endforeach; ?>
</table>

<!-- Example form to demonstrate CSRF protection -->
<form method="post" action="">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
    <!-- Add more fields as needed -->
    <button type="submit">Submit (CSRF protected)</button>
</form>

</body>
</html>
