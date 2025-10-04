<?php

// NOTE: Restored helper utilities post-merge to prep for new PR submission.
// Keep light and PHP 7.4-friendly; guard if already defined.
if (!function_exists('h')) {
    function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
if (!function_exists('getp')) {
    function getp(string $key, $default = null) { return $_GET[$key] ?? $default; }
}
if (!function_exists('postp')) {
    function postp(string $key, $default = null) { return $_POST[$key] ?? $default; }
}
if (!function_exists('now_iso')) {
    function now_iso(): string { return date('Y-m-d\TH:i:s'); }
}
if (!function_exists('sha256_file_s')) {
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
}
if (!function_exists('require_csrf')) {
    function require_csrf(): void {
        if (empty($_POST['csrf']) || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], (string)$_POST['csrf'])) {
            http_response_code(400);
            echo 'CSRF token invalid.'; exit;
        }
    }
}
if (!function_exists('ensure_csrf')) {
    function ensure_csrf(): string {
        if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
        if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(16)); }
        return $_SESSION['csrf'];
    }
}
if (!function_exists('render_nav_menu')) {
    /**
     * Render a navigation menu based on mc_menu.json definitions.
     *
     * @param string|null $currentFile Optional override for the current script filename.
     * @param string|null $menuFile    Optional override for the menu definition path.
     */
    function render_nav_menu(?string $currentFile = null, ?string $menuFile = null): string {
        $menuFile = $menuFile ?: __DIR__ . '/mc_menu.json';

        if ($currentFile === null) {
            $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
            $currentFile = basename($requestPath);
        }

        $items = [];
        if (is_file($menuFile) && is_readable($menuFile)) {
            $json = file_get_contents($menuFile);
            if ($json !== false) {
                $data = json_decode($json, true);
                if (is_array($data)) {
                    $items = $data;
                }
            }
        }

        if (empty($items)) {
            return '';
        }

        $links = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $url = isset($item['url']) ? (string)$item['url'] : '';
            $label = isset($item['label']) ? (string)$item['label'] : '';
            if ($url === '' || $label === '') {
                continue;
            }
            if ($currentFile !== '' && basename($url) === $currentFile) {
                continue;
            }
            $links[] = '<a class="btn btn-default" href="' . h($url) . '" style="background:#222;color:#eee;border-color:#444">&larr; ' . h($label) . '</a>';
        }

        if (empty($links)) {
            return '';
        }

        return '<div class="row" style="margin-top:8px;margin-bottom:8px"><div class="col-sm-12">' . implode(' ', $links) . '</div></div>';
    }
}
if (!function_exists('login_required')) {
    function login_required(string $ADMIN_PASS): void {
        if ($ADMIN_PASS === '') return; // disabled
        if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
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
}

// Polyfill
if (!function_exists('cw_starts_with')) {
    function cw_starts_with(string $haystack, string $needle): bool { return substr($haystack, 0, strlen($needle)) === $needle; }
}

?>
