<?php

declare(strict_types=1);

namespace Tarjim\Tests\Store;

use Tarjim\RefreshResult;
use Tarjim\Tests\Support\StoreCase;
use Tarjim\Wire\Response;

final class InstallTest extends StoreCase
{
    public function testAFirstRefreshInstallsTheRelease(): void
    {
        $release = $this->release();
        $this->serve($release);
        $result = $this->refresh();

        self::assertSame(RefreshResult::UPDATED, $result->status);
        self::assertTrue($result->copyHeld);
        $status = $this->client()->status();
        self::assertSame('7', $status->releaseId);
        self::assertSame('dst_test', $status->distribution);
        self::assertSame('dev', $status->stage);
        self::assertSame($this->clock->now, $status->installedAt);
        self::assertSame($this->clock->now, $status->lastSuccessAt);
        self::assertFalse($status->stale);
    }

    public function testKeepsEachVerifiedFileUnderItsHash(): void
    {
        $release = $this->release();
        $this->serve($release);
        $this->refresh();
        $files = $this->storeFiles();

        foreach ($release->files as $hash => $bytes) {
            self::assertSame($bytes, $files['objects/' . $hash . '.json'] ?? null);
        }
        self::assertArrayHasKey('current.json', $files);
        self::assertArrayHasKey('state.json', $files);
        self::assertSame(1, count(array_filter(array_keys($files), static function (string $name): bool {
            return preg_match('#^releases/7-[0-9a-f]{8}\.json$#', $name) === 1;
        })));
    }

    public function testNamesEveryStoreFileFromHashesAndIdsOnly(): void
    {
        $release = $this->release();
        $this->serve($release);
        $this->refresh();

        foreach (array_keys($this->storeFiles()) as $name) {
            self::assertMatchesRegularExpression('#^(objects/[0-9a-f]{64}\.json|releases/[0-9]+-[0-9a-f]{8}\.json|current\.json|state\.json|lock)$#', $name);
        }
    }

    public function testBundleAndLanguageNamesNeverBecomePaths(): void
    {
        $release = (new \Tarjim\Tests\Support\ReleaseBuilder('7'))
            ->flat('../../escape', 'namespace', '../../escape', '../../../outside', ['hello' => 'Hello'])
            ->flat('ns1', 'namespace', 'default', 'en/../../x', ['hello' => 'Hello']);
        $this->serve($release);
        $this->refresh();

        self::assertSame(['store'], array_values(array_diff(scandir($this->root) ?: [], ['.', '..'])));
        foreach (array_keys($this->storeFiles()) as $name) {
            self::assertMatchesRegularExpression('#^(objects/[0-9a-f]{64}\.json|releases/[0-9]+-[0-9a-f]{8}\.json|current\.json|state\.json|lock)$#', $name);
        }
    }

    public function testTheKeyIsWrittenNowhere(): void
    {
        $this->serve($this->release());
        $this->refresh();
        $this->laterBy(61);
        $this->fake->on(self::DELIVERY, new Response(503));
        $this->refresh();

        foreach ($this->storeFiles() as $name => $bytes) {
            self::assertStringNotContainsString(self::KEY, $bytes, $name);
        }
    }

    public function testFilesAndDirectoriesGetPlainModes(): void
    {
        $previous = umask(0077);
        try {
            $this->serve($this->release());
            $this->refresh();
        } finally {
            umask($previous);
        }

        clearstatcache();
        self::assertSame('0755', substr(sprintf('%o', fileperms($this->dir)), -4));
        self::assertSame('0755', substr(sprintf('%o', fileperms($this->dir . '/objects')), -4));
        foreach (array_keys($this->storeFiles()) as $name) {
            if ($name !== 'lock') {
                self::assertSame('0644', substr(sprintf('%o', fileperms($this->dir . '/' . $name)), -4), $name);
            }
        }
    }

    public function testTheSameReleaseAgainIsUnchangedWithoutReadingTheManifest(): void
    {
        $release = $this->release();
        $this->serve($release);
        $this->refresh();
        $this->takeRequests();
        $this->laterBy(61);
        $result = $this->refresh();

        self::assertSame(RefreshResult::UNCHANGED, $result->status);
        self::assertTrue($result->copyHeld);
        self::assertSame([self::DELIVERY], $this->takeRequests());
        self::assertSame($this->clock->now, $this->client()->status()->lastSuccessAt);
    }

    public function testSendsTheHeldEtagAndTakesNotModifiedAsUnchanged(): void
    {
        $release = $this->release();
        $this->serve($release, [], '"r7-etag"');
        $this->refresh();
        $this->fake->requests = [];
        $this->laterBy(61);
        $this->fake->on(self::DELIVERY, new Response(304, ['etag' => '"r7-etag"']));
        $result = $this->refresh();

        self::assertSame(RefreshResult::UNCHANGED, $result->status);
        $headers = array_change_key_case($this->fake->requests[0]->headers, CASE_LOWER);
        self::assertSame('"r7-etag"', $headers['if-none-match'] ?? null);
        self::assertStringEndsWith('&release=7', $this->fake->requests[0]->url);
    }

    public function testWithNothingHeldNoEtagIsSent(): void
    {
        $this->fake->on(self::DELIVERY, new Response(503));
        $this->refresh();

        $headers = array_change_key_case($this->fake->requests[0]->headers, CASE_LOWER);
        self::assertArrayNotHasKey('if-none-match', $headers);
        self::assertStringEndsWith('&release=0', $this->fake->requests[0]->url);
    }

    public function testANewReleaseFetchesOnlyTheFilesNotHeld(): void
    {
        $this->serve($this->release('7', ['hello' => 'Hello'], ['hello' => 'Bonjour']));
        $this->refresh();
        $this->takeRequests();
        $this->laterBy(61);
        $next = $this->release('8', ['hello' => 'Hello'], ['hello' => 'Salut']);
        $this->serve($next);
        $result = $this->refresh();

        self::assertSame(RefreshResult::UPDATED, $result->status);
        $fetched = array_values(array_filter($this->takeRequests(), static function (string $url): bool {
            return strpos($url, '/released/slices/') !== false;
        }));
        self::assertCount(1, $fetched);
        self::assertSame('8', $this->client()->status()->releaseId);
    }

    public function testARollbackToALowerReleaseIsInstalled(): void
    {
        $this->serve($this->release('8', ['hello' => 'Hi']));
        $this->refresh();
        $this->laterBy(61);
        $this->serve($this->release('7'));
        $result = $this->refresh();

        self::assertSame(RefreshResult::UPDATED, $result->status);
        self::assertSame('7', $this->client()->status()->releaseId);
    }

    public function testAStageWithNoReleaseYetIsUnreleased(): void
    {
        $this->fake->on(self::DELIVERY, new Response(404, [], '{"code":"delivery.stage_unreleased"}'));
        $result = $this->refresh();

        self::assertSame(RefreshResult::UNRELEASED, $result->status);
        self::assertFalse($result->copyHeld);
    }

    /** @return iterable<string, array{int}> */
    public function configurationStatuses(): iterable
    {
        foreach ([400, 401, 403, 404] as $status) {
            yield (string) $status => [$status];
        }
    }

    /** @dataProvider configurationStatuses */
    public function testARefusedKeyOrUrlIsMisconfiguredAndKeepsTheCopy(int $status): void
    {
        $this->serve($this->release());
        $this->refresh();
        $before = $this->storeFiles();
        $this->laterBy(61);
        $this->fake->on(self::DELIVERY, new Response($status, [], '{"code":"delivery.unknown_key"}'));
        $result = $this->refresh();

        self::assertSame(RefreshResult::MISCONFIGURED, $result->status);
        self::assertTrue($result->copyHeld);
        self::assertSame($before['current.json'], $this->storeFiles()['current.json']);
    }

    public function testRefreshNeverThrowsOnACorruptStore(): void
    {
        $this->serve($this->release());
        $this->refresh();
        file_put_contents($this->dir . '/current.json', '{not json');
        file_put_contents($this->dir . '/state.json', "\0\0\0");
        $this->laterBy(61);
        $result = $this->refresh();

        self::assertContains($result->status, [RefreshResult::UPDATED, RefreshResult::UNCHANGED]);
        self::assertSame('7', $this->client()->status()->releaseId);
    }

    public function testAMessageNeverCarriesTheKey(): void
    {
        $this->fake->on(self::DELIVERY, new Response(401, [], (string) json_encode(['code' => 'x', 'detail' => self::KEY])));
        $result = $this->refresh();

        self::assertStringNotContainsString(self::KEY, $result->message);
        self::assertStringNotContainsString(self::KEY, serialize($this->logged));
    }
}
