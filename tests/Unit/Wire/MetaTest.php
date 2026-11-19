<?php

declare(strict_types=1);

namespace Tarjim\Tests\Unit\Wire;

use Tarjim\Tests\Support\ReleaseBuilder;
use Tarjim\Version;
use Tarjim\Wire\Outcome;
use Tarjim\Wire\Response;

final class MetaTest extends WireCase
{
    public function testAsksWithThePlatformTheVersionAndNoHeldRelease(): void
    {
        $this->client()->fetchMeta();

        self::assertSame(
            [self::DELIVERY . '?platform=php&sdkVersion=' . rawurlencode(Version::SDK) . '&release=0'],
            $this->fake->urls()
        );
    }

    public function testNamesTheHeldRelease(): void
    {
        $this->client()->fetchMeta('12');

        self::assertStringEndsWith('&release=12', $this->fake->urls()[0]);
    }

    public function testNeverSendsAnAppVersionOrAnInstallId(): void
    {
        $this->client()->fetchMeta('12', '"r12-abc"');
        $url = $this->fake->urls()[0];

        self::assertStringNotContainsString('appVersion', $url);
        self::assertStringNotContainsString('installId', $url);
    }

    public function testSendsTheKeyAndTheApiVersionAsHeadersOnly(): void
    {
        $this->client()->fetchMeta();
        $request = $this->fake->requests[0];
        $headers = array_change_key_case($request->headers, CASE_LOWER);

        self::assertSame(self::KEY, $headers['x-tarjim-apikey'] ?? null);
        self::assertSame('2026-07-29', $headers['x-tarjim-api-version'] ?? null);
        self::assertStringNotContainsString(self::KEY, $request->url);
    }

    public function testSendsTheHeldEtagOnlyWhenOneIsHeld(): void
    {
        $this->client()->fetchMeta('12', '"r12-abc"');
        $this->client()->fetchMeta('12');
        $first = array_change_key_case($this->fake->requests[0]->headers, CASE_LOWER);
        $second = array_change_key_case($this->fake->requests[1]->headers, CASE_LOWER);

        self::assertSame('"r12-abc"', $first['if-none-match'] ?? null);
        self::assertArrayNotHasKey('if-none-match', $second);
    }

    public function testCapsTheMetaBodyAtOneMebibyte(): void
    {
        $this->client()->fetchMeta();

        self::assertSame(1024 * 1024, $this->fake->requests[0]->limit);
    }

    public function testDecodesAnOriginModeMeta(): void
    {
        $release = $this->release();
        $this->fake->on(self::DELIVERY, ReleaseBuilder::metaResponse($release->meta(), '"r7-x"'));
        $outcome = $this->client()->fetchMeta();

        self::assertSame(Outcome::RECEIVED, $outcome->kind);
        self::assertNotNull($outcome->meta);
        self::assertSame($release->checksum(), $outcome->meta->checksum);
        self::assertSame('7', $outcome->meta->releaseId);
        self::assertTrue($outcome->meta->authenticated);
        self::assertSame('dst_test', $outcome->meta->distribution);
        self::assertSame('dev', $outcome->meta->stage);
        self::assertSame('"r7-x"', $outcome->meta->etag);
    }

    public function testDecodesACdnModeMetaWithANumericReleaseId(): void
    {
        $meta = $this->release()->meta(['authenticated' => false, 'signedQuery' => self::SIGNED, 'releaseId' => 7]);
        $this->fake->on(self::DELIVERY, ReleaseBuilder::metaResponse($meta));
        $outcome = $this->client()->fetchMeta();

        self::assertSame(Outcome::RECEIVED, $outcome->kind);
        self::assertNotNull($outcome->meta);
        self::assertFalse($outcome->meta->authenticated);
        self::assertSame(self::SIGNED, $outcome->meta->signedQuery);
        self::assertSame('7', $outcome->meta->releaseId);
    }

    public function testANotModifiedAnswerMeansNothingChanged(): void
    {
        $this->fake->on(self::DELIVERY, new Response(304, ['etag' => '"r12-abc"']));

        self::assertSame(Outcome::NOT_MODIFIED, $this->client()->fetchMeta('12', '"r12-abc"')->kind);
    }

    /** @return iterable<string, array{int}> */
    public function configurationStatuses(): iterable
    {
        foreach ([400, 401, 403, 404] as $status) {
            yield (string) $status => [$status];
        }
    }

    /** @dataProvider configurationStatuses */
    public function testAConfigurationAnswerIsReportedWithItsCode(int $status): void
    {
        $this->fake->on(self::DELIVERY, new Response($status, ['content-type' => 'application/problem+json'], '{"code":"delivery.some_problem","detail":"x"}'));
        $outcome = $this->client()->fetchMeta();

        self::assertSame(Outcome::CONFIGURATION, $outcome->kind);
        self::assertSame($status, $outcome->status);
        self::assertSame('delivery.some_problem', $outcome->code);
    }

    public function testAStageWithNoReleaseIsNotAConfigurationError(): void
    {
        $this->fake->on(self::DELIVERY, new Response(404, [], '{"code":"delivery.stage_unreleased"}'));

        self::assertSame(Outcome::UNRELEASED, $this->client()->fetchMeta()->kind);
    }

    public function testThatCodeOnAnotherStatusIsStillAConfigurationError(): void
    {
        $this->fake->on(self::DELIVERY, new Response(403, [], '{"code":"delivery.stage_unreleased"}'));

        self::assertSame(Outcome::CONFIGURATION, $this->client()->fetchMeta()->kind);
    }

    public function testThrottledHonoursRetryAfter(): void
    {
        $this->fake->on(self::DELIVERY, new Response(429, ['retry-after' => '30']));
        $outcome = $this->client()->fetchMeta();

        self::assertSame(Outcome::THROTTLED, $outcome->kind);
        self::assertSame(30, $outcome->retryAfter);
    }

    public function testRetryAfterIsCappedAtADay(): void
    {
        $this->fake->on(self::DELIVERY, new Response(429, ['retry-after' => '999999999']));

        self::assertSame(86400, $this->client()->fetchMeta()->retryAfter);
    }

    /** @return iterable<string, array{string}> */
    public function unusableRetryAfter(): iterable
    {
        yield 'not a date' => ['Someday, 99 Foo 2026 07:28:00 GMT'];
        yield 'negative' => ['-5'];
        yield 'a fraction' => ['1.5'];
        yield 'empty' => [''];
        yield 'huge' => ['99999999999999999999999999'];
    }

    /** @dataProvider unusableRetryAfter */
    public function testAnUnusableRetryAfterIsIgnored(string $value): void
    {
        $this->fake->on(self::DELIVERY, new Response(503, ['retry-after' => $value]));
        $outcome = $this->client()->fetchMeta();

        self::assertSame(Outcome::SERVER, $outcome->kind);
        self::assertTrue($outcome->retryAfter === null || $outcome->retryAfter === 86400, (string) $outcome->retryAfter);
    }

    /** @return iterable<string, array{int}> */
    public function serverStatuses(): iterable
    {
        foreach ([500, 502, 503, 504] as $status) {
            yield (string) $status => [$status];
        }
    }

    /** @dataProvider serverStatuses */
    public function testAServerFailureKeepsWhatIsHeld(int $status): void
    {
        $this->fake->on(self::DELIVERY, new Response($status, ['retry-after' => '12']));
        $outcome = $this->client()->fetchMeta();

        self::assertSame(Outcome::SERVER, $outcome->kind);
        self::assertSame($status, $outcome->status);
        self::assertSame(12, $outcome->retryAfter);
    }

    public function testNoAnswerIsANetworkFailure(): void
    {
        self::assertSame(Outcome::NETWORK, $this->client()->fetchMeta()->kind);
    }

    /** @return iterable<string, array{int}> */
    public function redirects(): iterable
    {
        foreach ([300, 301, 302, 303, 307, 308] as $status) {
            yield (string) $status => [$status];
        }
    }

    /** @dataProvider redirects */
    public function testARedirectIsNeverASuccess(int $status): void
    {
        $meta = (string) json_encode($this->release()->meta());
        $this->fake->on(self::DELIVERY, new Response($status, ['location' => 'https://elsewhere.example.test/meta'], $meta));
        $outcome = $this->client()->fetchMeta();

        self::assertNotSame(Outcome::RECEIVED, $outcome->kind);
        self::assertNull($outcome->meta);
        self::assertSame([self::DELIVERY], array_map(static function (string $u): string {
            return (string) strtok($u, '?');
        }, $this->fake->urls()));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public function unreadableMetas(): iterable
    {
        yield 'checksum not hex' => [['checksum' => str_repeat('z', 64)]];
        yield 'checksum upper case' => [['checksum' => str_repeat('A', 64)]];
        yield 'checksum short' => [['checksum' => str_repeat('a', 63)]];
        yield 'no manifest url' => [['manifestUrl' => null]];
        yield 'no slices base' => [['slicesBaseUrl' => null]];
        yield 'schema version a string' => [['schemaVersion' => '1']];
        yield 'release id with a path' => [['releaseId' => '../7']];
        yield 'release id with letters' => [['releaseId' => '7a']];
        yield 'release id negative' => [['releaseId' => -7]];
        yield 'release id fractional' => [['releaseId' => 7.5]];
        yield 'release id empty' => [['releaseId' => '']];
        yield 'release id with a leading zero' => [['releaseId' => '007']];
        yield 'neither mode' => [['authenticated' => false]];
        yield 'cdn with an empty signature' => [['authenticated' => false, 'signedQuery' => '']];
    }

    /**
     * @dataProvider unreadableMetas
     * @param array<string, mixed> $overrides
     */
    public function testAnIncompleteOrUnsafeMetaIsUnreadable(array $overrides): void
    {
        $meta = array_filter($this->release()->meta($overrides), static function ($v): bool {
            return $v !== null;
        });
        $this->fake->on(self::DELIVERY, ReleaseBuilder::metaResponse($meta));

        self::assertSame(Outcome::UNREADABLE, $this->client()->fetchMeta()->kind);
    }

    public function testABodyThatIsNotJsonIsUnreadable(): void
    {
        $this->fake->on(self::DELIVERY, self::ok('<html>maintenance</html>'));

        self::assertSame(Outcome::UNREADABLE, $this->client()->fetchMeta()->kind);
    }

    public function testABodyOverTheCapIsUnreadable(): void
    {
        $meta = $this->release()->meta(['padding' => str_repeat('x', 1024 * 1024)]);
        $this->fake->on(self::DELIVERY, ReleaseBuilder::metaResponse($meta));

        self::assertSame(Outcome::UNREADABLE, $this->client()->fetchMeta()->kind);
    }

    public function testTheKeyEchoedInAnErrorBodyIsNotKept(): void
    {
        $body = (string) json_encode(['code' => 'delivery.bad_key', 'detail' => 'unknown key ' . self::KEY]);
        $this->fake->on(self::DELIVERY, new Response(401, [], $body));
        $outcome = $this->client()->fetchMeta();

        self::assertStringNotContainsString(self::KEY, serialize($outcome));
        self::assertStringNotContainsString(self::KEY, var_export($outcome, true));
    }

    public function testATransportThatThrowsIsANetworkFailure(): void
    {
        $client = new \Tarjim\Wire\DeliveryClient(new class implements \Tarjim\Wire\Transport {
            public function send(\Tarjim\Wire\Request $request): Response
            {
                throw new \RuntimeException('socket gone');
            }
        }, self::DELIVERY, self::KEY);

        self::assertSame(Outcome::NETWORK, $client->fetchMeta()->kind);
    }

    public function testRetryAfterAsADateIsHonoured(): void
    {
        $this->fake->on(self::DELIVERY, new Response(429, ['retry-after' => gmdate('D, d M Y H:i:s', time() + 120) . ' GMT']));
        $retryAfter = $this->client()->fetchMeta()->retryAfter;

        self::assertNotNull($retryAfter);
        self::assertGreaterThanOrEqual(110, $retryAfter);
        self::assertLessThanOrEqual(121, $retryAfter);
    }

    public function testRetryAfterAsAPastDateMeansNow(): void
    {
        $this->fake->on(self::DELIVERY, new Response(503, ['retry-after' => 'Wed, 21 Oct 2015 07:28:00 GMT']));

        self::assertSame(0, $this->client()->fetchMeta()->retryAfter);
    }

    /** @return iterable<string, array{string}> */
    public function unsafeEtags(): iterable
    {
        yield 'a carriage return' => ["\"a\rb\""];
        yield 'a line feed' => ["\"a\nb\""];
        yield 'a nul' => ["\"a\0b\""];
        yield 'a tab' => ["\"a\tb\""];
        yield 'non-ascii' => ["\"r7-\xc3\xa9\""];
        yield 'too long' => ['"' . str_repeat('a', 300) . '"'];
    }

    /** @dataProvider unsafeEtags */
    public function testAnEtagThatCouldNotBeSentBackIsNotKept(string $etag): void
    {
        $this->fake->on(self::DELIVERY, ReleaseBuilder::metaResponse($this->release()->meta(), $etag));
        $outcome = $this->client()->fetchMeta();

        self::assertSame(Outcome::RECEIVED, $outcome->kind);
        self::assertNotNull($outcome->meta);
        self::assertNull($outcome->meta->etag);
    }

    public function testAWeakEtagIsKept(): void
    {
        $this->fake->on(self::DELIVERY, ReleaseBuilder::metaResponse($this->release()->meta(), 'W/"r7-abc"'));
        $outcome = $this->client()->fetchMeta();

        self::assertNotNull($outcome->meta);
        self::assertSame('W/"r7-abc"', $outcome->meta->etag);
    }
}
