<?php

declare(strict_types=1);

namespace Tarjim\Tests\Export;

use Tarjim\Export\ExportResult;
use Tarjim\Tests\Support\ZipBuilder;

/** A zip from the server is refused whole if any entry could write outside the target. */
final class ZipSafetyTest extends ExportCase
{
    private function pullZip(ZipBuilder $zip, array $options = []): ExportResult
    {
        $this->serveExport($zip->bytes());

        return $this->pull($options);
    }

    /** @return iterable<string, array{string}> */
    public function escapingNames(): iterable
    {
        yield 'parent' => ['../escape.php'];
        yield 'nested parent' => ['en/../../escape.php'];
        yield 'absolute' => ['/tmp/tarjim-escape.php'];
        yield 'backslash parent' => ['..\\escape.php'];
        yield 'drive letter' => ['C:/escape.php'];
        yield 'our own list' => ['.tarjim-export.json'];
    }

    /** @dataProvider escapingNames */
    public function testAnEntryThatWouldLeaveTheTargetRefusesTheWholeZip(string $name): void
    {
        $result = $this->pullZip((new ZipBuilder())->file('en/ok.php', 'OK')->file($name, 'EVIL'));

        self::assertSame(ExportResult::FAILED, $result->status);
        self::assertSame([], $this->outFiles());
        self::assertFileDoesNotExist($this->root . '/escape.php');
        self::assertFileDoesNotExist('/tmp/tarjim-escape.php');
    }

    public function testASymlinkEntryRefusesTheWholeZip(): void
    {
        $result = $this->pullZip((new ZipBuilder())->file('en/ok.php', 'OK')->symlink('en/link.php', '/etc/passwd'));

        self::assertSame(ExportResult::FAILED, $result->status);
        self::assertSame([], $this->outFiles());
    }

    public function testAZipLargerThanTheCapOnceUnpackedIsRefused(): void
    {
        $result = $this->pullZip((new ZipBuilder())->file('en/big.php', str_repeat('a', 2 * 1024 * 1024)), ['maxBytes' => 1024 * 1024]);

        self::assertSame(ExportResult::FAILED, $result->status);
        self::assertSame([], $this->outFiles());
    }

    public function testDirectoryEntriesAreFine(): void
    {
        $result = $this->pullZip((new ZipBuilder())->dir('en/')->file('en/ok.php', 'OK'));

        self::assertSame(ExportResult::UPDATED, $result->status, $result->message);
        self::assertSame('OK', $this->outFiles()['en/ok.php'] ?? null);
    }

    public function testASymlinkInTheTargetIsNotFollowed(): void
    {
        mkdir($this->out, 0755, true);
        mkdir($this->root . '/elsewhere', 0755, true);
        symlink($this->root . '/elsewhere', $this->out . '/en');
        $result = $this->pullZip((new ZipBuilder())->file('en/ok.php', 'OK'));

        self::assertSame(ExportResult::FAILED, $result->status);
        self::assertFileDoesNotExist($this->root . '/elsewhere/ok.php');
    }

    public function testDotSegmentsThatStayInsideAreStillRefused(): void
    {
        $result = $this->pullZip((new ZipBuilder())->file('en/./a.php', 'A'));

        self::assertSame(ExportResult::FAILED, $result->status);
    }

    public function testANulInAnEntryNameNeverReachesTheDisk(): void
    {
        // libzip reports a NUL in a stored name as a space, so the entry may install under that name.
        $result = $this->pullZip((new ZipBuilder())->file('en/ok.php', 'OK')->rawName("en/a.php\0.txt", 'X'));

        foreach (array_keys($this->outFiles()) as $name) {
            self::assertStringNotContainsString("\0", $name);
        }
        self::assertContains($result->status, [ExportResult::FAILED, ExportResult::UPDATED]);
        self::assertSame(ExportResult::UPDATED === $result->status ? 'OK' : null, $this->outFiles()['en/ok.php'] ?? null);
    }
}
