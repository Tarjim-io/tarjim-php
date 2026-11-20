<?php

declare(strict_types=1);

namespace Tarjim\Tests\Store;

use Tarjim\RefreshResult;
use Tarjim\Tests\Support\LockHolder;
use Tarjim\Tests\Support\StoreCase;
use Tarjim\Wire\Response;

final class CadenceTest extends StoreCase
{
    public function testASecondRefreshInsideAMinuteCallsNobody(): void
    {
        $this->serve($this->release());
        $this->refresh();
        $this->takeRequests();
        $this->laterBy(59);
        $result = $this->refresh();

        self::assertSame(RefreshResult::NOT_DUE, $result->status);
        self::assertTrue($result->copyHeld);
        self::assertSame([], $this->takeRequests());
    }

    public function testAfterAMinuteItChecksAgain(): void
    {
        $this->serve($this->release());
        $this->refresh();
        $this->takeRequests();
        $this->laterBy(60);
        $this->refresh();

        self::assertSame([self::DELIVERY], $this->takeRequests());
    }

    public function testTheFloorHoldsAcrossClients(): void
    {
        $this->serve($this->release());
        $this->client()->refresh();
        $this->takeRequests();

        self::assertSame(RefreshResult::NOT_DUE, $this->client()->refresh()->status);
        self::assertSame([], $this->takeRequests());
    }

    public function testAFailedCheckStillCountsAgainstTheFloor(): void
    {
        $this->fake->on(self::DELIVERY, new Response(500));
        $this->refresh();
        $this->takeRequests();
        $this->laterBy(30);

        self::assertSame(RefreshResult::NOT_DUE, $this->refresh()->status);
        self::assertSame([], $this->takeRequests());
    }

    public function testRetryAfterHoldsChecksOff(): void
    {
        $this->fake->on(self::DELIVERY, new Response(429, ['retry-after' => '300']));
        $first = $this->refresh();
        $this->takeRequests();

        self::assertSame(300, $first->retryAfter);
        $this->laterBy(120);
        $held = $this->refresh();
        self::assertSame(RefreshResult::NOT_DUE, $held->status);
        self::assertSame([], $this->takeRequests());
        self::assertSame(180, $held->retryAfter);
        $this->laterBy(180);
        $this->refresh();
        self::assertSame([self::DELIVERY], $this->takeRequests());
    }

    public function testASuccessEndsAWait(): void
    {
        $this->fake->on(self::DELIVERY, new Response(429, ['retry-after' => '120']));
        $this->refresh();
        $this->laterBy(120);
        $this->serve($this->release());
        self::assertSame(RefreshResult::UPDATED, $this->refresh()->status);
        $this->takeRequests();
        $this->laterBy(60);
        $this->refresh();

        self::assertSame([self::DELIVERY], $this->takeRequests());
    }

    public function testAClockThatMovedBackDoesNotStopChecks(): void
    {
        $this->serve($this->release());
        $this->refresh();
        $this->takeRequests();
        $this->clock->advance(-3600);
        $this->refresh();

        self::assertSame([self::DELIVERY], $this->takeRequests());
    }

    public function testAWaitLongerThanADayIsIgnored(): void
    {
        file_exists($this->dir) || mkdir($this->dir, 0755, true);
        file_put_contents($this->dir . '/state.json', (string) json_encode(['notBefore' => $this->clock->now + 7 * 86400]));
        $this->serve($this->release());

        self::assertSame(RefreshResult::UPDATED, $this->refresh()->status);
    }

    public function testARefreshWhileAnotherHoldsTheLockIsBusyAndCallsNobody(): void
    {
        $this->serve($this->release());
        mkdir($this->dir, 0755, true);
        $holder = new LockHolder($this->dir . '/lock', 5);
        $started = microtime(true);
        $result = $this->refresh();
        $elapsed = microtime(true) - $started;
        $holder->release();

        self::assertSame(RefreshResult::BUSY, $result->status);
        self::assertSame([], $this->takeRequests());
        self::assertLessThan(2, $elapsed);
    }

    public function testWaitingOutTheLock(): void
    {
        $this->serve($this->release());
        mkdir($this->dir, 0755, true);
        $holder = new LockHolder($this->dir . '/lock', 1.5);
        // Real time here: the lock is held by another process.
        $result = $this->client(['now' => null, 'sleep' => null])->refresh(['wait' => 10]);
        $holder->release();

        self::assertSame(RefreshResult::UPDATED, $result->status);
    }

    public function testWaitingOutTheFloor(): void
    {
        $this->serve($this->release());
        $this->refresh();
        $this->takeRequests();
        $this->laterBy(10);
        $result = $this->refresh(['wait' => 120]);

        self::assertSame(RefreshResult::UNCHANGED, $result->status);
        self::assertSame([self::DELIVERY], $this->takeRequests());
        self::assertGreaterThanOrEqual(50, array_sum($this->clock->slept));
    }

    public function testWaitingOutAStoredRetryAfter(): void
    {
        $this->fake->on(self::DELIVERY, new Response(503, ['retry-after' => '200']));
        $this->refresh();
        $this->serve($this->release());
        $result = $this->refresh(['wait' => 300]);

        self::assertSame(RefreshResult::UPDATED, $result->status);
        self::assertGreaterThanOrEqual(190, array_sum($this->clock->slept));
    }

    public function testAWaitThatCannotEndInTimeDoesNotSleepForNothing(): void
    {
        $this->fake->on(self::DELIVERY, new Response(503, ['retry-after' => '600']));
        $this->refresh();
        $this->takeRequests();
        $result = $this->refresh(['wait' => 30]);

        self::assertSame(RefreshResult::NOT_DUE, $result->status);
        self::assertSame([], $this->takeRequests());
        self::assertLessThanOrEqual(30, array_sum($this->clock->slept));
    }

    public function testWaitingForAFirstRelease(): void
    {
        $this->fake->on(self::DELIVERY, [
            new Response(404, [], '{"code":"delivery.stage_unreleased"}'),
            new Response(404, [], '{"code":"delivery.stage_unreleased"}'),
            \Tarjim\Tests\Support\ReleaseBuilder::metaResponse($this->release()->meta()),
        ]);
        $release = $this->release();
        $this->fake->on(self::BASE . 'released/7/manifest', new Response(200, [], $release->manifestBytes()));
        foreach ($release->files as $hash => $bytes) {
            $this->fake->on(self::BASE . 'released/slices/' . $hash . '.json', new Response(200, [], $bytes));
        }
        $result = $this->refresh(['wait' => 600]);

        self::assertSame(RefreshResult::UPDATED, $result->status);
        self::assertCount(3, array_filter($this->takeRequests(), static function (string $u): bool {
            return $u === self::DELIVERY;
        }));
    }

    public function testAFailureIsNotWaitedOn(): void
    {
        $this->fake->on(self::DELIVERY, new Response(500));
        $result = $this->refresh(['wait' => 600]);

        self::assertSame(RefreshResult::FAILED, $result->status);
        self::assertCount(1, $this->takeRequests());
    }

    public function testACheckInterruptedMidInstallStillCountsAgainstTheFloor(): void
    {
        $disk = new \Tarjim\Tests\Support\FaultyDisk();
        $disk->failPutsMatching = '#/(objects|releases)/|/current\.json$#';
        $disk->throw = true;
        $this->serve($this->release());
        $this->refresh([], ['disk' => $disk]);
        $this->takeRequests();
        $this->laterBy(10);

        self::assertSame(RefreshResult::NOT_DUE, $this->refresh()->status);
        self::assertSame([], $this->takeRequests());
    }

    public function testACheckThatCannotBeRecordedCallsNobody(): void
    {
        $disk = new \Tarjim\Tests\Support\FaultyDisk();
        $disk->failPutsMatching = '#/state\.json$#';
        $this->serve($this->release());
        $result = $this->refresh([], ['disk' => $disk]);

        self::assertSame(RefreshResult::FAILED, $result->status);
        self::assertSame([], $this->takeRequests());
    }

    public function testAWaitAtTheCapSurvivesASmallClockStepBack(): void
    {
        $this->fake->on(self::DELIVERY, new Response(429, ['retry-after' => '86400']));
        $this->refresh();
        $this->takeRequests();
        $this->clock->advance(-30);

        self::assertSame(RefreshResult::NOT_DUE, $this->refresh()->status);
        self::assertSame([], $this->takeRequests());
    }
}
