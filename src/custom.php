<?php
/**
 * File: custom.php
 *
 * This script displays detailed information about a requested file and,
 * if the file is a supported text type, shows its contents.
 *
 * The original implementation was functional but hard to read and had
 * several side‑effects.  The refactored version below:
 *   • Separates logic into small functions.
 *   • Adds explicit error handling.
 *   • Uses constants for magic values.
 *   • Avoids global variables and reduces duplication.
 */

declare(strict_types=1);

/**
 * Configuration
 */
define('SUPPORTED_TEXT_EXTENSIONS', ['php', 'html', 'css', 'js', 'py', 'txt']);
define('MAX_HEADER_BYTES', 4);
define('MAX_CONTENT_PREVIEW', 512); // bytes to read for binary detection

/**
 * Entry point.
 *
 * @return void
 */
function main(): void
{
    $file = getRequestedFile();

    if (!is_readable_file($file)) {
        displayError("❌ File not found or unreadable.");
        return;
    }

    try {
        $info      = getFileInfo($file);
        $metadata  = extractMetadata($info, $file);
        $preview   = shouldShowPreview($metadata['extension'], $metadata['isBinary'])
            ? file_get_contents($file)
            : null;

        renderOutput($metadata, $preview);
    } catch (Throwable $e) {
        // Catch any unexpected errors and show a friendly message.
        displayError("⚠️ An error occurred: " . htmlspecialchars($e->getMessage()));
    }
}

/**
 * Retrieve the file path from GET parameters.
 *
 * @return string
 */
function getRequestedFile(): string
{
    return $_GET['file'] ?? '';
}

/**
 * Check if a file exists and is readable.
 *
 * @param string $path
 * @return bool
 */
function is_readable_file(string $path): bool
{
    return file_exists($path) && is_readable($path);
}

/**
 * Gather basic stat information for the file.
 *
 * @param string $path
 * @return array<string, mixed>
 * @throws RuntimeException if stat fails.
 */
function getFileInfo(string $path): array
{
    $stat = @stat($path);
    if ($stat === false) {
        throw new RuntimeException("Unable to retrieve file statistics.");
    }
    return $stat;
}

/**
 * Extract human‑readable metadata from the stat array and path.
 *
 * @param array<string, mixed> $info
 * @param string               $path
 * @return array<string, mixed>
 */
function extractMetadata(array $info, string $path): array
{
    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $owner     = posix_getpwuid($info['uid'] ?? 0)['name'] ?? (string) ($info['uid'] ?? 'unknown');
    $group     = posix_getgrgid($info['gid'] ?? 0)['name'] ?? (string) ($info['gid'] ?? 'unknown');
    $perms     = substr(sprintf('%o', fileperms($path)), -4);
    $size      = number_format((int)$info['size']);
    $modified  = date('Y-m-d H:i:s', $info['mtime'] ?? time());
    $created   = date('Y-m-d H:i:s', $info['ctime'] ?? time());

    // Detect binary content by inspecting the first few bytes.
    $sample     = @file_get_contents($path, false, null, 0, MAX_CONTENT_PREVIEW);
    $isBinary   = preg_match('/[\x00-\x08\x0E-\x1F\x80-\xFF]/', $sample ?? '') === 1;

    // Determine file type from magic numbers.
    $headerHex  = strtoupper(bin2hex(@file_get_contents($path, false, null, 0, MAX_HEADER_BYTES)));
    $fileType   = mapHeaderToFileType($headerHex);

    return [
        'path'      => $path,
        'extension' => $extension,
        'owner'     => $owner,
        'group'     => $group,
        'permissions' => $perms,
        'size'          => $size,
        'modified'      => $modified,
        'created'       => $created,
        'isBinary'      => (bool)$isBinary,
        'fileType'      => $fileType,
    ];
}

/**
 * Map a file header hex string to a human‑readable type.
 *
 * @param string $headerHex
 * @return string
 */
function mapHeaderToFileType(string $headerHex): string
{
    return match ($headerHex) {
        '89504E47' => 'PNG Image',
        '25504446' => 'PDF Document',
        'FFD8FFE0', 'FFD8FFE1', 'FFD8FFE2' => 'JPEG Image',
        '47494638' => 'GIF Image',
        '504B0304' => 'ZIP Archive or DOCX/XLSX',
        default   => 'Text or Unknown',
    };
}

/**
 * Determine whether the file content should be displayed.
 *
 * @param string $extension
 * @param bool   $isBinary
 * @return bool
 */
function shouldShowPreview(string $extension, bool $isBinary): bool
{
    return !$isBinary && in_array($extension, SUPPORTED_TEXT_EXTENSIONS, true);
}

/**
 * Render the HTML output.
 *
 * @param array<string, mixed> $metadata
 * @param string|null          $previewContent
 * @return void
 */
function renderOutput(array $metadata, ?string $previewContent): void
{
    echo '<div style="line-height:1.5em;">';
    printf(
        '<strong>📄 File:</strong> <code>%s</code><br>',
        htmlspecialchars($metadata['path'])
    );
    printf(
        '<strong>🗂️ Type:</strong> %s (%s)<br>',
        htmlspecialchars($metadata['fileType']),
        htmlspecialchars($metadata['extension'])
    );
    printf(
        '<strong>👤 Owner:</strong> %s (%s)<br>',
        htmlspecialchars($metadata['owner']),
        htmlspecialchars($metadata['group'])
    );
    printf(
        '<strong>🔒 Permissions:</strong> %s<br>',
        htmlspecialchars($metadata['permissions'])
    );
    printf(
        '<strong>📦 Size:</strong> %s bytes<br>',
        $metadata['size']
    );
    printf(
        '<strong>📅 Modified:</strong> %s<br>',
        htmlspecialchars($metadata['modified'])
    );
    printf(
        '<strong>🆕 Created:</strong> %s<br>',
        htmlspecialchars($metadata['created'])
    );
    printf(
        '<strong>🔍 Binary:</strong> %s<br>',
        $metadata['isBinary'] ? 'Yes' : 'No'
    );

    echo '<hr>';

    if ($previewContent !== null) {
        echo '<pre style="background:#111; color:#eee; padding:1em; overflow:auto;">';
        echo htmlspecialchars($previewContent);
        echo '</pre>';
    } else {
        echo '<em>Binary file content not shown.</em>';
    }

    echo '</div>';
}

/**
 * Display an error message inside a styled div.
 *
 * @param string $message
 * @return void
 */
function displayError(string $message): void
{
    printf(
        '<div style="color:red;">%s</div>',
        htmlspecialchars($message)
    );
}

// Execute the script.
main();
