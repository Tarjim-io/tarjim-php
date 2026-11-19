<?php

declare(strict_types=1);

namespace Tarjim\Export;

use Tarjim\Store\Disk;

/**
 * Puts an export into a folder the caller shares with other files. The installer owns only the
 * files it wrote, which it records in a list inside the folder; everything else is left alone.
 */
final class Installer
{
    /** @var Disk */
    private $disk;

    public function __construct(Disk $disk)
    {
        $this->disk = $disk;
    }

    /**
     * Every file is staged inside the target first, so a failure while staging changes nothing
     * there. Staging sits in the target because rename() is atomic only within one filesystem.
     *
     * @param list<string> $paths safe relative paths, in write order
     * @param callable(string, string): void $fill writes the bytes of a path to the staged file given
     * @return array{list<string>, list<string>} the paths written and the paths removed
     * @throws Refusal
     */
    public function install(string $target, array $paths, callable $fill, int $exportId): array
    {
        $this->ensureTarget($target);
        foreach ($paths as $path) {
            self::checkNoLinks($target, $path);
        }

        $staging = $target . '/' . Paths::OWN_PREFIX . '.' . bin2hex(random_bytes(6));
        if (!@mkdir($staging, 0700)) {
            throw new Refusal('a staging folder could not be created in the target');
        }
        $previous = $this->previous($target);
        $moved = [];
        try {
            foreach ($paths as $i => $path) {
                $fill($path, $staging . '/' . $i);
            }
            try {
                foreach ($paths as $i => $path) {
                    $this->place($target, $path, $staging . '/' . $i);
                    $moved[] = $path;
                }
            } catch (Refusal $e) {
                // What did land is still ours: keep it on the list so a later pull can clean it up.
                $this->record($target, array_values(array_unique(array_merge($previous, $moved))), $exportId);
                throw $e;
            }
        } finally {
            self::removeTree($staging);
        }
        if (!$this->record($target, $paths, $exportId)) {
            throw new Refusal('the files were installed but the list of them could not be written');
        }

        return [$paths, $this->prune($target, $previous, $paths)];
    }

    /** @throws Refusal */
    private function ensureTarget(string $target): void
    {
        if (is_dir($target)) {
            return;
        }
        if (file_exists($target) || is_link($target)) {
            throw new Refusal('the target exists and is not a folder');
        }
        if (!$this->disk->ensureDir($target)) {
            throw new Refusal('the target folder could not be created');
        }
    }

    /**
     * Refuses a path through a link, or through a file where a folder is needed, or onto a folder:
     * a link in the target could point anywhere, and the pull must not write through it.
     *
     * @throws Refusal
     */
    private static function checkNoLinks(string $target, string $path): void
    {
        $segments = explode('/', $path);
        $current = $target;
        foreach ($segments as $n => $segment) {
            $current .= '/' . $segment;
            if (is_link($current)) {
                throw new Refusal('the target holds a link where the export needs ' . ($n === count($segments) - 1 ? 'a file' : 'a folder'));
            }
            if (!file_exists($current)) {
                return;
            }
            $last = $n === count($segments) - 1;
            if ($last === is_dir($current)) {
                throw new Refusal('the target holds ' . ($last ? 'a folder' : 'a file') . ' where the export needs ' . ($last ? 'a file' : 'a folder'));
            }
        }
    }

    /** @throws Refusal */
    private function place(string $target, string $path, string $staged): void
    {
        self::checkNoLinks($target, $path);
        $dir = $target;
        $segments = explode('/', $path);
        array_pop($segments);
        foreach ($segments as $segment) {
            $dir .= '/' . $segment;
            if (!is_dir($dir)) {
                // mkdir() applies the umask; the web server's user has to be able to read these.
                if (!@mkdir($dir, 0755) && !is_dir($dir)) {
                    throw new Refusal('a folder of the export could not be created');
                }
                @chmod($dir, 0755);
            }
        }
        if (!@chmod($staged, 0644) || !@rename($staged, $target . '/' . $path)) {
            throw new Refusal('a file of the export could not be moved into place');
        }
    }

    /** @return list<string> */
    private function previous(string $target): array
    {
        $raw = $this->disk->read($target . '/' . Paths::LIST_NAME);
        $data = $raw === null ? null : json_decode($raw, true);
        if (!is_array($data) || !isset($data['files']) || !is_array($data['files'])) {
            return [];
        }
        $paths = [];
        foreach ($data['files'] as $path) {
            // The list is a file anyone can edit: a path from it is checked like a zip entry.
            if (is_string($path) && Paths::isSafe($path)) {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    /** @param list<string> $paths */
    private function record(string $target, array $paths, int $exportId): bool
    {
        $json = json_encode(['version' => 1, 'exportId' => $exportId, 'files' => $paths]);

        return $json !== false && $this->disk->put($target . '/' . Paths::LIST_NAME, $json);
    }

    /**
     * @param list<string> $previous
     * @param list<string> $kept
     * @return list<string>
     */
    private function prune(string $target, array $previous, array $kept): array
    {
        $removed = [];
        $keep = array_flip($kept);
        foreach ($previous as $path) {
            if (isset($keep[$path]) || !self::removeOwned($target, $path)) {
                continue;
            }
            $removed[] = $path;
        }

        return $removed;
    }

    /** Deletes a listed file only when nothing on its way is a link, then the folders it leaves empty. */
    private static function removeOwned(string $target, string $path): bool
    {
        $segments = explode('/', $path);
        $current = $target;
        foreach ($segments as $segment) {
            $current .= '/' . $segment;
            if (is_link($current)) {
                return false;
            }
        }
        if (!is_file($current) || !@unlink($current)) {
            return false;
        }
        array_pop($segments);
        while ($segments !== []) {
            $dir = $target . '/' . implode('/', $segments);
            // rmdir() refuses a folder that still holds anything, which is the check we want.
            if (is_link($dir) || !@rmdir($dir)) {
                break;
            }
            array_pop($segments);
        }

        return true;
    }

    private static function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        $entries = is_dir($path) ? @scandir($path) : false;
        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::removeTree($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }
}
