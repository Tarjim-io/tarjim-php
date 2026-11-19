<?php

declare(strict_types=1);

namespace Tarjim\Tests\Unit\Wire;

use PHPUnit\Framework\TestCase;
use Tarjim\Tests\Support\FakeTransport;
use Tarjim\Tests\Support\ReleaseBuilder;
use Tarjim\Wire\DeliveryClient;
use Tarjim\Wire\Meta;
use Tarjim\Wire\Outcome;

/** A delivery URL with no path still resolves references under its own origin, and only there. */
final class PathlessDeliveryUrlTest extends TestCase
{
    private const DELIVERY = 'https://delivery.example.test';

    /** @var FakeTransport */
    private $fake;

    protected function setUp(): void
    {
        $this->fake = new FakeTransport();
    }

    private function meta(string $manifestUrl, string $slicesBaseUrl = 'released/slices/'): Meta
    {
        $release = (new ReleaseBuilder('7'))->flat('ns1', 'namespace', 'default', 'en', ['hello' => 'Hello']);
        $this->fake->on(self::DELIVERY, ReleaseBuilder::metaResponse($release->meta(['manifestUrl' => $manifestUrl, 'slicesBaseUrl' => $slicesBaseUrl])));
        $outcome = (new DeliveryClient($this->fake, self::DELIVERY, ReleaseBuilder::KEY))->fetchMeta();
        self::assertNotNull($outcome->meta);
        $this->fake->requests = [];

        return $outcome->meta;
    }

    public function testARelativeReferenceLandsUnderTheRoot(): void
    {
        $meta = $this->meta('released/7/manifest');
        (new DeliveryClient($this->fake, self::DELIVERY, ReleaseBuilder::KEY))->fetchManifest($meta);

        self::assertSame(['https://delivery.example.test/released/7/manifest'], $this->fake->urls());
    }

    /** @return iterable<string, array{string}> */
    public function escapes(): iterable
    {
        yield 'credentials then another host' => ['@evil.example.test/m.json'];
        yield 'another port' => [':8443/m.json'];
    }

    /** @dataProvider escapes */
    public function testAReferenceCannotExtendTheHost(string $reference): void
    {
        $meta = $this->meta($reference);
        $outcome = (new DeliveryClient($this->fake, self::DELIVERY, ReleaseBuilder::KEY))->fetchManifest($meta);

        self::assertSame(Outcome::REFUSED, $outcome->kind);
        self::assertSame([], $this->fake->requests);
    }

    /** @dataProvider escapes */
    public function testASlicesBaseCannotExtendTheHost(string $reference): void
    {
        $meta = $this->meta('released/7/manifest', str_replace('m.json', '', $reference));
        $result = (new DeliveryClient($this->fake, self::DELIVERY, ReleaseBuilder::KEY))
            ->fetchFiles($meta, [new \Tarjim\Wire\FileRef(str_repeat('a', 64), 10)]);

        self::assertSame([], $this->fake->requests);
        self::assertSame(Outcome::REFUSED, $result->results[str_repeat('a', 64)]->kind);
    }

    /** @return iterable<string, array{string}> */
    public function hostLookalikePaths(): iterable
    {
        yield 'a longer host' => ['.evil.example.test/m.json'];
        yield 'another host after a dash' => ['-evil.example.test/m.json'];
    }

    /** @dataProvider hostLookalikePaths */
    public function testAReferenceShapedLikeAHostStaysAPathOnTheOrigin(string $reference): void
    {
        $meta = $this->meta($reference);
        (new DeliveryClient($this->fake, self::DELIVERY, ReleaseBuilder::KEY))->fetchManifest($meta);

        self::assertSame(['https://delivery.example.test/' . $reference], $this->fake->urls());
    }

    /** @dataProvider hostLookalikePaths */
    public function testASlicesBaseShapedLikeAHostStaysAPathOnTheOrigin(string $reference): void
    {
        $meta = $this->meta('released/7/manifest', str_replace('m.json', '', $reference));
        (new DeliveryClient($this->fake, self::DELIVERY, ReleaseBuilder::KEY))
            ->fetchFiles($meta, [new \Tarjim\Wire\FileRef(str_repeat('a', 64), 10)]);

        self::assertSame(['https://delivery.example.test/' . str_replace('m.json', '', $reference) . str_repeat('a', 64) . '.json'], $this->fake->urls());
    }
}
