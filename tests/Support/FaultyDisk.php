<?php

declare(strict_types=1);

namespace Tarjim\Tests\Support;

use Tarjim\Store\Disk;
use Tarjim\Store\LocalDisk;

/** The real disk, except that puts to paths matching a pattern fail, as on a full disk. */
final class FaultyDisk implements Disk
{
    /** @var LocalDisk */
    private $disk;

    /** @var string|null */
    public $failPutsMatching;

    /** @var list<string> */
    public $puts = [];

    public function __construct()
    {
        $this->disk = new LocalDisk();
    }

    public function read(string $path): ?string
    {
        return $this->disk->read($path);
    }

    public function put(string $path, string $bytes): bool
    {
        $this->puts[] = $path;
        if ($this->failPutsMatching !== null && preg_match($this->failPutsMatching, $path) === 1) {
            return false;
        }

        return $this->disk->put($path, $bytes);
    }

    public function delete(string $path): bool
    {
        return $this->disk->delete($path);
    }

    public function files(string $dir): array
    {
        return $this->disk->files($dir);
    }

    public function mtime(string $path): ?int
    {
        return $this->disk->mtime($path);
    }

    public function ensureDir(string $path): bool
    {
        return $this->disk->ensureDir($path);
    }
}
