<?php

declare(strict_types=1);

namespace Tarjim\Tests\Support;

/** Builds zip bytes, including entries an honest server would never send. */
final class ZipBuilder
{
    /** @var list<array{name: string, bytes: string, symlink: bool, dir: bool}> */
    private $entries = [];

    /** @var array<string, string> same-length name swaps applied to the finished bytes */
    private $rawNames = [];

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

    /**
     * An entry whose stored name holds bytes ZipArchive will not write (it cuts a name at NUL): it is
     * added under a same-length stand-in, then the stand-in is swapped in the zip's own bytes. Names
     * are not covered by the entry checksums, so the zip stays readable.
     */
    public function rawName(string $name, string $bytes): self
    {
        $standIn = str_replace("\0", 'Q', $name);
        $this->rawNames[$standIn] = $name;

        return $this->file($standIn, $bytes);
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

        return strtr($bytes, $this->rawNames);
    }
}
