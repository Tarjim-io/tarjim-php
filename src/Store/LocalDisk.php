<?php

declare(strict_types=1);

namespace Tarjim\Store;

final class LocalDisk implements Disk
{
    public function read(string $path): ?string
    {
        throw new \LogicException('not implemented');
    }

    public function put(string $path, string $bytes): bool
    {
        throw new \LogicException('not implemented');
    }

    public function delete(string $path): bool
    {
        throw new \LogicException('not implemented');
    }

    public function files(string $dir): array
    {
        throw new \LogicException('not implemented');
    }

    public function mtime(string $path): ?int
    {
        throw new \LogicException('not implemented');
    }

    public function ensureDir(string $path): bool
    {
        throw new \LogicException('not implemented');
    }
}
