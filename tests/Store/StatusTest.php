<?php

declare(strict_types=1);

namespace Tarjim\Tests\Store;

use Tarjim\Tests\Support\StoreCase;
use Tarjim\Wire\Response;

final class StatusTest extends StoreCase
{
    public function testNothingHeldReportsNothing(): void
    {
        $status = $this->client()->status();

        self::assertNull($status->releaseId);
        self::assertNull($status->installedAt);
        self::assertNull($status->lastSuccessAt);
    }

    public function testACopyNobodyRefreshedForADayIsStale(): void
    {
        $this->serve($this->release());
        $this->refresh();
        $this->laterBy(86401);

        self::assertTrue($this->client()->status()->stale);
    }

    public function testFailedChecksDoNotMakeACopyFresh(): void
    {
        $this->serve($this->release());
        $this->refresh();
        for ($i = 0; $i < 30; $i++) {
            $this->laterBy(3600);
            $this->fake->on(self::DELIVERY, new Response(503));
            $this->refresh();
        }
        $status = $this->client()->status();

        self::assertTrue($status->stale);
        self::assertGreaterThan($status->lastSuccessAt, $status->lastCheckAt);
    }

    public function testAnUnchangedCheckKeepsTheCopyFresh(): void
    {
        $this->serve($this->release());
        $this->refresh();
        $this->laterBy(80000);
        $this->refresh();
        $this->laterBy(80000);

        self::assertFalse($this->client()->status()->stale);
    }

    public function testStaleAfterIsConfigurable(): void
    {
        $this->serve($this->release());
        $this->refresh();
        $this->laterBy(601);

        self::assertTrue($this->client(['staleAfter' => 600])->status()->stale);
        self::assertFalse($this->client(['staleAfter' => 3600])->status()->stale);
    }

    public function testStatusNeverThrowsOnACorruptStore(): void
    {
        mkdir($this->dir, 0755, true);
        file_put_contents($this->dir . '/current.json', '[1,2');
        file_put_contents($this->dir . '/state.json', '"x"');
        $status = $this->client()->status();

        self::assertNull($status->releaseId);
    }

    public function testStatusNeverLogs(): void
    {
        $this->serve($this->release());
        $this->refresh();
        $this->laterBy(90000);
        $this->logged = [];
        $this->client()->status();
        $this->client()->status();

        self::assertSame([], $this->logged);
    }
}
