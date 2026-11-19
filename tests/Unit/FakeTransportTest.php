<?php

declare(strict_types=1);

namespace Tarjim\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tarjim\Tests\Support\FakeTransport;
use Tarjim\Wire\Request;
use Tarjim\Wire\Response;

final class FakeTransportTest extends TestCase
{
    public function testRecordsEveryRequestAndAnswersByUrlWithoutQuery(): void
    {
        $fake = (new FakeTransport())->on('https://d.example.test/meta', new Response(200, [], '{}'));
        $answer = $fake->send(new Request('https://d.example.test/meta?platform=php', ['X-A' => '1'], 100));

        self::assertSame(200, $answer->status);
        self::assertSame(['https://d.example.test/meta?platform=php'], $fake->urls());
        self::assertSame(['X-A' => '1'], $fake->requests[0]->headers);
    }

    public function testUnknownUrlIsNoAnswer(): void
    {
        self::assertSame(0, (new FakeTransport())->send(new Request('https://x.test/', [], 10))->status);
    }

    public function testServesAQueueInTurnAndRepeatsTheLast(): void
    {
        $fake = (new FakeTransport())->on('https://x.test/', [new Response(503), new Response(200, [], 'ok')]);
        $statuses = [];
        for ($i = 0; $i < 3; $i++) {
            $statuses[] = $fake->send(new Request('https://x.test/', [], 10))->status;
        }
        self::assertSame([503, 200, 200], $statuses);
    }

    public function testABodyOverTheLimitComesBackTooLargeWithoutBytes(): void
    {
        $fake = (new FakeTransport())->on('https://x.test/', new Response(200, [], str_repeat('a', 11)));
        $answer = $fake->send(new Request('https://x.test/', [], 10));

        self::assertTrue($answer->tooLarge);
        self::assertNull($answer->body);
    }
}
