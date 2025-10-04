<?php
/**
 * logout.php
 *
 * Terminates the current user session and redirects to the login page.
 *
 * The script is intentionally minimal but follows a clear structure:
 * 1. Start the session if it hasn't been started yet.
 * 2. Destroy all session data.
 * 3. Redirect the browser to the login screen.
 * 4. Terminate execution to prevent any further output.
 */

declare(strict_types=1);

/**
 * Starts or resumes a PHP session.
 *
 * @return void
 */
function startSession(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

/**
 * Destroys the current session and clears all associated data.
 *
 * @return void
 */
function destroyCurrentSession(): void
{
    // Unset all session variables
    $_SESSION = [];

    // Delete the session cookie if it exists
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'] ?? false,
            $params['httponly'] ?? false
        );
    }

    // Finally destroy the session
    session_destroy();
}

/**
 * Redirects the user to a specified URL and stops script execution.
 *
 * @param string $url The destination URL.
 * @return void
 */
function redirect(string $url): void
{
    header("Location: {$url}");
    exit;
}

// --- Execution flow ---------------------------------------------------------

startSession();          // Ensure session is active
destroyCurrentSession(); // Remove all session data
redirect('login.php');   // Send user back to the login page
