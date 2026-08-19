<?php

declare(strict_types=1);

namespace Framework\Storage;

use Framework\Config\Config;
use Framework\Exception\ValidationException;
use RuntimeException;

/**
 * Validates and persists a single normalized upload entry (the shape
 * returned by Request::file()/Request::files()) to disk under a random,
 * non-guessable filename. Configured from config/filesystem.php
 * (FILESYSTEM_DRIVER / STORAGE_*_PATH / UPLOAD_MAX_SIZE / ...  in .env).
 *
 * Two instances are bound in the container — see Application::run() —
 * keyed by driver name, so a controller can type-hint whichever one it
 * needs instead of always getting the default:
 *   - 'storage.private': under storage/app/uploads, outside the document
 *     root. Nothing here is reachable by a direct URL — pair this with a
 *     controller route that streams the file back after an auth check.
 *   - 'storage.public': under public/uploads, served directly by the web
 *     server. Only use this for content anyone with the link may view.
 *
 * @package Framework\Storage
 */
final class FileStorage
{
    public function __construct(private readonly string $basePath)
    {
    }

    /**
     * Validates and stores one uploaded file.
     *
     * @param array  $file   Normalized entry from Request::file('field') —
     *                       must have name/type/tmp_name/error/size keys.
     * @param string $subdir Optional subdirectory under the driver's base
     *                       path (e.g. 'avatars', 'invoices/2026').
     * @return string        The stored path, relative to the driver's base
     *                       path — this is what you save in the database,
     *                       not an absolute filesystem path.
     *
     * @throws ValidationException If the upload failed, is too large, or
     *                             its real MIME type isn't allow-listed.
     * @throws RuntimeException    If the file couldn't be moved to disk.
     */
    public function store(array $file, string $subdir = ''): string
    {
        $this->assertValid($file);

        $ext = pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION);
        $filename = bin2hex(random_bytes(16)) . ($ext !== '' ? ".{$ext}" : '');

        $relativeDir = trim($subdir, '/');
        $targetDir = rtrim($this->basePath, '/') . ($relativeDir !== '' ? "/{$relativeDir}" : '');

        if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            throw new RuntimeException("Failed to create upload directory: {$targetDir}");
        }

        $destination = "{$targetDir}/{$filename}";

        // is_uploaded_file() first: guards against something other than a genuine
        // HTTP upload (e.g. a crafted tmp_name) ever reaching move_uploaded_file().
        if (!is_uploaded_file($file['tmp_name']) || !move_uploaded_file($file['tmp_name'], $destination)) {
            throw new RuntimeException('Failed to store uploaded file.');
        }

        return $relativeDir !== '' ? "{$relativeDir}/{$filename}" : $filename;
    }

    /**
     * Deletes a previously stored file by its relative path (as returned by store()).
     */
    public function delete(string $relativePath): bool
    {
        $full = rtrim($this->basePath, '/') . '/' . ltrim($relativePath, '/');
        return is_file($full) ? unlink($full) : false;
    }

    /**
     * Resolves a relative path (as returned by store()) to an absolute
     * filesystem path — for a controller to read/stream after its own
     * auth/ownership check.
     */
    public function path(string $relativePath): string
    {
        return rtrim($this->basePath, '/') . '/' . ltrim($relativePath, '/');
    }

    private function assertValid(array $file): void
    {
        $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($error !== UPLOAD_ERR_OK) {
            throw new ValidationException(['file' => [$this->uploadErrorMessage((int) $error)]]);
        }

        if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            throw new ValidationException(['file' => ['No valid file was uploaded.']]);
        }

        $maxSize = (int) Config::get('filesystem.max_size', 5 * 1024 * 1024);
        if ((int) ($file['size'] ?? 0) > $maxSize) {
            throw new ValidationException(['file' => ['File exceeds the maximum allowed size.']]);
        }

        // Read the real MIME type from the file's bytes — $file['type'] is
        // reported by the client and trivially spoofable, never trust it alone.
        $mime = (string) mime_content_type($file['tmp_name']);
        $allowed = (array) Config::get('filesystem.allowed_mimes', []);
        if (!in_array($mime, $allowed, true)) {
            throw new ValidationException(['file' => ["File type '{$mime}' is not allowed."]]);
        }
    }

    private function uploadErrorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The uploaded file is too large.',
            UPLOAD_ERR_PARTIAL => 'The file was only partially uploaded.',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder on the server.',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write the file to disk.',
            UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the upload.',
            default => 'Unknown upload error.',
        };
    }
}
