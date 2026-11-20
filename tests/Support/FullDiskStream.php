<?php

declare(strict_types=1);

namespace Tarjim\Tests\Support;

/**
 * A stream wrapper over a real directory that accepts only $capacity bytes per file, as a full disk
 * does: fwrite writes what fits and reports the short count. Paths are fulldisk://<real path>.
 */
final class FullDiskStream
{
    /** @var int */
    public static $capacity = PHP_INT_MAX;

    /** @var resource|null */
    public $context;

    /** @var resource|null */
    private $handle;

    /** @var int */
    private $written = 0;

    public static function register(): void
    {
        if (!in_array('fulldisk', stream_get_wrappers(), true)) {
            stream_wrapper_register('fulldisk', self::class);
        }
    }

    private static function real(string $path): string
    {
        return substr($path, strlen('fulldisk://'));
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$opened): bool
    {
        $handle = @fopen(self::real($path), $mode);
        if ($handle === false) {
            return false;
        }
        $this->handle = $handle;

        return true;
    }

    public function stream_write(string $data): int
    {
        $room = max(0, self::$capacity - $this->written);
        $part = substr($data, 0, $room);
        $count = $part === '' ? 0 : (int) fwrite($this->handle, $part);
        $this->written += $count;

        return $count;
    }

    public function stream_read(int $count): string
    {
        return (string) fread($this->handle, $count);
    }

    public function stream_eof(): bool
    {
        return feof($this->handle);
    }

    public function stream_close(): void
    {
        fclose($this->handle);
    }

    public function stream_flush(): bool
    {
        return fflush($this->handle);
    }

    /** @return array<int|string, int>|false */
    public function stream_stat()
    {
        return fstat($this->handle);
    }

    /** @return array<int|string, int>|false */
    public function url_stat(string $path, int $flags)
    {
        $real = self::real($path);

        return file_exists($real) ? stat($real) : false;
    }

    public function rename(string $from, string $to): bool
    {
        return rename(self::real($from), self::real($to));
    }

    public function unlink(string $path): bool
    {
        return @unlink(self::real($path));
    }

    public function mkdir(string $path, int $mode, int $options): bool
    {
        return @mkdir(self::real($path), $mode, ($options & STREAM_MKDIR_RECURSIVE) !== 0);
    }

    /** @var list<string> */
    private $entries = [];

    public function dir_opendir(string $path, int $options): bool
    {
        $entries = @scandir(self::real($path));
        if ($entries === false) {
            return false;
        }
        $this->entries = $entries;

        return true;
    }

    /** @return string|false */
    public function dir_readdir()
    {
        $entry = array_shift($this->entries);

        return $entry === null ? false : $entry;
    }

    public function dir_closedir(): bool
    {
        return true;
    }

    public function dir_rewinddir(): bool
    {
        return true;
    }

    public function stream_metadata(string $path, int $option, $value): bool
    {
        if ($option === STREAM_META_ACCESS) {
            return chmod(self::real($path), (int) $value);
        }
        if ($option === STREAM_META_TOUCH) {
            return touch(self::real($path));
        }

        return false;
    }
}
