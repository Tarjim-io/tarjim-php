<?php

declare(strict_types=1);

namespace Tarjim\Tests\Support;

/** Builds zip bytes, including entries an honest server would never send. */
final class ZipBuilder
{
    /** @var list<array{name: string, bytes: string, symlink: bool, dir: bool}> */
    private $entries = [];

    public function file(string $name, string $bytes): self
    {
        $this->entries[] = ['name' => $name, 'bytes' => $bytes, 'symlink' => false, 'dir' => false];

        return $this;
    }

    public function dir(string $name): self
    {
        $this->entries[] = ['name' => $name, 'bytes' => '', 'symlink' => false, 'dir' => true];

        return $this;
    }

    /** A Unix symlink entry pointing at $target. */
    public function symlink(string $name, string $target): self
    {
        $this->entries[] = ['name' => $name, 'bytes' => $target, 'symlink' => true, 'dir' => false];

        return $this;
    }

    public function bytes(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'tarjim-zip-');
        $zip = new \ZipArchive();
        $zip->open((string) $path, \ZipArchive::OVERWRITE);
        foreach ($this->entries as $entry) {
            if ($entry['dir']) {
                $zip->addEmptyDir($entry['name']);
                continue;
            }
            $zip->addFromString($entry['name'], $entry['bytes']);
            if ($entry['symlink']) {
                $zip->setExternalAttributesName($entry['name'], \ZipArchive::OPSYS_UNIX, (0120777 << 16));
            }
        }
        $zip->close();
        $bytes = (string) file_get_contents((string) $path);
        unlink((string) $path);

        return $bytes;
    }
}
