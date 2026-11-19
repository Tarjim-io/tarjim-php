<?php

declare(strict_types=1);

namespace Tarjim\Store;

/** The file operations the store needs. Paths are absolute. Never throws. */
interface Disk
{
    public function read(string $path): ?string;

    /**
     * Replaces $path with $bytes so that a reader sees the old file or the whole new one, never part
     * of either: write a temp file in the same directory, check every byte was written, rename.
     */
    public function put(string $path, string $bytes): bool;

    public function delete(string $path): bool;

    /** @return list<string> names of the files directly in $dir; empty when it cannot be read */
    public function files(string $dir): array;

    public function mtime(string $path): ?int;

    public function ensureDir(string $path): bool;
}
