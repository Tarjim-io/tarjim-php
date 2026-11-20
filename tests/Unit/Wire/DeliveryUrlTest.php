<?php

declare(strict_types=1);

namespace Tarjim\Tests\Unit\Wire;

use PHPUnit\Framework\TestCase;
use Tarjim\Wire\DeliveryUrl;

final class DeliveryUrlTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public function usable(): iterable
    {
        yield 'https' => ['https://delivery.example.test/delivery/dst_abc/meta'];
        yield 'https with port' => ['https://delivery.example.test:8443/delivery/dst_abc/meta'];
        yield 'http on 127.0.0.1' => ['http://127.0.0.1:3002/delivery/dst_abc/meta'];
        yield 'http on localhost' => ['http://localhost:3002/delivery/dst_abc/meta'];
        yield 'http on ::1' => ['http://[::1]:3002/delivery/dst_abc/meta'];
    }

    /** @dataProvider usable */
    public function testAcceptsAUsableUrl(string $url): void
    {
        self::assertNull(DeliveryUrl::problem($url));
    }

    /** @return iterable<string, array{string}> */
    public function unusable(): iterable
    {
        yield 'empty' => [''];
        yield 'not a url' => ['delivery/dst_abc/meta'];
        yield 'plain http to a remote host' => ['http://delivery.example.test/delivery/dst_abc/meta'];
        yield 'http to a host that only starts like loopback' => ['http://127.0.0.1.example.test/delivery/dst_abc/meta'];
        yield 'http to a host that only starts like localhost' => ['http://localhost.example.test/delivery/dst_abc/meta'];
        yield 'another scheme' => ['ftp://delivery.example.test/delivery/dst_abc/meta'];
        yield 'file scheme' => ['file:///etc/passwd'];
        yield 'a query' => ['https://delivery.example.test/delivery/dst_abc/meta?x=1'];
        yield 'an empty query' => ['https://delivery.example.test/delivery/dst_abc/meta?'];
        yield 'a fragment' => ['https://delivery.example.test/delivery/dst_abc/meta#top'];
        yield 'credentials' => ['https://user:pass@delivery.example.test/delivery/dst_abc/meta'];
        yield 'a user only' => ['https://user@delivery.example.test/delivery/dst_abc/meta'];
        yield 'a trailing slash' => ['https://delivery.example.test/delivery/dst_abc/meta/'];
        yield 'whitespace' => [' https://delivery.example.test/delivery/dst_abc/meta'];
        yield 'a line break' => ["https://delivery.example.test/delivery/dst_abc/meta\r\nX-Injected: 1"];
        yield 'no host' => ['https:///delivery/dst_abc/meta'];
    }

    /** @dataProvider unusable */
    public function testRefusesAnUnusableUrl(string $url): void
    {
        $problem = DeliveryUrl::problem($url);

        self::assertIsString($problem);
        self::assertNotSame('', $problem);
    }

    public function testTheProblemNeverRepeatsTheUrl(): void
    {
        $url = 'https://user:secret-in-url@delivery.example.test/delivery/dst_abc/meta';

        self::assertStringNotContainsString('secret-in-url', (string) DeliveryUrl::problem($url));
    }
}
