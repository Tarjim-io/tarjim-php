<?php

declare(strict_types=1);

namespace Tarjim\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SkeletonTest extends TestCase
{
    /** @return iterable<string, array{class-string}> */
    public function publicTypes(): iterable
    {
        foreach ([
            \Tarjim\Client::class,
            \Tarjim\Config::class,
            \Tarjim\RefreshResult::class,
            \Tarjim\Status::class,
            \Tarjim\Image::class,
            \Tarjim\Export\Exports::class,
            \Tarjim\Export\ExportResult::class,
            \Tarjim\Wire\Transport::class,
            \Tarjim\Wire\CurlTransport::class,
            \Tarjim\Wire\DeliveryClient::class,
            \Tarjim\Wire\DeliveryUrl::class,
        ] as $class) {
            yield $class => [$class];
        }
    }

    /** @dataProvider publicTypes */
    public function testTypeLoads(string $class): void
    {
        self::assertTrue(class_exists($class) || interface_exists($class));
    }

    public function testClientHasTheDocumentedMethods(): void
    {
        foreach (['create', 'refresh', 'status', 't', 'tHtml', 'image', 'has', 'locales'] as $method) {
            self::assertTrue(method_exists(\Tarjim\Client::class, $method), $method);
        }
    }

    public function testComposerAsksForNoRuntimePackages(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/composer.json'), true);
        $runtime = array_filter(array_keys($composer['require']), static function (string $name): bool {
            return $name !== 'php' && strpos($name, 'ext-') !== 0;
        });
        self::assertSame([], array_values($runtime));
    }
}
