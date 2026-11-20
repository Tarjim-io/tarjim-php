<?php

declare(strict_types=1);

namespace Tarjim\Tests\Unit\Wire;

use Tarjim\Tests\Support\ReleaseBuilder;
use Tarjim\Wire\Manifest;
use Tarjim\Wire\Outcome;
use Tarjim\Wire\Response;

final class ManifestTest extends WireCase
{
    public function testOriginModeResolvesAgainstTheDeliveryUrlAndSendsTheKey(): void
    {
        $release = $this->release();
        $meta = $this->originMeta($release);
        $this->fake->on(self::BASE . 'released/7/manifest', self::ok($release->manifestBytes()));
        $outcome = $this->client()->fetchManifest($meta);

        self::assertSame(Outcome::VERIFIED, $outcome->kind);
        self::assertSame([self::BASE . 'released/7/manifest'], $this->fake->urls());
        $headers = array_change_key_case($this->fake->requests[0]->headers, CASE_LOWER);
        self::assertSame(self::KEY, $headers['x-tarjim-apikey'] ?? null);
        self::assertSame('2026-07-29', $headers['x-tarjim-api-version'] ?? null);
        self::assertSame($release->manifestBytes(), $outcome->bytes);
        self::assertNotNull($outcome->manifest);
    }

    public function testOriginModeAcceptsAnAbsoluteUrlOnTheSameOrigin(): void
    {
        $release = $this->release();
        $meta = $this->originMeta($release, ['manifestUrl' => 'https://delivery.example.test/elsewhere/manifest']);
        $this->fake->on('https://delivery.example.test/elsewhere/manifest', self::ok($release->manifestBytes()));

        self::assertSame(Outcome::VERIFIED, $this->client()->fetchManifest($meta)->kind);
    }

    /** @return iterable<string, array{string}> */
    public function otherOrigins(): iterable
    {
        yield 'another host' => ['https://evil.example.test/released/7/manifest'];
        yield 'protocol relative' => ['//evil.example.test/released/7/manifest'];
        yield 'another port' => ['https://delivery.example.test:8443/released/7/manifest'];
        yield 'plain http on the same host' => ['http://delivery.example.test/released/7/manifest'];
        yield 'a host that extends ours' => ['https://delivery.example.test.evil.example/released/7/manifest'];
        yield 'credentials then another host' => ['https://delivery.example.test@evil.example.test/released/7/manifest'];
        yield 'backslashes' => ['\\\\evil.example.test/released/7/manifest'];
        yield 'another scheme' => ['file:///etc/passwd'];
        yield 'javascript' => ['javascript:alert(1)'];
        yield 'empty' => [''];
    }

    /** @dataProvider otherOrigins */
    public function testOriginModeNeverSendsTheKeyToAnotherOrigin(string $reference): void
    {
        $meta = $this->originMeta($this->release(), ['manifestUrl' => $reference]);
        $outcome = $this->client()->fetchManifest($meta);

        self::assertSame(Outcome::REFUSED, $outcome->kind);
        foreach ($this->fake->requests as $request) {
            self::assertStringStartsWith('https://delivery.example.test/', $request->url);
        }
    }

    public function testCdnModeAppendsTheSignedQueryAndSendsNoTarjimHeader(): void
    {
        $release = $this->release();
        $meta = $this->cdnMeta($release);
        $this->fake->on(self::CDN . '7/manifest?' . self::SIGNED, self::ok($release->manifestBytes()));
        $outcome = $this->client()->fetchManifest($meta);

        self::assertSame(Outcome::VERIFIED, $outcome->kind);
        self::assertSame([self::CDN . '7/manifest?' . self::SIGNED], $this->fake->urls());
        foreach ($this->headerNamesSentTo(self::CDN) as $name) {
            self::assertStringStartsNotWith('x-tarjim', $name);
        }
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public function unsafeCdnMetas(): iterable
    {
        yield 'plain http' => [['manifestUrl' => 'http://cdn.example.test/r/7/manifest']];
        yield 'relative' => [['manifestUrl' => 'r/7/manifest']];
        yield 'a query already' => [['manifestUrl' => self::CDN . '7/manifest?x=1']];
        yield 'a fragment' => [['manifestUrl' => self::CDN . '7/manifest#x']];
        yield 'a space in the signature' => [['signedQuery' => 'Expires=1 Signature=2']];
        yield 'a line break in the signature' => [['signedQuery' => "Expires=1\r\nX: y"]];
        yield 'a hash in the signature' => [['signedQuery' => 'Expires=1#x']];
    }

    /**
     * @dataProvider unsafeCdnMetas
     * @param array<string, mixed> $overrides
     */
    public function testCdnModeRefusesAnUnsafeReference(array $overrides): void
    {
        $meta = $this->cdnMeta($this->release(), $overrides);

        self::assertSame(Outcome::REFUSED, $this->client()->fetchManifest($meta)->kind);
        self::assertSame([], $this->fake->requests);
    }

    public function testAManifestThatDoesNotMatchTheChecksumIsNotVerified(): void
    {
        $release = $this->release();
        $meta = $this->originMeta($release);
        $tampered = str_replace('"en"', '"de"', $release->manifestBytes());
        $this->fake->on(self::BASE . 'released/7/manifest', self::ok($tampered));
        $outcome = $this->client()->fetchManifest($meta);

        self::assertSame(Outcome::CHECKSUM_MISMATCH, $outcome->kind);
        self::assertNull($outcome->manifest);
        self::assertNull($outcome->bytes);
    }

    public function testCapsTheManifestAtEightMebibytes(): void
    {
        $release = $this->release();
        $meta = $this->originMeta($release);
        $this->fake->on(self::BASE . 'released/7/manifest', self::ok(str_repeat(' ', 8 * 1024 * 1024 + 1)));
        $outcome = $this->client()->fetchManifest($meta);

        self::assertSame(8 * 1024 * 1024, $this->fake->requests[0]->limit);
        self::assertSame(Outcome::UNREADABLE, $outcome->kind);
    }

    /** @return iterable<string, array{int, string}> */
    public function failures(): iterable
    {
        yield '400' => [400, Outcome::UNFETCHABLE];
        yield '403' => [403, Outcome::UNFETCHABLE];
        yield '404' => [404, Outcome::UNFETCHABLE];
        yield '410' => [410, Outcome::UNFETCHABLE];
        yield '503' => [503, Outcome::UNFETCHABLE];
        yield '429' => [429, Outcome::THROTTLED];
        yield '500' => [500, Outcome::SERVER];
        yield '502' => [502, Outcome::SERVER];
        yield '302' => [302, Outcome::NETWORK];
        yield '304' => [304, Outcome::SERVER];
        yield '204' => [204, Outcome::SERVER];
    }

    /** @dataProvider failures */
    public function testAFailedReadIsReportedByKind(int $status, string $kind): void
    {
        $release = $this->release();
        $meta = $this->originMeta($release);
        $answer = $status === 302 ? new Response(0) : new Response($status, ['retry-after' => '9'], $release->manifestBytes());
        $this->fake->on(self::BASE . 'released/7/manifest', $answer);
        $outcome = $this->client()->fetchManifest($meta);

        self::assertSame($kind, $outcome->kind);
        self::assertNull($outcome->manifest);
    }

    public function testCdnModeRereadsMetaOnceOnAnExpiredSignatureAndRetries(): void
    {
        $release = $this->release();
        $meta = $this->cdnMeta($release);
        $fresh = 'Expires=1700003600&Signature=fresh&Key-Pair-Id=EXAMPLE';
        $this->fake->on(self::CDN . '7/manifest?' . self::SIGNED, new Response(403));
        $this->fake->on(self::CDN . '7/manifest?' . $fresh, self::ok($release->manifestBytes()));
        $this->fake->on(self::DELIVERY, ReleaseBuilder::metaResponse($release->meta([
            'authenticated' => false,
            'signedQuery' => $fresh,
            'manifestUrl' => self::CDN . '7/manifest',
            'slicesBaseUrl' => self::CDN . 'slices/',
        ])));
        $outcome = $this->client()->fetchManifest($meta);

        self::assertSame(Outcome::VERIFIED, $outcome->kind);
        self::assertNotNull($outcome->meta);
        self::assertSame($fresh, $outcome->meta->signedQuery);
        self::assertCount(1, array_filter($this->fake->urls(), static function (string $u): bool {
            return strpos($u, self::DELIVERY) === 0;
        }));
    }

    public function testCdnModeGivesUpWhenTheRereadMetaNamesAnotherRelease(): void
    {
        $release = $this->release();
        $meta = $this->cdnMeta($release);
        $this->fake->on(self::CDN . '7/manifest?' . self::SIGNED, new Response(404));
        $this->fake->on(self::DELIVERY, ReleaseBuilder::metaResponse($release->meta([
            'checksum' => str_repeat('b', 64),
            'authenticated' => false,
            'signedQuery' => 'Expires=2&Signature=other',
            'manifestUrl' => self::CDN . '7/manifest',
            'slicesBaseUrl' => self::CDN . 'slices/',
        ])));
        $outcome = $this->client()->fetchManifest($meta);

        self::assertSame(Outcome::UNFETCHABLE, $outcome->kind);
        self::assertCount(2, $this->fake->requests);
    }

    public function testCdnModeRereadsMetaAtMostOnce(): void
    {
        $release = $this->release();
        $meta = $this->cdnMeta($release);
        $this->fake->on(self::CDN . '7/manifest', new Response(503));
        $this->fake->on(self::DELIVERY, ReleaseBuilder::metaResponse($release->meta([
            'authenticated' => false,
            'signedQuery' => 'Expires=3&Signature=again',
            'manifestUrl' => self::CDN . '7/manifest',
            'slicesBaseUrl' => self::CDN . 'slices/',
        ])));
        $this->client()->fetchManifest($meta);

        self::assertCount(1, array_filter($this->fake->urls(), static function (string $u): bool {
            return strpos($u, self::DELIVERY) === 0;
        }));
        self::assertLessThanOrEqual(3, count($this->fake->requests));
    }

    public function testOriginModeNeverRereadsMeta(): void
    {
        $meta = $this->originMeta($this->release());
        $this->fake->on(self::BASE . 'released/7/manifest', new Response(403));
        $this->client()->fetchManifest($meta);

        self::assertSame([self::BASE . 'released/7/manifest'], $this->fake->urls());
    }

    public function testAServerErrorOnTheCdnEarnsNoReread(): void
    {
        $meta = $this->cdnMeta($this->release());
        $this->fake->on(self::CDN . '7/manifest', new Response(500));
        $this->client()->fetchManifest($meta);

        self::assertCount(1, $this->fake->requests);
    }

    public function testDecodeKeepsOnlyTheJsonFileOfEachSlice(): void
    {
        $bytes = (string) json_encode([
            'schemaVersion' => 1,
            'bundles' => [
                'ns1' => ['type' => 'namespace', 'name' => 'default'],
                'cb2' => ['type' => 'custom', 'name' => 'ios', 'format' => 'ios'],
            ],
            'slices' => [
                'ns1' => ['en' => ['json' => ['hash' => str_repeat('a', 64), 'size' => 10], 'xml' => ['hash' => str_repeat('b', 64), 'size' => 5]]],
                'cb2' => ['en' => ['strings' => ['hash' => str_repeat('c', 64), 'size' => 5]]],
            ],
        ]);
        $manifest = Manifest::decode($bytes);

        self::assertNotNull($manifest);
        self::assertSame(['ns1'], array_keys($manifest->bundles));
        self::assertSame(['en'], array_keys($manifest->files['ns1']));
        self::assertSame(str_repeat('a', 64), $manifest->files['ns1']['en']->hash);
        self::assertSame(10, $manifest->files['ns1']['en']->size);
    }

    /** @return iterable<string, array{mixed, mixed}> */
    public function badFiles(): iterable
    {
        yield 'hash with a path' => ['../../../../etc/passwd' . str_repeat('a', 42), 10];
        yield 'hash upper case' => [str_repeat('A', 64), 10];
        yield 'hash too long' => [str_repeat('a', 65), 10];
        yield 'hash a number' => [123, 10];
        yield 'size negative' => [str_repeat('a', 64), -1];
        yield 'size a string' => [str_repeat('a', 64), '10'];
        yield 'size fractional' => [str_repeat('a', 64), 1.5];
    }

    /**
     * @dataProvider badFiles
     * @param mixed $hash
     * @param mixed $size
     */
    public function testDecodeDropsAFileWithAnUnsafeHashOrSize($hash, $size): void
    {
        $manifest = Manifest::decode((string) json_encode([
            'schemaVersion' => 1,
            'bundles' => ['ns1' => ['type' => 'namespace', 'name' => 'default'], 'ns2' => ['type' => 'namespace', 'name' => 'other']],
            'slices' => [
                'ns1' => ['en' => ['json' => ['hash' => $hash, 'size' => $size]]],
                'ns2' => ['en' => ['json' => ['hash' => str_repeat('d', 64), 'size' => 3]]],
            ],
        ]));

        self::assertNotNull($manifest);
        self::assertArrayNotHasKey('ns1', $manifest->files);
        self::assertArrayNotHasKey('ns1', $manifest->bundles);
        self::assertArrayHasKey('ns2', $manifest->files);
    }

    public function testDecodeKeepsTheBundleLayout(): void
    {
        $manifest = Manifest::decode((string) json_encode([
            'schemaVersion' => 1,
            'bundles' => ['cb1' => ['type' => 'custom', 'name' => 'web', 'layout' => 'nested']],
            'slices' => ['cb1' => ['en' => ['json' => ['hash' => str_repeat('e', 64), 'size' => 3]]]],
        ]));

        self::assertNotNull($manifest);
        self::assertSame('nested', $manifest->bundles['cb1']['layout']);
    }

    /** @return iterable<string, array{string}> */
    public function unusableManifests(): iterable
    {
        yield 'not json' => ['nope'];
        yield 'a list' => ['[1,2]'];
        yield 'no schema version' => ['{"bundles":{},"slices":{}}'];
        yield 'no slices' => ['{"schemaVersion":1,"bundles":{}}'];
        yield 'bundles a list' => ['{"schemaVersion":1,"bundles":[],"slices":{}}'];
    }

    /** @dataProvider unusableManifests */
    public function testDecodeRefusesAnUnusableManifest(string $bytes): void
    {
        self::assertNull(Manifest::decode($bytes));
    }

    public function testDecodeKeepsAnUnknownSchemaVersionForTheCallerToRefuse(): void
    {
        $manifest = Manifest::decode('{"schemaVersion":2,"bundles":{},"slices":{}}');

        self::assertNotNull($manifest);
        self::assertSame(2, $manifest->schemaVersion);
    }

    public function testTheRereadNamesTheReleaseHeld(): void
    {
        $release = $this->release();
        $client = $this->client();
        $this->fake->on(self::DELIVERY, ReleaseBuilder::metaResponse($release->meta([
            'authenticated' => false,
            'signedQuery' => self::SIGNED,
            'manifestUrl' => self::CDN . '7/manifest',
            'slicesBaseUrl' => self::CDN . 'slices/',
        ])));
        $meta = $client->fetchMeta('5')->meta;
        self::assertNotNull($meta);
        $this->fake->on(self::CDN . '7/manifest', new Response(403));
        $client->fetchManifest($meta);
        $metaReads = array_values(array_filter($this->fake->urls(), static function (string $u): bool {
            return strpos($u, self::DELIVERY) === 0;
        }));

        self::assertCount(2, $metaReads);
        self::assertStringEndsWith('&release=5', $metaReads[1]);
    }
}
