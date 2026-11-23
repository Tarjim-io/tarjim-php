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
    private const STALE_STAGING = 86400;
    private const REMOVED = 'removed';
    private const ABSENT = 'absent';
    private const FAILED = 'failed';

    /** @var Disk */
    private $disk;

    public function __construct(Disk $disk)
    {
        $this->disk = $disk;
    }

    /**
     * Stages every file inside the target first (rename() is atomic only within one filesystem),
     * then moves each existing file aside into the staging folder and renames the new one in. Any
     * failure puts every file back and removes what was placed, so the target ends as it began.
     *
     * @param list<string> $paths safe relative paths, in write order
     * @param callable(string, string): void $fill writes the bytes of a path to the staged file given
     * @return array{list<string>, list<string>, list<string>} the paths written, the paths removed
     *         and notes for the caller's message
     * @throws Refusal
     */
    public function install(string $target, array $paths, callable $fill, int $exportId, bool $adoptExisting = false): array
    {
        $previous = $this->previous($target);
        $this->guardExisting($target, $paths, $previous, $adoptExisting);

        $created = $this->ensureTarget($target);
        $staging = $target . '/' . Paths::OWN_PREFIX . '.' . bin2hex(random_bytes(6));
        $listPath = $target . '/' . Paths::LIST_NAME;
        $placed = [];
        $madeDirs = $created;
        $hadList = is_file($listPath);
        $listBefore = $hadList ? $this->disk->read($listPath) : null;
        $listWritten = false;
        try {
            if (!@mkdir($staging, 0700)) {
                throw new Refusal('a staging folder could not be created in the target');
            }
            foreach ($paths as $i => $path) {
                $fill($path, $staging . '/' . $i);
            }
            // Written first, so a pull killed half way still owns what it placed.
            if (!$this->record($target, array_values(array_unique(array_merge($previous, $paths))), $exportId)) {
                throw new Refusal('the list of the export\'s files could not be written, so nothing was installed');
            }
            $listWritten = true;
            foreach ($paths as $i => $path) {
                $this->place($target, $path, $staging, $i, $placed, $madeDirs);
            }
        } catch (\Throwable $e) {
            $restored = self::rollback($placed);
            // A list that existed but could not be read stays as the write-ahead version: a superset is safe.
            if ($listWritten && !$hadList) {
                $this->disk->delete($listPath);
            } elseif ($listWritten && $listBefore !== null) {
                $this->disk->put($listPath, $listBefore);
            }
            if ($restored) {
                self::removeTree($staging);
                self::removeDirs(array_reverse($madeDirs));
            }
            if (!$restored && $e instanceof Refusal) {
                throw new Refusal($e->getMessage() . '; some original files could not be put back and are kept in ' . basename($staging), $e->status);
            }
            throw $e;
        }

        $notes = [];
        try {
            [$removed, $stuck] = $this->prune($target, $previous, $paths);
            if ($stuck !== []) {
                $notes[] = 'could not remove ' . implode(', ', $stuck) . ' (kept on the list, a later pull tries again)';
            }
            if (!$this->record($target, array_values(array_unique(array_merge($paths, $stuck))), $exportId)) {
                $notes[] = 'the list of owned files could not be updated';
            }
        } finally {
            self::removeTree($staging);
        }

        return [$paths, $removed, $notes];
    }

    /** Deletes the staging folders an interrupted pull left behind, once they are a day old. */
    public static function sweep(string $target, int $now): void
    {
        $entries = is_dir($target) ? @scandir($target) : false;
        foreach ($entries === false ? [] : $entries as $entry) {
            if (preg_match('/^' . preg_quote(Paths::OWN_PREFIX, '/') . '\.[0-9a-f]{12}$/', $entry) !== 1) {
                continue;
            }
            $path = $target . '/' . $entry;
            $time = is_link($path) || !is_dir($path) ? false : @filemtime($path);
            if ($time !== false && $now - $time > self::STALE_STAGING) {
                self::removeTree($path);
            }
        }
    }

    /**
     * @return list<string> the folders this call had to create, outermost first
     * @throws Refusal
     */
    private function ensureTarget(string $target): array
    {
        if (is_dir($target)) {
            return [];
        }
        if (file_exists($target) || is_link($target)) {
            throw new Refusal('the target exists and is not a folder');
        }
        $missing = [];
        for ($dir = $target; !is_dir($dir) && !in_array($dir, $missing, true); $dir = dirname($dir)) {
            $missing[] = $dir;
        }
        $missing = array_reverse($missing);
        if (!$this->disk->ensureDir($target)) {
            self::removeDirs(array_reverse($missing));
            throw new Refusal('the target folder could not be created');
        }

        return $missing;
    }

    /**
     * Fails before anything is touched when the export would write over a file the list does not
     * name. The disk is asked by name and by folded key, so a case variant counts on a disk that
     * tells them apart too.
     *
     * @param list<string> $paths
     * @param list<string> $previous
     * @throws Refusal
     */
    private function guardExisting(string $target, array $paths, array $previous, bool $adoptExisting): void
    {
        if (!is_dir($target)) {
            return;
        }
        $owned = array_flip(array_map([Paths::class, 'key'], $previous));
        $listing = [];
        foreach ($paths as $path) {
            if (!self::exists($target, $path, $listing) || $adoptExisting || isset($owned[Paths::key($path)])) {
                continue;
            }
            throw new Refusal('the target already holds ' . $path . ', which an earlier pull did not write; move it away or set adoptExisting to take it over');
        }
    }

    /**
     * Whether the path, or a spelling of it that folds to the same key, is on the disk. Also refuses
     * a link, or a file where a folder is needed, or a folder where the file is.
     *
     * @param array<string, list<string>> $listing folder => its entries, read once
     * @throws Refusal
     */
    private static function exists(string $target, string $path, array &$listing): bool
    {
        $segments = explode('/', $path);
        $current = $target;
        foreach ($segments as $n => $segment) {
            $name = self::entry($current, $segment, $listing);
            if ($name === null) {
                return false;
            }
            $current .= '/' . $name;
            $last = $n === count($segments) - 1;
            if (is_link($current)) {
                throw new Refusal('the target holds a link where the export needs ' . ($last ? 'a file' : 'a folder'));
            }
            if ($last === is_dir($current)) {
                throw new Refusal('the target holds ' . ($last ? 'a folder' : 'a file') . ' where the export needs ' . ($last ? 'a file' : 'a folder'));
            }
        }

        return true;
    }

    /**
     * @param array<string, list<string>> $listing
     */
    private static function entry(string $dir, string $segment, array &$listing): ?string
    {
        if (file_exists($dir . '/' . $segment) || is_link($dir . '/' . $segment)) {
            return $segment;
        }
        if (!isset($listing[$dir])) {
            $names = is_dir($dir) ? @scandir($dir) : false;
            $listing[$dir] = $names === false ? [] : $names;
        }
        $key = Paths::key($segment);
        foreach ($listing[$dir] as $name) {
            if ($name !== '.' && $name !== '..' && Paths::key($name) === $key) {
                return $name;
            }
        }

        return null;
    }

    /**
     * Refuses a path through a link, or through a file where a folder is needed, or onto a folder:
     * a link in the target could point anywhere, and the pull must not write through it.
     *
     * @throws Refusal
     */
    private static function checkNoLinks(string $target, string $path): void
    {
        $listing = [];
        self::exists($target, $path, $listing);
    }

    /**
     * Moves what is at the path aside, then renames the staged file in. Registers each step in
     * $placed before taking it, so a rollback undoes a half-done one too.
     *
     * @param list<array{string, string|null, bool}> $placed
     * @param list<string> $madeDirs
     * @throws Refusal
     */
    private function place(string $target, string $path, string $staging, int $i, array &$placed, array &$madeDirs): void
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
                $madeDirs[] = $dir;
                @chmod($dir, 0755);
            }
        }
        $dest = $target . '/' . $path;
        $aside = null;
        if (is_file($dest)) {
            $aside = $staging . '/was-' . $i;
            if (!@rename($dest, $aside)) {
                throw new Refusal('a file of the target could not be moved aside');
            }
        }
        $step = count($placed);
        $placed[$step] = [$dest, $aside, false];
        if (!@chmod($staging . '/' . $i, 0644) || !@rename($staging . '/' . $i, $dest)) {
            throw new Refusal('a file of the export could not be moved into place');
        }
        $placed[$step][2] = true;
    }

    /**
     * @param list<array{string, string|null, bool}> $placed
     * @return bool whether every original is back where it was
     */
    private static function rollback(array $placed): bool
    {
        $restored = true;
        foreach (array_reverse($placed) as [$dest, $aside, $in]) {
            if ($in && !@unlink($dest)) {
                $restored = false;
                continue;
            }
            if ($aside !== null && !@rename($aside, $dest)) {
                $restored = false;
            }
        }

        return $restored;
    }

    /** @param list<string> $dirs innermost first */
    private static function removeDirs(array $dirs): void
    {
        foreach ($dirs as $dir) {
            // Refuses a folder that holds anything, which is the check we want.
            @rmdir($dir);
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
     * Removes what an earlier pull wrote and this one does not. A listed path that folds to the same
     * key as one just written is that file on a disk that ignores case, so it stays.
     *
     * @param list<string> $previous
     * @param list<string> $kept
     * @return array{list<string>, list<string>} the paths removed and the ones that could not be
     */
    private function prune(string $target, array $previous, array $kept): array
    {
        clearstatcache();
        $removed = [];
        $stuck = [];
        $keep = array_flip($kept);
        $written = [];
        foreach ($kept as $path) {
            $written[Paths::key($path)] = $path;
        }
        foreach ($previous as $path) {
            if (isset($keep[$path])) {
                continue;
            }
            $key = Paths::key($path);
            if (isset($written[$key]) && self::sameFile($target . '/' . $path, $target . '/' . $written[$key])) {
                continue;
            }
            $outcome = self::removeOwned($target, $path);
            if ($outcome === self::REMOVED) {
                $removed[] = $path;
            } elseif ($outcome === self::FAILED) {
                $stuck[] = $path;
            }
        }

        return [$removed, $stuck];
    }

    private static function sameFile(string $a, string $b): bool
    {
        $x = @stat($a);
        $y = @stat($b);

        return $x !== false && $y !== false && $x['ino'] === $y['ino'] && $x['dev'] === $y['dev'];
    }

    /**
     * Deletes a listed file only when nothing on its way is a link, then the folders it leaves empty.
     * A file that is already gone is neither removed nor stuck: there is nothing left to retry.
     */
    private static function removeOwned(string $target, string $path): string
    {
        $segments = explode('/', $path);
        $current = $target;
        foreach ($segments as $segment) {
            $current .= '/' . $segment;
            if (is_link($current)) {
                return self::FAILED;
            }
        }
        if (!is_file($current)) {
            return self::ABSENT;
        }
        if (!@unlink($current)) {
            return self::FAILED;
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

        return self::REMOVED;
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
