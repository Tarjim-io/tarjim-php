<?php

declare(strict_types=1);

namespace Tarjim\Tests\Unit\Wire;

use PHPUnit\Framework\TestCase;
use Tarjim\Tests\Support\FakeTransport;
use Tarjim\Tests\Support\ReleaseBuilder;
use Tarjim\Wire\DeliveryClient;
use Tarjim\Wire\Meta;
use Tarjim\Wire\Response;

abstract class WireCase extends TestCase
{
    protected const DELIVERY = 'https://delivery.example.test/delivery/dst_test/meta';
    protected const BASE = 'https://delivery.example.test/delivery/dst_test/';
    protected const CDN = 'https://cdn.example.test/r/';
    protected const SIGNED = 'Expires=1700000000&Signature=fake~sig_1&Key-Pair-Id=EXAMPLE';
    protected const KEY = ReleaseBuilder::KEY;

    /** @var FakeTransport */
    protected $fake;

    protected function setUp(): void
    {
        $this->fake = new FakeTransport();
    }

    protected function client(): DeliveryClient
    {
        return new DeliveryClient($this->fake, self::DELIVERY, self::KEY);
    }

    protected function release(): ReleaseBuilder
    {
        return (new ReleaseBuilder('7'))
            ->flat('ns1', 'namespace', 'default', 'en', ['hello' => 'Hello', 'bye' => 'Bye'])
            ->flat('ns1', 'namespace', 'default', 'fr', ['hello' => 'Bonjour', 'bye' => 'Au revoir']);
    }

    /** @param array<string, mixed> $overrides */
    protected function originMeta(ReleaseBuilder $release, array $overrides = []): Meta
    {
        $body = $release->meta($overrides);
        $this->fake->on(self::DELIVERY, ReleaseBuilder::metaResponse($body));
        $outcome = $this->client()->fetchMeta();
        self::assertNotNull($outcome->meta, 'meta fixture did not decode: ' . $outcome->kind);
        $this->fake->requests = [];

        return $outcome->meta;
    }

    /** @param array<string, mixed> $overrides */
    protected function cdnMeta(ReleaseBuilder $release, array $overrides = []): Meta
    {
        return $this->originMeta($release, $overrides + [
            'authenticated' => false,
            'signedQuery' => self::SIGNED,
            'manifestUrl' => self::CDN . '7/manifest',
            'slicesBaseUrl' => self::CDN . 'slices/',
        ]);
    }

    /** @param array<string, string> $headers */
    protected static function ok(string $body, array $headers = []): Response
    {
        return new Response(200, $headers + ['content-type' => 'application/json'], $body);
    }

    /** Every request header name, lowercased, across all recorded requests to $url's prefix. */
    protected function headerNamesSentTo(string $prefix): array
    {
        $names = [];
        foreach ($this->fake->requests as $request) {
            if (strpos($request->url, $prefix) === 0) {
                foreach (array_keys($request->headers) as $name) {
                    $names[] = strtolower($name);
                }
            }
        }

        return $names;
    }
}
