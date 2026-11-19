<?php

declare(strict_types=1);

namespace Tarjim\Tests\Lookup;

final class ReloadTest extends LookupCase
{
    public function testALongRunningProcessPicksUpAPromote(): void
    {
        $reader = $this->installed($this->release('7', ['hello' => 'Seven']), ['locale' => 'en']);
        self::assertSame('Seven', $reader->t('hello'));
        $this->laterBy(61);
        $this->serve($this->release('8', ['hello' => 'Eight']));
        $this->refresh();
        $this->laterBy(2);

        self::assertSame('Eight', $reader->t('hello'));
    }

    public function testChecksForAPromoteAtMostOnceASecond(): void
    {
        $reader = $this->installed($this->release('7', ['hello' => 'Seven']), ['locale' => 'en']);
        self::assertSame('Seven', $reader->t('hello'));
        $this->laterBy(61);
        $this->serve($this->release('8', ['hello' => 'Eight']));
        $this->refresh();

        // Same second as the last check: the reader may still answer from release 7, but never a mix.
        self::assertContains($reader->t('hello'), ['Seven', 'Eight']);
    }

    public function testAProcessThatStartedOnACopyKeepsServingItWhileThePointerIsMissing(): void
    {
        $reader = $this->installed($this->release('7', ['hello' => 'Seven']), ['locale' => 'en']);
        self::assertSame('Seven', $reader->t('hello'));
        unlink($this->dir . '/current.json');
        $this->laterBy(2);

        self::assertSame('Seven', $reader->t('hello'));
    }

    public function testACopyInstalledAfterTheProcessStartedIsPickedUp(): void
    {
        $reader = $this->client(['locale' => 'en']);
        self::assertSame('hello', $reader->t('hello'));
        $this->serve($this->release('7', ['hello' => 'Seven']));
        $this->refresh();
        $this->laterBy(2);

        self::assertSame('Seven', $reader->t('hello'));
    }
}
