<?php

declare(strict_types=1);

namespace Tarjim\Tests\Store;

use Tarjim\RefreshResult;
use Tarjim\Tests\Support\StoreCase;

final class MisconfiguredStoreTest extends StoreCase
{
    /** @return iterable<string, array{string}> */
    public function relativeDirs(): iterable
    {
        yield 'bare name' => ['storage/tarjim'];
        yield 'dot' => ['./storage/tarjim'];
        yield 'dot dot' => ['../tarjim'];
    }

    /** @dataProvider relativeDirs */
    public function testARelativeDirIsMisconfigured(string $dir): void
    {
        $this->serve($this->release());
        $result = $this->refresh([], ['dir' => $dir]);

        self::assertSame(RefreshResult::MISCONFIGURED, $result->status);
        self::assertSame([], $this->fake->requests);
    }

    public function testAStoreTheUserCannotWriteIsMisconfiguredBeforeAnyCall(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::fail('run this suite as a user other than root: root can write any directory');
        }
        mkdir($this->dir, 0755, true);
        chmod($this->dir, 0555);
        $this->serve($this->release());
        $result = $this->refresh();
        chmod($this->dir, 0755);

        self::assertSame(RefreshResult::MISCONFIGURED, $result->status);
        self::assertSame([], $this->fake->requests);
    }

    public function testADirThatCannotBeCreatedIsMisconfigured(): void
    {
        file_put_contents($this->root . '/a-file', 'x');
        $this->serve($this->release());
        $result = $this->refresh([], ['dir' => $this->root . '/a-file/store']);

        self::assertSame(RefreshResult::MISCONFIGURED, $result->status);
        self::assertSame([], $this->fake->requests);
    }

    /** @return iterable<string, array{array<string, string>}> */
    public function otherCopies(): iterable
    {
        yield 'another distribution' => [['distribution' => 'dst_other']];
        yield 'another stage' => [['stage' => 'prod']];
    }

    /**
     * @dataProvider otherCopies
     * @param array<string, string> $other
     */
    public function testAStoreHoldingAnotherDistributionIsMisconfiguredAndKeepsServing(array $other): void
    {
        $this->serve($this->release());
        $this->refresh();
        $before = $this->storeFiles();
        $this->laterBy(61);
        $this->serve($this->release('8', ['hello' => 'Hi']), $other);
        $result = $this->refresh();

        self::assertSame(RefreshResult::MISCONFIGURED, $result->status);
        self::assertTrue($result->copyHeld);
        self::assertSame($before['current.json'], $this->storeFiles()['current.json']);
        self::assertSame('7', $this->client()->status()->releaseId);
    }
}
