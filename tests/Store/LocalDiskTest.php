<?php

declare(strict_types=1);

namespace Tarjim\Tests\Store;

use PHPUnit\Framework\TestCase;
use Tarjim\Store\LocalDisk;
use Tarjim\Tests\Support\FullDiskStream;
use Tarjim\Tests\Support\StoreCase;

final class LocalDiskTest extends TestCase
{
    /** @var string */
    private $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/tarjim-disk-test-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0755, true);
        FullDiskStream::register();
        FullDiskStream::$capacity = PHP_INT_MAX;
    }

    protected function tearDown(): void
    {
        FullDiskStream::$capacity = PHP_INT_MAX;
        StoreCase::removeTree($this->dir);
    }

    public function testPutReplacesTheWholeFile(): void
    {
        $disk = new LocalDisk();
        self::assertTrue($disk->put($this->dir . '/a.json', 'old'));
        self::assertTrue($disk->put($this->dir . '/a.json', 'new contents'));

        self::assertSame('new contents', $disk->read($this->dir . '/a.json'));
        self::assertSame(['a.json'], $disk->files($this->dir));
    }

    public function testAShortWriteLeavesTheOldFileAndNoTempFile(): void
    {
        $disk = new LocalDisk();
        $path = 'fulldisk://' . $this->dir . '/a.json';
        self::assertTrue($disk->put($path, 'old'));
        FullDiskStream::$capacity = 5;

        self::assertFalse($disk->put($path, 'much longer new contents'));
        self::assertSame('old', file_get_contents($this->dir . '/a.json'));
        self::assertSame(['a.json'], array_values(array_diff(scandir($this->dir) ?: [], ['.', '..'])));
    }

    public function testTheTempFileSitsBesideItsTarget(): void
    {
        $disk = new LocalDisk();
        $path = 'fulldisk://' . $this->dir . '/a.json';
        FullDiskStream::$capacity = 0;
        mkdir($this->dir . '/other');
        $disk->put($path, 'x');

        self::assertSame([], array_values(array_diff(scandir($this->dir . '/other') ?: [], ['.', '..'])));
    }

    public function testReadOfAMissingFileIsNull(): void
    {
        self::assertNull((new LocalDisk())->read($this->dir . '/missing.json'));
    }

    public function testFilesOfAMissingDirIsEmpty(): void
    {
        self::assertSame([], (new LocalDisk())->files($this->dir . '/missing'));
    }

    public function testEnsureDirMakesParents(): void
    {
        $disk = new LocalDisk();

        self::assertTrue($disk->ensureDir($this->dir . '/x/y'));
        self::assertDirectoryExists($this->dir . '/x/y');
    }

    public function testNothingThrows(): void
    {
        $disk = new LocalDisk();
        self::assertFalse($disk->put('/nonexistent-root-dir-' . bin2hex(random_bytes(4)) . '/a.json', 'x'));
        self::assertFalse($disk->delete($this->dir . '/missing.json'));
        self::assertNull($disk->mtime($this->dir . '/missing.json'));
        self::assertFalse($disk->ensureDir('/proc/forbidden-' . bin2hex(random_bytes(4)) . '/x'));
    }
}
