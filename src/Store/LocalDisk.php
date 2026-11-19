<?php

declare(strict_types=1);

namespace Tarjim\Store;

/**
 * Plain stream functions only (no tempnam, no realpath), so a path under a stream wrapper behaves
 * like a local one.
 */
final class LocalDisk implements Disk
{
    public function read(string $path): ?string
    {
        try {
            if (!@is_file($path)) {
                return null;
            }
            $bytes = @file_get_contents($path);

            return is_string($bytes) ? $bytes : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function put(string $path, string $bytes): bool
    {
        $temp = null;
        $handle = null;
        try {
            // Beside the target: rename() is atomic only within one filesystem.
            $temp = dirname($path) . '/.' . basename($path) . '.' . bin2hex(random_bytes(4)) . '.tmp';
            $handle = @fopen($temp, 'xb');
            if ($handle === false) {
                $handle = null;
                $temp = null;

                return false;
            }
            // A full disk reports a short write rather than an error, so count the bytes.
            $length = strlen($bytes);
            $written = 0;
            while ($written < $length) {
                $count = @fwrite($handle, substr($bytes, $written));
                if ($count === false || $count === 0) {
                    break;
                }
                $written += $count;
            }
            $ok = $written === $length && @fflush($handle);
            $closed = @fclose($handle);
            $handle = null;
            if ($ok && $closed && @chmod($temp, 0644) && @rename($temp, $path)) {
                return true;
            }
        } catch (\Throwable $e) {
        }
        if (is_resource($handle)) {
            @fclose($handle);
        }
        if ($temp !== null) {
            @unlink($temp);
        }

        return false;
    }

    public function delete(string $path): bool
    {
        try {
            return @unlink($path);
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function files(string $dir): array
    {
        try {
            $entries = @scandir($dir);
            if ($entries === false) {
                return [];
            }
            $files = [];
            foreach ($entries as $entry) {
                if ($entry !== '.' && $entry !== '..' && @is_file($dir . '/' . $entry)) {
                    $files[] = $entry;
                }
            }

            return $files;
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function mtime(string $path): ?int
    {
        try {
            clearstatcache();
            $time = @filemtime($path);

            return $time === false ? null : $time;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function ensureDir(string $path): bool
    {
        try {
            if (@is_dir($path)) {
                return true;
            }
            // Not chmod'ed when it already exists: it may belong to someone else.
            if (!@mkdir($path, 0755, true) && !@is_dir($path)) {
                return false;
            }
            // mkdir() applies the umask; the web server's user has to be able to read the store.
            @chmod($path, 0755);

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
