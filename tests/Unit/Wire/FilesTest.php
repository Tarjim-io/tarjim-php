<?php

declare(strict_types=1);

namespace Tarjim\Tests\Unit\Wire;

use Tarjim\Tests\Support\ReleaseBuilder;
use Tarjim\Wire\FileRef;
use Tarjim\Wire\Outcome;
use Tarjim\Wire\Response;

final class FilesTest extends WireCase
{
    /** @return list<FileRef> */
    private static function refs(ReleaseBuilder $release): array
    {
        $refs = [];
        foreach ($release->files as $hash => $bytes) {
            $refs[] = new FileRef($hash, strlen($bytes));
        }

        return $refs;
    }

    private function serveOrigin(ReleaseBuilder $release): void
    {
        foreach ($release->files as $hash => $bytes) {
            $this->fake->on(self::BASE . 'released/slices/' . $hash . '.json', self::ok($bytes));
        }
    }

    public function testOriginModeFetchesEachFileByHashWithTheKey(): void
    {
        $release = $this->release();
        $meta = $this->originMeta($release);
        $this->serveOrigin($release);
        $result = $this->client()->fetchFiles($meta, self::refs($release));

        self::assertCount(2, $result->results);
        foreach ($release->files as $hash => $bytes) {
            self::assertSame(Outcome::VERIFIED, $result->results[$hash]->kind);
            self::assertSame($bytes, $result->results[$hash]->bytes);
        }
        foreach ($this->fake->requests as $request) {
            self::assertMatchesRegularExpression('#^' . preg_quote(self::BASE, '#') . 'released/slices/[0-9a-f]{64}\.json$#', $request->url);
            self::assertSame(self::KEY, array_change_key_case($request->headers, CASE_LOWER)['x-tarjim-apikey'] ?? null);
        }
        self::assertFalse($result->reread);
    }

    public function testCapsEachFileAtItsManifestSize(): void
    {
        $release = $this->release();
        $meta = $this->originMeta($release);
        $this->serveOrigin($release);
        $this->client()->fetchFiles($meta, self::refs($release));

        $limits = array_map(static function ($r): int {
            return $r->limit;
        }, $this->fake->requests);
        sort($limits);
        $sizes = array_map('strlen', array_values($release->files));
        sort($sizes);
        self::assertSame($sizes, $limits);
    }

    public function testAFileLongerThanItsSizeIsASizeMismatch(): void
    {
        $release = $this->release();
        $meta = $this->originMeta($release);
        [$hash, $bytes] = [array_key_first($release->files), reset($release->files)];
        $this->fake->on(self::BASE . 'released/slices/' . $hash . '.json', self::ok($bytes . ' '));
        $result = $this->client()->fetchFiles($meta, [new FileRef((string) $hash, strlen((string) $bytes))]);

        self::assertSame(Outcome::SIZE_MISMATCH, $result->results[$hash]->kind);
        self::assertNull($result->results[$hash]->bytes);
    }

    public function testAFileShorterThanItsSizeIsASizeMismatch(): void
    {
        $release = $this->release();
        $meta = $this->originMeta($release);
        [$hash, $bytes] = [array_key_first($release->files), reset($release->files)];
        $this->fake->on(self::BASE . 'released/slices/' . $hash . '.json', self::ok(substr((string) $bytes, 0, -1)));
        $result = $this->client()->fetchFiles($meta, [new FileRef((string) $hash, strlen((string) $bytes))]);

        self::assertSame(Outcome::SIZE_MISMATCH, $result->results[$hash]->kind);
    }

    public function testAFileWithOtherBytesOfTheRightLengthIsAHashMismatch(): void
    {
        $release = $this->release();
        $meta = $this->originMeta($release);
        [$hash, $bytes] = [array_key_first($release->files), (string) reset($release->files)];
        $tampered = strtr($bytes, ['Hello' => 'Hallo', 'Bonjour' => 'Bonjoor']);
        self::assertSame(strlen($bytes), strlen($tampered));
        $this->fake->on(self::BASE . 'released/slices/' . $hash . '.json', self::ok($tampered));
        $result = $this->client()->fetchFiles($meta, [new FileRef((string) $hash, strlen($bytes))]);

        self::assertSame(Outcome::HASH_MISMATCH, $result->results[$hash]->kind);
        self::assertNull($result->results[$hash]->bytes);
    }

    /** @return iterable<string, array{string}> */
    public function unsafeHashes(): iterable
    {
        yield 'a path' => ['../../etc/passwd'];
        yield 'upper case' => [str_repeat('A', 64)];
        yield 'too short' => [str_repeat('a', 63)];
        yield 'a query' => [str_repeat('a', 60) . '?x=1'];
        yield 'an encoded slash' => [str_repeat('a', 58) . '%2F..'];
    }

    /** @dataProvider unsafeHashes */
    public function testNeverBuildsAUrlFromAnUnsafeHash(string $hash): void
    {
        $meta = $this->originMeta($this->release());
        $result = $this->client()->fetchFiles($meta, [new FileRef($hash, 10)]);

        self::assertSame([], $this->fake->requests);
        self::assertSame(Outcome::REFUSED, $result->results[$hash]->kind);
    }

    public function testRefusesASlicesBaseWithoutATrailingSlash(): void
    {
        $release = $this->release();
        $meta = $this->originMeta($release, ['slicesBaseUrl' => 'released/slices']);
        $result = $this->client()->fetchFiles($meta, self::refs($release));

        self::assertSame([], $this->fake->requests);
        foreach ($result->results as $outcome) {
            self::assertSame(Outcome::REFUSED, $outcome->kind);
        }
    }

    public function testOriginModeRefusesASlicesBaseOnAnotherOrigin(): void
    {
        $release = $this->release();
        $meta = $this->originMeta($release, ['slicesBaseUrl' => 'https://evil.example.test/slices/']);
        $this->client()->fetchFiles($meta, self::refs($release));

        self::assertSame([], $this->fake->requests);
    }

    public function testFetchesAHashListedTwiceOnce(): void
    {
        $release = $this->release();
        $meta = $this->originMeta($release);
        $this->serveOrigin($release);
        $refs = self::refs($release);
        $this->client()->fetchFiles($meta, array_merge($refs, $refs));

        self::assertCount(count($refs), $this->fake->requests);
    }

    public function testCdnModeAppendsTheSignedQueryAndSendsNoTarjimHeader(): void
    {
        $release = $this->release();
        $meta = $this->cdnMeta($release);
        foreach ($release->files as $hash => $bytes) {
            $this->fake->on(self::CDN . 'slices/' . $hash . '.json?' . self::SIGNED, self::ok($bytes));
        }
        $result = $this->client()->fetchFiles($meta, self::refs($release));

        foreach ($result->results as $outcome) {
            self::assertSame(Outcome::VERIFIED, $outcome->kind);
        }
        foreach ($this->headerNamesSentTo(self::CDN) as $name) {
            self::assertStringStartsNotWith('x-tarjim', $name);
        }
    }

    /** @return iterable<string, array{int}> */
    public function staleSignatureStatuses(): iterable
    {
        foreach ([403, 404, 503] as $status) {
            yield (string) $status => [$status];
        }
    }

    /** @dataProvider staleSignatureStatuses */
    public function testCdnModeRereadsMetaOnceAndRetriesWithTheFreshSignature(int $status): void
    {
        $release = $this->release();
        $meta = $this->cdnMeta($release);
        $fresh = 'Expires=1700003600&Signature=fresh&Key-Pair-Id=EXAMPLE';
        foreach ($release->files as $hash => $bytes) {
            $this->fake->on(self::CDN . 'slices/' . $hash . '.json?' . self::SIGNED, new Response($status));
            $this->fake->on(self::CDN . 'slices/' . $hash . '.json?' . $fresh, self::ok($bytes));
        }
        $this->fake->on(self::DELIVERY, ReleaseBuilder::metaResponse($release->meta([
            'authenticated' => false,
            'signedQuery' => $fresh,
            'manifestUrl' => self::CDN . '7/manifest',
            'slicesBaseUrl' => self::CDN . 'slices/',
        ])));
        $result = $this->client()->fetchFiles($meta, self::refs($release));

        self::assertTrue($result->reread);
        self::assertSame($fresh, $result->meta->signedQuery);
        foreach ($result->results as $outcome) {
            self::assertSame(Outcome::VERIFIED, $outcome->kind);
        }
        self::assertCount(1, array_filter($this->fake->urls(), static function (string $u): bool {
            return strpos($u, self::DELIVERY) === 0;
        }));
    }

    public function testCdnModeGivesUpWhenTheRereadMetaNamesAnotherRelease(): void
    {
        $release = $this->release();
        $meta = $this->cdnMeta($release);
        foreach ($release->files as $hash => $bytes) {
            $this->fake->on(self::CDN . 'slices/' . $hash . '.json', new Response(403));
        }
        $this->fake->on(self::DELIVERY, ReleaseBuilder::metaResponse($release->meta([
            'checksum' => str_repeat('c', 64),
            'authenticated' => false,
            'signedQuery' => 'Expires=9&Signature=moved',
            'manifestUrl' => self::CDN . '8/manifest',
            'slicesBaseUrl' => self::CDN . 'slices/',
        ])));
        $result = $this->client()->fetchFiles($meta, self::refs($release));

        self::assertTrue($result->reread);
        foreach ($result->results as $outcome) {
            self::assertSame(Outcome::UNFETCHABLE, $outcome->kind);
        }
        self::assertCount(count($release->files) + 1, $this->fake->requests);
    }

    public function testCdnModeRereadsMetaAtMostOncePerCall(): void
    {
        $release = $this->release();
        $meta = $this->cdnMeta($release);
        foreach ($release->files as $hash => $bytes) {
            $this->fake->on(self::CDN . 'slices/' . $hash . '.json', new Response(403));
        }
        $this->fake->on(self::DELIVERY, ReleaseBuilder::metaResponse($release->meta([
            'authenticated' => false,
            'signedQuery' => 'Expires=10&Signature=still-bad',
            'manifestUrl' => self::CDN . '7/manifest',
            'slicesBaseUrl' => self::CDN . 'slices/',
        ])));
        $this->client()->fetchFiles($meta, self::refs($release));

        self::assertCount(1, array_filter($this->fake->urls(), static function (string $u): bool {
            return strpos($u, self::DELIVERY) === 0;
        }));
    }

    public function testOriginModeNeverRereadsMeta(): void
    {
        $release = $this->release();
        $meta = $this->originMeta($release);
        foreach ($release->files as $hash => $bytes) {
            $this->fake->on(self::BASE . 'released/slices/' . $hash . '.json', new Response(403));
        }
        $result = $this->client()->fetchFiles($meta, self::refs($release));

        self::assertFalse($result->reread);
        self::assertCount(count($release->files), $this->fake->requests);
    }

    public function testAFailedFileIsReportedByKind(): void
    {
        $release = $this->release();
        $meta = $this->originMeta($release);
        $hashes = array_keys($release->files);
        $this->fake->on(self::BASE . 'released/slices/' . $hashes[0] . '.json', new Response(429, ['retry-after' => '5']));
        $this->fake->on(self::BASE . 'released/slices/' . $hashes[1] . '.json', new Response(500));
        $result = $this->client()->fetchFiles($meta, self::refs($release));

        self::assertSame(Outcome::THROTTLED, $result->results[$hashes[0]]->kind);
        self::assertSame(5, $result->results[$hashes[0]]->retryAfter);
        self::assertSame(Outcome::SERVER, $result->results[$hashes[1]]->kind);
    }

    public function testAfterTheReleaseMovedTheResultKeepsTheMetaItWasAskedFor(): void
    {
        $release = $this->release();
        $meta = $this->cdnMeta($release);
        foreach ($release->files as $hash => $bytes) {
            $this->fake->on(self::CDN . 'slices/' . $hash . '.json', new Response(403));
        }
        $this->fake->on(self::DELIVERY, ReleaseBuilder::metaResponse($release->meta([
            'checksum' => str_repeat('c', 64),
            'releaseId' => '8',
            'authenticated' => false,
            'signedQuery' => 'Expires=9&Signature=moved',
            'manifestUrl' => self::CDN . '8/manifest',
            'slicesBaseUrl' => self::CDN . 'slices/',
        ])));
        $result = $this->client()->fetchFiles($meta, self::refs($release));

        self::assertSame('7', $result->meta->releaseId);
        self::assertSame($release->checksum(), $result->meta->checksum);
    }

    public function testTheRereadNamesTheReleaseHeldNotTheOneBeingFetched(): void
    {
        $release = $this->release();
        $client = $this->client();
        $this->fake->on(self::DELIVERY, ReleaseBuilder::metaResponse($release->meta([
            'authenticated' => false,
            'signedQuery' => self::SIGNED,
            'manifestUrl' => self::CDN . '7/manifest',
            'slicesBaseUrl' => self::CDN . 'slices/',
        ])));
        $meta = $client->fetchMeta('5', '"r5-x"')->meta;
        self::assertNotNull($meta);
        foreach ($release->files as $hash => $bytes) {
            $this->fake->on(self::CDN . 'slices/' . $hash . '.json', new Response(403));
        }
        $client->fetchFiles($meta, self::refs($release));
        $metaReads = array_values(array_filter($this->fake->urls(), static function (string $u): bool {
            return strpos($u, self::DELIVERY) === 0;
        }));

        self::assertCount(2, $metaReads);
        self::assertStringEndsWith('&release=5', $metaReads[1]);
    }
}
