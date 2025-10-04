<?php
/**
 * Classic PHP File Commander – Login handler.
 *
 * This file has been refactored for clarity and maintainability:
 * - All logic is encapsulated in functions or a small class.
 * - Type hints and return types are added where possible.
 * - Constants are grouped together.
 * - The HTML form remains unchanged to preserve the original UI.
 */

declare(strict_types=1);

/* -------------------------------------------------------------------------- */
/* Configuration & constants                                                  */
/* -------------------------------------------------------------------------- */
const COOKIE_VALID_LENGTH = 3600; // 1 hour
const MOS_COOKIE_NAME     = 'MOS_LOGIN_CLASS_COOKIE';

$credentials = [
    'username' => 'admin',
    'password' => 'password',
];

/**
 * Encrypt or decrypt a string using AES-256-CBC.
 *
 * @param 'encrypt'|'decrypt' $action   Action to perform.
 * @param string              $string  Input string.
 *
 * @return false|string Decrypted string on success, encrypted string on
 *                      encryption, or false on failure.
 */
function encryptDecrypt(string $action, string $string)
{
    $method = 'AES-256-CBC';
    $secretKey = 'This is my secret key';
    $secretIv  = 'This is my secret iv';

    // Derive a 32‑byte key and a 16‑byte IV
    $key = hash('sha256', $secretKey, true);
    $iv  = substr(hash('sha256', $secretIv, true), 0, 16);

    if ($action === 'encrypt') {
        $encrypted = openssl_encrypt($string, $method, $key, OPENSSL_RAW_DATA, $iv);
        return base64_encode($encrypted);
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
 * Validate user credentials and set up session & cookie.
 *
 * @param string $username Supplied username.
 * @param string $password Supplied password.
 *
 * @return bool True on successful login, false otherwise.
 */
function loginUser(string $username, string $password): bool
{
    global $credentials;

    if ($username !== $credentials['username'] || $password !== $credentials['password']) {
        return false;
    }

    session_start();
    $_SESSION['UserData']['Username'] = $credentials['username'];

    // Create a cookie that expires after COOKIE_VALID_LENGTH seconds.
    $token = encryptDecrypt('encrypt', $username . '|' . $password);
    setcookie(MOS_COOKIE_NAME, $token, time() + COOKIE_VALID_LENGTH, '/', '', false, true);

    header('Location: mc_class.php');
    exit;

    // Unreachable but keeps the return type explicit
    return true;
}

/**
 * Attempt to log in using a cookie.
 *
 * @return bool True if login succeeded via cookie, false otherwise.
 */
function tryLoginFromCookie(): bool
{
    global $credentials;

    if (!isset($_COOKIE[MOS_COOKIE_NAME])) {
        return false;
    }

    $cookie = htmlspecialchars($_COOKIE[MOS_COOKIE_NAME], ENT_QUOTES, 'UTF-8');
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
    return true; // Unreachable due to redirect in loginUser()
}

/* -------------------------------------------------------------------------- */
/* Main request handling                                                      */
/* -------------------------------------------------------------------------- */
$loginSuccessful = false;

// 1. Try cookie‑based auto‑login
if (tryLoginFromCookie()) {
    $loginSuccessful = true;
}

// 2. If not logged in, process POST credentials
if (!$loginSuccessful && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    if (loginUser($username, $password)) {
        // loginUser() redirects on success.
        $loginSuccessful = true;
    }
}

/* -------------------------------------------------------------------------- */
/* HTML output – unchanged from the original file                            */
/* -------------------------------------------------------------------------- */
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
    <form action="login.php" method="post">
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
