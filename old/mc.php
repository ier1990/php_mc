<?php
/**
 * Classic PHP File Commander – Refactored & Logged Version
 *
 * This file has been rewritten for clarity and modularity while preserving the original behaviour.
 * Logging statements have been added to aid debugging and trace execution paths.
 */

declare(strict_types=1);

session_start();

/**
 * --------------------------------------------------------------------
 * Configuration & Environment Setup
 * --------------------------------------------------------------------
 */
define('EXCLUDE_LIST', ['.', '..']);
$httpHost     = $_SERVER['HTTP_HOST'] ?? false;
$documentRoot = str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? false);

if (!$httpHost || !$documentRoot) {
    error_log('[mc] Missing HTTP_HOST or DOCUMENT_ROOT');
    echo 'HTTP_HOST or DOCUMENT_ROOT not set';
    exit;
}

/**
 * --------------------------------------------------------------------
 * Utility Functions
 * --------------------------------------------------------------------
 */

/**
 * Convert a file stat array into the Unix permission string.
 *
 * @param array $stat File stat information (from stat()).
 * @return string Permission string like "-rw-r--r--".
 */
function getPermissionString(array $stat): string
{
    $mode = $stat['mode'];
    $perm  = ($mode & 0x1000) ? 'd' : '-';
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

/**
 * Build a URL for the current script with given query parameters.
 *
 * @param array $params Query parameters to merge with existing GET values.
 * @return string Full URL.
 */
function buildUrl(array $params = []): string
{
    global $httpHost, $documentRoot;

    // Merge existing GET params with new ones
    $query = array_merge($_GET ?? [], $params);
    $path  = str_replace($documentRoot, '', $_SERVER['PHP_SELF']);

    $scheme = (isset($_SERVER['HTTPS']) && ($_SERVER['HTTPS'] === 'on' || $_SERVER['HTTPS'] == 1))
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
        ? 'https'
        : 'http';

    $url = sprintf(
        '%s://%s%s?%s',
        $scheme,
        $httpHost,
        $path,
        http_build_query($query)
    );

    error_log("[mc] buildUrl: {$url}");
    return $url;
}

/**
 * Format file size in human readable form.
 *
 * @param int $bytes File size in bytes.
 * @return string Formatted size (e.g., "1.23 KB").
 */
function formatSize(int $bytes): string
{
    return ($bytes > 1024) ? round($bytes / 1024, 2) . ' KB' : $bytes . ' B';
}

/**
 * Render the directory listing.
 *
 * @param string $dirPath Absolute path to the directory being listed.
 */
function renderDirectoryListing(string $dirPath): void
{
    global $httpHost;

    // Get entries excluding '.' and '..'
    $entries = array_diff(scandir($dirPath), EXCLUDE_LIST);

    echo '<h1>Classic PHP Mugsy Commander - <a href="' . buildUrl() . '">' . htmlspecialchars($httpHost) . '</a> - <a href="logout.php">logout?</a></h1>';
    echo 'Directory: ' . htmlspecialchars($dirPath) . '<br>';

    echo '<ul style="list-style:none;padding:0;">';

    // Link to parent directory
    $parentDir = dirname(rtrim($dirPath, '/'));
    echo '<li style="margin-left:1em;">.. <a href="' . buildUrl(['dir' => $parentDir]) . '">💾 up</a></li>';

    foreach ($entries as $entry) {
        $fullPath = rtrim($dirPath, '/') . '/' . $entry;
        $stat     = stat($fullPath);

        $size   = formatSize((int)$stat['size']);
        $perm   = substr(sprintf('%o', $stat['mode']), -4) . getPermissionString($stat);
        $isDir  = is_dir($fullPath);
        $link   = buildUrl(['dir' => rtrim($dirPath, '/') . '/' . $entry]);

        echo '<li style="margin-left:1em;">';
        if ($isDir) {
            // Directory entry
            echo '📁 <a href="' . htmlspecialchars($link) . '">' . htmlspecialchars($entry) . ' ' . $perm . '</a>';
        } else {
            // File entry
            $fileUrl = buildUrl(['tpage' => $fullPath, 'view' => true, 'dir' => rtrim($dirPath, '/')]);
            echo '📝 <a href="' . htmlspecialchars($fileUrl) . '" target="main">' . htmlspecialchars($entry) . '</a> ';
            echo $size . ' ' . $perm;
            // Link to execute the file
            $execUrl = str_replace($_SERVER['DOCUMENT_ROOT'], '', $fullPath);
            echo ' <a href="' . buildUrl(['tpage' => $fullPath, 'view' => false]) . '" target="main"> ▶️ </a>';
        }
        echo '</li>';
    }

    echo '</ul>';
}

/**
 * Render the source view of a file.
 *
 * @param string $file Path to the file to display.
 */
function renderFileView(string $file): void
{
    if (!is_readable($file)) {
        error_log("[mc] File not readable: {$file}");
        return;
    }

    echo '<div class="medium" style="overflow:scroll; overflow-x:hidden; height:50%;">';
    echo 'Displaying file: ' . htmlspecialchars($file) . "<br>";

    // Highlight the source code
    ini_set('highlight.comment', '#800080; font-weight: bold;');
    $highlighted = highlight_file($file, true);

    // Split into lines and output as a table with line numbers
    $lines = explode('<br />', $highlighted);
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
 * Render the debug view of the internal state array.
 */
function renderDebugView(): void
{
    global $mc_array;
    echo '<div class="medium" style="overflow:scroll; overflow-x:hidden; height:50%;">';
    echo 'Displaying array:<pre>' . var_export($mc_array, true) . '</pre>';
    echo '</div>';
}

/**
 * --------------------------------------------------------------------
 * Main Execution Flow
 * --------------------------------------------------------------------
 */
$dir   = $_GET['dir'] ?? getcwd();
$tpage = $_GET['tpage'] ?? false;
$view  = $_GET['view'] ?? false;

// Resolve real path and normalise slashes
$realDir = realpath($dir) ?: getcwd();
$realDir = str_replace('\\', '/', $realDir);
$dirPath = rtrim($realDir, '/') . '/';

$mc_array = [
    'exclude_list'  => EXCLUDE_LIST,
    'HTTP_HOST'     => $httpHost,
    'DOCUMENT_ROOT' => $documentRoot,
    'dir'           => $realDir,
    'dir_path'      => $dirPath,
    'tpage'         => $tpage,
    'view'          => $view,
];

?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Classic PHP File Commander</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css"
      integrity="sha384-rbsA2VBKQhggwzxH7pPCaAqO46MgnOM80zW1RWuH61DGLwZJEdK2Kadq2F9CUG65" crossorigin="anonymous">
<link rel="stylesheet" href="dark.css">
</head>
<body>

<div class="medium" style="overflow:scroll; overflow-x:hidden; height:50%;">
<?php
    renderDirectoryListing($dirPath);
?>
</div>

<?php
if ($view && is_readable($tpage)) {
    renderFileView($tpage);
} else {
    renderDebugView();
}
?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.min.js"
        integrity="sha384-cuYeSxntonz0PPNlHhBs68uyIAVpIIOZZ5JqeqvYYIcEL727kskC66kF92t6Xl2V" crossorigin="anonymous"></script>
</body>
</html>
