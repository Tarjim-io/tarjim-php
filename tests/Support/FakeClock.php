<?php

declare(strict_types=1);

namespace Tarjim\Tests\Support;

/** Unix time that moves only when told to, or when slept on. Starts at the real time. */
final class FakeClock
{
    /** @var int */
    public $now;

    /** @var list<int> */
    public $slept = [];

    public function __construct(?int $now = null)
    {
        $this->now = $now ?? time();
    }

    public function advance(int $seconds): void
    {
        $this->now += $seconds;
    }

    /** @return callable(): int */
    public function nowFn(): callable
    {
        return function (): int {
            return $this->now;
        };
    }

    /** @return callable(int): void */
    public function sleepFn(): callable
    {
        return function (int $seconds): void {
            $this->slept[] = $seconds;
            $this->now += max(1, $seconds);
        };
    }
}
