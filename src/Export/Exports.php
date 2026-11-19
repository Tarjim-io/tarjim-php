<?php

declare(strict_types=1);

namespace Tarjim\Export;

final class Exports
{
    /** @param array<string, mixed> $options */
    public function pull(int $projectId, int $bundleId, string $intoDir, array $options = []): ExportResult
    {
        throw new \LogicException('not implemented');
    }
}
