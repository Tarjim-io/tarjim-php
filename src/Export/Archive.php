<?php

declare(strict_types=1);

namespace Tarjim\Export;

/**
 * A downloaded zip, inspected whole before anything is read out of it. Entries are never
 * extracted by the library: each is streamed to a file we name, under a byte budget.
 */
final class Archive
{
    private const MAX_ENTRIES = 10000;
    private const TYPE_MASK = 0170000;
    private const TYPE_FILE = 0100000;
    private const TYPE_DIR = 0040000;

    /** @var \ZipArchive */
    private $zip;

    /** @var string */
    private $tempPath;

    /** @var array<string, string> safe path => entry name, files only */
    private $files = [];

    /** @var int */
    private $budget;

    private function __construct(\ZipArchive $zip, string $tempPath, int $budget)
    {
        $this->zip = $zip;
        $this->tempPath = $tempPath;
        $this->budget = $budget;
    }

    /** @throws Refusal when the zip cannot be opened or any entry is unsafe */
    public static function open(string $bytes, int $maxBytes): self
    {
        if (!class_exists('ZipArchive')) {
            throw new Refusal('unpacking an export needs the zip extension');
        }
        $path = @tempnam(sys_get_temp_dir(), 'tarjim-export-');
        if ($path === false) {
            throw new Refusal('a temporary file for the download could not be created');
        }
        $zip = new \ZipArchive();
        try {
            if (@file_put_contents($path, $bytes) !== strlen($bytes) || $zip->open($path) !== true) {
                throw new Refusal('the download is not a readable zip');
            }
            $archive = new self($zip, $path, $maxBytes);
            $archive->inspect($maxBytes);
        } catch (\Throwable $e) {
            @$zip->close();
            @unlink($path);
            throw $e instanceof Refusal ? $e : new Refusal('the download could not be read as a zip');
        }

        return $archive;
    }

    /** @return list<string> */
    public function paths(): array
    {
        return array_keys($this->files);
    }

    /** @throws Refusal */
    public function copy(string $path, string $to): void
    {
        $in = $this->zip->getStream($this->files[$path]);
        $out = $in === false ? false : @fopen($to, 'xb');
        if ($in === false || $out === false) {
            if (is_resource($in)) {
                fclose($in);
            }
            throw new Refusal('an entry of the export could not be read');
        }
        try {
            while (!feof($in)) {
                $chunk = fread($in, 65536);
                if ($chunk === false) {
                    throw new Refusal('an entry of the export could not be read');
                }
                $this->budget -= strlen($chunk);
                if ($this->budget < 0) {
                    throw new Refusal('the export is larger than maxBytes once unpacked');
                }
                if ($chunk !== '' && @fwrite($out, $chunk) !== strlen($chunk)) {
                    throw new Refusal('the export could not be written (is the disk full?)');
                }
            }
        } finally {
            fclose($in);
            fclose($out);
        }
    }

    public function close(): void
    {
        @$this->zip->close();
        @unlink($this->tempPath);
    }

    /** @throws Refusal */
    private function inspect(int $maxBytes): void
    {
        $count = $this->zip->numFiles;
        if ($count > self::MAX_ENTRIES) {
            throw new Refusal('the export has more than ' . self::MAX_ENTRIES . ' entries');
        }
        $total = 0;
        $seen = [];
        for ($i = 0; $i < $count; $i++) {
            $stat = $this->zip->statIndex($i);
            if ($stat === false) {
                throw new Refusal('the export has an unreadable entry');
            }
            $name = (string) $stat['name'];
            $isDir = substr($name, -1) === '/';
            $path = $isDir ? substr($name, 0, -1) : $name;
            if (!Paths::isSafe($path)) {
                throw new Refusal('the export has an entry whose name could leave the target folder');
            }
            if (isset($seen[$path])) {
                throw new Refusal('the export names the same path twice');
            }
            $seen[$path] = true;
            $opsys = 0;
            $attributes = 0;
            if ($this->zip->getExternalAttributesIndex($i, $opsys, $attributes)) {
                $type = $opsys === \ZipArchive::OPSYS_UNIX ? (($attributes >> 16) & self::TYPE_MASK) : 0;
                if ($type !== 0 && $type !== self::TYPE_FILE && $type !== self::TYPE_DIR) {
                    throw new Refusal('the export has a link or special file');
                }
            }
            if ($isDir) {
                continue;
            }
            $total += (int) $stat['size'];
            if ($total > $maxBytes) {
                throw new Refusal('the export is larger than maxBytes once unpacked');
            }
            $this->files[$path] = $name;
        }
    }
}
