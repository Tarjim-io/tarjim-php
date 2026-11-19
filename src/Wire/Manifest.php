<?php

declare(strict_types=1);

namespace Tarjim\Wire;

final class Manifest
{
    /** @var int */
    public $schemaVersion;

    /** @var array<string, array{type: string, name: string, layout: string|null}> by bundle id */
    public $bundles;

    /** @var array<string, array<string, FileRef>> bundle id => language => the json file */
    public $files;

    /**
     * @param array<string, array{type: string, name: string, layout: string|null}> $bundles
     * @param array<string, array<string, FileRef>> $files
     */
    public function __construct(int $schemaVersion, array $bundles, array $files)
    {
        $this->schemaVersion = $schemaVersion;
        $this->bundles = $bundles;
        $this->files = $files;
    }

    /** Lenient: an entry that is not understood is dropped; null only when the whole is unusable. */
    public static function decode(string $bytes): ?self
    {
        throw new \LogicException('not implemented');
    }
}
