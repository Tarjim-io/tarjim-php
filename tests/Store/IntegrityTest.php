<?php

declare(strict_types=1);

namespace Tarjim\Tests\Store;

use Tarjim\RefreshResult;
use Tarjim\Tests\Support\FaultyDisk;
use Tarjim\Tests\Support\StoreCase;
use Tarjim\Wire\Response;

/** What is held survives every way a refresh can go wrong, and nothing unverified is ever installed. */
final class IntegrityTest extends StoreCase
{
    /** @return array<string, string> */
    private function installSeven(): array
    {
        $this->serve($this->release('7'));
        self::assertSame(RefreshResult::UPDATED, $this->refresh()->status);
        $this->laterBy(61);

        return $this->storeFiles();
    }

    private function assertStillSeven(array $before): void
    {
        $after = $this->storeFiles();
        self::assertSame($before['current.json'], $after['current.json']);
        self::assertSame('7', $this->client()->status()->releaseId);
        foreach ($before as $name => $bytes) {
            if (strpos($name, 'objects/') === 0) {
                self::assertSame($bytes, $after[$name] ?? null, $name . ' was lost');
            }
        }
    }

    public function testAFileThatFailsItsHashIsNeitherKeptNorInstalled(): void
    {
        $before = $this->installSeven();
        $next = $this->release('8', ['hello' => 'Hi'], ['hello' => 'Salut']);
        $this->serve($next);
        foreach ($next->files as $hash => $bytes) {
            $this->fake->on(self::BASE . 'released/slices/' . $hash . '.json', new Response(200, [], strtr($bytes, ['Hi' => 'Ho'])));
        }
        $result = $this->refresh();

        self::assertSame(RefreshResult::FAILED, $result->status);
        self::assertTrue($result->copyHeld);
        $this->assertStillSeven($before);
        foreach ($this->storeFiles() as $name => $bytes) {
            if (preg_match('#^objects/([0-9a-f]{64})\.json$#', $name, $m) === 1) {
                self::assertSame($m[1], hash('sha256', $bytes), $name . ' holds other bytes than its name');
            }
        }
    }

    public function testAManifestThatFailsItsChecksumIsNotInstalled(): void
    {
        $before = $this->installSeven();
        $next = $this->release('8', ['hello' => 'Hi']);
        $this->serve($next);
        $this->fake->on(self::BASE . 'released/8/manifest', new Response(200, [], str_replace('"en"', '"de"', $next->manifestBytes())));

        self::assertSame(RefreshResult::FAILED, $this->refresh()->status);
        $this->assertStillSeven($before);
    }

    public function testAReleaseWithOneFileMissingIsNotInstalledAtAll(): void
    {
        $before = $this->installSeven();
        $next = $this->release('8', ['hello' => 'Hi'], ['hello' => 'Salut']);
        $this->serve($next);
        $hashes = array_keys($next->files);
        $this->fake->on(self::BASE . 'released/slices/' . $hashes[1] . '.json', new Response(503));
        $result = $this->refresh();

        self::assertSame(RefreshResult::FAILED, $result->status);
        $this->assertStillSeven($before);
    }

    public function testAFullDiskWhileWritingFilesLeavesTheCopyAsItWas(): void
    {
        $before = $this->installSeven();
        $disk = new FaultyDisk();
        $disk->failPutsMatching = '#/objects/#';
        $this->serve($this->release('8', ['hello' => 'Hi'], ['hello' => 'Salut']));
        $result = $this->refresh([], ['disk' => $disk]);

        self::assertSame(RefreshResult::FAILED, $result->status);
        self::assertTrue($result->copyHeld);
        $this->assertStillSeven($before);
    }

    public function testAFullDiskWhileWritingThePointerLeavesTheCopyAsItWas(): void
    {
        $before = $this->installSeven();
        $disk = new FaultyDisk();
        $disk->failPutsMatching = '#/current\.json$#';
        $this->serve($this->release('8', ['hello' => 'Hi'], ['hello' => 'Salut']));
        $result = $this->refresh([], ['disk' => $disk]);

        self::assertSame(RefreshResult::FAILED, $result->status);
        $this->assertStillSeven($before);
    }

    public function testThePointerIsWrittenLast(): void
    {
        $this->installSeven();
        $disk = new FaultyDisk();
        $this->serve($this->release('8', ['hello' => 'Hi'], ['hello' => 'Salut']));
        $this->refresh([], ['disk' => $disk]);
        $current = array_keys(array_filter($disk->puts, static function (string $p): bool {
            return substr($p, -strlen('/current.json')) === '/current.json';
        }));

        self::assertCount(1, $current);
        $after = array_slice($disk->puts, $current[0] + 1);
        foreach ($after as $path) {
            self::assertDoesNotMatchRegularExpression('#/(objects|releases)/#', $path, 'written after the pointer');
        }
    }

    public function testAnUnknownMetaSchemaKeepsWhatIsHeld(): void
    {
        $before = $this->installSeven();
        $this->serve($this->release('8', ['hello' => 'Hi']), ['schemaVersion' => 2]);
        $result = $this->refresh();

        self::assertSame(RefreshResult::FAILED, $result->status);
        $this->assertStillSeven($before);
    }

    public function testAnUnknownManifestSchemaKeepsWhatIsHeld(): void
    {
        $before = $this->installSeven();
        $next = $this->release('8', ['hello' => 'Hi']);
        $next->schemaVersion = 2;
        $this->serve($next);
        $result = $this->refresh();

        self::assertSame(RefreshResult::FAILED, $result->status);
        $this->assertStillSeven($before);
    }

    public function testAManifestWithNoJsonFileKeepsWhatIsHeld(): void
    {
        $before = $this->installSeven();
        $next = (new \Tarjim\Tests\Support\ReleaseBuilder('8'));
        $this->fake->on(self::DELIVERY, \Tarjim\Tests\Support\ReleaseBuilder::metaResponse($next->meta()));
        $this->fake->on(self::BASE . 'released/8/manifest', new Response(200, [], $next->manifestBytes()));
        $result = $this->refresh();

        self::assertSame(RefreshResult::FAILED, $result->status);
        $this->assertStillSeven($before);
    }

    public function testAFailureWithNothingHeldReportsNoCopy(): void
    {
        $this->fake->on(self::DELIVERY, new Response(503));
        $result = $this->refresh();

        self::assertSame(RefreshResult::FAILED, $result->status);
        self::assertFalse($result->copyHeld);
        self::assertNull($this->client()->status()->releaseId);
    }

    public function testACopyMissingAnObjectIsNotUsable(): void
    {
        $this->installSeven();
        foreach (glob($this->dir . '/objects/*.json') ?: [] as $file) {
            unlink($file);
            break;
        }
        $this->fake->on(self::DELIVERY, new Response(503));
        $result = $this->refresh();

        self::assertFalse($result->copyHeld);
    }
}
