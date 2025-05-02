<?php
$file = $_GET['file'] ?? '';
if (!file_exists($file)) {
    echo "<div style='color:red;'>❌ File not found.</div>";
    return;
}

$info = stat($file);
$ext = pathinfo($file, PATHINFO_EXTENSION);
$owner = posix_getpwuid($info['uid'])['name'] ?? $info['uid'];
$group = posix_getgrgid($info['gid'])['name'] ?? $info['gid'];
$perms = substr(sprintf('%o', fileperms($file)), -4);
$size = number_format($info['size']);
$modified = date("Y-m-d H:i:s", $info['mtime']);
$created = date("Y-m-d H:i:s", $info['ctime']);
$isBinary = preg_match('~[\x00-\x08\x0E-\x1F\x80-\xFF]~', file_get_contents($file, false, null, 0, 512));

// Detect common file headers
$header = strtoupper(bin2hex(file_get_contents($file, false, null, 0, 4)));
$filetype = "Text or Unknown";
switch ($header) {
    case '89504E47': $filetype = 'PNG Image'; break;
    case '25504446': $filetype = 'PDF Document'; break;
    case 'FFD8FFE0': case 'FFD8FFE1': case 'FFD8FFE2': $filetype = 'JPEG Image'; break;
    case '47494638': $filetype = 'GIF Image'; break;
    case '504B0304': $filetype = 'ZIP Archive or DOCX/XLSX'; break;
}

echo "<div style='line-height:1.5em;'>";
echo "<strong>📄 File:</strong> <code>$file</code><br>";
echo "<strong>🗂️ Type:</strong> $filetype ($ext)<br>";
echo "<strong>👤 Owner:</strong> $owner ($group)<br>";
echo "<strong>🔒 Permissions:</strong> $perms<br>";
echo "<strong>📦 Size:</strong> $size bytes<br>";
echo "<strong>📅 Modified:</strong> $modified<br>";
echo "<strong>🆕 Created:</strong> $created<br>";
echo "<strong>🔍 Binary:</strong> " . ($isBinary ? 'Yes' : 'No') . "<br>";

if (!$isBinary && in_array($ext, ['php','html','css','js','py','txt'])) {
    echo "<hr><pre style='background:#111; color:#eee; padding:1em; overflow:auto;'>";
    echo htmlspecialchars(file_get_contents($file));
    echo "</pre>";
} else {
    echo "<hr><em>Binary file content not shown.</em>";
}

echo "</div>";
