<?php

declare(strict_types=1);

namespace Tarjim\Store;

/** An exclusive flock on a file; released by the OS if the process dies. */
final class Lock
{
    /** @var resource */
    private $handle;

    /** @param resource $handle */
    private function __construct($handle)
    {
        $this->handle = $handle;
    }

    /** Null when the file cannot be opened at all. */
    public static function open(string $path): ?self
    {
        $handle = @fopen($path, 'c');

        return $handle === false ? null : new self($handle);
    }

    /** Never waits: a refresh that finds the lock held reports it and lets the holder finish. */
    public function tryLock(): bool
    {
        return @flock($this->handle, LOCK_EX | LOCK_NB);
    }

    public function release(): void
    {
        if (is_resource($this->handle)) {
            @flock($this->handle, LOCK_UN);
            @fclose($this->handle);
        }
    }
}
